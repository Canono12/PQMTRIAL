<?php
$page_title = 'Weaving';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// ── Filters ──────────────────────────────────────────────────────────────────
$f_monthyear = $_GET['monthyear'] ?? '';   // YYYY-MM
$f_date_from = $_GET['date_from'] ?? '';   // YYYY-MM-DD
$f_date_to   = $_GET['date_to']   ?? '';   // YYYY-MM-DD
$f_machines  = array_filter(array_map('trim', (array)($_GET['machine'] ?? [])));
$f_shift     = $_GET['shift']     ?? '';
$f_line      = $_GET['line']      ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

// Date_Harvested is stored as YYYY-MM-DD -- no conversion needed
if ($f_monthyear) {
    $where[] = "DATE_FORMAT(Date_Harvested, '%Y-%m') = ?";
    $params[] = $f_monthyear;
    $types .= 's';
}
if ($f_date_from) {
    $where[] = "Date_Harvested >= ?";
    $params[] = $f_date_from;
    $types .= 's';
}
if ($f_date_to) {
    $where[] = "Date_Harvested <= ?";
    $params[] = $f_date_to;
    $types .= 's';
}
if (!empty($f_machines)) {
    $placeholders = implode(',', array_fill(0, count($f_machines), '?'));
    $where[] = "`Machine_NO.` IN ($placeholders)";
    foreach ($f_machines as $m) { $params[] = $m; $types .= 's'; }
}
if ($f_shift)   { $where[] = 'Shift = ?';          $params[] = $f_shift;   $types .= 's'; }
if ($f_line)    { $where[] = 'Line = ?';            $params[] = $f_line;    $types .= 's'; }
if (!empty($_GET['search'])) {
    $f_search = trim($_GET['search']);
    $like = '%' . $f_search . '%';
    $where[] = "(`Series_Number` LIKE ? OR `Loom_Batch_Code` LIKE ? OR `Line` LIKE ? OR `Shift` LIKE ? OR `Machine_NO.` LIKE ? OR `Remarks` LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssssss';
} else {
    $f_search = '';
}
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
// Output = Weight(Kg) | no waste column in DB yet
$kpi = qry($conn,
    "SELECT COUNT(*) AS recs,
            SUM(CAST(wkg AS DECIMAL(10,2))) AS tot_weight,
            SUM(rolls) AS tot_rolls,
            COUNT(DISTINCT mno) AS machines,
            COUNT(DISTINCT shift_) AS shifts,
            COUNT(DISTINCT line_) AS tot_lines
     FROM (
         SELECT `Weight(Kg)` AS wkg, `NO_of_roll` AS rolls,
                `Machine_NO.` AS mno, Shift AS shift_, Line AS line_
         FROM weaving WHERE $wsql
     ) t", $types, $params
)->fetch_assoc();
$has_data = ($kpi['recs'] ?? 0) > 0;

// ── Chart data helpers ────────────────────────────────────────────────────────
function chart_data($conn, $sql, $t, $p, $key_col, $val_col, $val_col2 = null) {
    $r = qry($conn, $sql, $t, $p);
    $out = ['labels' => [], 'v1' => [], 'v2' => []];
    while ($row = $r->fetch_assoc()) {
        $out['labels'][] = $row[$key_col];
        $out['v1'][]     = (float)$row[$val_col];
        if ($val_col2) $out['v2'][] = (float)$row[$val_col2];
    }
    return $out;
}

