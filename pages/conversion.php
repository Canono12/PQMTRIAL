<?php
$page_title = 'Conversion';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// ── Filters ──────────────────────────────────────────────────────────────────
$f_monthyear = $_GET['monthyear'] ?? '';
$f_machines = array_filter(array_map('trim', (array)($_GET['machine'] ?? [])));
$f_customer = $_GET['customer'] ?? '';
$f_fabric   = $_GET['fabric']   ?? '';
$f_jo       = $_GET['jo']       ?? '';
$f_bagtype  = $_GET['bagtype']  ?? '';
$f_date_from = $_GET['date_from'] ?? '';
$f_date_to   = $_GET['date_to']   ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_monthyear) {
    $where[] = "DATE_FORMAT(DATE_COMPLETED, '%Y-%m') = ?";
    $params[] = $f_monthyear;
    $types .= 's';
}
if ($f_date_from) { $where[] = 'DATE_COMPLETED >= ?'; $params[] = $f_date_from; $types .= 's'; }
if ($f_date_to)   { $where[] = 'DATE_COMPLETED <= ?'; $params[] = $f_date_to;   $types .= 's'; }
if (!empty($f_machines)) {
    $placeholders = implode(',', array_fill(0, count($f_machines), '?'));
    $where[] = "MACINE_NUMBER IN ($placeholders)";
    foreach ($f_machines as $m) { $params[] = $m; $types .= 's'; }
}
if ($f_customer) { $where[] = 'INPUT_CUSTOMER = ?';             $params[] = $f_customer; $types .= 's'; }
if ($f_fabric)   { $where[] = 'FABRIC_WIDTH_TAPE_DENIER = ?';   $params[] = $f_fabric;   $types .= 's'; }
if ($f_jo)       { $where[] = 'JOB_ORDER_NO = ?';               $params[] = $f_jo;       $types .= 's'; }
if ($f_bagtype)  { $where[] = 'BAG_TYPE LIKE ?';                $params[] = '%'.$f_bagtype.'%'; $types .= 's'; }

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
            SUM(OUTPUT) AS tot_output,
            COUNT(DISTINCT MACINE_NUMBER) AS machines,
            COUNT(DISTINCT INPUT_CUSTOMER) AS customers,
            COUNT(DISTINCT JOB_ORDER_NO) AS jos,
            COUNT(DISTINCT FABRIC_WIDTH_TAPE_DENIER) AS fabrics
     FROM welding WHERE $wsql",
    $types, $params
)->fetch_assoc();

// ── Chart data helpers ────────────────────────────────────────────────────────
function chart_data($conn, $sql, $t, $p, $key_col, $val_col) {
    $r = qry($conn, $sql, $t, $p);
    $out = ['labels' => [], 'v1' => []];
    while ($row = $r->fetch_assoc()) {
        $out['labels'][] = $row[$key_col];
        $out['v1'][]     = (float)$row[$val_col];
    }
    return $out;
}

