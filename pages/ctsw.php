<?php
$page_title = 'CTSW';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// ── Filters ──────────────────────────────────────────────────────────────────
$f_monthyear = $_GET['monthyear'] ?? '';
$f_date_from = $_GET['date_from'] ?? '';
$f_date_to   = $_GET['date_to']   ?? '';
$f_machines = array_filter(array_map('trim', (array)($_GET['machine'] ?? [])));
$f_shift    = $_GET['shift']    ?? '';
$f_bag_type = $_GET['bag_type'] ?? '';
$f_fabric   = $_GET['fabric']   ?? '';
$f_jo       = $_GET['jo']       ?? '';
$f_customer = $_GET['customer'] ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_monthyear) {
    $where[] = "DATE_FORMAT(DATE_FINISHED, '%Y-%m') = ?";
    $params[] = $f_monthyear;
    $types .= 's';
}
if ($f_date_from) {
    $where[] = "DATE_FINISHED >= ?";
    $params[] = $f_date_from;
    $types .= 's';
}
if ($f_date_to) {
    $where[] = "DATE_FINISHED <= ?";
    $params[] = $f_date_to;
    $types .= 's';
}
if (!empty($f_machines)) {
    $placeholders = implode(',', array_fill(0, count($f_machines), '?'));
    $where[] = "MACHIN_NUMBER IN ($placeholders)";
    foreach ($f_machines as $m) { $params[] = $m; $types .= 's'; }
}
if ($f_shift)    { $where[] = 'SHIFT_PRODUCTION_PERSONNEL LIKE ?';  $params[] = '%'.$f_shift.'%'; $types .= 's'; }
if ($f_bag_type) { $where[] = 'BAG_TYPE LIKE ?';                    $params[] = '%'.$f_bag_type.'%'; $types .= 's'; }
if ($f_fabric)   { $where[] = 'FABRIC_WIDTH_TAPE_DENIER = ?';      $params[] = $f_fabric;   $types .= 's'; }
if ($f_jo)       { $where[] = 'JOB_ORDER_NUMBER = ?';               $params[] = $f_jo;       $types .= 's'; }
if ($f_customer) { $where[] = 'CUSTOMER = ?';                       $params[] = $f_customer; $types .= 's'; }
$wsql = implode(' AND ', $where);
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