// Output per Machine — Weight(Kg) + Rolls
$c_machine = chart_data($conn,
    "SELECT mno AS k, SUM(CAST(wkg AS DECIMAL(10,2))) AS v1, SUM(rolls) AS v2
     FROM (SELECT `Machine_NO.` AS mno, `Weight(Kg)` AS wkg, `NO_of_roll` AS rolls FROM weaving WHERE $wsql) t
     GROUP BY mno ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1', 'v2');
$c_machine['labels'] = array_map(fn($x) => 'M'.$x, $c_machine['labels']);

// Output per Line — Weight(Kg) + Rolls
$c_line = chart_data($conn,
    "SELECT line_ AS k, SUM(CAST(wkg AS DECIMAL(10,2))) AS v1, SUM(rolls) AS v2
     FROM (SELECT Line AS line_, `Weight(Kg)` AS wkg, `NO_of_roll` AS rolls FROM weaving WHERE $wsql) t
     GROUP BY line_ ORDER BY line_",
    $types, $params, 'k', 'v1', 'v2');
$c_line['labels'] = array_map(fn($x) => 'Line '.$x, $c_line['labels']);

// Output per Shift — Weight(Kg) + Rolls
$c_shift = chart_data($conn,
    "SELECT shift_ AS k, SUM(CAST(wkg AS DECIMAL(10,2))) AS v1, SUM(rolls) AS v2
     FROM (SELECT Shift AS shift_, `Weight(Kg)` AS wkg, `NO_of_roll` AS rolls FROM weaving WHERE $wsql) t
     GROUP BY shift_ ORDER BY shift_",
    $types, $params, 'k', 'v1', 'v2');
$c_shift['labels'] = array_map(fn($x) => 'Shift '.$x, $c_shift['labels']);

// Output per Fabric Width-Denier — Weight(Kg) + Rolls
$c_fabric = chart_data($conn,
    "SELECT fabric AS k, SUM(CAST(wkg AS DECIMAL(10,2))) AS v1, SUM(rolls) AS v2
     FROM (SELECT CONCAT(`Width(mm)`,'mm-',Denier,'D') AS fabric, `Weight(Kg)` AS wkg, `NO_of_roll` AS rolls FROM weaving WHERE $wsql) t
     GROUP BY fabric ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1', 'v2');

// ── Filter options ────────────────────────────────────────────────────────────
$opt_months_raw = $conn->query("SELECT DISTINCT DATE_FORMAT(Date_Harvested, '%Y-%m') AS v, DATE_FORMAT(Date_Harvested, '%M %Y') AS label FROM weaving WHERE Date_Harvested IS NOT NULL AND Date_Harvested != '' ORDER BY v DESC");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    if ($om['v']) $opt_months[$om['v']] = $om['label'];
}

$opt_machines = $conn->query("SELECT DISTINCT `Machine_NO.` v FROM weaving ORDER BY `Machine_NO.`+0");
$opt_shifts   = $conn->query("SELECT DISTINCT Shift v FROM weaving ORDER BY Shift");
$opt_lines    = $conn->query("SELECT DISTINCT Line v FROM weaving ORDER BY Line");

// ── Table ─────────────────────────────────────────────────────────────────────
$rows = qry($conn,
    "SELECT `ID`, `Series_Number`, `Date_Harvested`, `Time_Harvested`, `Line`,
            `Machine_NO.`, `Shift`, `Loom_Batch_Code`, `Length(M)`, `Weight(Kg)`,
            `Width(mm)`, `Denier`, `NO_of_roll`, `Classification_of_roll`,
            `Fabric_GSM`, `Remarks`,
            `YARN_BATCHCODE_(WARP1)`, `YARN_BATCHCODE(WARP2)`, `YARN_BATCHCODE(WARP3)`,
            `YARN_BATCHCODE(WARP4)`, `YARN_BATCHCODE(WEFT1)`, `YARN_BATCHCODE(WEFT2)`,
            `YARN_BATCHCODE(WEFT3)`, `YARN_BATCHCODE(WEFT4)`, `YARN_BATCHCODE(WEFT5)`,
            `YARN_BATCHCODE(WEFT6)`, `YARN_BATCHCODE(WEFT8)`
     FROM weaving WHERE $wsql ORDER BY Date_Harvested DESC, Time_Harvested DESC, ID DESC LIMIT 500",
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
            <div class="page-heading"><i class="bi bi-diagram-3 me-2" style="color:#3b82f6"></i>Weaving Module</div>
            <div class="page-subheading mt-1">Output (kg) and fabric production analytics</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- View Toggle -->
            <div class="ext-toggle-wrap">
                <button type="button" class="ext-toggle-btn active" id="weavBtnCounts" onclick="weavSetView('counts')">
                    <i class="bi bi-boxes me-1"></i>Counts
                </button>
                <button type="button" class="ext-toggle-btn" id="weavBtnWeight" onclick="weavSetView('weight')">
                    <i class="bi bi-box-seam me-1"></i>Weight
                </button>
            </div>
            <span class="badge process-badge" style="background:#1e3a5f;border:1px solid #3b82f6;color:#93c5fd">
                <i class="bi bi-database me-1"></i>weaving
            </span>
            <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_weaving">
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
                <input type="hidden" name="date_from" id="weav_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                <input type="hidden" name="date_to"   id="weav_date_to"   value="<?= htmlspecialchars($f_date_to) ?>">
                <input type="text" id="weav_date_range"
                       class="form-control form-control-sm pqm-input"
                       placeholder="Start date — End date"
                       autocomplete="off" readonly
                       style="background:#1a3358!important;color:#f1f5f9!important;border:1px solid rgba(255,255,255,.1)!important;border-radius:8px!important;">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">MACHINE</label>
                <div class="weav-multi-wrap" id="machineDropWrap">
                    <button type="button" class="weav-multi-btn pqm-input" id="machineDropBtn">
                        <span id="machineDropLabel">
                            <?php if (!empty($f_machines)): ?>
                                <?= count($f_machines) === 1 ? 'Machine ' . htmlspecialchars($f_machines[0]) : count($f_machines) . ' machines selected' ?>
                            <?php else: ?>All Machines<?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-down" style="font-size:.7rem;margin-left:auto;opacity:.6"></i>
                    </button>
                    <div class="weav-multi-panel" id="machineDropPanel">
                        <label class="weav-multi-opt">
                            <input type="checkbox" id="machineSelectAll" class="weav-cb">
                            <span style="color:#94a3b8;font-style:italic">All Machines</span>
                        </label>
                        <div style="border-top:1px solid rgba(255,255,255,.07);margin:3px 0"></div>
                        <?php
                        $machines_list = [];
                        while ($o = $opt_machines->fetch_assoc()) $machines_list[] = $o['v'];
                        foreach ($machines_list as $mv):
                            $checked = in_array((string)$mv, array_map('strval', $f_machines)) ? 'checked' : '';
                        ?>
                        <label class="weav-multi-opt">
                            <input type="checkbox" name="machine[]" value="<?= htmlspecialchars($mv) ?>"
                                   class="weav-cb weav-machine-cb" <?= $checked ?>>
                            <span>Machine <?= htmlspecialchars($mv) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">SHIFT</label>
                <select name="shift" class="form-select form-select-sm pqm-input">
                    <option value="">All Shifts</option>
                    <?php while ($o = $opt_shifts->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_shift===$o['v']?'selected':'' ?>>
                        Shift <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">LINE</label>
                <select name="line" class="form-select form-select-sm pqm-input">
                    <option value="">All Lines</option>
                    <?php while ($o = $opt_lines->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_line===$o['v']?'selected':'' ?>>
                        Line <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="weaving.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <?php if (!$has_data): ?>
    <!-- Empty state -->
    <div class="pqm-card mb-4">
        <div class="d-flex flex-column align-items-center justify-content-center py-5"
             style="border:1px dashed rgba(59,130,246,.3);border-radius:10px;background:rgba(30,58,95,.12);">
            <i class="bi bi-diagram-3" style="font-size:3rem;color:#3b82f6;opacity:.4"></i>
            <div class="mt-3 fw-semibold" style="color:#94a3b8;font-size:1rem">No Weaving Data Yet</div>
            <div class="mt-1" style="color:#64748b;font-size:.85rem">Upload a CSV file to populate charts and records.</div>
            <a href="#" class="pqm-upload-trigger-btn mt-3" data-bs-toggle="modal" data-bs-target="#uploadModal_weaving">
                <i class="bi bi-file-earmark-excel"></i> Upload CSV File
            </a>
        </div>
    </div>
    <?php else: ?>
    <!-- KPI Cards — COUNTS VIEW -->
    <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 row-cols-xl-5 weav-kpi-row weav-view-counts">
        <?php
        $kpis_counts = [
            ['val' => number_format((int)$kpi['recs']),        'label' => 'Total Records',       'icon' => 'bi-collection',          'cls' => 'icon-teal'],
            ['val' => number_format((int)$kpi['tot_rolls']),   'label' => 'Total Rolls (count)',  'icon' => 'bi-box-seam',            'cls' => 'icon-green'],
            ['val' => (int)$kpi['machines'],                   'label' => 'Active Machines',     'icon' => 'bi-cpu',                 'cls' => 'icon-red'],
            ['val' => (int)$kpi['shifts'],                     'label' => 'Active Shifts',       'icon' => 'bi-clock',               'cls' => 'icon-purple'],
            ['val' => (int)$kpi['tot_lines'],                  'label' => 'Active Lines',        'icon' => 'bi-layout-three-columns','cls' => 'icon-blue'],
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

    <!-- KPI Cards — WEIGHT VIEW -->
    <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 row-cols-xl-5 weav-kpi-row weav-view-weight" style="display:none!important">
        <?php
        $kpis_weight = [
            ['val' => number_format((int)$kpi['recs']),                     'label' => 'Total Records',    'icon' => 'bi-collection',   'cls' => 'icon-teal'],
            ['val' => number_format((float)$kpi['tot_weight'], 1) . ' kg',  'label' => 'Total Output (kg)','icon' => 'bi-speedometer2', 'cls' => 'icon-blue'],
            ['val' => '—',                                                   'label' => 'Total Waste (kg)', 'icon' => 'bi-slash-circle', 'cls' => 'icon-amber', 'note' => 'No data yet'],
            ['val' => (int)$kpi['machines'],                                'label' => 'Active Machines',  'icon' => 'bi-cpu',          'cls' => 'icon-red'],
            ['val' => (int)$kpi['shifts'],                                  'label' => 'Active Shifts',    'icon' => 'bi-clock',        'cls' => 'icon-purple'],
        ];
        foreach ($kpis_weight as $k): ?>
        <div class="col">
            <div class="pqm-card stat-card">
                <div class="stat-icon <?= $k['cls'] ?>"><i class="<?= $k['icon'] ?>"></i></div>
                <div>
                    <div class="stat-value <?= isset($k['note']) ? 'text-secondary' : '' ?>"><?= $k['val'] ?></div>
                    <div class="stat-label"><?= $k['label'] ?></div>
                    <?php if (isset($k['note'])): ?>
                    <div style="font-size:.65rem;color:#64748b;margin-top:1px"><?= $k['note'] ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── COUNTS VIEW: Charts ── -->
    <div class="weav-view-counts">
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-8">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Rolls (count) per Machine</div>
                    <div class="chart-wrapper" style="height:270px">
                        <canvas id="cMachineC"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Rolls (count) per Line</div>
                    <div class="chart-wrapper" style="height:270px">
                        <canvas id="cLineC"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-5">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-bar-chart"></i> Rolls (count) per Shift</div>
                    <div class="chart-wrapper" style="height:255px">
                        <canvas id="cShiftC"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-7">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-grid-3x2-gap"></i> Rolls (count) per Fabric Width-Denier</div>
                    <div class="chart-wrapper" style="height:255px">
                        <canvas id="cFabricC"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── WEIGHT VIEW: Charts ── -->
    <div class="weav-view-weight" style="display:none">
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-8">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-bar-chart-fill" style="color:#f59e0b"></i> Output (kg) per Machine</div>
                    <div class="chart-wrapper" style="height:270px">
                        <canvas id="cMachineW"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-pie-chart-fill" style="color:#f59e0b"></i> Output (kg) per Line</div>
                    <div class="chart-wrapper" style="height:270px">
                        <canvas id="cLineW"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-5">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-bar-chart" style="color:#f59e0b"></i> Output (kg) per Shift</div>
                    <div class="chart-wrapper" style="height:255px">
                        <canvas id="cShiftW"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-7">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-grid-3x2-gap" style="color:#f59e0b"></i> Output (kg) per Fabric Width-Denier</div>
                    <div class="chart-wrapper" style="height:255px">
                        <canvas id="cFabricW"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Production Table -->
    <div class="pqm-card">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="section-title mb-0">
                <i class="bi bi-table"></i> Production Records
                <span class="text-secondary fw-normal ms-1" style="font-size:.78rem" id="weavRowCount"><?= number_format($kpi['recs']) ?> total</span>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div class="d-flex align-items-center gap-1" style="position:relative;">
                    <input type="text" id="tblSearch" class="form-control form-control-sm pqm-input"
                           placeholder="Search records..." style="max-width:220px;padding-right:2rem;"
                           value="<?= htmlspecialchars($f_search) ?>"
                           oninput="weavDoSearch(this.value)">
                    <button id="tblSearchClear" onclick="weavClearSearch()"
                            style="display:<?= $f_search ? 'flex' : 'none' ?>;position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;line-height:1;font-size:.85rem;"
                            title="Clear search"><i class="bi bi-x-circle-fill"></i></button>
                </div>
                <a href="weaving_export.php?<?= http_build_query($_GET) ?>"
                   class="btn btn-sm btn-success px-3 d-flex align-items-center gap-1" style="white-space:nowrap">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="pqm-table" id="wTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Series Number</th>
                        <th>Date Harvested</th>
                        <th>Time Harvested</th>
                        <th>Line</th>
                        <th>Machine No.</th>
                        <th>Shift</th>
                        <th>Loom Batch Code</th>
                        <th>Length (M)</th>
                        <th>Weight (Kg)</th>
                        <th>Width (mm)</th>
                        <th>Denier</th>
                        <th>No. of Roll</th>
                        <th>Classification</th>
                        <th>Fabric GSM</th>
                        <th>Remarks</th>
                        <th>Warp1</th>
                        <th>Warp2</th>
                        <th>Warp3</th>
                        <th>Warp4</th>
                        <th>Weft1</th>
                        <th>Weft2</th>
                        <th>Weft3</th>
                        <th>Weft4</th>
                        <th>Weft5</th>
                        <th>Weft6</th>
                        <th>Weft8</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $rows->fetch_assoc()):
                    $rem = trim($r['Remarks'] ?? '');
                    $rb  = ($rem === '' || strtolower($rem) === 'n/a') ? 'bg-secondary' : 'bg-warning text-dark';
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['ID'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Series_Number'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Date_Harvested'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Time_Harvested'] ?? '') ?></td>
                    <td><span class="badge" style="background:#1e3a5f;color:#93c5fd;border:1px solid #3b82f655"><?= htmlspecialchars($r['Line'] ?? '') ?></span></td>
                    <td><span class="badge bg-primary">M<?= htmlspecialchars($r['Machine_NO.'] ?? '') ?></span></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($r['Shift'] ?? '') ?></span></td>
                    <td><?= htmlspecialchars($r['Loom_Batch_Code'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Length(M)'] ?? '') ?></td>
                    <td class="fw-semibold" style="color:#38bdf8"><?= htmlspecialchars($r['Weight(Kg)'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Width(mm)'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Denier'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['NO_of_roll'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Classification_of_roll'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Fabric_GSM'] ?? '') ?></td>
                    <td><span class="badge <?= $rb ?> process-badge"><?= htmlspecialchars($rem ?: 'N/A') ?></span></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE_(WARP1)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WARP2)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WARP3)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WARP4)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WEFT1)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WEFT2)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WEFT3)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WEFT4)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WEFT5)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WEFT6)'] ?? '') ?></td>
                    <td style="font-size:.72rem;color:#94a3b8"><?= htmlspecialchars($r['YARN_BATCHCODE(WEFT8)'] ?? '') ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->
