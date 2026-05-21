<?php
$page_title = 'Lamination';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$f_monthyear = $_GET['monthyear'] ?? '';
$f_date_from = $_GET['date_from'] ?? '';
$f_date_to   = $_GET['date_to']   ?? '';
$f_machines  = array_filter(array_map('trim', (array)($_GET['machine'] ?? [])));
$f_rm        = $_GET['rm']        ?? '';

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
$f_shift     = $_GET['shift']      ?? '';
$f_rm        = $_GET['rm']         ?? '';

if (!empty($f_machines)) {
    $placeholders = implode(',', array_fill(0, count($f_machines), '?'));
    $where[] = "machine_number IN ($placeholders)";
    foreach ($f_machines as $m) { $params[] = $m; $types .= 's'; }
}
if ($f_rm) {
    // RM filter covers PP and CC columns
    $where[] = '(PP LIKE ? OR CALCIUM_CARBONATE_1 LIKE ? OR CALCIUM_CARBONATE_2 LIKE ?)';
    $params[] = "%$f_rm%"; $params[] = "%$f_rm%"; $params[] = "%$f_rm%";
    $types .= 'sss';
}
$wsql = implode(' AND ', $where);

// Shift is derived from SHIFT_PRODUCTION_PERSONNEL; no direct shift column in lamination.
// Use machine_number as proxy — machines LXA / LXB are the known lamination machines.

function lqry($conn, $sql, $t = '', $p = []) {
    $s = $conn->prepare($sql);
    if ($s === false) die('<pre style="color:red">SQL Error: ' . htmlspecialchars($conn->error) . "\n" . htmlspecialchars($sql) . '</pre>');
    if ($t && $p) $s->bind_param($t, ...$p);
    $s->execute();
    return $s->get_result();
}

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi = lqry($conn,
    "SELECT COUNT(*) AS recs,
            SUM(CAST(ROLL_WEIGHT_kilograms AS DECIMAL(10,3))) AS tot_output,
            SUM(CAST(INPUT_WASTE_grams     AS DECIMAL(12,3))) AS tot_input_waste,
            SUM(CAST(UNLAMINATED_WASTE_grams AS DECIMAL(12,3))) AS tot_unlam_waste,
            SUM(CAST(OUTPUT_WASTE_grams    AS DECIMAL(12,3))) AS tot_output_waste,
            COUNT(DISTINCT machine_number) AS machines,
            SUM(CAST(ROLL_LENGTH_meters    AS DECIMAL(12,2))) AS tot_length,
            AVG(CAST(FABRIC_QUALITY_gsm    AS DECIMAL(8,2)))  AS avg_gsm
     FROM (
         SELECT ROLL_WEIGHT_kilograms, INPUT_WASTE_grams, UNLAMINATED_WASTE_grams,
                OUTPUT_WASTE_grams, machine_number, ROLL_LENGTH_meters, FABRIC_QUALITY_gsm
         FROM laminationimport_fixed WHERE $wsql
     ) t",
    $types, $params
)->fetch_assoc();

// Total waste (all types) in grams → kg
$tot_waste_kg = (((float)($kpi['tot_input_waste'] ?? 0))
               + ((float)($kpi['tot_unlam_waste'] ?? 0))
               + ((float)($kpi['tot_output_waste'] ?? 0))) / 1000;

$has_data = ($kpi['recs'] ?? 0) > 0;

// ── Chart helpers ─────────────────────────────────────────────────────────────
function lam_chart($conn, $sql, $t, $p, $key_col, $out_col, $waste_col = null, $len_col = null) {
    $r   = lqry($conn, $sql, $t, $p);
    $out = ['labels' => [], 'output' => [], 'waste' => [], 'length' => []];
    while ($row = $r->fetch_assoc()) {
        $out['labels'][] = $row[$key_col];
        $out['output'][] = round((float)$row[$out_col], 3);
        $out['waste'][]  = $waste_col ? round((float)$row[$waste_col] / 1000, 3) : 0;
        $out['length'][] = $len_col   ? round((float)$row[$len_col], 2)          : 0;
    }
    return $out;
}

// 1. Output/Waste per Machine
$c_machine = lam_chart($conn,
    "SELECT machine_number AS k,
            SUM(CAST(ROLL_WEIGHT_kilograms AS DECIMAL(10,3))) AS v_out,
            SUM(CAST(INPUT_WASTE_grams AS DECIMAL(12,3)) +
                CAST(UNLAMINATED_WASTE_grams AS DECIMAL(12,3)) +
                CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3))) AS v_waste,
            SUM(CAST(ROLL_LENGTH_meters AS DECIMAL(12,2))) AS v_len
     FROM laminationimport_fixed WHERE $wsql
     GROUP BY machine_number ORDER BY v_out DESC LIMIT 15",
    $types, $params, 'k', 'v_out', 'v_waste', 'v_len');

// 2. Output/Waste per Fabric Width-Denier
$c_fabric = lam_chart($conn,
    "SELECT FABRIC_WIDTH_AND_TAPE_DENIER AS k,
            SUM(CAST(ROLL_WEIGHT_kilograms AS DECIMAL(10,3))) AS v_out,
            SUM(CAST(INPUT_WASTE_grams AS DECIMAL(12,3)) +
                CAST(UNLAMINATED_WASTE_grams AS DECIMAL(12,3)) +
                CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3))) AS v_waste,
            SUM(CAST(ROLL_LENGTH_meters AS DECIMAL(12,2))) AS v_len
     FROM laminationimport_fixed WHERE $wsql
     GROUP BY FABRIC_WIDTH_AND_TAPE_DENIER ORDER BY v_out DESC LIMIT 10",
    $types, $params, 'k', 'v_out', 'v_waste', 'v_len');

