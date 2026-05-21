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
        'table'     => 'weaving',
        'no_header' => true,
        'columns'   => [
            'ID','Series_Number','Date_Harvested','Time_Harvested','Line',
            'Machine_NO.','Shift','Loom_Batch_Code','Length(M)','Weight(Kg)',
            'Width(mm)','Denier','NO_of_roll','Classification_of_roll',
            'Fabric_GSM','Remarks',
            'YARN_BATCHCODE_(WARP1)','YARN_BATCHCODE(WARP2)','YARN_BATCHCODE(WARP3)',
            'YARN_BATCHCODE(WARP4)','YARN_BATCHCODE(WEFT1)','YARN_BATCHCODE(WEFT2)',
            'YARN_BATCHCODE(WEFT3)','YARN_BATCHCODE(WEFT4)','YARN_BATCHCODE(WEFT5)',
            'YARN_BATCHCODE(WEFT6)','YARN_BATCHCODE(WEFT8)',
        ],
        'required'       => [],
        'sample_headers' => 'ID | Series_Number | Date_Harvested | Time_Harvested | Line | Machine_NO. | Shift | Loom_Batch_Code | Length(M) | Weight(Kg) | Width(mm) | Denier | NO_of_roll | Classification_of_roll | Fabric_GSM | Remarks | YARN_BATCHCODE_(WARP1) | ...',
    ],

    /* ── Lamination ── */
    'lamination' => [
        'table'     => 'laminationimport_fixed',
        'no_header' => true,
        'columns'   => [
            // [00-14] Identity & machine
            'FINAL_BATCH_CODE','Id','Start_time','Completion_time','Email','Name',
            'Encoding_date','Encoded_by','FABRIC_WIDTH_AND_TAPE_DENIER','FABRIC_TYPE',
            'ACTUAL_FABRICWIDTH','YEAR_and_PLANT','LAST_5-DIGITS','Splitting_Order',
            'machine_number',
            // [15-20] Dates, lengths, weights
            'DATE_STARTED','TIME_STARTED','DATE_COMPLETED','Time_Completed',
            'ROLL_LENGTH_meters','ROLL_WEIGHT_kilograms',
            // [21-25] GSM + Waste
            'FABRIC_QUALITY_gsm','INPUT_WASTE_grams','UNLAMINATED_WASTE_grams','OUTPUT_WASTE_grams',
            // [25-29] QC / personnel
            'PRODUCTION_REMARKS','LEAD_OPERATOR','SHIFT_PRODUCTION_PERSONNEL',
            'IPQC_TECHNICIAN','PP',
            // [30-35] PP RM details
            'PP_BATCH_CODE','PP_PERCENTAGE','IS_THE_FABRIC_FOR_OFC_SEALING_TAPE',
            'PP_side2','PP_side2_BATCHCODE','PP_side2_PERCENTAGE',
            // [36-41] Calcium Carbonate 1
            'CALCIUM_CARBONATE_1','CALCIUM-CARBONATE_1_BATCH_CODE','CALCIUM_CARBONATE_1_PERCENTAGE',
            // [39-41] Calcium Carbonate 2
            'CALCIUM_CARBONATE_2','CALCIUM_CARBONATE_2_BATCH_CODE','CALCIUM_CARBONATE_2_PERCENTAGE',
            // [42-46] Colorant
            'IS_THE_COATING_COLORED','COLORANT','COLORANT_BATCHCODE','COLORANT_PERCENTAGE',
            'SEQUENCE',
            // [47-60] Timing / formulation columns
            'DATE_STARTED.1','DATE_COMPLETED.1','FORMULATED','FORMULATED.1','COMBINATION',
            'TIME_STARTED_FINAL','FORMULATED.2','FORMULATED.3','COMBINATION.1',
            'TIME_COMPLETED_FINAL','STARTED','COMPLETED','PROCESS_TIME','PROCESS_TIME_minutes',
        ],
        'required'       => ['DATE_STARTED','machine_number','ROLL_WEIGHT_kilograms'],
        'sample_headers' => 'FINAL_BATCH_CODE | Id | machine_number | DATE_STARTED | ROLL_WEIGHT_kilograms | INPUT_WASTE_grams | ...',
    ],

    /* ── Printing ── */
    'printing' => [
        'table'     => 'printing',
        'no_header' => true,
        'columns'   => [
            // [00-07] Identity
            'FINAL_BATCH_CODE','Id','Start_time','Completion_time','Email','Name',
            'ENCODING_DATE','ENCODED_BY',
            // [08-18] Fabric / roll info
            'PRINTED_FABRIC','FABRIC_WIDTH_AND_TAPE_DENIER','ACTUAL_FABRIC_WIDTH',
            'PRINTING_STAGE','ROLL_SERIES_NO_YEAR_and_PLANT',
            'INPUT_ROLL_SERIES_NO_LAST_5_DIGITS','PRINT_DESIGN','JOB_ORDER_NUMBER',
            'JO_ROLL_QUANTITY','Bag/Batch_Code/PO_Code','Splitting_Order',
            // [19-26] Machine / production
            'MACHINE_NUMBER','DATE_STARTED','TIME_STARTED','DATE_COMPLETED',
            'TIME_COMPLETED','ROLL_ORDER','ROLL_LENGTH(meters)','ROLL_WEIGHT(kilograms)',
            // [27-34] Counts, waste, personnel
            'If_Fodah_printing_input_BEGINNING_COUNT','If_Fodah_printing_input_END_COUNT',
            'INPUT_WASTE(grams)','OUTPUT_WASTE(grams)','PRODUCTION_REMARKS',
            'SHIFT_LEADER_AND_LEAD_OPERATOR','SHIFT_PRODUCTION_PERSONNEL','IPQC_TECHNICIAN',
            // [35-49] Machine settings & inks
            'CORONA_DOSAGE','DRYER_TEMPERATURE','BLOWER_SETTING',
            'INK_1_PANTONE_CODE','INK_1_VISCOSITY',
            'INK_2_PANTONE_CODE','INK_2_VISCOSITY',
            'INK_3_PANTONE_CODE','INK_3_VISCOSITY',
            'INK_4_PANTONE_CODE','INK_4_VISCOSITY',
            'INK_5_PANTONE_CODE','INK_5_VISCOSITY',
            'INK_6_PANTONE_CODE','INK_6_VISCOSITY',
            // [50-64] Sequence / timing
            'SEQUENCE','DATE_STARTED_1','DATE_COMPLETED_1',
            'FORMULATED_1','FORMULATED_2','COMBINATION','TIME_STARTED_FINAL',
            'FORMULATED_3','FORMULATED_4','COMBINATION_1','TIME_COMPLETED _FINAL',
            'STARTED','COMPLETED','PROCESS_TIME','PROCESS_TIME(MINS)',
        ],
        'required'       => ['DATE_STARTED','MACHINE_NUMBER','ROLL_WEIGHT(kilograms)'],
        'sample_headers' => 'FINAL_BATCH_CODE | Id | MACHINE_NUMBER | DATE_STARTED | ROLL_WEIGHT(kilograms) | INPUT_WASTE(grams) | OUTPUT_WASTE(grams) | ...',
    ],

    /* ── Conversion (welding) ── */
    'conversion' => [
        'table'     => 'welding',
        'no_header' => true,         // WELDING.csv has no header row; data starts at row 1
        'columns'   => [
            // Positional columns 0-26 matching the 77-column WELDING.csv export
            // Columns 27-76 in the CSV are extra fields not stored in the welding table
            'FINAL_BATCH_CODE',          // col 0
            'Id',                        // col 1
            'Start_time',                // col 2
            'Completion_time',           // col 3
            'Email',                     // col 4
            'Name',                      // col 5
            'Encoding_date',             // col 6
            'ENCODED_BY',                // col 7
            'INPUT_CUSTOMER',            // col 8
            'FABRIC_WIDTH_TAPE_DENIER',  // col 9
            'BAG_TYPE',                  // col 10
            'JOB_ORDER_NO',              // col 11
            'Roll_Series_No_YEAR_and_PLANT',      // col 12
            'Roll_Series_No_LAST_5_DIGITS',       // col 13
            'Roll_Series_No_Splitting_Order',     // col 14
            'MACINE_NUMBER',             // col 15
            'DATE_STARTED',              // col 16
            'TIME_STARTED',              // col 17
            'DATE_COMPLETED',            // col 18
            'TIME_COMPLETED',            // col 19
            'BEGINNING_COUNT',           // col 20
            'END_COUNT',                 // col 21
            'OUTPUT',                    // col 22
            'PRODUCTION_REMARKS',        // col 23
            'SHIFT_PERSONNEL_HISTORY',   // col 24
            'TECNICIAN',                 // col 25
            'LEAD_INSPECTOR',            // col 26
        ],
        'required'       => ['DATE_STARTED','MACINE_NUMBER','OUTPUT'],
        'sample_headers' => 'No header row needed — upload the WELDING.csv export directly. Columns 0-26 are imported; the remaining 50 columns are ignored.',
    ],

    /* ── CTSW ── */
    'ctsw' => [
        'table'     => 'ctswtrial',
        'no_header' => true,         // CTSW.csv has no header row; data starts at row 1
        'columns'   => [
            // Positional columns 0-30 matching the 46-column CTSW.csv export
            // Columns 31-45 in the CSV are extra timing fields not stored in ctswtrial
            'FINAL_BATCH_CODE',                  // col 0
            'Id',                                // col 1
            'Start_time',                        // col 2
            'Completion_time',                   // col 3
            'Email',                             // col 4
            'Name',                              // col 5
            'ENCODING_DATE',                     // col 6
            'ENCODING_PERSONNEL',                // col 7
            'FABRIC_WIDTH_TAPE_DENIER',          // col 8
            'ACTUAL_FABRIC_WIDTH',               // col 9
            'CUSTOMER',                          // col 10
            'BAG_TYPE',                          // col 11
            'JOB_ORDER_NUMBER',                  // col 12
            'ROLL_SERIES_NO_YEAR_and_PLANT',     // col 13
            'ROLL_SERIES_NO_LAST_5_DIGITS',      // col 14
            'BAG_CODE',                          // col 15
            'Roll_Series_No_Splitting_Order',    // col 16
            'MACHIN_NUMBER',                     // col 17
            'DATE_STARTED',                      // col 18
            'TIME_STARTED',                      // col 19
            'DATE_FINISHED',                     // col 20
            'TIME_FINISHED',                     // col 21
            'ROLL_LENGTH_FROM_PRINTING(meters)', // col 22
            'ROLL_WEIGHT(kilograms)',             // col 23
            'GOOD_BAGS_IN_COUNT',                // col 24
            'GOOD_BAGS_IN_WEIGHT',               // col 25
            'DEFECTIVE_BAGS_(COUNT)',             // col 26
            'DEFECTIVE_BAGS(WEIGHT)',             // col 27
            'WASTE_FABRIC/BAG(WEIGHT)',           // col 28
            'PRODUCTION_REMARKS',                // col 29
            'SHIFT_PRODUCTION_PERSONNEL',        // col 30
            'IPQC_TECHNICIAN',                   // col 31
        ],
        'required'       => ['DATE_STARTED','MACHIN_NUMBER'],
        'sample_headers' => 'No header row needed — upload the CTSW.csv export directly. Columns 0-31 are imported; the remaining columns are ignored.',
    ],

    /* ── Extrusion ── */
    'extrusion' => [
        'table'     => 'extrusion',
        'no_header' => true,
        'columns'   => [
            'line','date','shift','bobbin_batchcode','class',
            'bobbin_type','gross_wt','no_pcs','bb_wt','pallet_wt',
            'net_wt','denier','time_start','time_end','qc_remarks',
        ],
        'required'       => [],
        'sample_headers' => 'line | date | shift | bobbin_batchcode | class | bobbin_type | gross_wt | no_pcs | bb_wt | pallet_wt | net_wt | denier | time_start | time_end | qc_remarks',
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
    if (!empty($cfg['no_header'])) {
        // No header row — read all rows as data
        while (($row = fgetcsv($fh)) !== false) $rows[] = $row;
    } else {
        $headers = array_map('trim', fgetcsv($fh));
        while (($row = fgetcsv($fh)) !== false) $rows[] = $row;
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
        if (!empty($cfg['no_header'])) {
            // No header row — treat ALL rows as data (don't strip the first row)
            $headers = [];
            $rows    = $sheetData;
        } else {
            $headers = array_map('trim', array_shift($sheetData));
            $rows    = $sheetData;
        }
    } catch (\Exception $e) {
        json_err('WRONG FORMAT — Could not read the Excel file. ' . $e->getMessage());
    }
}

