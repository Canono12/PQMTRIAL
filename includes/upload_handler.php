<?php
/**
 * PQM Dashboard — Universal Excel Upload Handler
 * Accepts .xlsx / .xls files and inserts rows into the target table.
 * Returns JSON: { success, inserted, errors[], message }
 */

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

// Auth check — viewers cannot upload
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['pqm_role']) || $_SESSION['pqm_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin login required to upload data.']);
    exit;
}
// ── Helpers ───────────────────────────────────────────────────────────────────
function json_err($msg, $extra = []) {
    echo json_encode(array_merge(['success' => false, 'message' => $msg], $extra));
    exit;
}
function json_ok($inserted, $skipped = 0, $errors = []) {
    echo json_encode([
        'success'  => true,
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'message'  => "Imported $inserted row(s) successfully." . ($skipped ? " ($skipped skipped)" : ''),
    ]);
    exit;
}

// ── Validate request ──────────────────────────────────────────────────────────
$module = trim($_POST['module'] ?? '');
if (!$module) json_err('No module specified.');

// ── Module → table + column map ───────────────────────────────────────────────
$module_config = [

    /* ── Weaving ── */
    'weaving' => [
        'table'   => 'weaving',
        'columns' => [
            'ID','Series_Number','Date_Harvested','Time_Harvested','Line',
            'Machine_NO.','Shift','Loom_Batch_Code','Length(M)','Weight(Kg)',
            'Width(mm)','Denier','NO_of_roll','Classification_of_roll',
            'Fabric_GSM','Remarks',
            'YARN_BATCHCODE_(WARP1)','YARN_BATCHCODE(WARP2)','YARN_BATCHCODE(WARP3)',
            'YARN_BATCHCODE(WARP4)','YARN_BATCHCODE(WEFT1)','YARN_BATCHCODE(WEFT2)',
            'YARN_BATCHCODE(WEFT3)','YARN_BATCHCODE(WEFT4)','YARN_BATCHCODE(WEFT5)',
            'YARN_BATCHCODE(WEFT6)','YARN_BATCHCODE(WEFT8)',
        ],
        'required' => ['Date_Harvested','Machine_NO.','Weight(Kg)'],
        'sample_headers' => 'ID | Series_Number | Date_Harvested | Time_Harvested | Line | Machine_NO. | Shift | Loom_Batch_Code | Length(M) | Weight(Kg) | Width(mm) | Denier | NO_of_roll | ...',
    ],

    /* ── Lamination ── */
    'lamination' => [
        'table'   => 'laminationimport_fixed',
        'columns' => [
            'FINAL_BATCH_CODE','Id','Start_time','Completion_time','Email','Name',
            'Encoding_date','Encoded_by','FABRIC_WIDTH_AND_TAPE_DENIER','FABRIC_TYPE',
            'ACTUAL_FABRICWIDTH','YEAR_and_PLANT','LAST_5-DIGITS','Splitting_Order',
            'machine_number','DATE_STARTED','TIME_STARTED','DATE_COMPLETED','Time_Completed',
            'ROLL_LENGTH_meters','ROLL_WEIGHT_kilograms','INPUT_WASTE_grams',
            'UNLAMINATED_WASTE_grams','OUTPUT_WASTE_grams','ROLL_SERIES_NO',
            'PP','CALCIUM_CARBONATE_1','CALCIUM_CARBONATE_2',
            'FABRIC_QUALITY_gsm','SHIFT_PRODUCTION_PERSONNEL',
        ],
        'required' => ['DATE_STARTED','machine_number','ROLL_WEIGHT_kilograms'],
        'sample_headers' => 'FINAL_BATCH_CODE | Id | machine_number | DATE_STARTED | ROLL_WEIGHT_kilograms | INPUT_WASTE_grams | ...',
    ],

    /* ── Printing ── */
    'printing' => [
        'table'   => 'printing',
        'columns' => [
            'FINAL_BATCH_CODE','Id','Start_time','Completion_time','Email','Name',
            'ENCODING_DATE','ENCODED_BY','PRINTED_FABRIC','FABRIC_WIDTH_AND_TAPE_DENIER',
            'ACTUAL_FABRIC_WIDTH','PRINTING_STAGE','ROLL_SERIES_NO_YEAR_and_PLANT',
            'INPUT_ROLL_SERIES_NO_LAST_5_DIGITS','PRINT_DESIGN','JOB_ORDER_NUMBER',
            'JO_ROLL_QUANTITY','Bag/Batch_Code/PO_Code','Splitting_Order',
            'MACHINE_NUMBER','DATE_STARTED','TIME_STARTED','DATE_COMPLETED',
            'TIME_COMPLETED','ROLL_LENGTH(meters)','ROLL_WEIGHT(kilograms)',
            'OUTPUT_WASTE(grams)','SHIFT_PRODUCTION_PERSONNEL',
        ],
        'required' => ['DATE_STARTED','MACHINE_NUMBER','ROLL_WEIGHT(kilograms)'],
        'sample_headers' => 'FINAL_BATCH_CODE | Id | MACHINE_NUMBER | DATE_STARTED | ROLL_WEIGHT(kilograms) | OUTPUT_WASTE(grams) | ...',
    ],

    /* ── Conversion (welding) ── */
    'conversion' => [
        'table'   => 'welding',
        'columns' => [
            'FINAL_BATCH_CODE','Id','Start_time','Completion_time','Email','Name',
            'Encoding_date','ENCODED_BY','INPUT_CUSTOMER','FABRIC_WIDTH_TAPE_DENIER',
            'BAG_TYPE','JOB_ORDER_NO','Roll_Series_No_YEAR_and_PLANT',
            'Roll_Series_No_LAST_5_DIGITS','Roll_Series_No_Splitting_Order',
            'MACINE_NUMBER','DATE_STARTED','TIME_STARTED','DATE_COMPLETED',
            'TIME_COMPLETED','BEGINNING_COUNT','END_COUNT','OUTPUT',
            'PRODUCTION_REMARKS','SHIFT_PERSONNEL_HISTORY','TECNICIAN',
            'LEAD_INSPECTOR',
        ],
        'required' => ['DATE_STARTED','MACINE_NUMBER','OUTPUT'],
        'sample_headers' => 'FINAL_BATCH_CODE | Id | MACINE_NUMBER | DATE_STARTED | OUTPUT | INPUT_CUSTOMER | BAG_TYPE | ...',
    ],

    /* ── CTSW ── */
    'ctsw' => [
        'table'   => 'ctswtrial',
        'columns' => [
            'FINAL_BATCH_CODE','Id','Start_time','Completion_time','Email','Name',
            'ENCODING_DATE','ENCODING_PERSONNEL','FABRIC_WIDTH_TAPE_DENIER',
            'ACTUAL_FABRIC_WIDTH','CUSTOMER','BAG_TYPE','JOB_ORDER_NUMBER',
            'ROLL_SERIES_NO_YEAR_and_PLANT','ROLL_SERIES_NO_LAST_5_DIGITS',
            'BAG_CODE','Roll_Series_No_Splitting_Order','MACHIN_NUMBER',
            'DATE_STARTED','TIME_STARTED','DATE_FINISHED','TIME_FINISHED',
            'ROLL_LENGTH_FROM_PRINTING(meters)','ROLL_WEIGHT(kilograms)',
            'GOOD_BAGS_IN_COUNT','GOOD_BAGS_IN_WEIGHT',
            'DEFECTIVE_BAGS_(COUNT)','DEFECTIVE_BAGS(WEIGHT)',
            'WASTE_FABRIC/BAG(WEIGHT)','PRODUCTION_REMARKS',
            'SHIFT_PRODUCTION_PERSONNEL','IPQC_TECHNICIAN',
        ],
        'required' => ['DATE_STARTED','MACHIN_NUMBER'],
        'sample_headers' => 'FINAL_BATCH_CODE | Id | MACHIN_NUMBER | DATE_STARTED | GOOD_BAGS_IN_COUNT | DEFECTIVE_BAGS_(COUNT) | ...',
    ],

    /* ── Extrusion (new) ── */
    'extrusion' => [
        'table'   => 'extrusion',
        'columns' => [
            'Id','Date','Shift','Machine_No','Line',
            'Output_Weight_kg','Waste_kg','Tape_Denier','Tape_Width_mm',
            'Batch_Code','Remarks','Encoded_By',
        ],
        'required' => ['Date','Machine_No','Output_Weight_kg'],
        'sample_headers' => 'Id | Date | Shift | Machine_No | Line | Output_Weight_kg | Waste_kg | Tape_Denier | Tape_Width_mm | Batch_Code | Remarks | Encoded_By',
        'auto_create' => "CREATE TABLE IF NOT EXISTS `extrusion` (
            `Id` int(6) AUTO_INCREMENT PRIMARY KEY,
            `Date` varchar(20) DEFAULT NULL,
            `Shift` varchar(5) DEFAULT NULL,
            `Machine_No` varchar(20) DEFAULT NULL,
            `Line` varchar(10) DEFAULT NULL,
            `Output_Weight_kg` decimal(10,3) DEFAULT NULL,
            `Waste_kg` decimal(10,3) DEFAULT NULL,
            `Tape_Denier` varchar(20) DEFAULT NULL,
            `Tape_Width_mm` decimal(6,2) DEFAULT NULL,
            `Batch_Code` varchar(30) DEFAULT NULL,
            `Remarks` varchar(200) DEFAULT NULL,
            `Encoded_By` varchar(50) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    ],

    /* ── FQC (new) ── */
    'fqc' => [
        'table'   => 'fqc',
        'columns' => [
            'Id','Date','Shift','Machine_No','Batch_Code','Job_Order',
            'Product_Type','Inspected_Qty','Passed_Qty','Failed_Qty',
            'Defect_Type','Inspector','Remarks',
        ],
        'required' => ['Date','Batch_Code','Inspected_Qty'],
        'sample_headers' => 'Id | Date | Shift | Machine_No | Batch_Code | Job_Order | Product_Type | Inspected_Qty | Passed_Qty | Failed_Qty | Defect_Type | Inspector | Remarks',
        'auto_create' => "CREATE TABLE IF NOT EXISTS `fqc` (
            `Id` int(6) AUTO_INCREMENT PRIMARY KEY,
            `Date` varchar(20) DEFAULT NULL,
            `Shift` varchar(5) DEFAULT NULL,
            `Machine_No` varchar(20) DEFAULT NULL,
            `Batch_Code` varchar(30) DEFAULT NULL,
            `Job_Order` varchar(20) DEFAULT NULL,
            `Product_Type` varchar(50) DEFAULT NULL,
            `Inspected_Qty` int(8) DEFAULT NULL,
            `Passed_Qty` int(8) DEFAULT NULL,
            `Failed_Qty` int(8) DEFAULT NULL,
            `Defect_Type` varchar(100) DEFAULT NULL,
            `Inspector` varchar(50) DEFAULT NULL,
            `Remarks` varchar(200) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    ],

    /* ── Packing (new) ── */
    'packing' => [
        'table'   => 'packing',
        'columns' => [
            'Id','Date','Shift','Machine_No','Batch_Code','Job_Order',
            'Customer','Product_Type','Packed_Qty','Packed_Weight_kg',
            'Defective_Qty','Dispatch_Date','Remarks','Encoded_By',
        ],
        'required' => ['Date','Batch_Code','Packed_Qty'],
        'sample_headers' => 'Id | Date | Shift | Machine_No | Batch_Code | Job_Order | Customer | Product_Type | Packed_Qty | Packed_Weight_kg | Defective_Qty | Dispatch_Date | Remarks | Encoded_By',
        'auto_create' => "CREATE TABLE IF NOT EXISTS `packing` (
            `Id` int(6) AUTO_INCREMENT PRIMARY KEY,
            `Date` varchar(20) DEFAULT NULL,
            `Shift` varchar(5) DEFAULT NULL,
            `Machine_No` varchar(20) DEFAULT NULL,
            `Batch_Code` varchar(30) DEFAULT NULL,
            `Job_Order` varchar(20) DEFAULT NULL,
            `Customer` varchar(50) DEFAULT NULL,
            `Product_Type` varchar(100) DEFAULT NULL,
            `Packed_Qty` int(8) DEFAULT NULL,
            `Packed_Weight_kg` decimal(10,3) DEFAULT NULL,
            `Defective_Qty` int(6) DEFAULT NULL,
            `Dispatch_Date` varchar(20) DEFAULT NULL,
            `Remarks` varchar(200) DEFAULT NULL,
            `Encoded_By` varchar(50) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    ],
];