// ── WEIGHT-based chart data ────────────────────────────────────────────────────
$cw_machine = chart_data_two($conn,
    "SELECT MACHIN_NUMBER AS k,
            SUM(CAST(`GOOD_BAGS_IN_WEIGHT` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS(WEIGHT)` AS DECIMAL(10,2))) AS v2
     FROM ctswtrial WHERE $wsql
     GROUP BY MACHIN_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

$cw_shift = chart_data_two($conn,
    "SELECT SHIFT_PRODUCTION_PERSONNEL AS k,
            SUM(CAST(`GOOD_BAGS_IN_WEIGHT` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS(WEIGHT)` AS DECIMAL(10,2))) AS v2
     FROM ctswtrial WHERE $wsql
     GROUP BY SHIFT_PRODUCTION_PERSONNEL ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

$cw_bagtype = chart_data_two($conn,
    "SELECT LEFT(BAG_TYPE, 45) AS k,
            SUM(CAST(`GOOD_BAGS_IN_WEIGHT` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS(WEIGHT)` AS DECIMAL(10,2))) AS v2
     FROM ctswtrial WHERE $wsql
     GROUP BY BAG_TYPE ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1', 'v2');

$cw_fabric = chart_data_two($conn,
    "SELECT FABRIC_WIDTH_TAPE_DENIER AS k,
            SUM(CAST(`GOOD_BAGS_IN_WEIGHT` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS(WEIGHT)` AS DECIMAL(10,2))) AS v2
     FROM ctswtrial WHERE $wsql
     GROUP BY FABRIC_WIDTH_TAPE_DENIER ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1', 'v2');

$cw_jo = chart_data_two($conn,
    "SELECT JOB_ORDER_NUMBER AS k,
            SUM(CAST(`GOOD_BAGS_IN_WEIGHT` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS(WEIGHT)` AS DECIMAL(10,2))) AS v2
     FROM ctswtrial WHERE $wsql AND JOB_ORDER_NUMBER IS NOT NULL AND JOB_ORDER_NUMBER != 'N/A'
     GROUP BY JOB_ORDER_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

$cw_customer = chart_data_two($conn,
    "SELECT CUSTOMER AS k,
            SUM(CAST(`GOOD_BAGS_IN_WEIGHT` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`DEFECTIVE_BAGS(WEIGHT)` AS DECIMAL(10,2))) AS v2
     FROM ctswtrial WHERE $wsql AND CUSTOMER IS NOT NULL
     GROUP BY CUSTOMER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

// ── Filter option lists ───────────────────────────────────────────────────────
$opt_months_raw = $conn->query("SELECT DISTINCT DATE_FORMAT(DATE_FINISHED,'%Y-%m') AS v, DATE_FORMAT(DATE_FINISHED,'%M %Y') AS label FROM ctswtrial WHERE DATE_FINISHED IS NOT NULL AND DATE_FINISHED != '' AND DATE_FINISHED != '0000-00-00' ORDER BY v DESC");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    if ($om['v']) $opt_months[$om['v']] = $om['label'];
}
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
            <!-- View Toggle -->
            <div class="ctsw-toggle-wrap">
                <button type="button" class="ctsw-toggle-btn active" id="ctswBtnCounts" onclick="ctswSetView('counts')">
                    <i class="bi bi-boxes me-1"></i>Counts
                </button>
                <button type="button" class="ctsw-toggle-btn" id="ctswBtnWeight" onclick="ctswSetView('weight')">
                    <i class="bi bi-box-seam me-1"></i>Weight
                </button>
            </div>
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
                <select name="monthyear" class="form-select form-select-sm pqm-input">
                    <option value="">All Months</option>
                    <?php foreach ($opt_months as $mv => $ml): ?>
                    <option value="<?= htmlspecialchars($mv) ?>" <?= $f_monthyear===$mv?'selected':'' ?>>
                        <?= htmlspecialchars($ml) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ── Date Range Filter ────────────────── -->
            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label text-secondary" style="font-size:.72rem">
                    <i class="bi bi-calendar-range me-1" style="color:#22c55e"></i>DATE FINISHED RANGE
                </label>
                <!-- hidden inputs carry the split values for PHP -->
                <input type="hidden" name="date_from" id="ctsw_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                <input type="hidden" name="date_to"   id="ctsw_date_to"   value="<?= htmlspecialchars($f_date_to) ?>">
                <input type="text" id="ctsw_date_range"
                       class="form-control form-control-sm pqm-input"
                       placeholder="Start date — End date"
                       autocomplete="off" readonly>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">MACHINE</label>
                <div class="ctsw-multi-wrap" id="ctswMachineWrap">
                    <button type="button" class="ctsw-multi-btn pqm-input" id="ctswMachineBtn">
                        <span id="ctswMachineLabel">
                            <?php if (!empty($f_machines)): ?>
                                <?= count($f_machines) === 1 ? htmlspecialchars($f_machines[0]) : count($f_machines) . ' machines selected' ?>
                            <?php else: ?>All Machines<?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-down" style="font-size:.7rem;margin-left:auto;opacity:.6"></i>
                    </button>
                    <div class="ctsw-multi-panel" id="ctswMachinePanel">
                        <label class="ctsw-multi-opt">
                            <input type="checkbox" id="ctswMachineAll" class="ctsw-cb">
                            <span style="color:#94a3b8;font-style:italic">All Machines</span>
                        </label>
                        <div style="border-top:1px solid rgba(255,255,255,.07);margin:3px 0"></div>
                        <?php
                        $machines_list = [];
                        while ($o = $opt_machines->fetch_assoc()) $machines_list[] = $o['v'];
                        foreach ($machines_list as $mv):
                            $checked = in_array((string)$mv, array_map('strval', $f_machines)) ? 'checked' : '';
                        ?>
                        <label class="ctsw-multi-opt">
                            <input type="checkbox" name="machine[]" value="<?= htmlspecialchars($mv) ?>"
                                   class="ctsw-cb ctsw-machine-cb" <?= $checked ?>>
                            <span><?= htmlspecialchars($mv) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
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
            <div class="col-auto d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="ctsw.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- KPI Cards — COUNTS view -->
    <div class="ctsw-view-counts">
    <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 row-cols-xl-5 ctsw-kpi-row">
        <?php
        $kpis_counts = [
            ['val' => number_format((int)$kpi['tot_good_count']),  'label' => 'Good Bags (count)',      'icon' => 'bi-bag-check-fill', 'cls' => 'icon-blue'],
            ['val' => number_format((int)$kpi['tot_def_count']),   'label' => 'Defective Bags (count)', 'icon' => 'bi-bag-x-fill',     'cls' => 'icon-amber'],
            ['val' => (int)$kpi['machines'],                       'label' => 'Active Machines',        'icon' => 'bi-cpu',            'cls' => 'icon-teal'],
            ['val' => (int)$kpi['customers'],                      'label' => 'Customers',              'icon' => 'bi-people-fill',    'cls' => 'icon-purple'],
            ['val' => number_format((int)$kpi['recs']),            'label' => 'Total Records',          'icon' => 'bi-collection',     'cls' => 'icon-green'],
        ];
        foreach ($kpis_counts as $k): ?>
        <div class="col">
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

    <!-- Charts Row 1 COUNTS -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-7">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Good &amp; Defective Bags (count) per Machine</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cMachine"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Good Bags (count) per Customer</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cCustomer"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 COUNTS -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-person-badge"></i> Good &amp; Defective (count) per Shift Personnel</div>
                <div class="chart-wrapper" style="height:265px">
                    <canvas id="cShift"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-grid-3x2-gap"></i> Good &amp; Defective (count) per Fabric Width-Denier</div>
                <div class="chart-wrapper" style="height:265px">
                    <canvas id="cFabric"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 3 COUNTS -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bag-fill"></i> Good &amp; Defective (count) per Bag Type</div>
                <div class="chart-wrapper" style="height:280px">
                    <canvas id="cBagType"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-graph-up"></i> Good &amp; Defective (count) per Job Order</div>
                <div class="chart-wrapper" style="height:280px">
                    <canvas id="cJO"></canvas>
                </div>
            </div>
        </div>
    </div>
    </div><!-- /ctsw-view-counts -->

    <!-- KPI Cards — WEIGHT view -->
    <div class="ctsw-view-weight" style="display:none">
    <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 row-cols-xl-5 ctsw-kpi-row">
        <?php
        $kpis_weight = [
            ['val' => number_format((float)$kpi['tot_good_weight'], 1).' kg',  'label' => 'Good Bags (weight)',    'icon' => 'bi-speedometer2',        'cls' => 'icon-green'],
            ['val' => number_format((float)$kpi['tot_def_weight'], 2).' kg',   'label' => 'Defective Bags (wt)',   'icon' => 'bi-exclamation-triangle', 'cls' => 'icon-red'],
            ['val' => number_format((float)$kpi['tot_waste'], 2).' kg',        'label' => 'Total Waste (kg)',      'icon' => 'bi-slash-circle',         'cls' => 'icon-purple'],
            ['val' => (int)$kpi['machines'],                                   'label' => 'Active Machines',       'icon' => 'bi-cpu',                  'cls' => 'icon-teal'],
            ['val' => (int)$kpi['customers'],                                  'label' => 'Customers',             'icon' => 'bi-people-fill',          'cls' => 'icon-blue'],
        ];
        foreach ($kpis_weight as $k): ?>
        <div class="col">
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

    <!-- Charts Row 1 WEIGHT -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-7">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Good &amp; Defective Bags (kg) per Machine</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cwMachine"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Good Bags (kg) per Customer</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cwCustomer"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 WEIGHT -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-person-badge"></i> Good &amp; Defective (kg) per Shift Personnel</div>
                <div class="chart-wrapper" style="height:265px">
                    <canvas id="cwShift"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-grid-3x2-gap"></i> Good &amp; Defective (kg) per Fabric Width-Denier</div>
                <div class="chart-wrapper" style="height:265px">
                    <canvas id="cwFabric"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 3 WEIGHT -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bag-fill"></i> Good &amp; Defective (kg) per Bag Type</div>
                <div class="chart-wrapper" style="height:280px">
                    <canvas id="cwBagType"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-graph-up"></i> Good &amp; Defective (kg) per Job Order</div>
                <div class="chart-wrapper" style="height:280px">
                    <canvas id="cwJO"></canvas>
                </div>
            </div>
        </div>
    </div>
    </div><!-- /ctsw-view-weight -->

 <!-- Production Table -->
    <div class="pqm-card">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="section-title mb-0">
                <i class="bi bi-table"></i> Production Records
                <span class="ms-1" style="font-size:.75rem;color:#22c55e;"><i class="bi bi-sort-down me-1"></i>Latest first</span>
                <span class="badge ms-2" style="background:rgba(59,130,246,.15);color:#93c5fd;font-size:.75rem;" id="ctswRowCount">
                    <?= number_format($kpi['recs']) ?> records
                </span>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div class="d-flex align-items-center gap-1" style="position:relative;">
                <input type="text" id="tblSearch" class="form-control form-control-sm pqm-input"
                       placeholder="Search table…" style="max-width:200px;padding-right:2rem;"
                       oninput="ctswDoSearch(this.value)">
                <button id="tblSearchClear" onclick="ctswClearSearch()"
                        style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;line-height:1;font-size:.85rem;"
                        title="Clear search"><i class="bi bi-x-circle-fill"></i></button>
                </div>
                <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_ctsw"
                   style="white-space:nowrap;">
                    <i class="bi bi-file-earmark-excel"></i> Add Data
                </a>
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
const CWM  = <?= json_encode($cw_machine)  ?>;
const CWSH = <?= json_encode($cw_shift)    ?>;
const CWBT = <?= json_encode($cw_bagtype)  ?>;
const CWF  = <?= json_encode($cw_fabric)   ?>;
const CWJO = <?= json_encode($cw_jo)       ?>;
const CWCU = <?= json_encode($cw_customer) ?>;

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

// ── WEIGHT VIEW CHARTS ─────────────────────────────────────────────────────────
function makeWeightBar(canvasId, data, label1, label2, indexAxis = 'x') {
    new Chart(document.getElementById(canvasId), {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [
                barDataset(label1, data.v1, PQM_COLORS.green  || '#22c55e'),
                barDataset(label2, data.v2, PQM_COLORS.red    || '#ef4444'),
            ]
        },
        options: {
            indexAxis,
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 800 },
            plugins: { legend: { position: 'top' } },
            scales: {
                x: { grid: { color: PQM_COLORS.grid }, beginAtZero: true,
                     ticks: { color: '#94a3b8', callback: v => v.toLocaleString() + ' kg' } },
                y: { grid: { color: PQM_COLORS.grid }, ticks: { color: '#94a3b8' } }
            }
        }
    });
}

makeWeightBar('cwMachine',  CWM,  'Good Bags (kg)', 'Defective (kg)');
makeWeightBar('cwShift',    CWSH, 'Good Bags (kg)', 'Defective (kg)', 'y');
makeWeightBar('cwBagType',  CWBT, 'Good Bags (kg)', 'Defective (kg)', 'y');
makeWeightBar('cwFabric',   CWF,  'Good Bags (kg)', 'Defective (kg)', 'y');
makeWeightBar('cwJO',       CWJO, 'Good Bags (kg)', 'Defective (kg)');

new Chart(document.getElementById('cwCustomer'), {
    type: 'doughnut',
    data: {
        labels: CWCU.labels,
        datasets: [{
            data: CWCU.v1,
            backgroundColor: ['#22c55e','#3b82f6','#f59e0b','#a855f7','#ef4444','#06b6d4','#f97316','#84cc16','#ec4899','#14b8a6','#8b5cf6','#f43f5e','#d97706','#0284c7','#34d399'],
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
            tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' kg' } }
        },
        cutout: '60%'
    }
});

// ── TOGGLE ─────────────────────────────────────────────────────────────────────
function ctswSetView(v) {
    document.querySelectorAll('.ctsw-view-counts').forEach(el => el.style.display = v === 'counts' ? '' : 'none');
    document.querySelectorAll('.ctsw-view-weight').forEach(el => el.style.display = v === 'weight' ? '' : 'none');
    document.getElementById('ctswBtnCounts').classList.toggle('active', v === 'counts');
    document.getElementById('ctswBtnWeight').classList.toggle('active', v === 'weight');
    localStorage.setItem('ctswView', v);
}
(function(){ const v = localStorage.getItem('ctswView'); if (v) ctswSetView(v); })();

// Live table search
function ctswDoSearch(q) {
    const term = q.trim().toLowerCase();
    let visibleCount = 0;
    document.querySelectorAll('#ctswTable tbody tr').forEach(tr => {
        const text = Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim()).join(' ').toLowerCase();
        const match = term === '' || text.includes(term);
        tr.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });
    const clearBtn = document.getElementById('tblSearchClear');
    if (clearBtn) clearBtn.style.display = q.trim() ? 'flex' : 'none';
    const countEl = document.getElementById('ctswRowCount');
    if (countEl) countEl.textContent = term ? visibleCount + ' result' + (visibleCount !== 1 ? 's' : '') : '<?= number_format($kpi["recs"]) ?> records';
}
function ctswClearSearch() {
    const input = document.getElementById('tblSearch');
    input.value = '';
    ctswDoSearch('');
    input.focus();
}
(function(){ const v = document.getElementById('tblSearch').value; if (v) ctswDoSearch(v); })();

// ── Date Range Picker (Flatpickr range mode) ────────────────
(function initDatePickers() {
    function loadFlatpickr(cb) {
        if (window.flatpickr) { cb(); return; }
        const link = document.createElement('link');
        link.rel  = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css';
        document.head.appendChild(link);
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/flatpickr';
        script.onload = cb;
        document.head.appendChild(script);
    }
    loadFlatpickr(function () {
        const style = document.createElement('style');
        style.textContent = `
            .flatpickr-calendar {
                background: #1a3358 !important;
                border: 1px solid rgba(34,197,94,.35) !important;
                box-shadow: 0 8px 32px rgba(0,0,0,.5) !important;
                border-radius: 10px !important;
                font-family: inherit !important;
            }
            .flatpickr-months .flatpickr-month,
            .flatpickr-weekdays,
            span.flatpickr-weekday { background: #1a3358 !important; color: #86efac !important; }
            .flatpickr-day { color: #f1f5f9 !important; border-radius: 6px !important; }
            .flatpickr-day:hover { background: rgba(34,197,94,.2) !important; border-color: #22c55e !important; }
            .flatpickr-day.selected,
            .flatpickr-day.startRange,
            .flatpickr-day.endRange {
                background: #22c55e !important; border-color: #22c55e !important;
                color: #052e16 !important; font-weight: 700 !important;
            }
            .flatpickr-day.inRange {
                background: rgba(34,197,94,.15) !important; border-color: transparent !important;
                box-shadow: -5px 0 0 rgba(34,197,94,.15), 5px 0 0 rgba(34,197,94,.15) !important;
            }
            .flatpickr-day.today { border-color: #22c55e !important; }
            .flatpickr-day.flatpickr-disabled { color: #334155 !important; }
            .flatpickr-current-month input.cur-year,
            .flatpickr-current-month .flatpickr-monthDropdown-months {
                color: #f1f5f9 !important; background: transparent !important;
            }
            .flatpickr-monthDropdown-months option { background: #1a3358 !important; }
            .flatpickr-prev-month svg, .flatpickr-next-month svg { fill: #86efac !important; }
            .flatpickr-prev-month:hover svg, .flatpickr-next-month:hover svg { fill: #22c55e !important; }
            .numInputWrapper:hover { background: rgba(34,197,94,.08) !important; }
`;
        document.head.appendChild(style);

        const fromHidden  = document.getElementById('ctsw_date_from');
        const toHidden    = document.getElementById('ctsw_date_to');
        const rangeInput  = document.getElementById('ctsw_date_range');

        // Pre-fill display input from hidden values on page load
        if (fromHidden.value && toHidden.value) {
            rangeInput.value = fromHidden.value + ' — ' + toHidden.value;
        } else if (fromHidden.value) {
            rangeInput.value = fromHidden.value;
        }

        flatpickr(rangeInput, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            allowInput: false,
            disableMobile: true,
            defaultDate: [
                fromHidden.value || null,
                toHidden.value   || null
            ].filter(Boolean),
            onChange: function(selectedDates) {
                fromHidden.value = selectedDates[0] ? selectedDates[0].toISOString().slice(0,10) : '';
                toHidden.value   = selectedDates[1] ? selectedDates[1].toISOString().slice(0,10) : '';
            }
        });

        // Clear button resets date pickers
        document.querySelector('a[href="ctsw.php"]')?.addEventListener('click', function() {
            fromHidden.value = '';
            toHidden.value   = '';
        });
    });
})();
</script>

<style>
.icon-teal   { background: rgba(20,184,166,.18); color: #2dd4bf; }
.icon-amber  { background: rgba(245,158,11,.18);  color: #fbbf24; }
.icon-purple { background: rgba(168,85,247,.18);  color: #c084fc; }

/* ── CTSW KPI card responsive fix ── */
.ctsw-kpi-row .stat-card {
    display: flex;
    align-items: center;
    gap: .55rem;
    padding: .75rem .9rem;
    overflow: hidden;
}
.ctsw-kpi-row .stat-icon {
    width: 38px; height: 38px;
    font-size: 1rem;
    flex-shrink: 0;
    border-radius: 10px;
}
.ctsw-kpi-row .stat-card > div:last-child {
    min-width: 0;
    flex: 1;
    overflow: hidden;
}
.ctsw-kpi-row .stat-value {
    font-size: .95rem;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ctsw-kpi-row .stat-label {
    font-size: .68rem;
    color: var(--text-muted);
    margin-top: .1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
@media (max-width: 576px) {
    .ctsw-kpi-row .stat-value { font-size: .85rem; }
    .ctsw-kpi-row .stat-icon  { width: 32px; height: 32px; font-size: .9rem; }
}

.ctsw-toggle-wrap {
    display: flex; background: rgba(15,23,42,.6);
    border: 1px solid rgba(148,163,184,.15); border-radius: 10px; padding: 3px; gap: 2px;
}
.ctsw-toggle-btn {
    padding: 5px 18px; font-size: .8rem; font-weight: 600; border: none; cursor: pointer;
    border-radius: 8px; background: transparent; color: #64748b;
    transition: all .2s; letter-spacing: .02em;
}
.ctsw-toggle-btn.active {
    background: #1e3a5f; color: #93c5fd;
    box-shadow: 0 2px 8px rgba(59,130,246,.25);
}
.ctsw-toggle-btn:hover:not(.active) { color: #94a3b8; background: rgba(255,255,255,.04); }
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

/* Multi-select machine dropdown (ctsw) */
.ctsw-multi-wrap { position: relative; }
.ctsw-multi-btn {
    width: 100%; display: flex; align-items: center; gap: 6px;
    padding: 4px 10px; font-size: .8rem; cursor: pointer; text-align: left;
    min-height: 31px; user-select: none; border-radius: 8px !important;
}
.ctsw-multi-btn.active { border-color: #3b82f6 !important; color: #93c5fd !important; }
.ctsw-multi-panel {
    display: none; position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 260px; overflow-y: auto; z-index: 1050;
    background: #0f1e35; border: 1px solid rgba(59,130,246,.35);
    border-radius: 10px; padding: 6px 4px; box-shadow: 0 8px 24px rgba(0,0,0,.5);
}
.ctsw-multi-panel.open { display: block; }
.ctsw-multi-opt {
    display: flex; align-items: center; gap: 8px; padding: 5px 10px;
    border-radius: 6px; cursor: pointer; font-size: .8rem; color: #cbd5e1;
    transition: background .15s; white-space: nowrap;
}
.ctsw-multi-opt:hover { background: rgba(59,130,246,.12); color: #f1f5f9; }
.ctsw-cb { accent-color: #3b82f6; width: 14px; height: 14px; cursor: pointer; flex-shrink: 0; }
</style>

<script>
(function () {
    const btn    = document.getElementById('ctswMachineBtn');
    const panel  = document.getElementById('ctswMachinePanel');
    const label  = document.getElementById('ctswMachineLabel');
    const allCb  = document.getElementById('ctswMachineAll');
    const getCbs = () => [...document.querySelectorAll('.ctsw-machine-cb')];

    function updateLabel() {
        const checked = getCbs().filter(c => c.checked);
        if (checked.length === 0) {
            label.textContent = 'All Machines';
            btn.classList.remove('active');
        } else if (checked.length === 1) {
            label.textContent = checked[0].value;
            btn.classList.add('active');
        } else {
            label.textContent = checked.length + ' machines selected';
            btn.classList.add('active');
        }
    }

    function syncAllCb() {
        const cbs = getCbs();
        allCb.checked = cbs.length > 0 && cbs.every(c => c.checked);
        allCb.indeterminate = !allCb.checked && cbs.some(c => c.checked);
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('open');
    });

    allCb.addEventListener('change', function () {
        getCbs().forEach(c => c.checked = this.checked);
        updateLabel();
    });

    getCbs().forEach(cb => cb.addEventListener('change', function () {
        syncAllCb();
        updateLabel();
    }));

    document.addEventListener('click', function (e) {
        if (!document.getElementById('ctswMachineWrap').contains(e.target))
            panel.classList.remove('open');
    });

    syncAllCb();
    updateLabel();
})();
</script>