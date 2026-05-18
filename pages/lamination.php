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

// ── Chart helpers ─────────────────────────────────────────────────────────────
function lam_chart($conn, $sql, $t, $p, $key_col, $out_col, $waste_col = null) {
    $r   = lqry($conn, $sql, $t, $p);
    $out = ['labels' => [], 'output' => [], 'waste' => []];
    while ($row = $r->fetch_assoc()) {
        $out['labels'][] = $row[$key_col];
        $out['output'][] = round((float)$row[$out_col], 3);
        $out['waste'][]  = $waste_col ? round((float)$row[$waste_col] / 1000, 3) : 0;
    }
    return $out;
}

// 1. Output/Waste per Machine
$c_machine = lam_chart($conn,
    "SELECT machine_number AS k,
            SUM(CAST(ROLL_WEIGHT_kilograms AS DECIMAL(10,3))) AS v_out,
            SUM(CAST(INPUT_WASTE_grams AS DECIMAL(12,3)) +
                CAST(UNLAMINATED_WASTE_grams AS DECIMAL(12,3)) +
                CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3))) AS v_waste
     FROM laminationimport_fixed WHERE $wsql
     GROUP BY machine_number ORDER BY v_out DESC LIMIT 15",
    $types, $params, 'k', 'v_out', 'v_waste');

// 2. Output/Waste per Fabric Width-Denier
$c_fabric = lam_chart($conn,
    "SELECT FABRIC_WIDTH_AND_TAPE_DENIER AS k,
            SUM(CAST(ROLL_WEIGHT_kilograms AS DECIMAL(10,3))) AS v_out,
            SUM(CAST(INPUT_WASTE_grams AS DECIMAL(12,3)) +
                CAST(UNLAMINATED_WASTE_grams AS DECIMAL(12,3)) +
                CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3))) AS v_waste
     FROM laminationimport_fixed WHERE $wsql
     GROUP BY FABRIC_WIDTH_AND_TAPE_DENIER ORDER BY v_out DESC LIMIT 10",
    $types, $params, 'k', 'v_out', 'v_waste');

// 3. Output/Waste per RM — PP component
$c_rm_pp = lam_chart($conn,
    "SELECT PP AS k,
            SUM(CAST(ROLL_WEIGHT_kilograms AS DECIMAL(10,3))) AS v_out,
            SUM(CAST(INPUT_WASTE_grams AS DECIMAL(12,3)) +
                CAST(UNLAMINATED_WASTE_grams AS DECIMAL(12,3)) +
                CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3))) AS v_waste
     FROM laminationimport_fixed WHERE $wsql AND PP IS NOT NULL AND PP != ''
     GROUP BY PP ORDER BY v_out DESC LIMIT 10",
    $types, $params, 'k', 'v_out', 'v_waste');

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
            SUM(CAST(OUTPUT_WASTE_grams AS DECIMAL(12,3)) / 1000) AS v_waste
     FROM laminationimport_fixed WHERE $wsql AND DATE_STARTED IS NOT NULL AND DATE_STARTED != ''
     GROUP BY DATE_STARTED ORDER BY DATE_STARTED ASC LIMIT 30",
    $types, $params);