if (!isset($module_config[$module])) json_err("Unknown module: $module");
$cfg = $module_config[$module];

// ── Auto-create table if needed ───────────────────────────────────────────────
if (!empty($cfg['auto_create'])) {
    if (!$conn->query($cfg['auto_create'])) {
        json_err('Could not create table: ' . $conn->error);
    }
}

// ── File validation ───────────────────────────────────────────────────────────
if (empty($_FILES['excel_file'])) json_err('No file uploaded.');
$file = $_FILES['excel_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
    ];
    json_err($upload_errors[$file['error']] ?? 'Upload error code ' . $file['error']);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
    json_err('WRONG FORMAT — Please upload an Excel file (.xlsx, .xls) or CSV (.csv). Got: .' . $ext);
}

// ── PhpSpreadsheet / fallback CSV ─────────────────────────────────────────────
$tmp = $file['tmp_name'];

// Try PhpSpreadsheet if available; otherwise fall back to CSV-only mode
$rows = [];
$headers = [];

if ($ext === 'csv') {
    // Parse CSV directly
    if (($fh = fopen($tmp, 'r')) === false) json_err('Cannot open file.');
    $headers = array_map('trim', fgetcsv($fh));
    while (($row = fgetcsv($fh)) !== false) {
        $rows[] = $row;
    }
    fclose($fh);
} else {
    // For xlsx/xls we need PhpSpreadsheet — check if it exists
    $pss_autoload = __DIR__ . '/../../vendor/autoload.php';
    $pss_bundled  = __DIR__ . '/PhpSpreadsheet/autoload.php';

    if (file_exists($pss_autoload)) {
        require_once $pss_autoload;
        $useLib = true;
    } elseif (file_exists($pss_bundled)) {
        require_once $pss_bundled;
        $useLib = true;
    } else {
        // No PhpSpreadsheet — instruct user to convert to CSV or install library
        json_err(
            'PhpSpreadsheet library not found. ' .
            'Please save your Excel file as CSV (File → Save As → CSV UTF-8) and re-upload, ' .
            'or ask your admin to run: composer require phpoffice/phpspreadsheet',
            ['hint' => 'csv_fallback']
        );
    }

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $sheetData = $sheet->toArray(null, true, true, false);
        if (empty($sheetData)) json_err('The Excel file appears to be empty.');
        $headers = array_map('trim', array_shift($sheetData));
        $rows    = $sheetData;
    } catch (\Exception $e) {
        json_err('WRONG FORMAT — Could not read the Excel file. ' . $e->getMessage());
    }
}

