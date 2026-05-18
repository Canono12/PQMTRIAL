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
    "SELECT FINAL_BATCH_CODE, DATE_STARTED, MACHINE_NUMBER,
            PRINTED_FABRIC, FABRIC_WIDTH_AND_TAPE_DENIER,
            `ROLL_WEIGHT(kilograms)` AS weight,
            `ROLL_LENGTH(meters)` AS roll_len,
            `OUTPUT_WASTE(grams)` AS waste,
            `INPUT_WASTE(grams)` AS input_waste,
            JOB_ORDER_NUMBER, PRINT_DESIGN,
            ROLL_ORDER, PRINTING_STAGE,
            PRODUCTION_REMARKS, IPQC_TECHNICIAN,
            ENCODED_BY, PROCESS_TIME
     FROM printing WHERE $wsql ORDER BY Id DESC LIMIT 50",
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
            <div class="col-12 d-flex gap-2 mt-1">
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="printing.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <?php
        $kpis = [
            ['val' => number_format((float)$kpi['tot_weight'], 1), 'label' => 'Total Output (kg)',  'icon' => 'bi-speedometer2',    'cls' => 'icon-amber'],
            ['val' => number_format((int)$kpi['recs']),            'label' => 'Total Records',      'icon' => 'bi-collection',      'cls' => 'icon-teal'],
            ['val' => number_format((float)$kpi['tot_length'], 0), 'label' => 'Total Length (m)',   'icon' => 'bi-rulers',          'cls' => 'icon-blue'],
            ['val' => number_format((float)$kpi['tot_waste'], 1),  'label' => 'Total Waste (g)',    'icon' => 'bi-exclamation-triangle', 'cls' => 'icon-red'],
            ['val' => (int)$kpi['machines'],                       'label' => 'Active Machines',    'icon' => 'bi-cpu',             'cls' => 'icon-green'],
            ['val' => (int)$kpi['fabrics'],                        'label' => 'Fabric Types',       'icon' => 'bi-layers',          'cls' => 'icon-purple'],
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

    <!-- Charts Row 1 — Output/Waste per Machine | per Printed Fabric -->
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
                <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Output per Printed Fabric</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cShift"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 — Output/Waste per Fabric Width-Denier | per Print Design -->
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

    <!-- Charts Row 3 — Output/Waste per Job Order -->
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
                <a href="printing_export.php?<?= http_build_query($_GET) ?>"
                   class="btn btn-sm btn-success px-3 d-flex align-items-center gap-1" style="white-space:nowrap">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="pqm-table" id="pTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Batch Code</th>
                        <th>Machine</th>
                        <th>Stage</th>
                        <th>Fabric Type</th>
                        <th>Width-Denier</th>
                        <th>Output (kg)</th>
                        <th>Input Waste (g)</th>
                        <th>Output Waste (g)</th>
                        <th>Roll (m)</th>
                        <th>Roll Order</th>
                        <th>Job Order</th>
                        <th>Print Design</th>
                        <th>IPQC Tech</th>
                        <th>Encoded By</th>
                        <th>Process Time</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $rows->fetch_assoc()):
                    $rem = trim($r['PRODUCTION_REMARKS'] ?? '');
                    $isOk = ($rem === '' || strtolower($rem) === 'n/a' || strtolower($rem) === 'na');
                    $rb   = $isOk ? 'bg-secondary' : 'bg-warning text-dark';
                    $waste = (float)($r['waste'] ?? 0);
                    $wcolor = $waste > 0.5 ? '#fbbf24' : '#94a3b8';
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['DATE_STARTED']) ?></td>
                    <td><code style="color:#fcd34d;font-size:.73rem"><?= htmlspecialchars($r['FINAL_BATCH_CODE']) ?></code></td>
                    <td><span class="badge" style="background:#2d1f00;color:#fcd34d;border:1px solid #f59e0b55"><?= htmlspecialchars($r['MACHINE_NUMBER']) ?></span></td>
                    <td><span class="badge bg-secondary process-badge"><?= htmlspecialchars($r['PRINTING_STAGE']) ?></span></td>
                    <td><span class="badge" style="background:#1e3a5f;color:#93c5fd;border:1px solid #3b82f655"><?= htmlspecialchars($r['PRINTED_FABRIC']) ?></span></td>
                    <td><code style="color:#a78bfa;font-size:.75rem"><?= htmlspecialchars($r['FABRIC_WIDTH_AND_TAPE_DENIER']) ?></code></td>
                    <td class="fw-semibold" style="color:#38bdf8"><?= number_format((float)$r['weight'], 1) ?></td>
                    <td style="color:#94a3b8"><?= number_format((float)$r['input_waste'], 1) ?></td>
                    <td style="color:<?= $wcolor ?>"><?= number_format($waste, 1) ?></td>
                    <td><?= number_format((int)$r['roll_len']) ?></td>
                    <td><?= htmlspecialchars($r['ROLL_ORDER']) ?></td>
                    <td><span class="badge bg-primary process-badge"><?= htmlspecialchars($r['JOB_ORDER_NUMBER']) ?></span></td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($r['PRINT_DESIGN']) ?>">
                        <span style="color:#c084fc;font-size:.73rem"><?= htmlspecialchars(mb_substr($r['PRINT_DESIGN'] ?? '', 0, 35)) ?><?= strlen($r['PRINT_DESIGN'] ?? '') > 35 ? '…' : '' ?></span>
                    </td>
                    <td style="font-size:.73rem;color:#94a3b8"><?= htmlspecialchars($r['IPQC_TECHNICIAN']) ?></td>
                    <td style="font-size:.73rem;color:#94a3b8"><?= htmlspecialchars($r['ENCODED_BY']) ?></td>
                    <td style="font-size:.73rem;color:#64748b"><?= htmlspecialchars($r['PROCESS_TIME']) ?></td>
                    <td><span class="badge <?= $rb ?> process-badge"><?= htmlspecialchars($rem ?: 'N/A') ?></span></td>
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

// Live table search
document.getElementById('tblSearch').addEventListener('input', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('#pTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<style>
.icon-amber  { background: rgba(245,158,11,.18); color: #fcd34d; }
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
</script>