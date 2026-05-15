<?php
$page_title = 'CTSW';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// ── Filters ──────────────────────────────────────────────────────────────────
$f_month    = $_GET['month']    ?? '';   // M/YYYY
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
if ($f_machine)  { $where[] = 'MACHIN_NUMBER = ?';              $params[] = $f_machine;  $types .= 's'; }
if ($f_shift)    { $where[] = 'SHIFT_PRODUCTION_PERSONNEL LIKE ?'; $params[] = '%'.$f_shift.'%'; $types .= 's'; }
if ($f_bag_type) { $where[] = 'BAG_TYPE LIKE ?';                $params[] = '%'.$f_bag_type.'%'; $types .= 's'; }
if ($f_fabric)   { $where[] = 'FABRIC_WIDTH_TAPE_DENIER = ?';  $params[] = $f_fabric;   $types .= 's'; }
if ($f_jo)       { $where[] = 'JOB_ORDER_NUMBER = ?';           $params[] = $f_jo;       $types .= 's'; }
if ($f_customer) { $where[] = 'CUSTOMER = ?';                   $params[] = $f_customer; $types .= 's'; }
$wsql = implode(' AND ', $where);

function qry($conn, $sql, $t = '', $p = []) {
    $s = $conn->prepare($sql);
    if ($s === false) {
        die('<pre style="color:red">SQL prepare error: ' . htmlspecialchars($conn->error) . "\n\nSQL:\n" . htmlspecialchars($sql) . '</pre>');
    }
    if ($t && $p) $s->bind_param($t, ...$p);
    $s->execute();
    return $s->get_result();
}

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi = qry($conn,
    "SELECT
        COUNT(*)                                   AS recs,
        SUM(CAST(`GOOD_BAGS_IN_COUNT` AS UNSIGNED)) AS tot_good_count,
        SUM(CAST(`GOOD_BAGS_IN_WEIGHT` AS DECIMAL(10,2))) AS tot_good_weight,
        SUM(CAST(`DEFECTIVE_BAGS_(COUNT)` AS UNSIGNED))   AS tot_def_count,
        SUM(CAST(`DEFECTIVE_BAGS(WEIGHT)` AS DECIMAL(10,2))) AS tot_def_weight,
        SUM(CAST(`WASTE_FABRIC/BAG(WEIGHT)` AS DECIMAL(10,4))) AS tot_waste,
        COUNT(DISTINCT MACHIN_NUMBER)              AS machines,
        COUNT(DISTINCT CUSTOMER)                   AS customers
     FROM ctswtrial WHERE $wsql",
    $types, $params
)->fetch_assoc();

// ── Chart helpers ─────────────────────────────────────────────────────────────
function chart_data_two($conn, $sql, $t, $p, $key_col, $v1_col, $v2_col) {
    $r = qry($conn, $sql, $t, $p);
    $out = ['labels' => [], 'v1' => [], 'v2' => []];
    while ($row = $r->fetch_assoc()) {
        $out['labels'][] = $row[$key_col];
        $out['v1'][]     = (float)$row[$v1_col];
        $out['v2'][]     = (float)$row[$v2_col];
    }
    return $out;
}

// Output + Waste per Machine
$c_machine = chart_data_two($conn,
    "SELECT MACHIN_NUMBER AS k,
            SUM(CAST(`GOOD_BAGS_IN_COUNT` AS UNSIGNED)) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS_(COUNT)` AS UNSIGNED)) AS v2
     FROM ctswtrial WHERE $wsql
     GROUP BY MACHIN_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per Shift / Production Personnel
$c_shift = chart_data_two($conn,
    "SELECT SHIFT_PRODUCTION_PERSONNEL AS k,
            SUM(CAST(`GOOD_BAGS_IN_COUNT` AS UNSIGNED)) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS_(COUNT)` AS UNSIGNED)) AS v2
     FROM ctswtrial WHERE $wsql
     GROUP BY SHIFT_PRODUCTION_PERSONNEL ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per Bag Type (trim to first 40 chars for readability)
$c_bagtype = chart_data_two($conn,
    "SELECT LEFT(BAG_TYPE, 45) AS k,
            SUM(CAST(`GOOD_BAGS_IN_COUNT` AS UNSIGNED)) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS_(COUNT)` AS UNSIGNED)) AS v2
     FROM ctswtrial WHERE $wsql
     GROUP BY BAG_TYPE ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per Fabric Width-Denier
$c_fabric = chart_data_two($conn,
    "SELECT FABRIC_WIDTH_TAPE_DENIER AS k,
            SUM(CAST(`GOOD_BAGS_IN_COUNT` AS UNSIGNED)) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS_(COUNT)` AS UNSIGNED)) AS v2
     FROM ctswtrial WHERE $wsql
     GROUP BY FABRIC_WIDTH_TAPE_DENIER ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per JO