// ── Validate headers ──────────────────────────────────────────────────────────
if (empty($headers)) json_err('The file has no header row. Expected first row: ' . $cfg['sample_headers']);

// Check required columns exist in the header
$headerLower = array_map('strtolower', $headers);
$missing = [];
foreach ($cfg['required'] as $req) {
    if (!in_array(strtolower($req), $headerLower)) $missing[] = $req;
}
if ($missing) {
    json_err(
        'WRONG FORMAT — Missing required column(s): ' . implode(', ', $missing) . '. ' .
        'Expected headers: ' . $cfg['sample_headers']
    );
}

if (empty($rows)) json_err('The file has a header but no data rows.', ['success' => false]);

// ── Map file columns to DB columns ────────────────────────────────────────────
// Build header→index map (case-insensitive)
$headerMap = [];
foreach ($headers as $i => $h) {
    $headerMap[strtolower(trim($h))] = $i;
}

// Determine which DB columns we can fill from the file
$dbCols = $cfg['columns'];
$fillableCols = [];
$fillableIdx  = [];
foreach ($dbCols as $col) {
    $key = strtolower($col);
    if (isset($headerMap[$key])) {
        $fillableCols[] = $col;
        $fillableIdx[]  = $headerMap[$key];
    }
}
if (empty($fillableCols)) {
    json_err('WRONG FORMAT — No matching columns found between your file and the database. Expected headers: ' . $cfg['sample_headers']);
}