// Output per Machine Number
$c_machine = chart_data($conn,
    "SELECT MACINE_NUMBER AS k, SUM(OUTPUT) AS v1
     FROM welding WHERE $wsql
     GROUP BY MACINE_NUMBER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1');

// Output per Shift Personnel
$c_shift = chart_data($conn,
    "SELECT SHIFT_PERSONNEL_HISTORY AS k, SUM(OUTPUT) AS v1
     FROM welding WHERE $wsql
     GROUP BY SHIFT_PERSONNEL_HISTORY ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1');

// Output per Bag Type (top 10, truncated label)
$c_bagtype = chart_data($conn,
    "SELECT SUBSTR(BAG_TYPE, 1, 50) AS k, SUM(OUTPUT) AS v1
     FROM welding WHERE $wsql
     GROUP BY BAG_TYPE ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1');

// Output per Fabric Width-Denier
$c_fabric = chart_data($conn,
    "SELECT FABRIC_WIDTH_TAPE_DENIER AS k, SUM(OUTPUT) AS v1
     FROM welding WHERE $wsql
     GROUP BY FABRIC_WIDTH_TAPE_DENIER ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1');

// Output per Job Order No
$c_jo = chart_data($conn,
    "SELECT JOB_ORDER_NO AS k, SUM(OUTPUT) AS v1
     FROM welding WHERE $wsql
     GROUP BY JOB_ORDER_NO ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1');

// Output per Customer
$c_customer = chart_data($conn,
    "SELECT INPUT_CUSTOMER AS k, SUM(OUTPUT) AS v1
     FROM welding WHERE $wsql
     GROUP BY INPUT_CUSTOMER ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1');

// ── Filter options ────────────────────────────────────────────────────────────
$opt_months    = $conn->query("SELECT DISTINCT DATE_FORMAT(DATE_COMPLETED, '%Y-%m') AS v, DATE_FORMAT(DATE_COMPLETED, '%M %Y') AS label FROM welding WHERE DATE_COMPLETED IS NOT NULL AND DATE_COMPLETED != '' AND DATE_COMPLETED != '0000-00-00' ORDER BY v DESC");
$opt_machines  = $conn->query("SELECT DISTINCT MACINE_NUMBER v FROM welding WHERE MACINE_NUMBER IS NOT NULL AND MACINE_NUMBER != '' ORDER BY MACINE_NUMBER");
$opt_customers = $conn->query("SELECT DISTINCT INPUT_CUSTOMER v FROM welding WHERE INPUT_CUSTOMER IS NOT NULL AND INPUT_CUSTOMER != '' ORDER BY INPUT_CUSTOMER");
$opt_fabrics   = $conn->query("SELECT DISTINCT FABRIC_WIDTH_TAPE_DENIER v FROM welding WHERE FABRIC_WIDTH_TAPE_DENIER IS NOT NULL AND FABRIC_WIDTH_TAPE_DENIER != '' ORDER BY FABRIC_WIDTH_TAPE_DENIER");
$opt_jos       = $conn->query("SELECT DISTINCT JOB_ORDER_NO v FROM welding WHERE JOB_ORDER_NO IS NOT NULL AND JOB_ORDER_NO != '' ORDER BY JOB_ORDER_NO");

// ── Table ─────────────────────────────────────────────────────────────────────
$rows = qry($conn,
    "SELECT FINAL_BATCH_CODE, DATE_STARTED, MACINE_NUMBER,
            INPUT_CUSTOMER, FABRIC_WIDTH_TAPE_DENIER,
            BAG_TYPE, JOB_ORDER_NO,
            BEGINNING_COUNT, END_COUNT, OUTPUT,
            SHIFT_PERSONNEL_HISTORY, TECNICIAN, LEAD_INSPECTOR,
            PRODUCTION_REMARKS, WELDING_REMARKS,
            ENCODED_BY, PROCESS_TIME, `PROCESS_TIM(MINUTES)`
     FROM welding WHERE $wsql ORDER BY Id DESC LIMIT 50",
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
            <div class="page-heading"><i class="bi bi-gear-wide-connected me-2" style="color:#22c55e"></i>Conversion Module</div>
            <div class="page-subheading mt-1">Output analytics per machine, shift personnel, bag type, fabric, job order, and customer</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge process-badge" style="background:#052e16;border:1px solid #22c55e;color:#86efac">
                <i class="bi bi-database me-1"></i>welding
            </span>
            <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_conversion">
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
                    <?php while ($o = $opt_months->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_monthyear===$o['v']?'selected':'' ?>>
                        <?= htmlspecialchars($o['label']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">MACHINE NUMBER</label>
                <div class="cnv-multi-wrap" id="cnvMachineWrap">
                    <button type="button" class="cnv-multi-btn pqm-input" id="cnvMachineBtn">
                        <span id="cnvMachineLabel">
                            <?php if (!empty($f_machines)): ?>
                                <?= count($f_machines) === 1 ? htmlspecialchars($f_machines[0]) : count($f_machines) . ' machines selected' ?>
                            <?php else: ?>All Machines<?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-down" style="font-size:.7rem;margin-left:auto;opacity:.6"></i>
                    </button>
                    <div class="cnv-multi-panel" id="cnvMachinePanel">
                        <label class="cnv-multi-opt">
                            <input type="checkbox" id="cnvMachineAll" class="cnv-cb">
                            <span style="color:#94a3b8;font-style:italic">All Machines</span>
                        </label>
                        <div style="border-top:1px solid rgba(255,255,255,.07);margin:3px 0"></div>
                        <?php
                        $machines_list = [];
                        while ($o = $opt_machines->fetch_assoc()) $machines_list[] = $o['v'];
                        foreach ($machines_list as $mv):
                            $checked = in_array((string)$mv, array_map('strval', $f_machines)) ? 'checked' : '';
                        ?>
                        <label class="cnv-multi-opt">
                            <input type="checkbox" name="machine[]" value="<?= htmlspecialchars($mv) ?>"
                                   class="cnv-cb cnv-machine-cb" <?= $checked ?>>
                            <span><?= htmlspecialchars($mv) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
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
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">BAG TYPE</label>
                <input type="text" name="bagtype" class="form-control form-control-sm pqm-input"
                       placeholder="Search bag type..." value="<?= htmlspecialchars($f_bagtype) ?>">
            </div>
            <!-- ── Date Range Filter ───────────────────────────── -->
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">
                    <i class="bi bi-calendar-range me-1" style="color:#22c55e"></i>DATE FINISHED FROM
                </label>
                <input type="text" name="date_from" id="date_from"
                       class="form-control form-control-sm pqm-input pqm-datepicker"
                       placeholder="Start date"
                       value="<?= htmlspecialchars($f_date_from) ?>"
                       autocomplete="off" readonly>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label text-secondary" style="font-size:.72rem">
                    <i class="bi bi-calendar-range me-1" style="color:#22c55e"></i>DATE FINISHED TO
                </label>
                <input type="text" name="date_to" id="date_to"
                       class="form-control form-control-sm pqm-input pqm-datepicker"
                       placeholder="End date"
                       value="<?= htmlspecialchars($f_date_to) ?>"
                       autocomplete="off" readonly>
            </div>
            <div class="col-12 d-flex gap-2 mt-1">
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="conversion.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <?php
        $kpis = [
            ['val' => number_format((int)$kpi['tot_output']),  'label' => 'Total Output (bags)', 'icon' => 'bi-bag-check',       'cls' => 'icon-green'],
            ['val' => number_format((int)$kpi['recs']),        'label' => 'Total Records',       'icon' => 'bi-collection',      'cls' => 'icon-teal'],
            ['val' => (int)$kpi['machines'],                   'label' => 'Active Machines',     'icon' => 'bi-cpu',             'cls' => 'icon-blue'],
            ['val' => (int)$kpi['customers'],                  'label' => 'Customers',           'icon' => 'bi-people',          'cls' => 'icon-amber'],
            ['val' => (int)$kpi['jos'],                        'label' => 'Job Orders',          'icon' => 'bi-file-earmark-text','cls' => 'icon-purple'],
            ['val' => (int)$kpi['fabrics'],                    'label' => 'Fabric Types',        'icon' => 'bi-layers',          'cls' => 'icon-red'],
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

    <!-- Charts Row 1 — Output per Machine | Output per Customer -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-8">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Output per Machine Number</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cMachine"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Output per Customer</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cCustomer"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 — Output per Shift Personnel | Output per Fabric Width-Denier -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-person-badge"></i> Output per Shift Personnel (Top 15)</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cShift"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-grid-3x2-gap"></i> Output per Fabric Width-Denier</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cFabric"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 3 — Output per Bag Type | Output per Job Order -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-5">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bag"></i> Output per Bag Type (Top 10)</div>
                <div class="chart-wrapper" style="height:260px">
                    <canvas id="cBagType"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-file-earmark-text"></i> Output per Job Order No. (Top 15)</div>
                <div class="chart-wrapper" style="height:260px">
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
                <a href="conversion_export.php?<?= http_build_query($_GET) ?>"
                   class="btn btn-sm btn-success px-3 d-flex align-items-center gap-1" style="white-space:nowrap">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="pqm-table" id="cTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Batch Code</th>
                        <th>Machine</th>
                        <th>Customer</th>
                        <th>Fabric Width-Denier</th>
                        <th>Bag Type</th>
                        <th>Job Order</th>
                        <th>Beg. Count</th>
                        <th>End Count</th>
                        <th>Output (bags)</th>
                        <th>Shift Personnel</th>
                        <th>Technician</th>
                        <th>Lead Inspector</th>
                        <th>Process Time</th>
                        <th>Prod. Remarks</th>
                        <th>Welding Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $rows->fetch_assoc()):
                    $rem  = trim($r['PRODUCTION_REMARKS'] ?? '');
                    $wrem = trim($r['WELDING_REMARKS'] ?? '');
                    $isOk = ($rem === '' || strtolower($rem) === 'n/a' || strtolower($rem) === 'na');
                    $rb   = $isOk ? 'bg-secondary' : 'bg-warning text-dark';
                    $wOk  = ($wrem === '' || strtolower($wrem) === 'n/a' || strtolower($wrem) === 'na');
                    $wrb  = $wOk ? 'bg-secondary' : ($wrem === 'Passed' || $wrem === 'Good' ? 'bg-success' : 'bg-warning text-dark');
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['DATE_STARTED']) ?></td>
                    <td><code style="color:#86efac;font-size:.73rem"><?= htmlspecialchars($r['FINAL_BATCH_CODE']) ?></code></td>
                    <td><span class="badge" style="background:#052e16;color:#86efac;border:1px solid #22c55e55"><?= htmlspecialchars($r['MACINE_NUMBER']) ?></span></td>
                    <td><span class="badge" style="background:#2d1f00;color:#fcd34d;border:1px solid #f59e0b55"><?= htmlspecialchars($r['INPUT_CUSTOMER']) ?></span></td>
                    <td><code style="color:#a78bfa;font-size:.75rem"><?= htmlspecialchars($r['FABRIC_WIDTH_TAPE_DENIER']) ?></code></td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="<?= htmlspecialchars($r['BAG_TYPE']) ?>">
                        <span style="color:#c084fc;font-size:.73rem"><?= htmlspecialchars(mb_substr($r['BAG_TYPE'] ?? '', 0, 35)) ?><?= strlen($r['BAG_TYPE'] ?? '') > 35 ? '…' : '' ?></span>
                    </td>
                    <td><span class="badge bg-primary process-badge"><?= htmlspecialchars($r['JOB_ORDER_NO']) ?></span></td>
                    <td style="color:#94a3b8;font-size:.8rem"><?= number_format((int)$r['BEGINNING_COUNT']) ?></td>
                    <td style="color:#94a3b8;font-size:.8rem"><?= number_format((int)$r['END_COUNT']) ?></td>
                    <td class="fw-semibold" style="color:#22c55e"><?= number_format((int)$r['OUTPUT']) ?></td>
                    <td style="font-size:.73rem;color:#94a3b8"><?= htmlspecialchars($r['SHIFT_PERSONNEL_HISTORY']) ?></td>
                    <td style="font-size:.73rem;color:#94a3b8"><?= htmlspecialchars($r['TECNICIAN']) ?></td>
                    <td style="font-size:.73rem;color:#94a3b8"><?= htmlspecialchars($r['LEAD_INSPECTOR']) ?></td>
                    <td style="font-size:.73rem;color:#64748b"><?= htmlspecialchars($r['PROCESS_TIME']) ?></td>
                    <td><span class="badge <?= $rb ?> process-badge"><?= htmlspecialchars($rem ?: 'N/A') ?></span></td>
                    <td><span class="badge <?= $wrb ?> process-badge"><?= htmlspecialchars($wrem ?: 'N/A') ?></span></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->
<?php
$upload_module='conversion';$upload_label='Conversion';
$upload_sample='FINAL_BATCH_CODE | MACINE_NUMBER | DATE_STARTED | OUTPUT | INPUT_CUSTOMER | BAG_TYPE | JOB_ORDER_NO | ...';
require_once __DIR__.'/../includes/upload_modal.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
const CM  = <?= json_encode($c_machine)  ?>;
const CS  = <?= json_encode($c_shift)    ?>;
const CB  = <?= json_encode($c_bagtype)  ?>;
const CF  = <?= json_encode($c_fabric)   ?>;
const CJ  = <?= json_encode($c_jo)       ?>;
const CU  = <?= json_encode($c_customer) ?>;

// ── Shared dataset builder ─────────────────────────────────────────────────
function greenDataset(label, data) {
    return {
        label, data,
        backgroundColor: 'rgba(34,197,94,.75)',
        borderColor: '#22c55e',
        borderWidth: 1,
        borderRadius: 4
    };
}

const MULTI_COLORS = [
    '#22c55e','#0ea5e9','#f59e0b','#a855f7','#ef4444',
    '#06b6d4','#f97316','#84cc16','#ec4899','#3b82f6',
    '#10b981','#eab308','#8b5cf6','#14b8a6','#f43f5e'
];

// 1. Machine — bar
new Chart(document.getElementById('cMachine'), {
    type: 'bar',
    data: { labels: CM.labels, datasets: [ greenDataset('Output (bags)', CM.v1) ] },
    options: { responsive: true, maintainAspectRatio: false,
        animation: { duration: 800 },
        plugins: { legend: { position: 'top', labels: { color: '#94a3b8' } } },
        scales: {
            x: { grid: { color: PQM_COLORS.grid }, ticks: { color: '#94a3b8' } },
            y: { grid: { color: PQM_COLORS.grid }, beginAtZero: true,
                 ticks: { color: '#94a3b8', callback: v => v.toLocaleString() } }
        }
    }
});

// 2. Customer — doughnut
new Chart(document.getElementById('cCustomer'), {
    type: 'doughnut',
    data: { labels: CU.labels, datasets: [{
        data: CU.v1,
        backgroundColor: MULTI_COLORS,
        borderWidth: 2, borderColor: '#162032', hoverOffset: 8
    }]},
    options: { responsive: true, maintainAspectRatio: false,
        animation: { animateRotate: true, duration: 900 },
        plugins: {
            legend: { position: 'right', labels: { boxWidth: 12, padding: 8, color: '#94a3b8' } },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' bags' } }
        },
        cutout: '60%'
    }
});

// 3. Shift Personnel — horizontal bar
new Chart(document.getElementById('cShift'), {
    type: 'bar',
    data: { labels: CS.labels, datasets: [ greenDataset('Output (bags)', CS.v1) ] },
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        animation: { duration: 750 },
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: PQM_COLORS.grid }, beginAtZero: true,
                 ticks: { color: '#94a3b8', callback: v => v.toLocaleString() } },
            y: { grid: { color: PQM_COLORS.grid }, ticks: { color: '#94a3b8', font: { size: 10 } } }
        }
    }
});

// 4. Fabric Width-Denier — horizontal bar
new Chart(document.getElementById('cFabric'), {
    type: 'bar',
    data: { labels: CF.labels, datasets: [{
        label: 'Output (bags)', data: CF.v1,
        backgroundColor: 'rgba(168,85,247,.75)',
        borderColor: '#a855f7', borderWidth: 1, borderRadius: 4
    }]},
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        animation: { duration: 800 },
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: PQM_COLORS.grid }, beginAtZero: true,
                 ticks: { color: '#94a3b8', callback: v => v.toLocaleString() } },
            y: { grid: { color: PQM_COLORS.grid }, ticks: { color: '#94a3b8' } }
        }
    }
});

