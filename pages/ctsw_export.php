<?php
/**
 * CTSW — Export ALL rows to CSV (opens cleanly in Excel)
 * Includes every column from the ctswtrial table.
 */
require_once __DIR__ . '/../includes/db.php';

// ── Filters (same as main page) ───────────────────────────────────────────────
$f_month    = $_GET['month']    ?? '';
$f_machine  = $_GET['machine']  ?? '';
$f_shift    = $_GET['shift']    ?? '';
$f_bag_type = $_GET['bag_type'] ?? '';
$f_fabric   = $_GET['fabric']   ?? '';
$f_jo       = $_GET['jo']       ?? '';
$f_customer = $_GET['customer'] ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_month) {
    list($fm, $fy) = explode('/', $f_month, 2);
    $where[] = "MONTH(STR_TO_DATE(ENCODING_DATE,'%m/%d/%Y'))=? AND YEAR(STR_TO_DATE(ENCODING_DATE,'%m/%d/%Y'))=?";
    $params[] = (int)$fm;
    $params[] = (int)$fy;
    $types .= 'ss';
}
if ($f_machine)  { $where[] = 'MACHIN_NUMBER = ?';              $params[] = $f_machine;          $types .= 's'; }
if ($f_shift)    { $where[] = 'SHIFT_PRODUCTION_PERSONNEL LIKE ?'; $params[] = '%'.$f_shift.'%'; $types .= 's'; }
if ($f_bag_type) { $where[] = 'BAG_TYPE LIKE ?';                $params[] = '%'.$f_bag_type.'%'; $types .= 's'; }
if ($f_fabric)   { $where[] = 'FABRIC_WIDTH_TAPE_DENIER = ?';  $params[] = $f_fabric;           $types .= 's'; }
if ($f_jo)       { $where[] = 'JOB_ORDER_NUMBER = ?';           $params[] = $f_jo;               $types .= 's'; }
if ($f_customer) { $where[] = 'CUSTOMER = ?';                   $params[] = $f_customer;         $types .= 's'; }
$wsql = implode(' AND ', $where);

// ── Fetch ALL rows, ALL columns (no LIMIT) ────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT
        FINAL_BATCH_CODE,
        Id,
        Start_time,
        Completion_time,
        Email,
        Name,
        ENCODING_DATE,
        ENCODING_PERSONNEL,
        FABRIC_WIDTH_TAPE_DENIER,
        ACTUAL_FABRIC_WIDTH,
        CUSTOMER,
        BAG_TYPE,
        JOB_ORDER_NUMBER,
        ROLL_SERIES_NO_YEAR_and_PLANT,
        ROLL_SERIES_NO_LAST_5_DIGITS,
        BAG_CODE,
        Roll_Series_No_Splitting_Order,
        MACHIN_NUMBER,
        DATE_STARTED,
        TIME_STARTED,
        DATE_FINISHED,
        TIME_FINISHED,
        `ROLL_LENGTH_FROM_PRINTING(meters)`,
        `ROLL_WEIGHT(kilograms)`,
        GOOD_BAGS_IN_COUNT,
        GOOD_BAGS_IN_WEIGHT,
        `DEFECTIVE_BAGS_(COUNT)`,
        `DEFECTIVE_BAGS(WEIGHT)`,
        `WASTE_FABRIC/BAG(WEIGHT)`,
        PRODUCTION_REMARKS,
        SHIFT_PRODUCTION_PERSONNEL,
        IPQC_TECHNICIAN,
        DATE_STARTED1,
        DATE_COMPLETED1,
        FORMULATED1,
        FORMULATED2,
        COMBINATION1,
        TIME_STARTED_FINAL,
        FORMULATED3,
        FORMULATED4,
        COMBINATION2,
        TIME_COMPLETED_FINAL,
        STARTED,
        COMPLETED,
        PROCESS_TIME,
        `PROCESS_TIME(MINUTES)`
     FROM ctswtrial
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
$filename = 'ctsw_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// UTF-8 BOM so Excel opens with correct encoding automatically
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// ── Column headers ────────────────────────────────────────────────────────────
fputcsv($out, [
    'Final Batch Code',
    'Id',
    'Start Time',
    'Completion Time',
    'Email',
    'Name',
    'Encoding Date',
    'Encoding Personnel',
    'Fabric Width Tape Denier',
    'Actual Fabric Width',
    'Customer',
    'Bag Type',
    'Job Order Number',
    'Roll Series No Year and Plant',
    'Roll Series No Last 5 Digits',
    'Bag Code',
    'Roll Series No Splitting Order',
    'Machine Number',
    'Date Started',
    'Time Started',
    'Date Finished',
    'Time Finished',
    'Roll Length From Printing (meters)',
    'Roll Weight (kilograms)',
    'Good Bags In Count',
    'Good Bags In Weight',
    'Defective Bags (Count)',
    'Defective Bags (Weight)',
    'Waste Fabric/Bag (Weight)',
    'Production Remarks',
    'Shift Production Personnel',
    'IPQC Technician',
    'Date Started 1',
    'Date Completed 1',
    'Formulated 1',
    'Formulated 2',
    'Combination 1',
    'Time Started Final',
    'Formulated 3',
    'Formulated 4',
    'Combination 2',
    'Time Completed Final',
    'Started',
    'Completed',
    'Process Time',
    'Process Time (Minutes)',
]);

// ── Stream all rows (memory efficient for large datasets) ─────────────────────
while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['FINAL_BATCH_CODE'],
        $row['Id'],
        $row['Start_time'],
        $row['Completion_time'],
        $row['Email'],
        $row['Name'],
        $row['ENCODING_DATE'],
        $row['ENCODING_PERSONNEL'],
        $row['FABRIC_WIDTH_TAPE_DENIER'],
        $row['ACTUAL_FABRIC_WIDTH'],
        $row['CUSTOMER'],
        $row['BAG_TYPE'],
        $row['JOB_ORDER_NUMBER'],
        $row['ROLL_SERIES_NO_YEAR_and_PLANT'],
        $row['ROLL_SERIES_NO_LAST_5_DIGITS'],
        $row['BAG_CODE'],
        $row['Roll_Series_No_Splitting_Order'],
        $row['MACHIN_NUMBER'],
        $row['DATE_STARTED'],
        $row['TIME_STARTED'],
        $row['DATE_FINISHED'],
        $row['TIME_FINISHED'],
        $row['ROLL_LENGTH_FROM_PRINTING(meters)'],
        $row['ROLL_WEIGHT(kilograms)'],
        $row['GOOD_BAGS_IN_COUNT'],
        $row['GOOD_BAGS_IN_WEIGHT'],
        $row['DEFECTIVE_BAGS_(COUNT)'],
        $row['DEFECTIVE_BAGS(WEIGHT)'],
        $row['WASTE_FABRIC/BAG(WEIGHT)'],
        $row['PRODUCTION_REMARKS'],
        $row['SHIFT_PRODUCTION_PERSONNEL'],
        $row['IPQC_TECHNICIAN'],
        $row['DATE_STARTED1'],
        $row['DATE_COMPLETED1'],
        $row['FORMULATED1'],
        $row['FORMULATED2'],
        $row['COMBINATION1'],
        $row['TIME_STARTED_FINAL'],
        $row['FORMULATED3'],
        $row['FORMULATED4'],
        $row['COMBINATION2'],
        $row['TIME_COMPLETED_FINAL'],
        $row['STARTED'],
        $row['COMPLETED'],
        $row['PROCESS_TIME'],
        $row['PROCESS_TIME(MINUTES)'],
    ]);
}

fclose($out);
exit;