$trend_data = ['labels' => [], 'output' => [], 'waste' => []];
while ($row = $c_trend->fetch_assoc()) {
    $trend_data['labels'][] = $row['dt'];
    $trend_data['output'][] = round((float)$row['v_out'], 2);
    $trend_data['waste'][]  = round((float)$row['v_waste'], 3);
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
    "SELECT Id, FINAL_BATCH_CODE, DATE_STARTED, machine_number,
            ROLL_WEIGHT_kilograms, ROLL_LENGTH_meters,
            INPUT_WASTE_grams, UNLAMINATED_WASTE_grams, OUTPUT_WASTE_grams,
            FABRIC_WIDTH_AND_TAPE_DENIER, FABRIC_TYPE,
            PP, PP_BATCH_CODE,
            CALCIUM_CARBONATE_1, `CALCIUM-CARBONATE_1_BATCH_CODE`,
            SHIFT_PRODUCTION_PERSONNEL, LEAD_OPERATOR,
            PRODUCTION_REMARKS
     FROM laminationimport_fixed WHERE $wsql ORDER BY Id DESC LIMIT 50",
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

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">DATE FINISHED FROM</label>
                <input type="date" name="date_from" class="form-control form-control-sm pqm-input"
                       value="<?= htmlspecialchars($f_date_from) ?>">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">DATE FINISHED TO</label>
                <input type="date" name="date_to" class="form-control form-control-sm pqm-input"
                       value="<?= htmlspecialchars($f_date_to) ?>">
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
            <div class="col-12 col-lg-4 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-sm px-4" style="background:#0e7490;color:#fff;border:none">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="lamination.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <?php
        $kpis = [
            ['val' => number_format((float)($kpi['tot_output'] ?? 0), 1),  'label' => 'Total Output (kg)',     'icon' => 'bi-speedometer2',  'cls' => 'icon-cyan'],
            ['val' => number_format($tot_waste_kg, 3),                     'label' => 'Total Waste (kg)',      'icon' => 'bi-trash3',        'cls' => 'icon-red'],
            ['val' => number_format((int)($kpi['recs'] ?? 0)),             'label' => 'Total Records',         'icon' => 'bi-collection',    'cls' => 'icon-teal'],
            ['val' => number_format((float)($kpi['tot_length'] ?? 0), 1), 'label' => 'Total Length (m)',      'icon' => 'bi-rulers',        'cls' => 'icon-green'],
            ['val' => (int)($kpi['machines'] ?? 0),                        'label' => 'Active Machines',       'icon' => 'bi-cpu',           'cls' => 'icon-amber'],
            ['val' => number_format((float)($kpi['avg_gsm'] ?? 0), 2),    'label' => 'Avg Fabric GSM',        'icon' => 'bi-bar-chart-line','cls' => 'icon-purple'],
        ];
        foreach ($kpis as $k): ?>
        <div class="col-6 col-xl-2">
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

    <!-- Charts Row 1: Output/Waste per Machine | Waste Breakdown Doughnut -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-8">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart-fill" style="color:#22d3ee"></i> Output &amp; Waste per Machine</div>
                <div class="chart-wrapper" style="height:275px">
                    <canvas id="cMachine"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-pie-chart-fill" style="color:#f87171"></i> Waste Breakdown</div>
                <div class="chart-wrapper" style="height:275px">
                    <canvas id="cWasteBreak"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2: Output/Waste per Fabric Width-Denier | Output/Waste per RM (PP) -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-grid-3x2-gap" style="color:#a78bfa"></i> Output &amp; Waste per Fabric Width-Denier</div>
                <div class="chart-wrapper" style="height:260px">
                    <canvas id="cFabric"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-box-seam" style="color:#4ade80"></i> Output &amp; Waste per RM Component (PP)</div>
                <div class="chart-wrapper" style="height:260px">
                    <canvas id="cRmPP"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 3: Output/Waste Trend (Line Chart) -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="pqm-card">
                <div class="section-title"><i class="bi bi-graph-up" style="color:#fb923c"></i> Daily Output &amp; Output Waste Trend</div>
                <div class="chart-wrapper" style="height:240px">
                    <canvas id="cTrend"></canvas>
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
                <input type="text" id="tblSearch" class="form-control form-control-sm pqm-input lam-input"
                       placeholder="&#xF52A; Search..." style="max-width:200px">
                <a href="laminationexport.php?<?= http_build_query($_GET) ?>"
                   class="btn btn-sm d-flex align-items-center gap-1 px-3"
                   style="background:#166534;color:#fff;border:none;white-space:nowrap">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="pqm-table" id="lamTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Batch Code</th>
                        <th>Machine</th>
                        <th>Fabric W-D</th>
                        <th>Output (kg)</th>
                        <th>Input Waste (g)</th>
                        <th>Unlam Waste (g)</th>
                        <th>Output Waste (g)</th>
                        <th>RM (PP)</th>
                        <th>RM (CC1)</th>
                        <th>Lead Operator</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $rows->fetch_assoc()):
                    $rem = trim($r['PRODUCTION_REMARKS'] ?? '');
                    $rb  = ($rem === '' || strtolower($rem) === 'n/a') ? 'bg-secondary' : 'bg-warning text-dark';
                    $total_waste_g = (float)($r['INPUT_WASTE_grams'] ?? 0)
                                   + (float)($r['UNLAMINATED_WASTE_grams'] ?? 0)
                                   + (float)($r['OUTPUT_WASTE_grams'] ?? 0);
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['DATE_STARTED'] ?? '') ?></td>
                    <td><code style="color:#67e8f9;font-size:.72rem"><?= htmlspecialchars($r['FINAL_BATCH_CODE'] ?? '') ?></code></td>
                    <td><span class="badge" style="background:#0e3040;color:#22d3ee;border:1px solid #22d3ee55"><?= htmlspecialchars($r['machine_number'] ?? '') ?></span></td>
                    <td><code style="color:#a78bfa;font-size:.72rem"><?= htmlspecialchars($r['FABRIC_WIDTH_AND_TAPE_DENIER'] ?? '') ?></code></td>
                    <td class="fw-semibold" style="color:#22d3ee"><?= number_format((float)($r['ROLL_WEIGHT_kilograms'] ?? 0), 3) ?></td>
                    <td style="color:#f87171"><?= number_format((float)($r['INPUT_WASTE_grams'] ?? 0), 1) ?></td>
                    <td style="color:#fb923c"><?= number_format((float)($r['UNLAMINATED_WASTE_grams'] ?? 0), 1) ?></td>
                    <td style="color:#fbbf24"><?= number_format((float)($r['OUTPUT_WASTE_grams'] ?? 0), 1) ?></td>
                    <td style="font-size:.75rem"><?= htmlspecialchars($r['PP'] ?? '—') ?></td>
                    <td style="font-size:.75rem"><?= htmlspecialchars($r['CALCIUM_CARBONATE_1'] ?? '—') ?></td>
                    <td style="font-size:.75rem"><?= htmlspecialchars($r['LEAD_OPERATOR'] ?? '—') ?></td>
                    <td><span class="badge <?= $rb ?> process-badge"><?= htmlspecialchars($rem ?: 'N/A') ?></span></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

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