// 5. Bag Type — horizontal bar
new Chart(document.getElementById('cBagType'), {
    type: 'bar',
    data: { labels: CB.labels, datasets: [{
        label: 'Output (bags)', data: CB.v1,
        backgroundColor: 'rgba(14,165,233,.75)',
        borderColor: '#0ea5e9', borderWidth: 1, borderRadius: 4
    }]},
    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        animation: { duration: 800 },
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: PQM_COLORS.grid }, beginAtZero: true,
                 ticks: { color: '#94a3b8', callback: v => v.toLocaleString() } },
            y: { grid: { color: PQM_COLORS.grid }, ticks: { color: '#94a3b8', font: { size: 10 } } }
        }
    }
});

// 6. Job Order — bar
new Chart(document.getElementById('cJO'), {
    type: 'bar',
    data: { labels: CJ.labels, datasets: [{
        label: 'Output (bags)', data: CJ.v1,
        backgroundColor: 'rgba(245,158,11,.75)',
        borderColor: '#f59e0b', borderWidth: 1, borderRadius: 4
    }]},
    options: { responsive: true, maintainAspectRatio: false,
        animation: { duration: 800 },
        plugins: { legend: { position: 'top', labels: { color: '#94a3b8' } } },
        scales: {
            x: { grid: { color: PQM_COLORS.grid }, ticks: { color: '#94a3b8' } },
            y: { grid: { color: PQM_COLORS.grid }, beginAtZero: true,
                 ticks: { color: '#94a3b8', callback: v => v.toLocaleString() } }
        }
    }
});

