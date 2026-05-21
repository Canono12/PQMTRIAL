<?php
$page_title = 'Printing';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// ── Filters ──────────────────────────────────────────────────────────────────
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
    $where[] = "DATE_FORMAT(DATE_COMPLETED, '%Y-%m') = ?";
    $params[] = $f_monthyear;
    $types .= 's';
}
if ($f_date_from) {
    $where[] = "DATE_COMPLETED >= ?";
    $params[] = $f_date_from;
    $types .= 's';
}
if ($f_date_to) {
    $where[] = "DATE_COMPLETED <= ?";
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

// ── KPIs ─────────────────────────────────────────────────────────────────────
$kpi = qry($conn,
    "SELECT COUNT(*) AS recs,
            SUM(CAST(`ROLL_WEIGHT(kilograms)` AS DECIMAL(10,2))) AS tot_weight,
            SUM(CAST(`OUTPUT_WASTE(grams)` AS DECIMAL(10,2))) AS tot_waste,
            SUM(`ROLL_LENGTH(meters)`) AS tot_length,
            COUNT(DISTINCT MACHINE_NUMBER) AS machines,
            COUNT(DISTINCT PRINTED_FABRIC) AS fabrics
     FROM printing WHERE $wsql",
    $types, $params
)->fetch_assoc();

// ── Chart data helpers ────────────────────────────────────────────────────────
function chart_data($conn, $sql, $t, $p, $key_col, $val1_col, $val2_col = null) {
    $r = qry($conn, $sql, $t, $p);
    $out = ['labels' => [], 'v1' => [], 'v2' => []];
    while ($row = $r->fetch_assoc()) {
        $out['labels'][] = $row[$key_col];
        $out['v1'][]     = (float)$row[$val1_col];
        if ($val2_col) $out['v2'][] = (float)$row[$val2_col];
    }
    return $out;
}

// Output + Waste per Machine
$c_machine = chart_data($conn,
    "SELECT MACHINE_NUMBER AS k,
            SUM(CAST(`ROLL_WEIGHT(kilograms)` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`OUTPUT_WASTE(grams)` AS DECIMAL(10,2))) AS v2
     FROM printing WHERE $wsql
     GROUP BY MACHINE_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per Printed Fabric (Shift-type)
$c_shift = chart_data($conn,
    "SELECT PRINTED_FABRIC AS k,
            SUM(CAST(`ROLL_WEIGHT(kilograms)` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`OUTPUT_WASTE(grams)` AS DECIMAL(10,2))) AS v2
     FROM printing WHERE $wsql
     GROUP BY PRINTED_FABRIC ORDER BY v1 DESC",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per Print Design (top 10)
$c_design = chart_data($conn,
    "SELECT SUBSTR(PRINT_DESIGN, 1, 40) AS k,
            SUM(CAST(`ROLL_WEIGHT(kilograms)` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`OUTPUT_WASTE(grams)` AS DECIMAL(10,2))) AS v2
     FROM printing WHERE $wsql
     GROUP BY PRINT_DESIGN ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per Fabric Width-Denier (top 10)
$c_fabric = chart_data($conn,
    "SELECT FABRIC_WIDTH_AND_TAPE_DENIER AS k,
            SUM(CAST(`ROLL_WEIGHT(kilograms)` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`OUTPUT_WASTE(grams)` AS DECIMAL(10,2))) AS v2
     FROM printing WHERE $wsql
     GROUP BY FABRIC_WIDTH_AND_TAPE_DENIER ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1', 'v2');

// Output + Waste per Job Order (top 15)
$c_jo = chart_data($conn,
    "SELECT JOB_ORDER_NUMBER AS k,
            SUM(CAST(`ROLL_WEIGHT(kilograms)` AS DECIMAL(10,2))) AS v1,
            SUM(CAST(`OUTPUT_WASTE(grams)` AS DECIMAL(10,2))) AS v2
     FROM printing WHERE $wsql
     GROUP BY JOB_ORDER_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');

// ── COUNT-based chart data (rolls/records) ────────────────────────────────────
$cc_machine = chart_data($conn,
    "SELECT MACHINE_NUMBER AS k, COUNT(*) AS v1
     FROM printing WHERE $wsql
     GROUP BY MACHINE_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1');

$cc_shift = chart_data($conn,
    "SELECT PRINTED_FABRIC AS k, COUNT(*) AS v1
     FROM printing WHERE $wsql
     GROUP BY PRINTED_FABRIC ORDER BY v1 DESC",
    $types, $params, 'k', 'v1');

$cc_design = chart_data($conn,
    "SELECT SUBSTR(PRINT_DESIGN, 1, 40) AS k, COUNT(*) AS v1
     FROM printing WHERE $wsql
     GROUP BY PRINT_DESIGN ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1');

$cc_fabric = chart_data($conn,
    "SELECT FABRIC_WIDTH_AND_TAPE_DENIER AS k, COUNT(*) AS v1
     FROM printing WHERE $wsql
     GROUP BY FABRIC_WIDTH_AND_TAPE_DENIER ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1');

$cc_jo = chart_data($conn,
    "SELECT JOB_ORDER_NUMBER AS k, COUNT(*) AS v1
     FROM printing WHERE $wsql
     GROUP BY JOB_ORDER_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1');

// ── Filter options ────────────────────────────────────────────────────────────
$opt_months_raw = $conn->query("SELECT DISTINCT DATE_FORMAT(DATE_COMPLETED,'%Y-%m') AS v, DATE_FORMAT(DATE_COMPLETED,'%M %Y') AS label FROM printing WHERE DATE_COMPLETED IS NOT NULL AND DATE_COMPLETED != '' AND DATE_COMPLETED != '0000-00-00' ORDER BY v DESC");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    if ($om['v']) $opt_months[$om['v']] = $om['label'];
}
$opt_machines = $conn->query("SELECT DISTINCT MACHINE_NUMBER v FROM printing WHERE MACHINE_NUMBER IS NOT NULL AND MACHINE_NUMBER != '' ORDER BY MACHINE_NUMBER");
$opt_shifts   = $conn->query("SELECT DISTINCT PRINTED_FABRIC v FROM printing WHERE PRINTED_FABRIC IS NOT NULL AND PRINTED_FABRIC != '' ORDER BY PRINTED_FABRIC");
$opt_fabrics  = $conn->query("SELECT DISTINCT FABRIC_WIDTH_AND_TAPE_DENIER v FROM printing WHERE FABRIC_WIDTH_AND_TAPE_DENIER IS NOT NULL AND FABRIC_WIDTH_AND_TAPE_DENIER != '' ORDER BY FABRIC_WIDTH_AND_TAPE_DENIER");
$opt_jos      = $conn->query("SELECT DISTINCT JOB_ORDER_NUMBER v FROM printing WHERE JOB_ORDER_NUMBER IS NOT NULL AND JOB_ORDER_NUMBER != '' ORDER BY JOB_ORDER_NUMBER");

// ── Table ─────────────────────────────────────────────────────────────────────
$rows = qry($conn,
    "SELECT FINAL_BATCH_CODE, DATE_STARTED, DATE_COMPLETED, MACHINE_NUMBER,
            PRINTED_FABRIC, FABRIC_WIDTH_AND_TAPE_DENIER, PRINTING_STAGE,
            `ROLL_WEIGHT(kilograms)` AS weight,
            `ROLL_LENGTH(meters)` AS roll_len,
            `INPUT_WASTE(grams)` AS input_waste,
            `OUTPUT_WASTE(grams)` AS waste,
            ROLL_ORDER, JOB_ORDER_NUMBER, PRINT_DESIGN,
            CORONA_DOSAGE, DRYER_TEMPERATURE, BLOWER_SETTING,
            INK_1_PANTONE_CODE, INK_1_VISCOSITY,
            INK_2_PANTONE_CODE, INK_2_VISCOSITY,
            INK_3_PANTONE_CODE, INK_3_VISCOSITY,
            SHIFT_LEADER_AND_LEAD_OPERATOR, SHIFT_PRODUCTION_PERSONNEL,
            IPQC_TECHNICIAN, PRODUCTION_REMARKS, PROCESS_TIME
     FROM printing WHERE $wsql ORDER BY DATE_STARTED DESC, STARTED DESC, Id DESC LIMIT 50",
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
            <div class="page-heading"><i class="bi bi-printer me-2" style="color:#f59e0b"></i>Printing Module</div>
            <div class="page-subheading mt-1">Output (kg) and waste analytics per machine, shift, design, fabric, and job order</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- View Toggle -->
            <div class="prn-toggle-wrap">
                <button type="button" class="prn-toggle-btn active" id="prnBtnCounts" onclick="prnSetView('counts')">
                    <i class="bi bi-boxes me-1"></i>Counts
                </button>
                <button type="button" class="prn-toggle-btn" id="prnBtnWeight" onclick="prnSetView('weight')">
                    <i class="bi bi-box-seam me-1"></i>Weight
                </button>
            </div>
            <span class="badge process-badge" style="background:#2d1f00;border:1px solid #f59e0b;color:#fcd34d">
                <i class="bi bi-database me-1"></i>printing
            </span>
            <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_printing">
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

            <div class="col-12 col-md-6 col-lg-3">
                <label class="form-label text-secondary" style="font-size:.72rem">
                    <i class="bi bi-calendar-range me-1" style="color:#22c55e"></i>DATE FINISHED RANGE
                </label>
                <input type="hidden" name="date_from" id="prn_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                <input type="hidden" name="date_to"   id="prn_date_to"   value="<?= htmlspecialchars($f_date_to) ?>">
                <input type="text" id="prn_date_range"
                       class="form-control form-control-sm pqm-input"
                       placeholder="Start date — End date"
                       autocomplete="off" readonly>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">MACHINE</label>
                <div class="prn-multi-wrap" id="prnMachineWrap">
                    <button type="button" class="prn-multi-btn pqm-input" id="prnMachineBtn">
                        <span id="prnMachineLabel">
                            <?php if (!empty($f_machines)): ?>
                                <?= count($f_machines) === 1 ? htmlspecialchars($f_machines[0]) : count($f_machines) . ' machines selected' ?>
                            <?php else: ?>All Machines<?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-down" style="font-size:.7rem;margin-left:auto;opacity:.6"></i>
                    </button>
                    <div class="prn-multi-panel" id="prnMachinePanel">
                        <label class="prn-multi-opt">
                            <input type="checkbox" id="prnMachineAll" class="prn-cb">
                            <span style="color:#94a3b8;font-style:italic">All Machines</span>
                        </label>
                        <div style="border-top:1px solid rgba(255,255,255,.07);margin:3px 0"></div>
                        <?php
                        $machines_list = [];
                        while ($o = $opt_machines->fetch_assoc()) $machines_list[] = $o['v'];
                        foreach ($machines_list as $mv):
                            $checked = in_array((string)$mv, array_map('strval', $f_machines)) ? 'checked' : '';
                        ?>
                        <label class="prn-multi-opt">
                            <input type="checkbox" name="machine[]" value="<?= htmlspecialchars($mv) ?>"
                                   class="prn-cb prn-machine-cb" <?= $checked ?>>
                            <span><?= htmlspecialchars($mv) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">PRINTED FABRIC</label>
                <select name="shift" class="form-select form-select-sm pqm-input">
                    <option value="">All Fabric Types</option>
                    <?php while ($o = $opt_shifts->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_shift===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">FABRIC WIDTH-DENIER</label>
                <select name="fabric" class="form-select form-select-sm pqm-input">
                    <option value="">All Widths</option>
                    <?php while ($o = $opt_fabrics->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_fabric===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">PRINT DESIGN</label>
                <input type="text" name="design" class="form-control form-control-sm pqm-input"
                       placeholder="Search design..." value="<?= htmlspecialchars($f_design) ?>">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">JOB ORDER NO.</label>
                <select name="jo" class="form-select form-select-sm pqm-input">
                    <option value="">All JO Numbers</option>
                    <?php while ($o = $opt_jos->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_jo===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="printing.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- KPI Cards — COUNTS view -->
    <div class="prn-view-counts">
    <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 prn-kpi-row">
        <?php
        $kpis_counts = [
            ['val' => number_format((int)$kpi['recs']),    'label' => 'Total Records',   'icon' => 'bi-collection',       'cls' => 'icon-teal'],
            ['val' => (int)$kpi['machines'],               'label' => 'Active Machines', 'icon' => 'bi-cpu',              'cls' => 'icon-green'],
            ['val' => (int)$kpi['fabrics'],                'label' => 'Fabric Types',    'icon' => 'bi-layers',           'cls' => 'icon-purple'],
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

    <!-- Charts Row 1 COUNTS — Rolls per Machine | per Printed Fabric -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-8">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Rolls (Count) per Machine</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cMachineCnt"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Rolls per Printed Fabric</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cShiftCnt"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 COUNTS -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-5">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-grid-3x2-gap"></i> Rolls per Fabric Width-Denier</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cFabricCnt"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-palette"></i> Rolls per Print Design (Top 10)</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cDesignCnt"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 3 COUNTS -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="pqm-card">
                <div class="section-title"><i class="bi bi-file-earmark-text"></i> Rolls per Job Order / JO Number (Top 15)</div>
                <div class="chart-wrapper" style="height:240px">
                    <canvas id="cJOCnt"></canvas>
                </div>
            </div>
        </div>
    </div>
    </div><!-- /prn-view-counts -->

    <!-- KPI Cards — WEIGHT view -->
    <div class="prn-view-weight" style="display:none">
    <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 prn-kpi-row">
        <?php
        $kpis_weight = [
            ['val' => number_format((float)$kpi['tot_weight'], 1).' kg', 'label' => 'Total Output (kg)',  'icon' => 'bi-speedometer2',         'cls' => 'icon-amber'],
            ['val' => number_format((float)$kpi['tot_length'], 0).' m',  'label' => 'Total Length (m)',   'icon' => 'bi-rulers',               'cls' => 'icon-blue'],
            ['val' => number_format((float)$kpi['tot_waste'], 1).' g',   'label' => 'Total Waste (g)',    'icon' => 'bi-exclamation-triangle',  'cls' => 'icon-red'],
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

    <!-- Charts Row 1 WEIGHT — Output/Waste per Machine | per Printed Fabric -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-8">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Output &amp; Waste per Machine</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cMachine"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Output (kg) per Printed Fabric</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cShift"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 WEIGHT — Output/Waste per Fabric Width-Denier | per Print Design -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-5">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-grid-3x2-gap"></i> Output &amp; Waste per Fabric Width-Denier</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cFabric"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-palette"></i> Output &amp; Waste per Print Design (Top 10)</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cDesign"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 3 WEIGHT — Output/Waste per Job Order -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="pqm-card">
                <div class="section-title"><i class="bi bi-file-earmark-text"></i> Output &amp; Waste per Job Order / JO Number (Top 15)</div>
                <div class="chart-wrapper" style="height:240px">
                    <canvas id="cJO"></canvas>
                </div>
            </div>
        </div>
    </div>
    </div><!-- /prn-view-weight -->

    <!-- Production Table -->
    <div class="pqm-card">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="chart-title mb-0">
                Production Records
                <span class="badge ms-2" style="background:rgba(245,158,11,.15);color:#fcd34d;font-size:.75rem;" id="prtRowCount">
                    <?= number_format($kpi['recs']) ?> rows
                </span>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div style="position:relative;">
                    <input type="text" id="tblSearch" class="form-control form-control-sm pqm-input"
                           placeholder="Search table…" style="max-width:220px;padding-right:2rem;">
                    <button id="tblSearchClear" onclick="prtClearSearch()"
                            style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;line-height:1;font-size:.85rem;"
                            title="Clear search"><i class="bi bi-x-circle-fill"></i></button>
                </div>
                <a href="printing_export.php?<?= http_build_query($_GET) ?>"
                   class="btn btn-sm btn-success px-3 d-flex align-items-center gap-1" style="white-space:nowrap">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="table-responsive" style="max-height:480px;overflow-y:auto;">
            <table class="pqm-table" id="pTable">
                <thead>
                    <tr>
                        <th style="white-space:nowrap">Date Started</th>
                        <th style="white-space:nowrap">Date Completed</th>
                        <th style="white-space:nowrap">Batch Code</th>
                        <th style="white-space:nowrap">Machine</th>
                        <th style="white-space:nowrap">Stage</th>
                        <th style="white-space:nowrap">Fabric Type</th>
                        <th style="white-space:nowrap">Width-Denier</th>
                        <th style="white-space:nowrap">Output (kg)</th>
                        <th style="white-space:nowrap">Length (m)</th>
                        <th style="white-space:nowrap">Input Waste (g)</th>
                        <th style="white-space:nowrap">Output Waste (g)</th>
                        <th style="white-space:nowrap">Roll Order</th>
                        <th style="white-space:nowrap">Job Order</th>
                        <th style="white-space:nowrap">Print Design</th>
                        <th style="white-space:nowrap">Corona</th>
                        <th style="white-space:nowrap">Dryer °</th>
                        <th style="white-space:nowrap">Blower</th>
                        <th style="white-space:nowrap">Ink 1</th>
                        <th style="white-space:nowrap">Ink 1 Visc</th>
                        <th style="white-space:nowrap">Ink 2</th>
                        <th style="white-space:nowrap">Ink 2 Visc</th>
                        <th style="white-space:nowrap">Ink 3</th>
                        <th style="white-space:nowrap">Ink 3 Visc</th>
                        <th style="white-space:nowrap">Shift Leader</th>
                        <th style="white-space:nowrap">Shift Personnel</th>
                        <th style="white-space:nowrap">IPQC</th>
                        <th style="white-space:nowrap">Process Time</th>
                        <th style="white-space:nowrap">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $rows->fetch_assoc()):
                    $rem   = trim($r['PRODUCTION_REMARKS'] ?? '');
                    $isOk  = ($rem === '' || in_array(strtolower($rem), ['n/a','na','okay','normal','no','0']));
                    $rb    = $isOk ? 'bg-secondary' : 'bg-warning text-dark';
                    $waste = (float)($r['waste'] ?? 0);
                    $wcolor = $waste > 0.5 ? '#fbbf24' : '#94a3b8';
                    $dash  = '—';
                    // Ink helper: suppress zeros
                    $ink = fn($v) => (!$v || $v === '0' || $v === 'N/A') ? $dash : htmlspecialchars($v);
                ?>
                <tr>
                    <td style="white-space:nowrap"><?= htmlspecialchars($r['DATE_STARTED']) ?></td>
                    <td style="white-space:nowrap"><?= htmlspecialchars($r['DATE_COMPLETED'] ?? '') ?></td>
                    <td><code style="color:#fcd34d;font-size:.73rem;white-space:nowrap"><?= htmlspecialchars($r['FINAL_BATCH_CODE']) ?></code></td>
                    <td><span class="badge" style="background:#2d1f00;color:#fcd34d;border:1px solid #f59e0b55"><?= htmlspecialchars($r['MACHINE_NUMBER']) ?></span></td>
                    <td><span class="badge bg-secondary process-badge"><?= htmlspecialchars($r['PRINTING_STAGE']) ?></span></td>
                    <td><span class="badge" style="background:#1e3a5f;color:#93c5fd;border:1px solid #3b82f655"><?= htmlspecialchars($r['PRINTED_FABRIC']) ?></span></td>
                    <td><code style="color:#a78bfa;font-size:.75rem;white-space:nowrap"><?= htmlspecialchars($r['FABRIC_WIDTH_AND_TAPE_DENIER']) ?></code></td>
                    <td class="fw-semibold" style="color:#38bdf8;white-space:nowrap"><?= number_format((float)$r['weight'], 1) ?></td>
                    <td style="color:#4ade80"><?= number_format((int)$r['roll_len']) ?></td>
                    <td style="color:#94a3b8"><?= number_format((float)$r['input_waste'], 1) ?></td>
                    <td style="color:<?= $wcolor ?>"><?= number_format($waste, 1) ?></td>
                    <td style="font-size:.75rem"><?= htmlspecialchars($r['ROLL_ORDER'] ?? $dash) ?></td>
                    <td><span class="badge bg-primary process-badge"><?= htmlspecialchars($r['JOB_ORDER_NUMBER']) ?></span></td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($r['PRINT_DESIGN'] ?? '') ?>">
                        <span style="color:#c084fc;font-size:.73rem"><?= htmlspecialchars(mb_substr($r['PRINT_DESIGN'] ?? '', 0, 35)) ?><?= mb_strlen($r['PRINT_DESIGN'] ?? '') > 35 ? '…' : '' ?></span>
                    </td>
                    <td style="font-size:.75rem;color:#94a3b8"><?= htmlspecialchars($r['CORONA_DOSAGE'] ?? $dash) ?></td>
                    <td style="font-size:.75rem;color:#94a3b8"><?= htmlspecialchars($r['DRYER_TEMPERATURE'] ?? $dash) ?></td>
                    <td style="font-size:.75rem;color:#94a3b8"><?= htmlspecialchars($r['BLOWER_SETTING'] ?? $dash) ?></td>
                    <td style="font-size:.73rem;white-space:nowrap"><?= $ink($r['INK_1_PANTONE_CODE'] ?? '') ?></td>
                    <td style="font-size:.73rem;color:#94a3b8"><?= $ink($r['INK_1_VISCOSITY'] ?? '') ?></td>
                    <td style="font-size:.73rem;white-space:nowrap"><?= $ink($r['INK_2_PANTONE_CODE'] ?? '') ?></td>
                    <td style="font-size:.73rem;color:#94a3b8"><?= $ink($r['INK_2_VISCOSITY'] ?? '') ?></td>
                    <td style="font-size:.73rem;white-space:nowrap"><?= $ink($r['INK_3_PANTONE_CODE'] ?? '') ?></td>
                    <td style="font-size:.73rem;color:#94a3b8"><?= $ink($r['INK_3_VISCOSITY'] ?? '') ?></td>
                    <td style="font-size:.72rem;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($r['SHIFT_LEADER_AND_LEAD_OPERATOR'] ?? '') ?>"><?= htmlspecialchars($r['SHIFT_LEADER_AND_LEAD_OPERATOR'] ?? $dash) ?></td>
                    <td style="font-size:.72rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($r['SHIFT_PRODUCTION_PERSONNEL'] ?? '') ?>"><?= htmlspecialchars($r['SHIFT_PRODUCTION_PERSONNEL'] ?? $dash) ?></td>
                    <td style="font-size:.73rem;white-space:nowrap"><?= htmlspecialchars($r['IPQC_TECHNICIAN'] ?? $dash) ?></td>
                    <td style="font-size:.73rem;color:#64748b;white-space:nowrap"><?= htmlspecialchars($r['PROCESS_TIME'] ?? $dash) ?></td>
                    <td><span class="badge <?= $rb ?> process-badge" title="<?= htmlspecialchars($rem) ?>"><?= htmlspecialchars($rem ?: 'N/A') ?></span></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->
<?php
$upload_module='printing';$upload_label='Printing';
$upload_sample='FINAL_BATCH_CODE | MACHINE_NUMBER | DATE_STARTED | ROLL_WEIGHT(kilograms) | OUTPUT_WASTE(grams) | PRINT_DESIGN | PRINTED_FABRIC | ...';
require_once __DIR__.'/../includes/upload_modal.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
const CM = <?= json_encode($c_machine) ?>;
const CS = <?= json_encode($c_shift)   ?>;
const CF = <?= json_encode($c_fabric)  ?>;
const CD = <?= json_encode($c_design)  ?>;
const CJ = <?= json_encode($c_jo)      ?>;
const CM_CNT = <?= json_encode($cc_machine) ?>;
const CS_CNT = <?= json_encode($cc_shift)   ?>;
const CF_CNT = <?= json_encode($cc_fabric)  ?>;
const CD_CNT = <?= json_encode($cc_design)  ?>;
const CJ_CNT = <?= json_encode($cc_jo)      ?>;

// ── Shared gradient helper ─────────────────────────────────────────────────
function amberDataset(label, data) {
    return { label, data, backgroundColor: 'rgba(245,158,11,.75)', borderColor: '#f59e0b', borderWidth:1, borderRadius:4 };
}
function redDataset(label, data) {
    return { label, data, backgroundColor: 'rgba(239,68,68,.6)', borderColor: '#ef4444', borderWidth:1, borderRadius:4 };
}

// 1. Machine — grouped bar (Output + Waste)
new Chart(document.getElementById('cMachine'), {
    type: 'bar',
    data: { labels: CM.labels, datasets: [
        amberDataset('Output (kg)', CM.v1),
        redDataset('Waste (g÷1000 kg)', CM.v2.map(v => v/1000)),
    ]},
    options: { responsive:true, maintainAspectRatio:false,
        animation:{ duration:800 },
        plugins:{ legend:{ position:'top', labels:{ color:'#94a3b8' } } },
        scales:{
            x:{ grid:{ color:PQM_COLORS.grid }, ticks:{ color:'#94a3b8' } },
            y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                ticks:{ color:'#94a3b8', callback: v => v.toLocaleString() + ' kg' } }
        }
    }
});

// 2. Printed Fabric — doughnut
new Chart(document.getElementById('cShift'), {
    type: 'doughnut',
    data: { labels: CS.labels, datasets:[{
        data: CS.v1,
        backgroundColor:['#f59e0b','#0ea5e9','#22c55e','#a855f7','#ef4444','#06b6d4','#f97316','#84cc16'],
        borderWidth:2, borderColor:'#162032', hoverOffset:8
    }]},
    options:{ responsive:true, maintainAspectRatio:false,
        animation:{ animateRotate:true, duration:900 },
        plugins:{ legend:{ position:'right', labels:{ boxWidth:12, padding:8, color:'#94a3b8' } },
                  tooltip:{ callbacks:{ label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' kg' } } },
        cutout:'60%'
    }
});

// 3. Fabric Width-Denier — horizontal grouped bar
new Chart(document.getElementById('cFabric'), {
    type: 'bar',
    data: { labels: CF.labels, datasets:[
        amberDataset('Output (kg)', CF.v1),
        redDataset('Waste (g÷1000 kg)', CF.v2.map(v => v/1000)),
    ]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
        animation:{ duration:800 },
        plugins:{ legend:{ position:'top', labels:{ color:'#94a3b8' } } },
        scales:{
            x:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                ticks:{ color:'#94a3b8', callback: v => v.toLocaleString() + ' kg' } },
            y:{ grid:{ color:PQM_COLORS.grid }, ticks:{ color:'#94a3b8' } }
        }
    }
});

// 4. Print Design — horizontal grouped bar
new Chart(document.getElementById('cDesign'), {
    type: 'bar',
    data: { labels: CD.labels, datasets:[
        amberDataset('Output (kg)', CD.v1),
        redDataset('Waste (g÷1000 kg)', CD.v2.map(v => v/1000)),
    ]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
        animation:{ duration:850 },
        plugins:{ legend:{ position:'top', labels:{ color:'#94a3b8' } } },
        scales:{
            x:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                ticks:{ color:'#94a3b8', callback: v => v.toLocaleString() + ' kg' } },
            y:{ grid:{ color:PQM_COLORS.grid }, ticks:{ color:'#94a3b8', font:{ size:10 } } }
        }
    }
});

// 5. Job Order — grouped bar
new Chart(document.getElementById('cJO'), {
    type: 'bar',
    data: { labels: CJ.labels, datasets: [
        amberDataset('Output (kg)', CJ.v1),
        redDataset('Waste (g÷1000 kg)', CJ.v2.map(v => v/1000)),
    ]},
    options: { responsive:true, maintainAspectRatio:false,
        animation:{ duration:800 },
        plugins:{ legend:{ position:'top', labels:{ color:'#94a3b8' } } },
        scales:{
            x:{ grid:{ color:PQM_COLORS.grid }, ticks:{ color:'#94a3b8' } },
            y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                ticks:{ color:'#94a3b8', callback: v => v.toLocaleString() + ' kg' } }
        }
    }
});

// Table search
function prtDoSearch(q) {
    const term = q.trim().toLowerCase();
    let visibleCount = 0;
    document.querySelectorAll('#pTable tbody tr').forEach(tr => {
        const text = Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim()).join(' ').toLowerCase();
        const match = term === '' || text.includes(term);
        tr.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });
    const clearBtn = document.getElementById('tblSearchClear');
    if (clearBtn) clearBtn.style.display = q.trim() ? 'flex' : 'none';
    const countEl = document.getElementById('prtRowCount');
    if (countEl) countEl.textContent = term ? visibleCount + ' result' + (visibleCount !== 1 ? 's' : '') : '<?= number_format($kpi["recs"]) ?> rows';
}
function prtClearSearch() {
    const input = document.getElementById('tblSearch');
    input.value = '';
    prtDoSearch('');
    input.focus();
}
document.getElementById('tblSearch').addEventListener('input', function() {
    prtDoSearch(this.value);
});

// ── COUNT CHARTS ───────────────────────────────────────────────────────────────
const tealPalette = ['#0ea5e9','#22c55e','#a855f7','#f59e0b','#ef4444','#06b6d4','#f97316','#84cc16','#e879f9','#34d399'];

new Chart(document.getElementById('cMachineCnt'), {
    type: 'bar',
    data: { labels: CM_CNT.labels, datasets: [{ label: 'Rolls (count)', data: CM_CNT.v1, backgroundColor: 'rgba(14,165,233,.75)', borderColor: '#0ea5e9', borderWidth:1, borderRadius:4 }]},
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid }, ticks:{ color:'#94a3b8' } },
                 y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true, ticks:{ color:'#94a3b8', callback: v => v.toLocaleString() } } } }
});

new Chart(document.getElementById('cShiftCnt'), {
    type: 'doughnut',
    data: { labels: CS_CNT.labels, datasets:[{ data: CS_CNT.v1, backgroundColor: tealPalette, borderWidth:2, borderColor:'#162032', hoverOffset:8 }]},
    options:{ responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ position:'right', labels:{ boxWidth:12, padding:8, color:'#94a3b8' } },
                  tooltip:{ callbacks:{ label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' rolls' } } }, cutout:'60%' }
});

new Chart(document.getElementById('cFabricCnt'), {
    type: 'bar',
    data: { labels: CF_CNT.labels, datasets:[{ label: 'Rolls (count)', data: CF_CNT.v1, backgroundColor: 'rgba(14,165,233,.75)', borderColor:'#0ea5e9', borderWidth:1, borderRadius:4 }]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true, ticks:{ color:'#94a3b8' } },
                 y:{ grid:{ color:PQM_COLORS.grid }, ticks:{ color:'#94a3b8' } } } }
});