// 3. Output/Waste per RM — PP component
$c_rm_pp = lam_chart($conn,
    "SELECT PP AS k,
            SUM(CAST(ROLL_WEIGHT_kilograms AS DECIMAL(10,3))) AS v_out,
            SUM(CAST(INPUT_WASTE_grams AS DECIMAL(12,3)) +
                CAST(UNLAMINATED_WASTE_grams AS DECIMAL(12,3)) +
                CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3))) AS v_waste,
            SUM(CAST(ROLL_LENGTH_meters AS DECIMAL(12,2))) AS v_len
     FROM laminationimport_fixed WHERE $wsql AND PP IS NOT NULL AND PP != ''
     GROUP BY PP ORDER BY v_out DESC LIMIT 10",
    $types, $params, 'k', 'v_out', 'v_waste', 'v_len');

// 4. Waste breakdown (input / unlaminated / output) — doughnut
$c_waste_breakdown = lqry($conn,
    "SELECT SUM(CAST(INPUT_WASTE_grams AS DECIMAL(12,3)))       AS iw,
            SUM(CAST(UNLAMINATED_WASTE_grams AS DECIMAL(12,3))) AS uw,
            SUM(CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3)))      AS ow
     FROM laminationimport_fixed WHERE $wsql",
    $types, $params
)->fetch_assoc();

// 5. Output/Waste per Fabric Type (line chart trend over date)
$c_trend = lqry($conn,
    "SELECT DATE_STARTED AS dt,
            SUM(CAST(ROLL_WEIGHT_kilograms AS DECIMAL(10,3))) AS v_out,
            SUM(CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3)) / 1000) AS v_waste,
            SUM(CAST(ROLL_LENGTH_meters AS DECIMAL(12,2))) AS v_len
     FROM laminationimport_fixed WHERE $wsql AND DATE_STARTED IS NOT NULL AND DATE_STARTED != ''
     GROUP BY DATE_STARTED ORDER BY DATE_STARTED ASC LIMIT 30",
    $types, $params);
$trend_data = ['labels' => [], 'output' => [], 'waste' => [], 'length' => []];
while ($row = $c_trend->fetch_assoc()) {
    $trend_data['labels'][] = $row['dt'];
    $trend_data['output'][] = round((float)$row['v_out'], 2);
    $trend_data['waste'][]  = round((float)$row['v_waste'], 3);
    $trend_data['length'][] = round((float)$row['v_len'], 2);
}

// ── Filter options ────────────────────────────────────────────────────────────
$opt_months_raw = $conn->query("SELECT DISTINCT DATE_FORMAT(DATE_COMPLETED,'%Y-%m') AS v, DATE_FORMAT(DATE_COMPLETED,'%M %Y') AS label FROM laminationimport_fixed WHERE DATE_COMPLETED IS NOT NULL AND DATE_COMPLETED != '' AND DATE_COMPLETED != '0000-00-00' ORDER BY v DESC");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    if ($om['v']) $opt_months[$om['v']] = $om['label'];
}
$opt_machines = $conn->query("SELECT DISTINCT machine_number v FROM laminationimport_fixed WHERE machine_number IS NOT NULL AND machine_number != '' ORDER BY machine_number");
$opt_pp       = $conn->query("SELECT DISTINCT PP v FROM laminationimport_fixed WHERE PP IS NOT NULL AND PP != '' ORDER BY PP");