$c_jo = chart_data_two($conn,
    "SELECT JOB_ORDER_NUMBER AS k,
            SUM(CAST(`GOOD_BAGS_IN_COUNT` AS UNSIGNED)) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS_(COUNT)` AS UNSIGNED)) AS v2
     FROM ctswtrial WHERE $wsql AND JOB_ORDER_NUMBER IS NOT NULL AND JOB_ORDER_NUMBER != 'N/A'
     GROUP BY JOB_ORDER_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per Customer
$c_customer = chart_data_two($conn,
    "SELECT CUSTOMER AS k,
            SUM(CAST(`GOOD_BAGS_IN_COUNT` AS UNSIGNED)) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS_(COUNT)` AS UNSIGNED)) AS v2
     FROM ctswtrial WHERE $wsql AND CUSTOMER IS NOT NULL
     GROUP BY CUSTOMER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

// ── Filter option lists ───────────────────────────────────────────────────────
$opt_months_raw = $conn->query("SELECT DISTINCT ENCODING_DATE FROM ctswtrial WHERE ENCODING_DATE IS NOT NULL AND ENCODING_DATE != '' ORDER BY ENCODING_DATE");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    $parts = explode('/', $om['ENCODING_DATE']);
    if (count($parts) === 3) {
        $key = $parts[0] . '/' . $parts[2];
        $label = date('F Y', mktime(0,0,0,(int)$parts[0],1,(int)$parts[2]));
        $opt_months[$key] = $label;
    }
}
uksort($opt_months, function($a,$b){ list($am,$ay)=explode('/',$a); list($bm,$by)=explode('/',$b); return $ay!=$by?$ay-$by:$am-$bm; });

$opt_machines  = $conn->query("SELECT DISTINCT MACHIN_NUMBER v FROM ctswtrial WHERE MACHIN_NUMBER IS NOT NULL ORDER BY MACHIN_NUMBER");
$opt_fabrics   = $conn->query("SELECT DISTINCT FABRIC_WIDTH_TAPE_DENIER v FROM ctswtrial WHERE FABRIC_WIDTH_TAPE_DENIER IS NOT NULL ORDER BY FABRIC_WIDTH_TAPE_DENIER");
$opt_jo        = $conn->query("SELECT DISTINCT JOB_ORDER_NUMBER v FROM ctswtrial WHERE JOB_ORDER_NUMBER IS NOT NULL AND JOB_ORDER_NUMBER != 'N/A' ORDER BY JOB_ORDER_NUMBER");
$opt_customers = $conn->query("SELECT DISTINCT CUSTOMER v FROM ctswtrial WHERE CUSTOMER IS NOT NULL ORDER BY CUSTOMER");

