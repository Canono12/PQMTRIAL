<?php
/**
 * Weaving — Export ALL rows to CSV (opens cleanly in Excel)
 * No libraries needed. No corrupt file errors.
 */
require_once __DIR__ . '/../includes/db.php';

// ── Filters (same as main page) ───────────────────────────────────────────────
$f_date    = $_GET['date']    ?? '';
$f_machine = $_GET['machine'] ?? '';
$f_shift   = $_GET['shift']   ?? '';
$f_line    = $_GET['line']    ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_date)    { $where[] = 'Date_Harvested = ?';  $params[] = $f_date;    $types .= 's'; }
if ($f_machine) { $where[] = '`Machine_NO.` = ?';   $params[] = $f_machine; $types .= 's'; }
if ($f_shift)   { $where[] = 'Shift = ?';            $params[] = $f_shift;   $types .= 's'; }
if ($f_line)    { $where[] = 'Line = ?';             $params[] = $f_line;    $types .= 's'; }
$wsql = implode(' AND ', $where);

// ── Fetch ALL rows, ALL columns (no LIMIT) ────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT
        ID,
        Series_Number,
        Date_Harvested,
        Time_Harvested,
        Line,
        `Machine_NO.`,
        Shift,
        Loom_Batch_Code,
        `Length(M)`,
        `Weight(Kg)`,
        `Width(mm)`,
        Denier,
        NO_of_roll,
        Classification_of_roll,
        Fabric_GSM,
        Remarks,
        `YARN_BATCHCODE_(WARP1)`,
        `YARN_BATCHCODE(WARP2)`,
        `YARN_BATCHCODE(WARP3)`,
        `YARN_BATCHCODE(WARP4)`,
        `YARN_BATCHCODE(WEFT1)`,
        `YARN_BATCHCODE(WEFT2)`,
        `YARN_BATCHCODE(WEFT3)`,
        `YARN_BATCHCODE(WEFT4)`,
        `YARN_BATCHCODE(WEFT5)`,
        `YARN_BATCHCODE(WEFT6)`,
        `YARN_BATCHCODE(WEFT8)`
     FROM weaving
     WHERE $wsql
     ORDER BY ID ASC"
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
$filename = 'weaving_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// UTF-8 BOM so Excel opens with correct encoding automatically
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// ── Column headers ────────────────────────────────────────────────────────────
fputcsv($out, [
    'ID', 'Series Number', 'Date Harvested', 'Time Harvested',
    'Line', 'Machine No.', 'Shift', 'Loom Batch Code',
    'Length (M)', 'Weight (Kg)', 'Width (mm)', 'Denier',
    'No. of Roll', 'Classification of Roll', 'Fabric GSM', 'Remarks',
    'Yarn Batchcode (Warp1)', 'Yarn Batchcode (Warp2)',
    'Yarn Batchcode (Warp3)', 'Yarn Batchcode (Warp4)',
    'Yarn Batchcode (Weft1)', 'Yarn Batchcode (Weft2)',
    'Yarn Batchcode (Weft3)', 'Yarn Batchcode (Weft4)',
    'Yarn Batchcode (Weft5)', 'Yarn Batchcode (Weft6)',
    'Yarn Batchcode (Weft8)',
]);

// ── Stream all rows directly (memory efficient for large datasets) ─────────────
while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['ID'],
        $row['Series_Number'],
        $row['Date_Harvested'],
        $row['Time_Harvested'],
        $row['Line'],
        $row['Machine_NO.'],
        $row['Shift'],
        $row['Loom_Batch_Code'],
        $row['Length(M)'],
        $row['Weight(Kg)'],
        $row['Width(mm)'],
        $row['Denier'],
        $row['NO_of_roll'],
        $row['Classification_of_roll'],
        $row['Fabric_GSM'],
        $row['Remarks'],
        $row['YARN_BATCHCODE_(WARP1)'],
        $row['YARN_BATCHCODE(WARP2)'],
        $row['YARN_BATCHCODE(WARP3)'],
        $row['YARN_BATCHCODE(WARP4)'],
        $row['YARN_BATCHCODE(WEFT1)'],
        $row['YARN_BATCHCODE(WEFT2)'],
        $row['YARN_BATCHCODE(WEFT3)'],
        $row['YARN_BATCHCODE(WEFT4)'],
        $row['YARN_BATCHCODE(WEFT5)'],
        $row['YARN_BATCHCODE(WEFT6)'],
        $row['YARN_BATCHCODE(WEFT8)'],
    ]);
}

fclose($out);
exit;