</div><!-- /app-layout -->

<?php endif; ?>

<?php
$upload_module='weaving';$upload_label='Weaving';
$upload_sample='ID | Series_Number | Date_Harvested | Time_Harvested | Line | Machine_NO. | Shift | Loom_Batch_Code | Length(M) | Weight(Kg) | Width(mm) | Denier | NO_of_roll | Classification_of_roll | Fabric_GSM | Remarks | YARN_BATCHCODE_(WARP1) | ...';
require_once __DIR__.'/../includes/upload_modal.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if ($has_data): ?>
<script>
const CM = <?= json_encode($c_machine) ?>;
const CL = <?= json_encode($c_line)    ?>;
const CS = <?= json_encode($c_shift)   ?>;
const CF = <?= json_encode($c_fabric)  ?>;
const DONUT_BG = ['#3b82f6','#0ea5e9','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#f97316','#84cc16','#ec4899'];

// ── COUNTS CHARTS (Rolls) ─────────────────────────────────────────────────────
new Chart(document.getElementById('cMachineC'), {
    type: 'bar',
    data: { labels: CM.labels, datasets: [ barDataset('Rolls (count)', CM.v2, PQM_COLORS.blue) ]},
    options: { responsive:true, maintainAspectRatio:false, animation:{ duration:800 },
        plugins:{ legend:{ position:'top' } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid } },
                 y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                     ticks:{ callback: v => v.toLocaleString() + ' rolls' } } } }
});
new Chart(document.getElementById('cLineC'), {
    type: 'doughnut',
    data: { labels: CL.labels, datasets:[{ data: CL.v2, backgroundColor: DONUT_BG, borderWidth:2, borderColor:'#162032', hoverOffset:8 }]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{ animateRotate:true, duration:900 },
        plugins:{ legend:{ position:'right', labels:{ boxWidth:12, padding:8 } },
                  tooltip:{ callbacks:{ label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' rolls' } } }, cutout:'60%' }
});
new Chart(document.getElementById('cShiftC'), {
    type: 'bar',
    data: { labels: CS.labels, datasets:[ barDataset('Rolls (count)', CS.v2, PQM_COLORS.green) ]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{ duration:700 },
        plugins:{ legend:{ position:'top' } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid } },
                 y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                     ticks:{ callback: v => v.toLocaleString() + ' rolls' } } } }
});
new Chart(document.getElementById('cFabricC'), {
    type: 'bar',
    data: { labels: CF.labels, datasets:[ barDataset('Rolls (count)', CF.v2, PQM_COLORS.purple) ]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, animation:{ duration:800 },
        plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label: ctx => ctx.parsed.x.toLocaleString() + ' rolls' } } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true, ticks:{ callback: v => v.toLocaleString() } },
                 y:{ grid:{ color:PQM_COLORS.grid } } } }
});