// ── Production table rows ────────────────────────────────────────────────────
$rows = qry($conn,
    "SELECT
        FINAL_BATCH_CODE, BAG_CODE, MACHIN_NUMBER, ENCODING_DATE,
        ENCODING_PERSONNEL, SHIFT_PRODUCTION_PERSONNEL,
        FABRIC_WIDTH_TAPE_DENIER, BAG_TYPE, JOB_ORDER_NUMBER, CUSTOMER,
        `GOOD_BAGS_IN_COUNT`, `GOOD_BAGS_IN_WEIGHT`,
        `DEFECTIVE_BAGS_(COUNT)`, `DEFECTIVE_BAGS(WEIGHT)`,
        `WASTE_FABRIC/BAG(WEIGHT)`, PRODUCTION_REMARKS,
        DATE_STARTED, DATE_FINISHED, PROCESS_TIME
     FROM ctswtrial WHERE $wsql ORDER BY Id DESC LIMIT 50",
    $types, $params);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-content">

    <!-- Page Heading -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <div class="page-heading"><i class="bi bi-scissors me-2" style="color:#3b82f6"></i>CTSW Module</div>
            <div class="page-subheading mt-1">Output &amp; Waste analytics — Cutting, Sewing &amp; Finishing</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge process-badge" style="background:#1e3a5f;border:1px solid #3b82f6;color:#93c5fd">
                <i class="bi bi-database me-1"></i>ctswtrial
            </span>
            <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_ctsw">
                <i class="bi bi-file-earmark-excel"></i> Add Data (Excel)
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="pqm-card mb-4">
        <div class="section-title mb-3"><i class="bi bi-funnel"></i> Filters</div>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">MONTH</label>
                <select name="month" class="form-select form-select-sm pqm-input">
                    <option value="">All Months</option>
                    <?php foreach ($opt_months as $mv => $ml): ?>
                    <option value="<?= htmlspecialchars($mv) ?>" <?= $f_month===$mv?'selected':'' ?>>
                        <?= htmlspecialchars($ml) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">MACHINE</label>
                <select name="machine" class="form-select form-select-sm pqm-input">
                    <option value="">All Machines</option>
                    <?php while ($o = $opt_machines->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_machine===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">FABRIC WIDTH-DENIER</label>
                <select name="fabric" class="form-select form-select-sm pqm-input">
                    <option value="">All Fabrics</option>
                    <?php while ($o = $opt_fabrics->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_fabric===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">JOB ORDER</label>
                <select name="jo" class="form-select form-select-sm pqm-input">
                    <option value="">All JO</option>
                    <?php while ($o = $opt_jo->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_jo===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">CUSTOMER</label>
                <select name="customer" class="form-select form-select-sm pqm-input">
                    <option value="">All Customers</option>
                    <?php while ($o = $opt_customers->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_customer===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-12 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="ctsw.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <?php
        $kpis = [
            ['val' => number_format((int)$kpi['tot_good_count']),              'label' => 'Good Bags (count)',     'icon' => 'bi-bag-check-fill',  'cls' => 'icon-blue'],
            ['val' => number_format((float)$kpi['tot_good_weight'], 1).' kg',  'label' => 'Good Bags (weight)',    'icon' => 'bi-speedometer2',    'cls' => 'icon-green'],
            ['val' => number_format((int)$kpi['tot_def_count']),               'label' => 'Defective Bags (count)','icon' => 'bi-bag-x-fill',      'cls' => 'icon-amber'],
            ['val' => number_format((float)$kpi['tot_def_weight'], 2).' kg',   'label' => 'Defective Bags (wt)',   'icon' => 'bi-exclamation-triangle','cls' => 'icon-red'],
            ['val' => number_format((float)$kpi['tot_waste'], 2).' kg',        'label' => 'Total Waste (kg)',      'icon' => 'bi-slash-circle',    'cls' => 'icon-purple'],
            ['val' => (int)$kpi['machines'],                                   'label' => 'Active Machines',       'icon' => 'bi-cpu',             'cls' => 'icon-teal'],
            ['val' => (int)$kpi['customers'],                                  'label' => 'Customers',             'icon' => 'bi-people-fill',     'cls' => 'icon-blue'],
            ['val' => number_format((int)$kpi['recs']),                        'label' => 'Total Records',         'icon' => 'bi-collection',      'cls' => 'icon-green'],
        ];
        foreach ($kpis as $k): ?>
        <div class="col-6 col-xl-3">
            <div class="pqm-card stat-card">
                <div class="stat-icon <?= $k['cls'] ?>"><i class="<?= $k['icon'] ?>"></i></div>
                <div>
                    <div class="stat-value"><?= $k['val'] ?></div>
                    <div class="stat-label"><?= $k['label'] ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts Row 1 — Output/Waste per Machine | per Customer -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-7">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Output &amp; Waste per Machine Number</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cMachine"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Output per Customer</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cCustomer"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 — Output/Waste per Shift | per Fabric Width-Denier -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-person-badge"></i> Output &amp; Waste per Shift / Production Personnel</div>
                <div class="chart-wrapper" style="height:265px">
                    <canvas id="cShift"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-grid-3x2-gap"></i> Output &amp; Waste per Fabric Width-Denier</div>
                <div class="chart-wrapper" style="height:265px">
                    <canvas id="cFabric"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 3 — Output/Waste per Bag Type | per JO -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bag-fill"></i> Output &amp; Waste per Bag Type</div>
                <div class="chart-wrapper" style="height:280px">
                    <canvas id="cBagType"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-graph-up"></i> Output &amp; Waste per JO / Job Order Number</div>
                <div class="chart-wrapper" style="height:280px">
                    <canvas id="cJO"></canvas>
                </div>
            </div>
        </div>
    </div>
 <!-- Production Table -->
    <div class="pqm-card">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="section-title mb-0">
                <i class="bi bi-table"></i> Production Records
                <span class="text-secondary fw-normal ms-1" style="font-size:.78rem">(latest 50)</span>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <input type="text" id="tblSearch" class="form-control form-control-sm pqm-input"
                       placeholder="&#xF52A; Search..." style="max-width:200px">
                <a href="ctsw_export.php?<?= http_build_query($_GET) ?>"
                   class="btn btn-sm btn-success px-3 d-flex align-items-center gap-1" style="white-space:nowrap">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="pqm-table" id="ctswTable">
                <thead>
                    <tr>
                        <th>SERIES CODE</th>
                        <th>ENCODING STARTED</th>
                        <th>ENCODING FINISHED</th>
                        <th>PERSONNEL ENCODER</th>
                        <th>FABRIC WIDTH-TAPE DENIER</th>
                        <th>ACTUAL FABRIC WIDTH</th>
                        <th>CUSTOMER</th>
                        <th>BAG TYPE</th>
                        <th>JO NUMBER</th>
                        <th>YEAR & PLANT</th>
                        <th>SERIES LAST 5 DIGITS</th>
                        <th>BAG CODE</th>
                        <th>Roll Series No. - Splitting Order</th>
                        <th>MACHINE NUMBER</th>
                        <th>DATE STARTED</th>
                        <th>TIME STARTED</th>
                        <th>DATE FINISHED</th>
                        <th>TIME FINISHED</th>
                        <th>ROLL LENGTH FROM PRINTING (meters).</th>
                        <th>ROLL WEIGHT (kilograms)</th>
                        <th>GOOD BAGS IN COUNTS</th>
                        <th>GOOD BAGS IN WEIGHT</th>
                        <th>DEFECTIVE BAGS (COUNT)</th>
                        <th>DEFECTIVE BAGS (WEIGHT)</th>
                        <th>WASTE FABRIC/BAG (WEIGHT)</th>
                        <th>PRODUCTION REMARKS  </th>
                        <th>SHIFT PRODUCTION PERSONNEL</th>
                        <th>IPQC TECHNICIAN</th>
                        <th>PROCESS TIME</th>



                    </tr>
                </thead>
                <tbody>
<?php while ($r = $rows->fetch_assoc()): ?>
<tr>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['FINAL_BATCH_CODE'] ?? '') ?></code></td>

    <td><?= !empty($r['Completion_time']) ? date("M d, Y h:i A", strtotime($r['start_time'])) : '—' ?></td>

    <td><?= !empty($r['Completion_time']) ? date("M d, Y h:i A", strtotime($r['Completion_time'])) : '—' ?></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['ENCODING_PERSONNEL'] ?? '') ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['FABRIC_WIDTH_TAPE_DENIER'] ?? '') ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= number_format((float)($r['ACTUAL_FABRIC_WIDTH'] ?? 0)) ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['CUSTOMER'] ?? '') ?></code></td>

    <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
        title="<?= htmlspecialchars($r['BAG_TYPE'] ?? '') ?>">
        <span style="font-size:.75rem;color:#cbd5e1">
            <?= htmlspecialchars(mb_substr($r['BAG_TYPE'] ?? '',0,40)) ?>
            <?= mb_strlen($r['BAG_TYPE'] ?? '') > 40 ? '…' : '' ?>
        </span>
    </td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['JOB_ORDER_NUMBER'] ?? '') ?></code></td>

   <td>
    <code style="color:#93c5fd;font-size:.72rem">
        <?= htmlspecialchars($r['ROLL_SERIES_NO_YEAR_AND_PLANT'] ?? '') ?>
    </code>
