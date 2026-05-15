<?php
/**
 * Lamination — Export ALL rows to CSV (opens cleanly in Excel)
 * Includes ALL columns from the `laminationimport_fixed` table.
 */
require_once __DIR__ . '/../includes/db.php';

// ── Filters (same as main page) ───────────────────────────────────────────────
$f_date_from = $_GET['date_from'] ?? '';
$f_date_to   = $_GET['date_to']   ?? '';
$f_machine   = $_GET['machine']   ?? '';
$f_rm        = $_GET['rm']        ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_date_from) { $where[] = 'DATE_STARTED >= ?';  $params[] = $f_date_from; $types .= 's'; }
if ($f_date_to)   { $where[] = 'DATE_STARTED <= ?';  $params[] = $f_date_to;   $types .= 's'; }
if ($f_machine)   { $where[] = 'machine_number = ?';  $params[] = $f_machine;  $types .= 's'; }
if ($f_rm) {
    $where[] = '(PP LIKE ? OR CALCIUM_CARBONATE_1 LIKE ? OR CALCIUM_CARBONATE_2 LIKE ?)';
    $params[] = "%$f_rm%"; $params[] = "%$f_rm%"; $params[] = "%$f_rm%";
    $types .= 'sss';
}
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
        Encoding_date,
        Encoded_by,
        FABRIC_WIDTH_AND_TAPE_DENIER,
        FABRIC_TYPE,
        ACTUAL_FABRICWIDTH,
        YEAR_and_PLANT,
        `LAST_5-DIGITS`,
        Splitting_Order,
        machine_number,
        DATE_STARTED,
        TIME_STARTED,
        DATE_COMPLETED,
        Time_Completed,
        ROLL_LENGTH_meters,
        ROLL_WEIGHT_kilograms,
        FABRIC_QUALITY_gsm,
        INPUT_WASTE_grams,
        UNLAMINATED_WASTE_grams,
        OUTPUT_WASTE_grams,
        PRODUCTION_REMARKS,
        LEAD_OPERATOR,
        SHIFT_PRODUCTION_PERSONNEL,
        IPQC_TECHNICIAN,
        PP,
        PP_BATCH_CODE,
        PP_PERCENTAGE,
        IS_THE_FABRIC_FOR_OFC_SEALING_TAPE,
        PP_side2,
        PP_side2_BATCHCODE,
        PP_side2_PERCENTAGE,
        CALCIUM_CARBONATE_1,
        `CALCIUM-CARBONATE_1_BATCH_CODE` AS CC1_BATCH_CODE,
        CALCIUM_CARBONATE_1_PERCENTAGE,
        CALCIUM_CARBONATE_2,
        CALCIUM_CARBONATE_2_BATCH_CODE,
        CALCIUM_CARBONATE_2_PERCENTAGE,
        IS_THE_COATING_COLORED,
        COLORANT,
        COLORANT_BATCHCODE,
        COLORANT_PERCENTAGE,
        SEQUENCE,
        `DATE_STARTED.1`,
        `DATE_COMPLETED.1`,
        FORMULATED,
        `FORMULATED.1`,
        COMBINATION,
        TIME_STARTED_FINAL,
        `FORMULATED.2`,
        `FORMULATED.3`,
        `COMBINATION.1`,
        TIME_COMPLETED_FINAL,
        STARTED,
        COMPLETED,
        PROCESS_TIME,
        PROCESS_TIME_minutes
     FROM laminationimport_fixed
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
$filename = 'lamination_export_' . date('Ymd_His') . '.csv';
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
    'Fabric Width and Tape Denier',
    'Fabric Type',
    'Actual Fabric Width',
    'Year and Plant',
    'Last 5 Digits',
    'Splitting Order',
    'Machine Number',
    'Date Started',
    'Time Started',
    'Date Completed',
    'Time Completed',
    'Roll Length (meters)',
    'Roll Weight (kilograms)',
    'Fabric Quality (GSM)',
    'Input Waste (grams)',
    'Unlaminated Waste (grams)',
    'Output Waste (grams)',
    'Production Remarks',
    'Lead Operator',
    'Shift Production Personnel',
    'IPQC Technician',
    'PP',
    'PP Batch Code',
    'PP Percentage',
    'Is Fabric for OFC Sealing Tape',
    'PP Side 2',
    'PP Side 2 Batchcode',
    'PP Side 2 Percentage',
    'Calcium Carbonate 1',
    'CC1 Batch Code',
    'CC1 Percentage',
    'Calcium Carbonate 2',
    'CC2 Batch Code',
    'CC2 Percentage',
    'Is Coating Colored',
    'Colorant',
    'Colorant Batchcode',
    'Colorant Percentage',
    'Sequence',
    'Date Started (1)',
    'Date Completed (1)',
    'Formulated (Hr)',
    'Formulated (Min)',
    'Combination (Time)',
    'Time Started Final',
    'Formulated 2 (Hr)',
    'Formulated 3 (Min)',
    'Combination 1 (Time)',
    'Time Completed Final',
    'Started',
    'Completed',
    'Process Time',
    'Process Time (Minutes)',
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
        $row['Encoding_date'],
        $row['Encoded_by'],
        $row['FABRIC_WIDTH_AND_TAPE_DENIER'],
        $row['FABRIC_TYPE'],
        $row['ACTUAL_FABRICWIDTH'],
        $row['YEAR_and_PLANT'],
        $row['LAST_5-DIGITS'],
        $row['Splitting_Order'],
        $row['machine_number'],
        $row['DATE_STARTED'],
        $row['TIME_STARTED'],
        $row['DATE_COMPLETED'],
        $row['Time_Completed'],
        $row['ROLL_LENGTH_meters'],
        $row['ROLL_WEIGHT_kilograms'],
        $row['FABRIC_QUALITY_gsm'],
        $row['INPUT_WASTE_grams'],
        $row['UNLAMINATED_WASTE_grams'],
        $row['OUTPUT_WASTE_grams'],
        $row['PRODUCTION_REMARKS'],
        $row['LEAD_OPERATOR'],
        $row['SHIFT_PRODUCTION_PERSONNEL'],
        $row['IPQC_TECHNICIAN'],
        $row['PP'],
        $row['PP_BATCH_CODE'],
        $row['PP_PERCENTAGE'],
        $row['IS_THE_FABRIC_FOR_OFC_SEALING_TAPE'],
        $row['PP_side2'],
        $row['PP_side2_BATCHCODE'],
        $row['PP_side2_PERCENTAGE'],
        $row['CALCIUM_CARBONATE_1'],
        $row['CC1_BATCH_CODE'],
        $row['CALCIUM_CARBONATE_1_PERCENTAGE'],
        $row['CALCIUM_CARBONATE_2'],
        $row['CALCIUM_CARBONATE_2_BATCH_CODE'],
        $row['CALCIUM_CARBONATE_2_PERCENTAGE'],
        $row['IS_THE_COATING_COLORED'],
        $row['COLORANT'],
        $row['COLORANT_BATCHCODE'],
        $row['COLORANT_PERCENTAGE'],
        $row['SEQUENCE'],
        $row['DATE_STARTED.1'],
        $row['DATE_COMPLETED.1'],
        $row['FORMULATED'],
        $row['FORMULATED.1'],
        $row['COMBINATION'],
        $row['TIME_STARTED_FINAL'],
        $row['FORMULATED.2'],
        $row['FORMULATED.3'],
        $row['COMBINATION.1'],
        $row['TIME_COMPLETED_FINAL'],
        $row['STARTED'],
        $row['COMPLETED'],
        $row['PROCESS_TIME'],
        $row['PROCESS_TIME_minutes'],
    ]);
}

fclose($out);
exit;