// ── WEIGHT CHARTS (kg) ────────────────────────────────────────────────────────
new Chart(document.getElementById('cMachineW'), {
    type: 'bar',
    data: { labels: CM.labels, datasets: [ barDataset('Output (kg)', CM.v1, '#f59e0b') ]},
    options: { responsive:true, maintainAspectRatio:false, animation:{ duration:800 },
        plugins:{ legend:{ position:'top' } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid } },
                 y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                     ticks:{ callback: v => v.toLocaleString() + ' kg' } } } }
});
new Chart(document.getElementById('cLineW'), {
    type: 'doughnut',
    data: { labels: CL.labels, datasets:[{ data: CL.v1, backgroundColor: DONUT_BG, borderWidth:2, borderColor:'#162032', hoverOffset:8 }]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{ animateRotate:true, duration:900 },
        plugins:{ legend:{ position:'right', labels:{ boxWidth:12, padding:8 } },
                  tooltip:{ callbacks:{ label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' kg' } } }, cutout:'60%' }
});
new Chart(document.getElementById('cShiftW'), {
    type: 'bar',
    data: { labels: CS.labels, datasets:[ barDataset('Output (kg)', CS.v1, '#f59e0b') ]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{ duration:700 },
        plugins:{ legend:{ position:'top' } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid } },
                 y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                     ticks:{ callback: v => v.toLocaleString() + ' kg' } } } }
});
new Chart(document.getElementById('cFabricW'), {
    type: 'bar',
    data: { labels: CF.labels, datasets:[ barDataset('Output (kg)', CF.v1, '#f59e0b') ]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, animation:{ duration:800 },
        plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label: ctx => ctx.parsed.x.toLocaleString() + ' kg' } } },
        scales:{ x:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true, ticks:{ callback: v => v.toLocaleString() + ' kg' } },
                 y:{ grid:{ color:PQM_COLORS.grid } } } }
});