</td>

<td>
    <code style="color:#93c5fd;font-size:.72rem">
        <?= htmlspecialchars($r['ROLL_SERIES_NO_LAST_5_DIGITS'] ?? '') ?>
    </code>
</td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['BAG_CODE'] ?? '') ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['Roll_Series_No_Splitting_Order'] ?? '') ?></code></td>

    <td><span class="badge bg-primary"><?= htmlspecialchars($r['MACHIN_NUMBER'] ?? '') ?></span></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['DATE_STARTED'] ?? '') ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['TIME_STARTED'] ?? '') ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['DATE_FINISHED'] ?? '') ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['TIME_FINISHED'] ?? '') ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= number_format((float)($r['ROLL_LENGTH_FROM_PRINTING_meters'] ?? 0), 2) ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= number_format((float)($r['ROLL_WEIGHT_kilograms'] ?? 0), 2) ?></code></td>

    <td><span style="color:#38bdf8"><?= number_format((int)($r['GOOD_BAGS_IN_COUNT'] ?? 0)) ?></span></td>

    <td><span style="color:#34d399"><?= number_format((float)($r['GOOD_BAGS_IN_WEIGHT'] ?? 0), 2) ?></span></td>

    <td><span style="color:#fb923c"><?= number_format((int)($r['DEFECTIVE_BAGS_(COUNT)'] ?? 0)) ?></span></td>

    <td><span style="color:#f87171"><?= number_format((float)($r['DEFECTIVE_BAGS(WEIGHT)'] ?? 0), 2) ?></span></td>

    <td><span style="color:#f472b6"><?= number_format((float)($r['WASTE_FABRIC/BAG(WEIGHT)'] ?? 0), 2) ?></span></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['PRODUCTION_REMARKS'] ?? 'N/A') ?></code></td>

    <td><code style="color:#93c5fd;font-size:.72rem"><?= htmlspecialchars($r['SHIFT_PRODUCTION_PERSONNEL'] ?? '') ?></code></td>

    <td>