// Live table search
document.getElementById('tblSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#cTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// ── Date Range Pickers (Flatpickr) ────────────────────────────────────────────
(function initDatePickers() {
    // Dynamically load Flatpickr if not already present
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
        // Apply dark theme overrides to match .pqm-input style
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
                background: #22c55e !important;
                border-color: #22c55e !important;
                color: #052e16 !important;
                font-weight: 700 !important;
            }
            .flatpickr-day.inRange {
                background: rgba(34,197,94,.15) !important;
                border-color: transparent !important;
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

        const fromPicker = flatpickr('#date_from', {
            dateFormat: 'Y-m-d',
            allowInput: false,
            disableMobile: true,
            onChange: function(selectedDates, dateStr) {
                // Auto-open the "to" picker after selecting from-date
                if (selectedDates.length > 0) {
                    toPicker.set('minDate', selectedDates[0]);
                    setTimeout(() => toPicker.open(), 120);
                }
            }
        });

        const toPicker = flatpickr('#date_to', {
            dateFormat: 'Y-m-d',
            allowInput: false,
            disableMobile: true,
            onChange: function(selectedDates) {
                if (selectedDates.length > 0) {
                    fromPicker.set('maxDate', selectedDates[0]);
                }
            }
        });

        // Clear button resets date pickers too
        document.querySelector('a[href="conversion.php"]')?.addEventListener('click', function() {
            fromPicker.clear();
            toPicker.clear();
        });
    });
})();
</script>