// ── TOGGLE ────────────────────────────────────────────────────────────────────
function weavSetView(v) {
    document.querySelectorAll('.weav-view-counts').forEach(el => el.style.display = v === 'counts' ? '' : 'none');
    document.querySelectorAll('.weav-view-weight').forEach(el => el.style.display = v === 'weight' ? '' : 'none');
    document.getElementById('weavBtnCounts').classList.toggle('active', v === 'counts');
    document.getElementById('weavBtnWeight').classList.toggle('active', v === 'weight');
    localStorage.setItem('weavView', v);
}
(function(){ const v = localStorage.getItem('weavView'); if (v) weavSetView(v); })();

// Search — pure client-side filter, no page reload, no scroll jump
function weavDoSearch(q) {
    const term = q.trim().toLowerCase();
    let visibleCount = 0;
    document.querySelectorAll('#wTable tbody tr').forEach(tr => {
        const match = term === '' || tr.textContent.toLowerCase().includes(term);
        tr.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });
    // Show/hide clear button
    const clearBtn = document.getElementById('tblSearchClear');
    if (clearBtn) clearBtn.style.display = q.trim() ? 'flex' : 'none';
    // Update row count label
    const countEl = document.getElementById('weavRowCount');
    if (countEl) countEl.textContent = term ? visibleCount + ' result' + (visibleCount !== 1 ? 's' : '') : '<?= number_format($kpi["recs"]) ?> total';
}
function weavClearSearch() {
    const input = document.getElementById('tblSearch');
    input.value = '';
    weavDoSearch('');
    input.focus();
}
// Run search on load if there was a server-side search param
(function(){ const v = document.getElementById('tblSearch').value; if (v) weavDoSearch(v); })();
</script>