// ── Table rows ────────────────────────────────────────────────────────────────
$rows = lqry($conn,
    "SELECT Id, FINAL_BATCH_CODE, DATE_STARTED, DATE_COMPLETED, machine_number,
            FABRIC_WIDTH_AND_TAPE_DENIER, FABRIC_TYPE, FABRIC_QUALITY_gsm,
            ROLL_LENGTH_meters, ROLL_WEIGHT_kilograms,
            INPUT_WASTE_grams, UNLAMINATED_WASTE_grams, OUTPUT_WASTE_grams,
            PP, PP_BATCH_CODE, PP_PERCENTAGE,
            CALCIUM_CARBONATE_1, `CALCIUM-CARBONATE_1_BATCH_CODE`, CALCIUM_CARBONATE_1_PERCENTAGE,
            CALCIUM_CARBONATE_2, CALCIUM_CARBONATE_2_BATCH_CODE, CALCIUM_CARBONATE_2_PERCENTAGE,
            SHIFT_PRODUCTION_PERSONNEL, LEAD_OPERATOR, IPQC_TECHNICIAN,
            PRODUCTION_REMARKS
     FROM laminationimport_fixed WHERE $wsql ORDER BY DATE_STARTED DESC, STARTED DESC, Id DESC LIMIT 50",
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
            <div class="page-heading"><i class="bi bi-layers me-2" style="color:#22d3ee"></i>Lamination Module</div>
            <div class="page-subheading mt-1">Output (kg), waste analytics &amp; RM component breakdown</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- View Toggle -->
            <div class="ext-toggle-wrap">
                <button type="button" class="ext-toggle-btn active" id="lamBtnCounts" onclick="lamSetView('counts')">
                    <i class="bi bi-rulers me-1"></i>Length (m)
                </button>
                <button type="button" class="ext-toggle-btn" id="lamBtnWeight" onclick="lamSetView('weight')">
                    <i class="bi bi-box-seam me-1"></i>Weight (kg)
                </button>
            </div>
            <span class="badge process-badge" style="background:#0e3040;border:1px solid #22d3ee;color:#67e8f9">
                <i class="bi bi-database me-1"></i>laminationimport_fixed
            </span>
            <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_lamination">
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
                <select name="monthyear" class="form-select form-select-sm pqm-input lam-input">
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
                <input type="hidden" name="date_from" id="lam_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                <input type="hidden" name="date_to"   id="lam_date_to"   value="<?= htmlspecialchars($f_date_to) ?>">
                <input type="text" id="lam_date_range"
                       class="form-control form-control-sm pqm-input"
                       placeholder="Start date — End date"
                       autocomplete="off" readonly
                       style="background:#1a3358!important;color:#f1f5f9!important;border:1px solid rgba(255,255,255,.1)!important;border-radius:8px!important;">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">MACHINE</label>
                <div class="lam-multi-wrap" id="lamMachineWrap">
                    <button type="button" class="lam-multi-btn lam-input" id="lamMachineBtn">
                        <span id="lamMachineLabel">
                            <?php if (!empty($f_machines)): ?>
                                <?= count($f_machines) === 1 ? htmlspecialchars($f_machines[0]) : count($f_machines) . ' machines selected' ?>
                            <?php else: ?>All Machines<?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-down" style="font-size:.7rem;margin-left:auto;opacity:.6"></i>
                    </button>
                    <div class="lam-multi-panel" id="lamMachinePanel">
                        <label class="lam-multi-opt">
                            <input type="checkbox" id="lamMachineAll" class="lam-cb">
                            <span style="color:#94a3b8;font-style:italic">All Machines</span>
                        </label>
                        <div style="border-top:1px solid rgba(255,255,255,.07);margin:3px 0"></div>
                        <?php
                        $machines_list = [];
                        while ($o = $opt_machines->fetch_assoc()) $machines_list[] = $o['v'];
                        foreach ($machines_list as $mv):
                            $checked = in_array((string)$mv, array_map('strval', $f_machines)) ? 'checked' : '';
                        ?>
                        <label class="lam-multi-opt">
                            <input type="checkbox" name="machine[]" value="<?= htmlspecialchars($mv) ?>"
                                   class="lam-cb lam-machine-cb" <?= $checked ?>>
                            <span><?= htmlspecialchars($mv) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">RM COMPONENT (PP)</label>
                <select name="rm" class="form-select form-select-sm pqm-input lam-input">
                    <option value="">All RM</option>
                    <?php while ($o = $opt_pp->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_rm===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="lamination.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <?php if (!$has_data): ?>
    <!-- Empty state -->
    <div class="pqm-card">
        <div class="d-flex flex-column align-items-center justify-content-center py-5"
             style="border:1px dashed rgba(34,211,238,.3);border-radius:10px;background:rgba(14,48,64,.18);">
            <i class="bi bi-layers" style="font-size:3rem;color:#22d3ee;opacity:.4"></i>
            <div class="mt-3 fw-semibold" style="color:#94a3b8;font-size:1rem">No Lamination Data Yet</div>
            <div class="mt-1" style="color:#64748b;font-size:.85rem">Upload an Excel file to populate charts and records.</div>
            <a href="#" class="pqm-upload-trigger-btn mt-3" data-bs-toggle="modal" data-bs-target="#uploadModal_lamination">
                <i class="bi bi-file-earmark-excel"></i> Upload Excel File
            </a>
        </div>
    </div>

    <?php else: ?>

    <!-- KPI Cards — LENGTH (COUNTS) VIEW -->
    <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 row-cols-xl-5 lam-kpi-row lam-view-counts">
        <?php
        $kpis_counts = [
            ['val' => number_format((int)($kpi['recs'] ?? 0)),             'label' => 'Total Records',    'icon' => 'bi-collection',    'cls' => 'icon-teal'],
            ['val' => number_format((float)($kpi['tot_length'] ?? 0), 1) . ' m', 'label' => 'Total Length (m)', 'icon' => 'bi-rulers',   'cls' => 'icon-green'],
            ['val' => (int)($kpi['machines'] ?? 0),                        'label' => 'Active Machines',  'icon' => 'bi-cpu',           'cls' => 'icon-amber'],
            ['val' => number_format((float)($kpi['avg_gsm'] ?? 0), 2),    'label' => 'Avg Fabric GSM',   'icon' => 'bi-bar-chart-line','cls' => 'icon-purple'],
            ['val' => number_format($tot_waste_kg, 3) . ' kg',             'label' => 'Total Waste (kg)', 'icon' => 'bi-trash3',        'cls' => 'icon-red'],
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
    <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 row-cols-xl-5 lam-kpi-row lam-view-weight" style="display:none!important">
        <?php
        $kpis_weight = [
            ['val' => number_format((int)($kpi['recs'] ?? 0)),             'label' => 'Total Records',    'icon' => 'bi-collection',    'cls' => 'icon-teal'],
            ['val' => number_format((float)($kpi['tot_output'] ?? 0), 1) . ' kg', 'label' => 'Total Output (kg)', 'icon' => 'bi-speedometer2', 'cls' => 'icon-cyan'],
            ['val' => number_format($tot_waste_kg, 3) . ' kg',             'label' => 'Total Waste (kg)', 'icon' => 'bi-trash3',        'cls' => 'icon-red'],
            ['val' => (int)($kpi['machines'] ?? 0),                        'label' => 'Active Machines',  'icon' => 'bi-cpu',           'cls' => 'icon-amber'],
            ['val' => number_format((float)($kpi['avg_gsm'] ?? 0), 2),    'label' => 'Avg Fabric GSM',   'icon' => 'bi-bar-chart-line','cls' => 'icon-purple'],
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

    <!-- ── LENGTH (COUNTS) VIEW Charts ── -->
    <div class="lam-view-counts">
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-8">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-bar-chart-fill" style="color:#4ade80"></i> Length (m) per Machine</div>
                    <div class="chart-wrapper" style="height:275px"><canvas id="cMachineC"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-pie-chart-fill" style="color:#f87171"></i> Waste Breakdown</div>
                    <div class="chart-wrapper" style="height:275px"><canvas id="cWasteBreakC"></canvas></div>
                </div>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-grid-3x2-gap" style="color:#a78bfa"></i> Length (m) per Fabric Width-Denier</div>
                    <div class="chart-wrapper" style="height:260px"><canvas id="cFabricC"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-box-seam" style="color:#4ade80"></i> Length (m) per RM Component (PP)</div>
                    <div class="chart-wrapper" style="height:260px"><canvas id="cRmPPC"></canvas></div>
                </div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="pqm-card">
                    <div class="section-title"><i class="bi bi-graph-up" style="color:#fb923c"></i> Daily Length (m) Trend</div>
                    <div class="chart-wrapper" style="height:240px"><canvas id="cTrendC"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── WEIGHT VIEW Charts ── -->
    <div class="lam-view-weight" style="display:none">
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-8">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-bar-chart-fill" style="color:#22d3ee"></i> Output &amp; Waste per Machine (kg)</div>
                    <div class="chart-wrapper" style="height:275px"><canvas id="cMachineW"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-pie-chart-fill" style="color:#f87171"></i> Waste Breakdown (kg)</div>
                    <div class="chart-wrapper" style="height:275px"><canvas id="cWasteBreakW"></canvas></div>
                </div>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-grid-3x2-gap" style="color:#a78bfa"></i> Output &amp; Waste per Fabric Width-Denier (kg)</div>
                    <div class="chart-wrapper" style="height:260px"><canvas id="cFabricW"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="pqm-card h-100">
                    <div class="section-title"><i class="bi bi-box-seam" style="color:#4ade80"></i> Output &amp; Waste per RM Component (kg)</div>
                    <div class="chart-wrapper" style="height:260px"><canvas id="cRmPPW"></canvas></div>
                </div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="pqm-card">
                    <div class="section-title"><i class="bi bi-graph-up" style="color:#fb923c"></i> Daily Output &amp; Output Waste Trend (kg)</div>
                    <div class="chart-wrapper" style="height:240px"><canvas id="cTrendW"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Production Table -->
    <div class="pqm-card">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="chart-title mb-0">
                Production Records
                <span class="badge ms-2" style="background:rgba(34,211,238,.15);color:#67e8f9;font-size:.75rem;" id="lamRowCount">
                    <?= number_format($kpi['recs']) ?> rows
                </span>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div style="position:relative;">
                    <input type="text" id="tblSearch" class="form-control form-control-sm pqm-input lam-input"
                           placeholder="Search table…" style="max-width:220px;padding-right:2rem;">
                    <button id="tblSearchClear" onclick="lamClearSearch()"
                            style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;line-height:1;font-size:.85rem;"
                            title="Clear search"><i class="bi bi-x-circle-fill"></i></button>
                </div>
                <a href="laminationexport.php?<?= http_build_query($_GET) ?>"
                   class="btn btn-sm d-flex align-items-center gap-1 px-3"
                   style="background:#166534;color:#fff;border:none;white-space:nowrap">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="table-responsive" style="max-height:480px;overflow-y:auto;">
            <table class="pqm-table" id="lamTable">
                <thead>
                    <tr>
                        <th style="white-space:nowrap">Date Started</th>
                        <th style="white-space:nowrap">Date Completed</th>
                        <th style="white-space:nowrap">Batch Code</th>
                        <th style="white-space:nowrap">Machine</th>
                        <th style="white-space:nowrap">Fabric W-D</th>
                        <th style="white-space:nowrap">Type</th>
                        <th style="white-space:nowrap">GSM</th>
                        <th style="white-space:nowrap">Length (m)</th>
                        <th style="white-space:nowrap">Output (kg)</th>
                        <th style="white-space:nowrap">Input Waste (g)</th>
                        <th style="white-space:nowrap">Unlam Waste (g)</th>
                        <th style="white-space:nowrap">Output Waste (g)</th>
                        <th style="white-space:nowrap">PP</th>
                        <th style="white-space:nowrap">PP Batch</th>
                        <th style="white-space:nowrap">PP %</th>
                        <th style="white-space:nowrap">CC1</th>
                        <th style="white-space:nowrap">CC1 Batch</th>
                        <th style="white-space:nowrap">CC1 %</th>
                        <th style="white-space:nowrap">CC2</th>
                        <th style="white-space:nowrap">CC2 Batch</th>
                        <th style="white-space:nowrap">CC2 %</th>
                        <th style="white-space:nowrap">Shift Personnel</th>
                        <th style="white-space:nowrap">Lead Operator</th>
                        <th style="white-space:nowrap">IPQC</th>
                        <th style="white-space:nowrap">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $rows->fetch_assoc()):
                    $rem  = trim($r['PRODUCTION_REMARKS'] ?? '');
                    $rb   = ($rem === '' || strtolower($rem) === 'n/a' || strtolower($rem) === 'normal' || strtolower($rem) === 'no') ? 'bg-secondary' : 'bg-warning text-dark';
                    $dash = '—';
                ?>
                <tr>
                    <td style="white-space:nowrap"><?= htmlspecialchars($r['DATE_STARTED'] ?? '') ?></td>
                    <td style="white-space:nowrap"><?= htmlspecialchars($r['DATE_COMPLETED'] ?? '') ?></td>
                    <td><code style="color:#67e8f9;font-size:.72rem;white-space:nowrap"><?= htmlspecialchars($r['FINAL_BATCH_CODE'] ?? '') ?></code></td>
                    <td><span class="badge" style="background:#0e3040;color:#22d3ee;border:1px solid #22d3ee55"><?= htmlspecialchars($r['machine_number'] ?? '') ?></span></td>
                    <td><code style="color:#a78bfa;font-size:.72rem;white-space:nowrap"><?= htmlspecialchars($r['FABRIC_WIDTH_AND_TAPE_DENIER'] ?? '') ?></code></td>
                    <td style="font-size:.75rem"><?= htmlspecialchars($r['FABRIC_TYPE'] ?? $dash) ?></td>
                    <td style="color:#94a3b8;font-size:.75rem"><?= $r['FABRIC_QUALITY_gsm'] !== null ? number_format((float)$r['FABRIC_QUALITY_gsm'], 1) : $dash ?></td>
                    <td style="color:#4ade80;font-size:.8rem"><?= number_format((float)($r['ROLL_LENGTH_meters'] ?? 0)) ?></td>
                    <td class="fw-semibold" style="color:#22d3ee;white-space:nowrap"><?= number_format((float)($r['ROLL_WEIGHT_kilograms'] ?? 0), 1) ?></td>
                    <td style="color:#f87171"><?= number_format((float)($r['INPUT_WASTE_grams'] ?? 0), 1) ?></td>
                    <td style="color:#fb923c"><?= number_format((float)($r['UNLAMINATED_WASTE_grams'] ?? 0), 1) ?></td>
                    <td style="color:#fbbf24"><?= number_format((float)($r['OUTPUT_WASTE_grams'] ?? 0), 1) ?></td>
                    <td style="font-size:.75rem;white-space:nowrap"><?= htmlspecialchars($r['PP'] ?? $dash) ?></td>
                    <td style="font-size:.72rem;color:#94a3b8;white-space:nowrap"><?= htmlspecialchars($r['PP_BATCH_CODE'] ?? $dash) ?></td>
                    <td style="font-size:.75rem;text-align:center"><?= htmlspecialchars($r['PP_PERCENTAGE'] ?? $dash) ?><?= isset($r['PP_PERCENTAGE']) && $r['PP_PERCENTAGE'] !== null ? '%' : '' ?></td>
                    <td style="font-size:.75rem;white-space:nowrap"><?= htmlspecialchars($r['CALCIUM_CARBONATE_1'] ?? $dash) ?></td>
                    <td style="font-size:.72rem;color:#94a3b8;white-space:nowrap"><?= htmlspecialchars($r['CALCIUM-CARBONATE_1_BATCH_CODE'] ?? $dash) ?></td>
                    <td style="font-size:.75rem;text-align:center"><?= htmlspecialchars($r['CALCIUM_CARBONATE_1_PERCENTAGE'] ?? $dash) ?><?= isset($r['CALCIUM_CARBONATE_1_PERCENTAGE']) && $r['CALCIUM_CARBONATE_1_PERCENTAGE'] !== null && $r['CALCIUM_CARBONATE_1_PERCENTAGE'] !== '' && $r['CALCIUM_CARBONATE_1_PERCENTAGE'] !== 'N/A' ? '%' : '' ?></td>
                    <td style="font-size:.75rem;white-space:nowrap"><?= htmlspecialchars($r['CALCIUM_CARBONATE_2'] ?? $dash) ?></td>
                    <td style="font-size:.72rem;color:#94a3b8;white-space:nowrap"><?= htmlspecialchars($r['CALCIUM_CARBONATE_2_BATCH_CODE'] ?? $dash) ?></td>
                    <td style="font-size:.75rem;text-align:center"><?= htmlspecialchars($r['CALCIUM_CARBONATE_2_PERCENTAGE'] ?? $dash) ?><?= isset($r['CALCIUM_CARBONATE_2_PERCENTAGE']) && $r['CALCIUM_CARBONATE_2_PERCENTAGE'] !== null && $r['CALCIUM_CARBONATE_2_PERCENTAGE'] !== '' && $r['CALCIUM_CARBONATE_2_PERCENTAGE'] !== 'N/A' ? '%' : '' ?></td>
                    <td style="font-size:.72rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($r['SHIFT_PRODUCTION_PERSONNEL'] ?? '') ?>"><?= htmlspecialchars($r['SHIFT_PRODUCTION_PERSONNEL'] ?? $dash) ?></td>
                    <td style="font-size:.75rem;white-space:nowrap"><?= htmlspecialchars($r['LEAD_OPERATOR'] ?? $dash) ?></td>
                    <td style="font-size:.72rem;white-space:nowrap"><?= htmlspecialchars($r['IPQC_TECHNICIAN'] ?? $dash) ?></td>
                    <td><span class="badge <?= $rb ?> process-badge" title="<?= htmlspecialchars($rem) ?>"><?= htmlspecialchars($rem ?: 'N/A') ?></span></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

</div><!-- /main-content -->
<?php
$upload_module='lamination';$upload_label='Lamination';
$upload_sample='FINAL_BATCH_CODE | machine_number | DATE_STARTED | ROLL_WEIGHT_kilograms | INPUT_WASTE_grams | UNLAMINATED_WASTE_grams | OUTPUT_WASTE_grams | ...';
require_once __DIR__.'/../includes/upload_modal.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// ── Chart data from PHP ──────────────────────────────────────────────────────
const CM  = <?= json_encode($c_machine) ?>;
const CF  = <?= json_encode($c_fabric)  ?>;
const CRP = <?= json_encode($c_rm_pp)   ?>;
const CT  = <?= json_encode($trend_data) ?>;
const CWB = {
    input:   <?= round((float)($c_waste_breakdown['iw'] ?? 0) / 1000, 3) ?>,
    unlam:   <?= round((float)($c_waste_breakdown['uw'] ?? 0) / 1000, 3) ?>,
    output:  <?= round((float)($c_waste_breakdown['ow'] ?? 0) / 1000, 3) ?>,
};

const GRID = 'rgba(255,255,255,0.06)';
const WASTE_BG = ['rgba(248,113,113,0.8)','rgba(251,146,60,0.8)','rgba(251,191,36,0.8)'];
const WASTE_BD = ['rgba(248,113,113,1)','rgba(251,146,60,1)','rgba(251,191,36,1)'];

// ── LENGTH (COUNTS) CHARTS ─────────────────────────────────────────────────────
new Chart(document.getElementById('cMachineC'), {
    type: 'bar',
    data: { labels: CM.labels, datasets: [
        { label: 'Length (m)', data: CM.length, backgroundColor: 'rgba(74,222,128,0.75)', borderColor: 'rgba(74,222,128,1)', borderWidth:1, borderRadius:5 },
    ]},
    options: { responsive:true, maintainAspectRatio:false, animation:{duration:800},
        plugins:{ legend:{position:'top',labels:{color:'#94a3b8',font:{size:11}}} },
        scales:{ x:{grid:{color:GRID},ticks:{color:'#94a3b8'}},
                 y:{grid:{color:GRID},beginAtZero:true,ticks:{color:'#94a3b8',callback:v=>v.toLocaleString()+' m'}} } }
});
new Chart(document.getElementById('cWasteBreakC'), {
    type: 'doughnut',
    data: { labels:['Input Waste (kg)','Unlaminated Waste (kg)','Output Waste (kg)'],
            datasets:[{data:[CWB.input,CWB.unlam,CWB.output],backgroundColor:WASTE_BG,borderColor:WASTE_BD,borderWidth:2,hoverOffset:8}] },
    options:{ responsive:true,maintainAspectRatio:false,animation:{animateRotate:true,duration:900},cutout:'62%',
        plugins:{ legend:{position:'bottom',labels:{color:'#94a3b8',font:{size:10},boxWidth:12,padding:10}},
                  tooltip:{callbacks:{label:ctx=>ctx.label+': '+ctx.parsed.toLocaleString()+' kg'}} } }
});
new Chart(document.getElementById('cFabricC'), {
    type: 'bar',
    data: { labels:CF.labels, datasets:[
        { label:'Length (m)', data:CF.length, backgroundColor:'rgba(167,139,250,0.75)', borderColor:'rgba(167,139,250,1)', borderWidth:1, borderRadius:4 },
    ]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, animation:{duration:750},
        plugins:{legend:{position:'top',labels:{color:'#94a3b8',font:{size:10}}}},
        scales:{ x:{grid:{color:GRID},beginAtZero:true,ticks:{color:'#94a3b8',callback:v=>v+' m'}},
                 y:{grid:{color:GRID},ticks:{color:'#94a3b8',font:{size:10}}} } }
});
new Chart(document.getElementById('cRmPPC'), {
    type: 'bar',
    data: { labels:CRP.labels, datasets:[
        { label:'Length (m)', data:CRP.length, backgroundColor:'rgba(74,222,128,0.75)', borderColor:'rgba(74,222,128,1)', borderWidth:1, borderRadius:5 },
    ]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{duration:850},
        plugins:{legend:{position:'top',labels:{color:'#94a3b8',font:{size:10}}}},
        scales:{ x:{grid:{color:GRID},ticks:{color:'#94a3b8',font:{size:9},maxRotation:20}},
                 y:{grid:{color:GRID},beginAtZero:true,ticks:{color:'#94a3b8',callback:v=>v+' m'}} } }
});
new Chart(document.getElementById('cTrendC'), {
    type: 'line',
    data: { labels:CT.labels, datasets:[
        { label:'Length (m)', data:CT.length, borderColor:'rgba(74,222,128,1)', backgroundColor:'rgba(74,222,128,0.12)',
          tension:0.4, fill:true, pointRadius:3, pointHoverRadius:6, borderWidth:2 },
    ]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{duration:900},
        plugins:{legend:{position:'top',labels:{color:'#94a3b8',font:{size:11}}}},
        scales:{ x:{grid:{color:GRID},ticks:{color:'#94a3b8',font:{size:10},maxRotation:35,maxTicksLimit:15}},
                 y:{grid:{color:GRID},beginAtZero:true,ticks:{color:'#94a3b8',callback:v=>v+' m'}} } }
});

// ── WEIGHT CHARTS ─────────────────────────────────────────────────────────────
new Chart(document.getElementById('cMachineW'), {
    type: 'bar',
    data: { labels:CM.labels, datasets:[
        { label:'Output (kg)',    data:CM.output, backgroundColor:'rgba(34,211,238,0.75)', borderColor:'rgba(34,211,238,1)', borderWidth:1, borderRadius:5 },
        { label:'Total Waste (kg)', data:CM.waste, backgroundColor:'rgba(248,113,113,0.75)', borderColor:'rgba(248,113,113,1)', borderWidth:1, borderRadius:5 },
    ]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{duration:800},
        plugins:{legend:{position:'top',labels:{color:'#94a3b8',font:{size:11}}}},
        scales:{ x:{grid:{color:GRID},ticks:{color:'#94a3b8'}},
                 y:{grid:{color:GRID},beginAtZero:true,ticks:{color:'#94a3b8',callback:v=>v.toLocaleString()+' kg'}} } }
});
new Chart(document.getElementById('cWasteBreakW'), {
    type: 'doughnut',
    data: { labels:['Input Waste (kg)','Unlaminated Waste (kg)','Output Waste (kg)'],
            datasets:[{data:[CWB.input,CWB.unlam,CWB.output],backgroundColor:WASTE_BG,borderColor:WASTE_BD,borderWidth:2,hoverOffset:8}] },
    options:{ responsive:true,maintainAspectRatio:false,animation:{animateRotate:true,duration:900},cutout:'62%',
        plugins:{ legend:{position:'bottom',labels:{color:'#94a3b8',font:{size:10},boxWidth:12,padding:10}},
                  tooltip:{callbacks:{label:ctx=>ctx.label+': '+ctx.parsed.toLocaleString()+' kg'}} } }
});
new Chart(document.getElementById('cFabricW'), {
    type: 'bar',
    data: { labels:CF.labels, datasets:[
        { label:'Output (kg)',    data:CF.output, backgroundColor:'rgba(167,139,250,0.75)', borderColor:'rgba(167,139,250,1)', borderWidth:1, borderRadius:4 },
        { label:'Total Waste (kg)', data:CF.waste, backgroundColor:'rgba(248,113,113,0.75)', borderColor:'rgba(248,113,113,1)', borderWidth:1, borderRadius:4 },
    ]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, animation:{duration:750},
        plugins:{legend:{position:'top',labels:{color:'#94a3b8',font:{size:10}}}},
        scales:{ x:{grid:{color:GRID},beginAtZero:true,ticks:{color:'#94a3b8',callback:v=>v+' kg'}},
                 y:{grid:{color:GRID},ticks:{color:'#94a3b8',font:{size:10}}} } }
});
new Chart(document.getElementById('cRmPPW'), {
    type: 'bar',
    data: { labels:CRP.labels, datasets:[
        { label:'Output (kg)',    data:CRP.output, backgroundColor:'rgba(74,222,128,0.75)', borderColor:'rgba(74,222,128,1)', borderWidth:1, borderRadius:5 },
        { label:'Total Waste (kg)', data:CRP.waste, backgroundColor:'rgba(248,113,113,0.75)', borderColor:'rgba(248,113,113,1)', borderWidth:1, borderRadius:5 },
    ]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{duration:850},
        plugins:{legend:{position:'top',labels:{color:'#94a3b8',font:{size:10}}}},
        scales:{ x:{grid:{color:GRID},ticks:{color:'#94a3b8',font:{size:9},maxRotation:20}},
                 y:{grid:{color:GRID},beginAtZero:true,ticks:{color:'#94a3b8',callback:v=>v+' kg'}} } }
});
new Chart(document.getElementById('cTrendW'), {
    type: 'line',
    data: { labels:CT.labels, datasets:[
        { label:'Output (kg)', data:CT.output, borderColor:'rgba(34,211,238,1)', backgroundColor:'rgba(34,211,238,0.12)',
          tension:0.4, fill:true, pointRadius:3, pointHoverRadius:6, borderWidth:2 },
        { label:'Output Waste (kg)', data:CT.waste, borderColor:'rgba(248,113,113,1)', backgroundColor:'rgba(248,113,113,0.10)',
          tension:0.4, fill:true, pointRadius:3, pointHoverRadius:6, borderWidth:2 },
    ]},
    options:{ responsive:true, maintainAspectRatio:false, animation:{duration:900},
        plugins:{legend:{position:'top',labels:{color:'#94a3b8',font:{size:11}}}},
        scales:{ x:{grid:{color:GRID},ticks:{color:'#94a3b8',font:{size:10},maxRotation:35,maxTicksLimit:15}},
                 y:{grid:{color:GRID},beginAtZero:true,ticks:{color:'#94a3b8',callback:v=>v+' kg'}} } }
});

// ── TOGGLE ────────────────────────────────────────────────────────────────────
function lamSetView(v) {
    document.querySelectorAll('.lam-view-counts').forEach(el => el.style.display = v === 'counts' ? '' : 'none');
    document.querySelectorAll('.lam-view-weight').forEach(el => el.style.display = v === 'weight' ? '' : 'none');
    document.getElementById('lamBtnCounts').classList.toggle('active', v === 'counts');
    document.getElementById('lamBtnWeight').classList.toggle('active', v === 'weight');
    localStorage.setItem('lamView', v);
}
(function(){ const v = localStorage.getItem('lamView'); if (v) lamSetView(v); })();

// Table search
function lamDoSearch(q) {
    const term = q.trim().toLowerCase();
    let visibleCount = 0;
    document.querySelectorAll('#lamTable tbody tr').forEach(tr => {
        const text = Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim()).join(' ').toLowerCase();
        const match = term === '' || text.includes(term);
        tr.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });
    const clearBtn = document.getElementById('tblSearchClear');
    if (clearBtn) clearBtn.style.display = q.trim() ? 'flex' : 'none';
    const countEl = document.getElementById('lamRowCount');
    if (countEl) countEl.textContent = term ? visibleCount + ' result' + (visibleCount !== 1 ? 's' : '') : '<?= number_format($kpi["recs"]) ?> rows';
}
function lamClearSearch() {
    const input = document.getElementById('tblSearch');
    input.value = '';
    lamDoSearch('');
    input.focus();
}
document.getElementById('tblSearch').addEventListener('input', function () {
    lamDoSearch(this.value);
});
</script>

<style>
.icon-cyan   { background: rgba(34,211,238,.18); color: #22d3ee; }
/* Toggle button */
.ext-toggle-wrap {
    display: flex; background: rgba(15,23,42,.6);
    border: 1px solid rgba(148,163,184,.15); border-radius: 10px; padding: 3px; gap: 2px;
}
.ext-toggle-btn {
    padding: 5px 18px; font-size: .8rem; font-weight: 600; border: none; cursor: pointer;
    border-radius: 8px; background: transparent; color: #64748b;
    transition: all .2s; letter-spacing: .02em;
}
.ext-toggle-btn.active { background: #0e3040; color: #67e8f9; box-shadow: 0 2px 8px rgba(34,211,238,.25); }
.ext-toggle-btn:hover:not(.active) { color: #94a3b8; background: rgba(255,255,255,.04); }
.icon-red    { background: rgba(248,113,113,.18); color: #f87171; }
.icon-amber  { background: rgba(251,191,36,.18);  color: #fbbf24; }
.lam-input {
    background: #0e2236 !important;
    border: 1px solid rgba(34,211,238,.2) !important;
    color: #f1f5f9 !important;
    border-radius: 8px !important;
}
.lam-input::placeholder { color: #64748b !important; }
.lam-input:focus {
    border-color: #22d3ee !important;
    box-shadow: 0 0 0 3px rgba(34,211,238,.15) !important;
    outline: none;
}
.lam-input option { background: #0e2236; }

/* Multi-select machine dropdown (lamination) */
.lam-multi-wrap { position: relative; }
.lam-multi-btn {
    width: 100%; display: flex; align-items: center; gap: 6px;
    padding: 4px 10px; font-size: .8rem; cursor: pointer; text-align: left;
    min-height: 31px; user-select: none; border-radius: 8px !important;
}
.lam-multi-btn.active { border-color: #22d3ee !important; color: #67e8f9 !important; }
.lam-multi-panel {
    display: none; position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 260px; overflow-y: auto; z-index: 1050;
    background: #0e2236; border: 1px solid rgba(34,211,238,.3);
    border-radius: 10px; padding: 6px 4px; box-shadow: 0 8px 24px rgba(0,0,0,.5);
}
.lam-multi-panel.open { display: block; }
.lam-multi-opt {
    display: flex; align-items: center; gap: 8px; padding: 5px 10px;
    border-radius: 6px; cursor: pointer; font-size: .8rem; color: #cbd5e1;
    transition: background .15s; white-space: nowrap;
}
.lam-multi-opt:hover { background: rgba(34,211,238,.12); color: #f1f5f9; }
.lam-cb { accent-color: #22d3ee; width: 14px; height: 14px; cursor: pointer; flex-shrink: 0; }

/* ── Lamination KPI cards ── */
.lam-kpi-row .stat-card {
    display: flex; align-items: center; gap: .55rem;
    padding: .75rem .9rem; overflow: hidden;
}
.lam-kpi-row .stat-icon {
    width: 38px; height: 38px; font-size: 1rem;
    flex-shrink: 0; border-radius: 10px;
}
.lam-kpi-row .stat-card > div:last-child { min-width: 0; flex: 1; overflow: hidden; }
.lam-kpi-row .stat-value {
    font-size: .95rem; font-weight: 700; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.lam-kpi-row .stat-label {
    font-size: .68rem; color: var(--text-muted); margin-top: .1rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width: 576px) {
    .lam-kpi-row .stat-value { font-size: .85rem; }
    .lam-kpi-row .stat-icon  { width: 32px; height: 32px; font-size: .9rem; }
}
</style>

<script>
(function () {
    const btn    = document.getElementById('lamMachineBtn');
    const panel  = document.getElementById('lamMachinePanel');
    const label  = document.getElementById('lamMachineLabel');
    const allCb  = document.getElementById('lamMachineAll');
    const getCbs = () => [...document.querySelectorAll('.lam-machine-cb')];

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
        if (!document.getElementById('lamMachineWrap').contains(e.target))
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
        const fromHidden = document.getElementById('lam_date_from');
        const toHidden   = document.getElementById('lam_date_to');
        const rangeInput = document.getElementById('lam_date_range');
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
        document.querySelector('a[href="lamination.php"]')?.addEventListener('click', function() {
            fromHidden.value = ''; toHidden.value = '';
        });
    });
})();
</script>