const LAM_CYAN   = 'rgba(34,211,238,';
const LAM_RED    = 'rgba(248,113,113,';
const LAM_ORANGE = 'rgba(251,146,60,';
const LAM_PURPLE = 'rgba(167,139,250,';
const LAM_GREEN  = 'rgba(74,222,128,';
const GRID       = 'rgba(255,255,255,0.06)';

function lamBar(label, data, alpha, prefix = '') {
    return {
        label,
        data,
        backgroundColor: alpha.replace('(', '(').replace(',', ',') + '0.75)',
        borderColor:     alpha + '1)',
        borderWidth: 1,
        borderRadius: 5,
    };
}

// Rebuild using the known pattern from the project
function mkBarDS(label, data, color) {
    return { label, data,
        backgroundColor: color.replace('ALPHA', '0.75'),
        borderColor:     color.replace('ALPHA', '1'),
        borderWidth: 1, borderRadius: 5 };
}

// 1. Output/Waste per Machine — grouped bar
new Chart(document.getElementById('cMachine'), {
    type: 'bar',
    data: {
        labels: CM.labels,
        datasets: [
            { label: 'Output (kg)',   data: CM.output, backgroundColor: 'rgba(34,211,238,0.75)', borderColor: 'rgba(34,211,238,1)', borderWidth:1, borderRadius:5 },
            { label: 'Total Waste (kg)', data: CM.waste,  backgroundColor: 'rgba(248,113,113,0.75)', borderColor: 'rgba(248,113,113,1)', borderWidth:1, borderRadius:5 },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        animation: { duration: 800 },
        plugins: { legend: { position: 'top', labels: { color: '#94a3b8', font: { size: 11 } } } },
        scales: {
            x: { grid: { color: GRID }, ticks: { color: '#94a3b8' } },
            y: { grid: { color: GRID }, beginAtZero: true, ticks: { color: '#94a3b8', callback: v => v.toLocaleString() + ' kg' } }
        }
    }
});

// 2. Waste Breakdown Doughnut
new Chart(document.getElementById('cWasteBreak'), {
    type: 'doughnut',
    data: {
        labels: ['Input Waste (kg)', 'Unlaminated Waste (kg)', 'Output Waste (kg)'],
        datasets: [{
            data: [CWB.input, CWB.unlam, CWB.output],
            backgroundColor: ['rgba(248,113,113,0.8)', 'rgba(251,146,60,0.8)', 'rgba(251,191,36,0.8)'],
            borderColor:     ['rgba(248,113,113,1)',   'rgba(251,146,60,1)',   'rgba(251,191,36,1)'],
            borderWidth: 2, hoverOffset: 8
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        animation: { animateRotate: true, duration: 900 },
        cutout: '62%',
        plugins: {
            legend: { position: 'bottom', labels: { color: '#94a3b8', font: { size: 10 }, boxWidth: 12, padding: 10 } },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' kg' } }
        }
    }
});

// 3. Output/Waste per Fabric Width-Denier — horizontal grouped bar
new Chart(document.getElementById('cFabric'), {
    type: 'bar',
    data: {
        labels: CF.labels,
        datasets: [
            { label: 'Output (kg)',      data: CF.output, backgroundColor: 'rgba(167,139,250,0.75)', borderColor: 'rgba(167,139,250,1)', borderWidth:1, borderRadius:4 },
            { label: 'Total Waste (kg)', data: CF.waste,  backgroundColor: 'rgba(248,113,113,0.75)', borderColor: 'rgba(248,113,113,1)', borderWidth:1, borderRadius:4 },
        ]
    },
    options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        animation: { duration: 750 },
        plugins: { legend: { position: 'top', labels: { color: '#94a3b8', font: { size: 10 } } } },
        scales: {
            x: { grid: { color: GRID }, beginAtZero: true, ticks: { color: '#94a3b8', callback: v => v + ' kg' } },
            y: { grid: { color: GRID }, ticks: { color: '#94a3b8', font: { size: 10 } } }
        }
    }
});

