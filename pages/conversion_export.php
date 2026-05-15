<?php
/**
 * Conversion — Export ALL rows to CSV (opens cleanly in Excel)
 * Includes ALL columns from the `welding` table.
 */
require_once __DIR__ . '/../includes/db.php';

// ── Filters (same as main page) ───────────────────────────────────────────────
$f_month    = $_GET['month']    ?? '';
$f_machine  = $_GET['machine']  ?? '';
$f_customer = $_GET['customer'] ?? '';
$f_fabric   = $_GET['fabric']   ?? '';
$f_jo       = $_GET['jo']       ?? '';
$f_bagtype  = $_GET['bagtype']  ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_month) {
    list($fm, $fy) = explode('/', $f_month, 2);
    $where[] = "MONTH(STR_TO_DATE(DATE_STARTED,'%m/%d/%Y'))=? AND YEAR(STR_TO_DATE(DATE_STARTED,'%m/%d/%Y'))=?";
    $params[] = (int)$fm;
    $params[] = (int)$fy;
    $types .= 'ss';
}
if ($f_machine)  { $where[] = 'MACINE_NUMBER = ?';             $params[] = $f_machine;  $types .= 's'; }
if ($f_customer) { $where[] = 'INPUT_CUSTOMER = ?';            $params[] = $f_customer; $types .= 's'; }
if ($f_fabric)   { $where[] = 'FABRIC_WIDTH_TAPE_DENIER = ?';  $params[] = $f_fabric;   $types .= 's'; }
if ($f_jo)       { $where[] = 'JOB_ORDER_NO = ?';              $params[] = $f_jo;       $types .= 's'; }
if ($f_bagtype)  { $where[] = 'BAG_TYPE LIKE ?';               $params[] = '%'.$f_bagtype.'%'; $types .= 's'; }

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
        ENCODED_BY,
        INPUT_CUSTOMER,
        FABRIC_WIDTH_TAPE_DENIER,
        BAG_TYPE,
        JOB_ORDER_NO,
        Roll_Series_No_YEAR_and_PLANT,
        Roll_Series_No_LAST_5_DIGITS,
        Roll_Series_No_Splitting_Order,
        MACINE_NUMBER,
        DATE_STARTED,
        TIME_STARTED,
        DATE_COMPLETED,
        TIME_COMPLETED,
        BEGINNING_COUNT,
        END_COUNT,
        OUTPUT,
        PRODUCTION_REMARKS,
        SHIFT_PERSONNEL_HISTORY,
        TECNICIAN,
        LEAD_INSPECTOR,
        TOP_PATCH_BATCH_CODE,
        TOP_PATCH_LENGTH,
        TOP_PATCH_WEIGTH,
        BOTTOM_PATCH_BATCH_CODE,
        BOTTOM_PATCH_LENGTH,
        BOTTOM_PATCH_WEIGTH,
        VALVE_BATCH_CODE,
        VALVE_LENGTH,
        VALVE_WEIGTH,
        IN_WEAVING,
        IN_LAMINATION,
        IN_PRINTING,
        IN_CONVERSION,
        MIDDLE_WEAVING,
        MIDDLE_LAMINATION,
        MIDDLE_PRINTING,
        MIDDLE_CONVERSION,
        OUT_WEAVING,
        OUT_LAMINATION,
        OUT_PRINTING,
        OUT_CONVERSION,
        ADDITIONAL_REMARKS,
        ADJUSTMENT_SEQUENCE,
        VALVE_PATCH_TEMPERATURE,
        VALVE_PATCH_PRESSURE,
        VALVE_PATCH_OFFSET_BEGIN,
        VALVE_PATCH_OFFSET_END,
        COVER_PATCH_TEMPERATURE,
        COVER_PATCH_PRESSURE,
        COVER_PATCH_OFFSET_BEGIN,
        COVER_PATCH_OFFSET_END,
        BOTTOM_PATCH_TEMPERATURE,
        BOTTOM_PATCH_PRESSURE,
        BOTTOM_PATCH_OFFSET_BEGIN,
        BOTTOM_PATCH_OFFSET_END,
        WELDING_REMARKS,
        DATE_STARTED1,
        DATE_COMPLETED1,
        FORMULATED,
        FORMULATED_1,
        COMBINATION_1,
        TIME_STARTED_FINAL,
        FORMULATED_2,
        FORMULATED_3,
        COMBINATION,
        TIME_COMPLETED_FINAL,
        STARTED,
        COMPLETED,
        PROCESS_TIME,
        `PROCESS_TIM(MINUTES)`
     FROM welding
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
$filename = 'conversion_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// UTF-8 BOM so Excel opens with correct encoding automatically
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// ── Column headers (all 76 columns) ──────────────────────────────────────────
fputcsv($out, [
    'Final Batch Code',
    'ID',
    'Start Time',
    'Completion Time',
    'Email',
    'Name',
    'Encoding Date',
    'Encoded By',
    'Input Customer',
    'Fabric Width and Tape Denier',
    'Bag Type',
    'Job Order No.',
    'Roll Series No. Year and Plant',
    'Roll Series No. Last 5 Digits',
    'Roll Series No. Splitting Order',
    'Machine Number',
    'Date Started',
    'Time Started',
    'Date Completed',
    'Time Completed',
    'Beginning Count',
    'End Count',
    'Output',
    'Production Remarks',
    'Shift Personnel History',
    'Technician',
    'Lead Inspector',
    'Top Patch Batch Code',
    'Top Patch Length',
    'Top Patch Weight',
    'Bottom Patch Batch Code',
    'Bottom Patch Length',
    'Bottom Patch Weight',
    'Valve Batch Code',
    'Valve Length',
    'Valve Weight',
    'In Weaving',
    'In Lamination',
    'In Printing',
    'In Conversion',
    'Middle Weaving',
    'Middle Lamination',
    'Middle Printing',
    'Middle Conversion',
    'Out Weaving',
    'Out Lamination',
    'Out Printing',
    'Out Conversion',
    'Additional Remarks',
    'Adjustment Sequence',
    'Valve Patch Temperature',
    'Valve Patch Pressure',
    'Valve Patch Offset Begin',
    'Valve Patch Offset End',
    'Cover Patch Temperature',
    'Cover Patch Pressure',
    'Cover Patch Offset Begin',
    'Cover Patch Offset End',
    'Bottom Patch Temperature',
    'Bottom Patch Pressure',
    'Bottom Patch Offset Begin',
    'Bottom Patch Offset End',
    'Welding Remarks',
    'Date Started (1)',
    'Date Completed (1)',
    'Formulated (Hr)',
    'Formulated 1 (Min)',
    'Combination 1 (Time)',
    'Time Started Final',
    'Formulated 2 (Hr)',
    'Formulated 3 (Min)',
    'Combination (Time)',
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
        $row['ENCODED_BY'],
        $row['INPUT_CUSTOMER'],
        $row['FABRIC_WIDTH_TAPE_DENIER'],
        $row['BAG_TYPE'],
        $row['JOB_ORDER_NO'],
        $row['Roll_Series_No_YEAR_and_PLANT'],
        $row['Roll_Series_No_LAST_5_DIGITS'],
        $row['Roll_Series_No_Splitting_Order'],
        $row['MACINE_NUMBER'],
        $row['DATE_STARTED'],
        $row['TIME_STARTED'],
        $row['DATE_COMPLETED'],
        $row['TIME_COMPLETED'],
        $row['BEGINNING_COUNT'],
        $row['END_COUNT'],
        $row['OUTPUT'],
        $row['PRODUCTION_REMARKS'],
        $row['SHIFT_PERSONNEL_HISTORY'],
        $row['TECNICIAN'],
        $row['LEAD_INSPECTOR'],
        $row['TOP_PATCH_BATCH_CODE'],
        $row['TOP_PATCH_LENGTH'],
        $row['TOP_PATCH_WEIGTH'],
        $row['BOTTOM_PATCH_BATCH_CODE'],
        $row['BOTTOM_PATCH_LENGTH'],
        $row['BOTTOM_PATCH_WEIGTH'],
        $row['VALVE_BATCH_CODE'],
        $row['VALVE_LENGTH'],
        $row['VALVE_WEIGTH'],
        $row['IN_WEAVING'],
        $row['IN_LAMINATION'],
        $row['IN_PRINTING'],
        $row['IN_CONVERSION'],
        $row['MIDDLE_WEAVING'],
        $row['MIDDLE_LAMINATION'],
        $row['MIDDLE_PRINTING'],
        $row['MIDDLE_CONVERSION'],
        $row['OUT_WEAVING'],
        $row['OUT_LAMINATION'],
        $row['OUT_PRINTING'],
        $row['OUT_CONVERSION'],
        $row['ADDITIONAL_REMARKS'],
        $row['ADJUSTMENT_SEQUENCE'],
        $row['VALVE_PATCH_TEMPERATURE'],
        $row['VALVE_PATCH_PRESSURE'],
        $row['VALVE_PATCH_OFFSET_BEGIN'],
        $row['VALVE_PATCH_OFFSET_END'],
        $row['COVER_PATCH_TEMPERATURE'],
        $row['COVER_PATCH_PRESSURE'],
        $row['COVER_PATCH_OFFSET_BEGIN'],
        $row['COVER_PATCH_OFFSET_END'],
        $row['BOTTOM_PATCH_TEMPERATURE'],
        $row['BOTTOM_PATCH_PRESSURE'],
        $row['BOTTOM_PATCH_OFFSET_BEGIN'],
        $row['BOTTOM_PATCH_OFFSET_END'],
        $row['WELDING_REMARKS'],
        $row['DATE_STARTED1'],
        $row['DATE_COMPLETED1'],
        $row['FORMULATED'],
        $row['FORMULATED_1'],
        $row['COMBINATION_1'],
        $row['TIME_STARTED_FINAL'],
        $row['FORMULATED_2'],
        $row['FORMULATED_3'],
        $row['COMBINATION'],
        $row['TIME_COMPLETED_FINAL'],
        $row['STARTED'],
        $row['COMPLETED'],
        $row['PROCESS_TIME'],
        $row['PROCESS_TIM(MINUTES)'],
    ]);
}

fclose($out);
exit;