// ── Insert rows ───────────────────────────────────────────────────────────────
$table    = $cfg['table'];
$colList  = implode('`,`', $fillableCols);
$placeholders = implode(',', array_fill(0, count($fillableCols), '?'));
$types    = str_repeat('s', count($fillableCols));

$stmt = $conn->prepare("INSERT INTO `$table` (`$colList`) VALUES ($placeholders)");
if (!$stmt) json_err('DB prepare failed: ' . $conn->error);

$inserted = 0;
$skipped  = 0;
$rowErrors = [];

foreach ($rows as $rowNum => $row) {
    // Skip completely empty rows
    $vals = [];
    foreach ($fillableIdx as $idx) {
        $vals[] = isset($row[$idx]) ? (string)$row[$idx] : null;
    }
    if (count(array_filter($vals, fn($v) => $v !== null && $v !== '')) === 0) {
        $skipped++;
        continue;
    }

    $stmt->bind_param($types, ...$vals);
    if ($stmt->execute()) {
        $inserted++;
    } else {
        $rowErrors[] = 'Row ' . ($rowNum + 2) . ': ' . $stmt->error;
        if (count($rowErrors) >= 10) { $rowErrors[] = '...more errors truncated'; break; }
    }
}

$stmt->close();
json_ok($inserted, $skipped, $rowErrors);
