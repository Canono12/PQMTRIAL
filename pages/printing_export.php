<?php
/**
 * Printing — Export ALL rows to CSV (opens cleanly in Excel)
 * Includes ALL columns from the `printing` table.
 */
require_once __DIR__ . '/../includes/db.php';

// ── Filters (same as main page) ───────────────────────────────────────────────
$f_monthyear = $_GET['monthyear'] ?? '';
$f_date_from = $_GET['date_from'] ?? '';
$f_date_to   = $_GET['date_to']   ?? '';
$f_machines = array_filter(array_map('trim', (array)($_GET['machine'] ?? [])));
$f_shift   = $_GET['shift']   ?? '';
$f_fabric  = $_GET['fabric']  ?? '';
$f_design  = $_GET['design']  ?? '';
$f_jo      = $_GET['jo']      ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_monthyear) {
    $where[] = "DATE_FORMAT(STR_TO_DATE(DATE_COMPLETED,'%m/%d/%Y'), '%Y-%m') = ?";
    $params[] = $f_monthyear;
    $types .= 's';
}
if ($f_date_from) {
    $where[] = "STR_TO_DATE(DATE_COMPLETED,'%m/%d/%Y') >= ?";
    $params[] = $f_date_from;
    $types .= 's';
}
if ($f_date_to) {
    $where[] = "STR_TO_DATE(DATE_COMPLETED,'%m/%d/%Y') <= ?";
    $params[] = $f_date_to;
    $types .= 's';
}
if (!empty($f_machines)) {
    $placeholders = implode(',', array_fill(0, count($f_machines), '?'));
    $where[] = "MACHINE_NUMBER IN ($placeholders)";
    foreach ($f_machines as $m) { $params[] = $m; $types .= 's'; }
}
if ($f_shift)   { $where[] = 'PRINTED_FABRIC = ?';               $params[] = $f_shift;   $types .= 's'; }
if ($f_fabric)  { $where[] = 'FABRIC_WIDTH_AND_TAPE_DENIER = ?'; $params[] = $f_fabric;  $types .= 's'; }
if ($f_design)  { $where[] = 'PRINT_DESIGN LIKE ?';              $params[] = '%'.$f_design.'%'; $types .= 's'; }
if ($f_jo)      { $where[] = 'JOB_ORDER_NUMBER = ?';             $params[] = $f_jo;      $types .= 's'; }
// machine filter handled above via \$f_machines array
if ($f_shift)   { $where[] = 'PRINTED_FABRIC = ?';                $params[] = $f_shift;   $types .= 's'; }
if ($f_fabric)  { $where[] = 'FABRIC_WIDTH_AND_TAPE_DENIER = ?';  $params[] = $f_fabric;  $types .= 's'; }
if ($f_design)  { $where[] = 'PRINT_DESIGN LIKE ?';               $params[] = '%'.$f_design.'%'; $types .= 's'; }
if ($f_jo)      { $where[] = 'JOB_ORDER_NUMBER = ?';              $params[] = $f_jo;      $types .= 's'; }

$wsql = implode(' AND ', $where);

// ── Fetch ALL rows, ALL columns (no LIMIT) ────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT
        FINAL_BATCH_CODE,
        Id,
        Start_time,
        Completion_time,
        Email,
        `Name`,
        ENCODING_DATE,
        ENCODED_BY,
        PRINTED_FABRIC,
        FABRIC_WIDTH_AND_TAPE_DENIER,
        ACTUAL_FABRIC_WIDTH,
        PRINTING_STAGE,
        ROLL_SERIES_NO_YEAR_and_PLANT,
        INPUT_ROLL_SERIES_NO_LAST_5_DIGITS,
        PRINT_DESIGN,
        JOB_ORDER_NUMBER,
        JO_ROLL_QUANTITY,
        `Bag/Batch_Code/PO_Code`,
        Splitting_Order,
        MACHINE_NUMBER,
        DATE_STARTED,
        TIME_STARTED,
        DATE_COMPLETED,
        TIME_COMPLETED,
        ROLL_ORDER,
        `ROLL_LENGTH(meters)`,
        `ROLL_WEIGHT(kilograms)`,
        If_Fodah_printing_input_BEGINNING_COUNT,
        If_Fodah_printing_input_END_COUNT,
        `INPUT_WASTE(grams)`,
        `OUTPUT_WASTE(grams)`,
        PRODUCTION_REMARKS,
        SHIFT_LEADER_AND_LEAD_OPERATOR,
        SHIFT_PRODUCTION_PERSONNEL,
        IPQC_TECHNICIAN,
        CORONA_DOSAGE,
        DRYER_TEMPERATURE,
        BLOWER_SETTING,
        INK_1_PANTONE_CODE,
        INK_1_VISCOSITY,
        INK_2_PANTONE_CODE,
        INK_2_VISCOSITY,
        INK_3_PANTONE_CODE,
        INK_3_VISCOSITY,
        INK_4_PANTONE_CODE,
        INK_4_VISCOSITY,
        INK_5_PANTONE_CODE,
        INK_5_VISCOSITY,
        INK_6_PANTONE_CODE,
        INK_6_VISCOSITY,
        SEQUENCE,
        DATE_STARTED_1,
        DATE_COMPLETED_1,
        FORMULATED_1,
        FORMULATED_2,
        COMBINATION,
        TIME_STARTED_FINAL,
        FORMULATED_3,
        FORMULATED_4,
        COMBINATION_1,
        `TIME_COMPLETED _FINAL`,
        STARTED,
        COMPLETED,
        PROCESS_TIME,
        `PROCESS_TIME(MINS)`
     FROM printing
     WHERE $wsql
     ORDER BY Id ASC"
);