// 4. Output/Waste per RM (PP) — bar
new Chart(document.getElementById('cRmPP'), {
    type: 'bar',
    data: {
        labels: CRP.labels,
        datasets: [
            { label: 'Output (kg)',      data: CRP.output, backgroundColor: 'rgba(74,222,128,0.75)', borderColor: 'rgba(74,222,128,1)', borderWidth:1, borderRadius:5 },
            { label: 'Total Waste (kg)', data: CRP.waste,  backgroundColor: 'rgba(248,113,113,0.75)', borderColor: 'rgba(248,113,113,1)', borderWidth:1, borderRadius:5 },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        animation: { duration: 850 },
        plugins: { legend: { position: 'top', labels: { color: '#94a3b8', font: { size: 10 } } } },
        scales: {
            x: { grid: { color: GRID }, ticks: { color: '#94a3b8', font: { size: 9 }, maxRotation: 20 } },
            y: { grid: { color: GRID }, beginAtZero: true, ticks: { color: '#94a3b8', callback: v => v + ' kg' } }
        }
    }
});

// 5. Daily Output/Waste Trend — line chart
new Chart(document.getElementById('cTrend'), {
    type: 'line',
    data: {
        labels: CT.labels,
        datasets: [
            {
                label: 'Output (kg)', data: CT.output,
                borderColor: 'rgba(34,211,238,1)', backgroundColor: 'rgba(34,211,238,0.12)',
                tension: 0.4, fill: true, pointRadius: 3, pointHoverRadius: 6, borderWidth: 2
            },
            {
                label: 'Output Waste (kg)', data: CT.waste,
                borderColor: 'rgba(248,113,113,1)', backgroundColor: 'rgba(248,113,113,0.10)',
                tension: 0.4, fill: true, pointRadius: 3, pointHoverRadius: 6, borderWidth: 2
            },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        animation: { duration: 900 },
        plugins: { legend: { position: 'top', labels: { color: '#94a3b8', font: { size: 11 } } } },
        scales: {
            x: { grid: { color: GRID }, ticks: { color: '#94a3b8', font: { size: 10 }, maxRotation: 35, maxTicksLimit: 15 } },
            y: { grid: { color: GRID }, beginAtZero: true, ticks: { color: '#94a3b8', callback: v => v + ' kg' } }
        }
    }
});

// Table search
document.getElementById('tblSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#lamTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<style>
.icon-cyan   { background: rgba(34,211,238,.18); color: #22d3ee; }
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
</script>