<?= !empty($r['IPQC_TECHNICIAN']) 
    ? htmlspecialchars($r['IPQC_TECHNICIAN']) 
    : '<span style="color:#64748b">—</span>' ?>
</td>

    <td style="font-size:.75rem;color:#94a3b8">
    <?= htmlspecialchars($r['PROCESS_TIME(MINUTES)'] ?? '—') ?>
</td>

</tr>
<?php endwhile; ?>
</tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->
<?php
$upload_module='ctsw';$upload_label='CTSW';
$upload_sample='FINAL_BATCH_CODE | MACHIN_NUMBER | DATE_STARTED | GOOD_BAGS_IN_COUNT | DEFECTIVE_BAGS_(COUNT) | CUSTOMER | BAG_TYPE | ...';
require_once __DIR__.'/../includes/upload_modal.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
const CM  = <?= json_encode($c_machine)  ?>;
const CSH = <?= json_encode($c_shift)    ?>;
const CBT = <?= json_encode($c_bagtype)  ?>;
const CF  = <?= json_encode($c_fabric)   ?>;
const CJO = <?= json_encode($c_jo)       ?>;
const CCU = <?= json_encode($c_customer) ?>;

const goodColor = PQM_COLORS.blue  || '#3b82f6';
const defColor  = PQM_COLORS.amber || '#f59e0b';

// Helper: grouped bar (output + waste)
function makeGroupedBar(canvasId, data, label1, label2, indexAxis = 'x') {
    new Chart(document.getElementById(canvasId), {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [
                barDataset(label1, data.v1, goodColor),
                barDataset(label2, data.v2, defColor),
            ]
        },
        options: {
            indexAxis,
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 800 },
            plugins: { legend: { position: 'top' } },
            scales: {
                x: { grid: { color: PQM_COLORS.grid }, beginAtZero: true },
                y: { grid: { color: PQM_COLORS.grid } }
            }
        }
    });
}

// 1. Machine — grouped bar
makeGroupedBar('cMachine', CM, 'Good Bags (count)', 'Defective (count)');

// 2. Shift/Personnel — horizontal grouped bar
makeGroupedBar('cShift', CSH, 'Good Bags (count)', 'Defective (count)', 'y');

// 3. Bag Type — horizontal grouped bar
makeGroupedBar('cBagType', CBT, 'Good Bags (count)', 'Defective (count)', 'y');

// 4. Fabric Width-Denier — horizontal bar
makeGroupedBar('cFabric', CF, 'Good Bags (count)', 'Defective (count)', 'y');

// 5. JO — grouped bar
makeGroupedBar('cJO', CJO, 'Good Bags (count)', 'Defective (count)');

// 6. Customer — doughnut (output count only)
new Chart(document.getElementById('cCustomer'), {
    type: 'doughnut',
    data: {
        labels: CCU.labels,
        datasets: [{
            data: CCU.v1,
            backgroundColor: ['#3b82f6','#0ea5e9','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#f97316','#84cc16','#ec4899','#14b8a6','#8b5cf6','#f43f5e','#d97706','#0284c7'],
            borderWidth: 2,
            borderColor: '#162032',
            hoverOffset: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { animateRotate: true, duration: 900 },
        plugins: {
            legend: { position: 'right', labels: { boxWidth: 12, padding: 8 } },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' bags' } }
        },
        cutout: '60%'
    }
});

// Live table search
document.getElementById('tblSearch').addEventListener('input', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('#ctswTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<style>
.icon-teal   { background: rgba(20,184,166,.18); color: #2dd4bf; }
.icon-amber  { background: rgba(245,158,11,.18);  color: #fbbf24; }
.icon-purple { background: rgba(168,85,247,.18);  color: #c084fc; }
.pqm-input {
    background: #1a3358 !important;
    border: 1px solid rgba(255,255,255,.1) !important;
    color: #f1f5f9 !important;
    border-radius: 8px !important;
}
.pqm-input::placeholder { color: #64748b !important; }
.pqm-input:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59,130,246,.2) !important;
    outline: none;
}
.pqm-input option { background: #1a3358; }
</style>