new Chart(document.getElementById('cDesignCnt'), {
    type: 'bar',
    data: { labels: CD_CNT.labels, datasets:[{ label: 'Rolls (count)', data: CD_CNT.v1, backgroundColor: 'rgba(14,165,233,.75)', borderColor:'#0ea5e9', borderWidth:1, borderRadius:4 }]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true, ticks:{ color:'#94a3b8' } },
                 y:{ grid:{ color:PQM_COLORS.grid }, ticks:{ color:'#94a3b8', font:{ size:10 } } } } }
});

new Chart(document.getElementById('cJOCnt'), {
    type: 'bar',
    data: { labels: CJ_CNT.labels, datasets:[{ label: 'Rolls (count)', data: CJ_CNT.v1, backgroundColor: 'rgba(14,165,233,.75)', borderColor:'#0ea5e9', borderWidth:1, borderRadius:4 }]},
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid }, ticks:{ color:'#94a3b8' } },
                 y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true, ticks:{ color:'#94a3b8', callback: v => v.toLocaleString() } } } }
});

// ── TOGGLE ────────────────────────────────────────────────────────────────────
function prnSetView(v) {
    document.querySelectorAll('.prn-view-counts').forEach(el => el.style.display = v === 'counts' ? '' : 'none');
    document.querySelectorAll('.prn-view-weight').forEach(el => el.style.display = v === 'weight' ? '' : 'none');
    document.getElementById('prnBtnCounts').classList.toggle('active', v === 'counts');
    document.getElementById('prnBtnWeight').classList.toggle('active', v === 'weight');
    localStorage.setItem('prnView', v);
}
(function(){ const v = localStorage.getItem('prnView'); if (v) prnSetView(v); })();
</script>