if ($stmt === false) {
    die('Query error: ' . $conn->error);
}
if ($types && $params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// ── Stream CSV to browser ─────────────────────────────────────────────────────
$filename = 'printing_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// UTF-8 BOM so Excel opens with correct encoding automatically
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// ── Column headers ────────────────────────────────────────────────────────────
fputcsv($out, [
    'Final Batch Code',
    'ID',
    'Start Time',
    'Completion Time',
    'Email',
    'Name',
    'Encoding Date',
    'Encoded By',
    'Printed Fabric',
    'Fabric Width and Tape Denier',
    'Actual Fabric Width',
    'Printing Stage',
    'Roll Series No. Year and Plant',
    'Input Roll Series No. (Last 5 Digits)',
    'Print Design',
    'Job Order Number',
    'JO Roll Quantity',
    'Bag/Batch Code/PO Code',
    'Splitting Order',
    'Machine Number',
    'Date Started',
    'Time Started',
    'Date Completed',
    'Time Completed',
    'Roll Order',
    'Roll Length (meters)',
    'Roll Weight (kilograms)',
    'If Fodah Printing - Beginning Count',
    'If Fodah Printing - End Count',
    'Input Waste (grams)',
    'Output Waste (grams)',
    'Production Remarks',
    'Shift Leader and Lead Operator',
    'Shift Production Personnel',
    'IPQC Technician',
    'Corona Dosage',
    'Dryer Temperature',
    'Blower Setting',
    'Ink 1 Pantone Code',
    'Ink 1 Viscosity',
    'Ink 2 Pantone Code',
    'Ink 2 Viscosity',
    'Ink 3 Pantone Code',
    'Ink 3 Viscosity',
    'Ink 4 Pantone Code',
    'Ink 4 Viscosity',
    'Ink 5 Pantone Code',
    'Ink 5 Viscosity',
    'Ink 6 Pantone Code',
    'Ink 6 Viscosity',
    'Sequence',
    'Date Started (1)',
    'Date Completed (1)',
    'Formulated 1 (Hr)',
    'Formulated 2 (Min)',
    'Combination (Time)',
    'Time Started Final',
    'Formulated 3 (Hr)',
    'Formulated 4 (Min)',
    'Combination 1 (Time)',
    'Time Completed Final',
    'Started',
    'Completed',
    'Process Time',
    'Process Time (Mins)',
]);

// ── Stream all rows directly (memory efficient for large datasets) ─────────────
while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['FINAL_BATCH_CODE'],
        $row['Id'],
        $row['Start_time'],
        $row['Completion_time'],
        $row['Email'],
        $row['Name'],
        $row['ENCODING_DATE'],
        $row['ENCODED_BY'],
        $row['PRINTED_FABRIC'],
        $row['FABRIC_WIDTH_AND_TAPE_DENIER'],
        $row['ACTUAL_FABRIC_WIDTH'],
        $row['PRINTING_STAGE'],
        $row['ROLL_SERIES_NO_YEAR_and_PLANT'],
        $row['INPUT_ROLL_SERIES_NO_LAST_5_DIGITS'],
        $row['PRINT_DESIGN'],
        $row['JOB_ORDER_NUMBER'],
        $row['JO_ROLL_QUANTITY'],
        $row['Bag/Batch_Code/PO_Code'],
        $row['Splitting_Order'],
        $row['MACHINE_NUMBER'],
        $row['DATE_STARTED'],
        $row['TIME_STARTED'],
        $row['DATE_COMPLETED'],
        $row['TIME_COMPLETED'],
        $row['ROLL_ORDER'],
        $row['ROLL_LENGTH(meters)'],
        $row['ROLL_WEIGHT(kilograms)'],
        $row['If_Fodah_printing_input_BEGINNING_COUNT'],
        $row['If_Fodah_printing_input_END_COUNT'],
        $row['INPUT_WASTE(grams)'],
        $row['OUTPUT_WASTE(grams)'],
        $row['PRODUCTION_REMARKS'],
        $row['SHIFT_LEADER_AND_LEAD_OPERATOR'],
        $row['SHIFT_PRODUCTION_PERSONNEL'],
        $row['IPQC_TECHNICIAN'],
        $row['CORONA_DOSAGE'],
        $row['DRYER_TEMPERATURE'],
        $row['BLOWER_SETTING'],
        $row['INK_1_PANTONE_CODE'],
        $row['INK_1_VISCOSITY'],
        $row['INK_2_PANTONE_CODE'],
        $row['INK_2_VISCOSITY'],
        $row['INK_3_PANTONE_CODE'],
        $row['INK_3_VISCOSITY'],
        $row['INK_4_PANTONE_CODE'],
        $row['INK_4_VISCOSITY'],
        $row['INK_5_PANTONE_CODE'],
        $row['INK_5_VISCOSITY'],
        $row['INK_6_PANTONE_CODE'],
        $row['INK_6_VISCOSITY'],
        $row['SEQUENCE'],
        $row['DATE_STARTED_1'],
        $row['DATE_COMPLETED_1'],
        $row['FORMULATED_1'],
        $row['FORMULATED_2'],
        $row['COMBINATION'],
        $row['TIME_STARTED_FINAL'],
        $row['FORMULATED_3'],
        $row['FORMULATED_4'],
        $row['COMBINATION_1'],
        $row['TIME_COMPLETED _FINAL'],
        $row['STARTED'],
        $row['COMPLETED'],
        $row['PROCESS_TIME'],
        $row['PROCESS_TIME(MINS)'],
    ]);
}

fclose($out);
exit;