// ── Validate headers / map columns ─────────────────────────────────────────
$dbCols = $cfg['columns'];
$fillableCols = [];
$fillableIdx  = [];

if (!empty($cfg['no_header'])) {
    // No header row: map DB columns to positional indices (0, 1, 2 ...)
    if (empty($rows)) json_err('The file appears to be empty.');
    foreach ($dbCols as $i => $col) {
        $fillableCols[] = $col;
        $fillableIdx[]  = $i;
    }
} else {
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

    // Build header→index map (case-insensitive)
    $headerMap = [];
    foreach ($headers as $i => $h) {
        $headerMap[strtolower(trim($h))] = $i;
    }

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
}

// ── Insert rows ─────────────────────────────────────────────────────────────
$table        = $cfg['table'];
$colList      = implode('`,`', $fillableCols);
$placeholders = implode(',', array_fill(0, count($fillableCols), '?'));
$types        = str_repeat('s', count($fillableCols));

$stmt = $conn->prepare("INSERT INTO `$table` (`$colList`) VALUES ($placeholders)");
if (!$stmt) json_err('DB prepare failed: ' . $conn->error);

$inserted  = 0;
$skipped   = 0;
$rowErrors = [];

// Find date column position in fillableCols for sanitization (supports 'date' and 'Date_Harvested')
$datePos = false;
foreach ($fillableCols as $di => $dc) {
    if (strtolower($dc) === 'date' || strtolower($dc) === 'date_harvested' || strtolower($dc) === 'date_started') {
        $datePos = $di; break;
    }
}

foreach ($rows as $rowNum => $row) {
    // Build values array
    $vals = [];
    foreach ($fillableIdx as $idx) {
        $v = isset($row[$idx]) ? trim((string)$row[$idx]) : null;
        $vals[] = ($v === '' || $v === null) ? null : $v;
    }

    // Skip completely empty rows
    if (count(array_filter($vals, fn($v) => $v !== null)) === 0) {
        $skipped++;
        continue;
    }

    // Sanitize date column — ensure YYYY-MM-DD for MySQL DATE type
    if ($datePos !== false && !empty($vals[$datePos])) {
        $raw = $vals[$datePos];
        if (is_numeric($raw) && (int)$raw > 40000) {
            // Excel serial number → real date
            $vals[$datePos] = gmdate('Y-m-d', ((int)$raw - 25569) * 86400);
        } elseif (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})$/', $raw, $m)) {
            // MM/DD/YYYY → YYYY-MM-DD
            $y = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];
            $vals[$datePos] = sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]);
        }
        // Already YYYY-MM-DD: leave as-is
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