<style>
.icon-amber  { background: rgba(245,158,11,.18); color: #fcd34d; }
.prn-toggle-wrap {
    display: flex; background: rgba(15,23,42,.6);
    border: 1px solid rgba(148,163,184,.15); border-radius: 10px; padding: 3px; gap: 2px;
}
.prn-toggle-btn {
    padding: 5px 18px; font-size: .8rem; font-weight: 600; border: none; cursor: pointer;
    border-radius: 8px; background: transparent; color: #64748b;
    transition: all .2s; letter-spacing: .02em;
}
.prn-toggle-btn.active {
    background: #2d1f00; color: #fcd34d;
    box-shadow: 0 2px 8px rgba(245,158,11,.25);
}
.prn-toggle-btn:hover:not(.active) { color: #94a3b8; background: rgba(255,255,255,.04); }
.pqm-input {
    background: #1a3358 !important;
    border: 1px solid rgba(255,255,255,.1) !important;
    color: #f1f5f9 !important;
    border-radius: 8px !important;
}
.pqm-input::placeholder { color: #64748b !important; }
.pqm-input:focus {
    border-color: #f59e0b !important;
    box-shadow: 0 0 0 3px rgba(245,158,11,.2) !important;
    outline: none;
}
.pqm-input option { background: #1a3358; }

/* Multi-select machine dropdown (printing) */
.prn-multi-wrap { position: relative; }
.prn-multi-btn {
    width: 100%; display: flex; align-items: center; gap: 6px;
    padding: 4px 10px; font-size: .8rem; cursor: pointer; text-align: left;
    min-height: 31px; user-select: none; border-radius: 8px !important;
}
.prn-multi-btn.active { border-color: #f59e0b !important; color: #fcd34d !important; }
.prn-multi-panel {
    display: none; position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 260px; overflow-y: auto; z-index: 1050;
    background: #1a2535; border: 1px solid rgba(245,158,11,.35);
    border-radius: 10px; padding: 6px 4px; box-shadow: 0 8px 24px rgba(0,0,0,.5);
}
.prn-multi-panel.open { display: block; }
.prn-multi-opt {
    display: flex; align-items: center; gap: 8px; padding: 5px 10px;
    border-radius: 6px; cursor: pointer; font-size: .8rem; color: #cbd5e1;
    transition: background .15s; white-space: nowrap;
}
.prn-multi-opt:hover { background: rgba(245,158,11,.12); color: #f1f5f9; }
.prn-cb { accent-color: #f59e0b; width: 14px; height: 14px; cursor: pointer; flex-shrink: 0; }

/* ── Printing KPI cards ── */
.prn-kpi-row .stat-card {
    display: flex; align-items: center; gap: .55rem;
    padding: .75rem .9rem; overflow: hidden;
}
.prn-kpi-row .stat-icon {
    width: 38px; height: 38px; font-size: 1rem;
    flex-shrink: 0; border-radius: 10px;
}
.prn-kpi-row .stat-card > div:last-child {
    min-width: 0; flex: 1; overflow: hidden;
}
.prn-kpi-row .stat-value {
    font-size: .95rem; font-weight: 700; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.prn-kpi-row .stat-label {
    font-size: .68rem; color: var(--text-muted); margin-top: .1rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width: 576px) {
    .prn-kpi-row .stat-value { font-size: .85rem; }
    .prn-kpi-row .stat-icon  { width: 32px; height: 32px; font-size: .9rem; }
}
</style>

<script>
(function () {
    const btn    = document.getElementById('prnMachineBtn');
    const panel  = document.getElementById('prnMachinePanel');
    const label  = document.getElementById('prnMachineLabel');
    const allCb  = document.getElementById('prnMachineAll');
    const getCbs = () => [...document.querySelectorAll('.prn-machine-cb')];

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
        if (!document.getElementById('prnMachineWrap').contains(e.target))
            panel.classList.remove('open');
    });

    syncAllCb();
    updateLabel();
})();

// ── Date Range Picker (Flatpickr range mode) ──
(function initDatePickers() {
    function loadFlatpickr(cb) {
        if (window.flatpickr) { cb(); return; }
        const link = document.createElement('link');
        link.rel = 'stylesheet';
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
        const fromHidden = document.getElementById('prn_date_from');
        const toHidden   = document.getElementById('prn_date_to');
        const rangeInput = document.getElementById('prn_date_range');
        if (fromHidden.value && toHidden.value) rangeInput.value = fromHidden.value + ' — ' + toHidden.value;
        else if (fromHidden.value) rangeInput.value = fromHidden.value;
        flatpickr(rangeInput, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            allowInput: false,
            disableMobile: true,
            defaultDate: [fromHidden.value || null, toHidden.value || null].filter(Boolean),
            onChange: function(selectedDates) {
                fromHidden.value = selectedDates[0] ? selectedDates[0].toISOString().slice(0,10) : '';
                toHidden.value   = selectedDates[1] ? selectedDates[1].toISOString().slice(0,10) : '';
            }
        });
        document.querySelector('a[href="printing.php"]')?.addEventListener('click', function() {
            fromHidden.value = ''; toHidden.value = '';
        });
    });
})();
</script>