<style>
.icon-purple { background: rgba(168,85,247,.18); color: #c084fc; }
/* Toggle button (shared with extrusion style) */
.ext-toggle-wrap {
    display: flex; background: rgba(15,23,42,.6);
    border: 1px solid rgba(148,163,184,.15); border-radius: 10px; padding: 3px; gap: 2px;
}
.ext-toggle-btn {
    padding: 5px 18px; font-size: .8rem; font-weight: 600; border: none; cursor: pointer;
    border-radius: 8px; background: transparent; color: #64748b;
    transition: all .2s; letter-spacing: .02em;
}
.ext-toggle-btn.active { background: #1e3a5f; color: #93c5fd; box-shadow: 0 2px 8px rgba(59,130,246,.25); }
.ext-toggle-btn:hover:not(.active) { color: #94a3b8; background: rgba(255,255,255,.04); }
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

/* Multi-select machine dropdown */
.weav-multi-wrap { position: relative; }
.weav-multi-btn {
    width: 100%; display: flex; align-items: center; gap: 6px;
    padding: 4px 10px; font-size: .8rem; cursor: pointer; text-align: left;
    background: #1a3358 !important; border: 1px solid rgba(255,255,255,.1) !important;
    color: #f1f5f9 !important; border-radius: 8px !important;
    min-height: 31px; user-select: none;
}
.weav-multi-btn:focus { border-color: #3b82f6 !important; box-shadow: 0 0 0 3px rgba(59,130,246,.2) !important; outline: none; }
.weav-multi-btn.active { border-color: #3b82f6 !important; color: #93c5fd !important; }
.weav-multi-panel {
    display: none; position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 260px; overflow-y: auto; z-index: 1050;
    background: #1a3358; border: 1px solid rgba(59,130,246,.35);
    border-radius: 10px; padding: 6px 4px; box-shadow: 0 8px 24px rgba(0,0,0,.45);
}
.weav-multi-panel.open { display: block; }
.weav-multi-opt {
    display: flex; align-items: center; gap: 8px; padding: 5px 10px;
    border-radius: 6px; cursor: pointer; font-size: .8rem; color: #cbd5e1;
    transition: background .15s; white-space: nowrap;
}
.weav-multi-opt:hover { background: rgba(59,130,246,.15); color: #f1f5f9; }
.weav-cb { accent-color: #3b82f6; width: 14px; height: 14px; cursor: pointer; flex-shrink: 0; }

/* ── Weaving KPI cards ── */
.weav-kpi-row .stat-card {
    display: flex; align-items: center; gap: .55rem;
    padding: .75rem .9rem; overflow: hidden;
}
.weav-kpi-row .stat-icon {
    width: 38px; height: 38px; font-size: 1rem;
    flex-shrink: 0; border-radius: 10px;
}
.weav-kpi-row .stat-card > div:last-child { min-width: 0; flex: 1; overflow: hidden; }
.weav-kpi-row .stat-value {
    font-size: .95rem; font-weight: 700; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.weav-kpi-row .stat-label {
    font-size: .68rem; color: var(--text-muted); margin-top: .1rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width: 576px) {
    .weav-kpi-row .stat-value { font-size: .85rem; }
    .weav-kpi-row .stat-icon  { width: 32px; height: 32px; font-size: .9rem; }
}
</style>

<script>
(function () {
    const btn    = document.getElementById('machineDropBtn');
    const panel  = document.getElementById('machineDropPanel');
    const label  = document.getElementById('machineDropLabel');
    const allCb  = document.getElementById('machineSelectAll');
    const getCbs = () => [...document.querySelectorAll('.weav-machine-cb')];

    function updateLabel() {
        const checked = getCbs().filter(c => c.checked);
        if (checked.length === 0) {
            label.textContent = 'All Machines';
            btn.classList.remove('active');
        } else if (checked.length === 1) {
            label.textContent = 'Machine ' + checked[0].value;
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
        if (!document.getElementById('machineDropWrap').contains(e.target))
            panel.classList.remove('open');
    });

    syncAllCb();
    updateLabel();
})();

// ── Date Range Picker (Flatpickr range mode) ──
(function initDatePickers() {
    function loadFlatpickr(cb) {
        if (window.flatpickr) { cb(); return; }
        const link = document.createElement('link'); link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css';
        document.head.appendChild(link);
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/flatpickr';
        script.onload = cb; document.head.appendChild(script);
    }
    loadFlatpickr(function () {
        const style = document.createElement('style');
        style.textContent = `
            .flatpickr-calendar {
                background: #1a3358 !important;
                border: 1px solid rgba(34,197,94,.35) !important;
                box-shadow: 0 8px 32px rgba(0,0,0,.5) !important;
                border-radius: 10px !important; font-family: inherit !important;
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
        const fromHidden = document.getElementById('weav_date_from');
        const toHidden   = document.getElementById('weav_date_to');
        const rangeInput = document.getElementById('weav_date_range');
        if (fromHidden.value && toHidden.value) rangeInput.value = fromHidden.value + ' — ' + toHidden.value;
        else if (fromHidden.value) rangeInput.value = fromHidden.value;
        flatpickr(rangeInput, {
            mode: 'range', dateFormat: 'Y-m-d', allowInput: false, disableMobile: true,
            defaultDate: [fromHidden.value || null, toHidden.value || null].filter(Boolean),
            onChange: function(selectedDates) {
                fromHidden.value = selectedDates[0] ? selectedDates[0].toISOString().slice(0,10) : '';
                toHidden.value   = selectedDates[1] ? selectedDates[1].toISOString().slice(0,10) : '';
            }
        });
        document.querySelector('a[href="weaving.php"]')?.addEventListener('click', function() {
            fromHidden.value = ''; toHidden.value = '';
        });
    });
})();
</script>
<?php endif; ?>