<style>
.icon-green { background: rgba(34,197,94,.18); color: #86efac; }
.pqm-input {
    background: #1a3358 !important;
    border: 1px solid rgba(255,255,255,.1) !important;
    color: #f1f5f9 !important;
    border-radius: 8px !important;
}
.pqm-input::placeholder { color: #64748b !important; }
.pqm-input:focus {
    border-color: #22c55e !important;
    box-shadow: 0 0 0 3px rgba(34,197,94,.2) !important;
    outline: none;
}
.pqm-input option { background: #1a3358; }

/* Multi-select machine dropdown (conversion) */
.cnv-multi-wrap { position: relative; }
.cnv-multi-btn {
    width: 100%; display: flex; align-items: center; gap: 6px;
    padding: 4px 10px; font-size: .8rem; cursor: pointer; text-align: left;
    min-height: 31px; user-select: none; border-radius: 8px !important;
}
.cnv-multi-btn.active { border-color: #22c55e !important; color: #86efac !important; }
.cnv-multi-panel {
    display: none; position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 260px; overflow-y: auto; z-index: 1050;
    background: #0f2818; border: 1px solid rgba(34,197,94,.35);
    border-radius: 10px; padding: 6px 4px; box-shadow: 0 8px 24px rgba(0,0,0,.5);
}
.cnv-multi-panel.open { display: block; }
.cnv-multi-opt {
    display: flex; align-items: center; gap: 8px; padding: 5px 10px;
    border-radius: 6px; cursor: pointer; font-size: .8rem; color: #cbd5e1;
    transition: background .15s; white-space: nowrap;
}
.cnv-multi-opt:hover { background: rgba(34,197,94,.12); color: #f1f5f9; }
.cnv-cb { accent-color: #22c55e; width: 14px; height: 14px; cursor: pointer; flex-shrink: 0; }
</style>

<script>
(function () {
    const btn    = document.getElementById('cnvMachineBtn');
    const panel  = document.getElementById('cnvMachinePanel');
    const label  = document.getElementById('cnvMachineLabel');
    const allCb  = document.getElementById('cnvMachineAll');
    const getCbs = () => [...document.querySelectorAll('.cnv-machine-cb')];

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
        if (!document.getElementById('cnvMachineWrap').contains(e.target))
            panel.classList.remove('open');
    });

    syncAllCb();
    updateLabel();
})();
</script>