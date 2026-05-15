<?php
$page_title = 'Weaving';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// ── Filters ──────────────────────────────────────────────────────────────────
$f_month   = $_GET['month']   ?? '';   // M/YYYY
$f_machine = $_GET['machine'] ?? '';
$f_shift   = $_GET['shift']   ?? '';
$f_line    = $_GET['line']    ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_month) {
    list($fm, $fy) = explode('/', $f_month, 2);
    $where[] = "MONTH(STR_TO_DATE(Date_Harvested,'%m/%d/%Y'))=? AND YEAR(STR_TO_DATE(Date_Harvested,'%m/%d/%Y'))=?";
    $params[] = (int)$fm;
    $params[] = (int)$fy;
    $types .= 'ss';
}
if ($f_machine) { $where[] = '`Machine_NO.` = ?';   $params[] = $f_machine; $types .= 's'; }
if ($f_shift)   { $where[] = 'Shift = ?';            $params[] = $f_shift;   $types .= 's'; }
if ($f_line)    { $where[] = 'Line = ?';             $params[] = $f_line;    $types .= 's'; }
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

// Output per Machine — Weight(Kg)
$c_machine = chart_data($conn,
    "SELECT mno AS k, SUM(CAST(wkg AS DECIMAL(10,2))) AS v1
     FROM (SELECT `Machine_NO.` AS mno, `Weight(Kg)` AS wkg FROM weaving WHERE $wsql) t
     GROUP BY mno ORDER BY v1 DESC LIMIT 15",
    $types, $params, 'k', 'v1');
$c_machine['labels'] = array_map(fn($x) => 'M'.$x, $c_machine['labels']);

// Output per Line — Weight(Kg)
$c_line = chart_data($conn,
    "SELECT line_ AS k, SUM(CAST(wkg AS DECIMAL(10,2))) AS v1
     FROM (SELECT Line AS line_, `Weight(Kg)` AS wkg FROM weaving WHERE $wsql) t
     GROUP BY line_ ORDER BY line_",
    $types, $params, 'k', 'v1');
$c_line['labels'] = array_map(fn($x) => 'Line '.$x, $c_line['labels']);

// Output per Shift — Weight(Kg)
$c_shift = chart_data($conn,
    "SELECT shift_ AS k, SUM(CAST(wkg AS DECIMAL(10,2))) AS v1
     FROM (SELECT Shift AS shift_, `Weight(Kg)` AS wkg FROM weaving WHERE $wsql) t
     GROUP BY shift_ ORDER BY shift_",
    $types, $params, 'k', 'v1');
$c_shift['labels'] = array_map(fn($x) => 'Shift '.$x, $c_shift['labels']);

// Output per Fabric Width-Denier — Weight(Kg)
$c_fabric = chart_data($conn,
    "SELECT fabric AS k, SUM(CAST(wkg AS DECIMAL(10,2))) AS v1
     FROM (SELECT CONCAT(`Width(mm)`,'mm-',Denier,'D') AS fabric, `Weight(Kg)` AS wkg FROM weaving WHERE $wsql) t
     GROUP BY fabric ORDER BY v1 DESC LIMIT 10",
    $types, $params, 'k', 'v1');

// ── Filter options ────────────────────────────────────────────────────────────
$opt_months_raw = $conn->query("SELECT DISTINCT Date_Harvested FROM weaving WHERE Date_Harvested IS NOT NULL AND Date_Harvested != '' ORDER BY Date_Harvested");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    $parts = explode('/', $om['Date_Harvested']);
    if (count($parts) === 3) {
        $key = $parts[0] . '/' . $parts[2];
        $label = date('F Y', mktime(0,0,0,(int)$parts[0],1,(int)$parts[2]));
        $opt_months[$key] = $label;
    }
}
uksort($opt_months, function($a,$b){ list($am,$ay)=explode('/',$a); list($bm,$by)=explode('/',$b); return $ay!=$by?$ay-$by:$am-$bm; });

$opt_machines = $conn->query("SELECT DISTINCT `Machine_NO.` v FROM weaving ORDER BY `Machine_NO.`+0");
$opt_shifts   = $conn->query("SELECT DISTINCT Shift v FROM weaving ORDER BY Shift");
$opt_lines    = $conn->query("SELECT DISTINCT Line v FROM weaving ORDER BY Line");

// ── Table ─────────────────────────────────────────────────────────────────────
$rows = qry($conn,
    "SELECT `Machine_NO.` AS machine, Line, Shift,
            `Weight(Kg)` AS weight,
            `NO_of_roll` AS rolls, Loom_Batch_Code AS jo,
            Date_Harvested AS dt,
            CONCAT(`Width(mm)`,'mm-',Denier,'D') AS fabric,
            Remarks
     FROM weaving WHERE $wsql ORDER BY ID DESC LIMIT 50",
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
                        Machine <?= htmlspecialchars($o['v']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
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
            <div class="col-12 col-lg-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-search me-1"></i> Apply
                </button>
                <a href="weaving.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <?php
        $kpis = [
            ['val' => number_format((float)$kpi['tot_weight'], 1), 'label' => 'Total Output (kg)',  'icon' => 'bi-speedometer2', 'cls' => 'icon-blue'],
            ['val' => number_format((int)$kpi['recs']),            'label' => 'Total Records',      'icon' => 'bi-collection',   'cls' => 'icon-teal'],
            ['val' => number_format((int)$kpi['tot_rolls']),       'label' => 'Total Rolls',        'icon' => 'bi-box-seam',     'cls' => 'icon-green'],
            ['val' => '—',                                          'label' => 'Total Waste (kg)',   'icon' => 'bi-slash-circle', 'cls' => 'icon-amber', 'note' => 'No data yet'],
            ['val' => (int)$kpi['machines'],                       'label' => 'Active Machines',    'icon' => 'bi-cpu',          'cls' => 'icon-red'],
            ['val' => (int)$kpi['shifts'],                         'label' => 'Active Shifts',      'icon' => 'bi-clock',        'cls' => 'icon-purple'],
        ];
        foreach ($kpis as $k): ?>
        <div class="col-6 col-xl-2">
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

    <!-- Charts Row 1 — Output per Machine | Output per Line -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-8">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Output (kg) per Machine</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cMachine"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Output (kg) per Line</div>
                <div class="chart-wrapper" style="height:270px">
                    <canvas id="cLine"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 — Output per Shift | Output per Fabric Width-Denier -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-5">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-bar-chart"></i> Output (kg) per Shift</div>
                <div class="chart-wrapper" style="height:255px">
                    <canvas id="cShift"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-7">
            <div class="pqm-card h-100">
                <div class="section-title"><i class="bi bi-grid-3x2-gap"></i> Output (kg) per Fabric Width-Denier</div>
                <div class="chart-wrapper" style="height:255px">
                    <canvas id="cFabric"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 3 — Output per JO (placeholder — no JO data yet) -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="pqm-card">
                <div class="section-title"><i class="bi bi-graph-up"></i> Output (kg) per Job Order</div>
                <div class="d-flex flex-column align-items-center justify-content-center"
                     style="height:180px; border:1px dashed rgba(59,130,246,.3); border-radius:10px; background:rgba(30,58,95,.15);">
                    <i class="bi bi-hourglass-split" style="font-size:2rem;color:#3b82f6;opacity:.5"></i>
                    <div class="mt-2" style="color:#64748b;font-size:.85rem">Job Order (JO) data not yet available</div>
                    <div style="color:#475569;font-size:.75rem;margin-top:4px">This chart will populate once JO data is linked to the weaving records.</div>
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
                        <th>Date</th>
                        <th>Machine</th>
                        <th>Line</th>
                        <th>Shift</th>
                        <th>Output (kg)</th>
                        <th>Waste (kg)</th>
                        <th>Rolls</th>
                        <th>Fabric</th>
                        <th>Job Order</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $rows->fetch_assoc()):
                    $rem = trim($r['Remarks'] ?? '');
                    $rb  = ($rem === '' || strtolower($rem) === 'n/a') ? 'bg-secondary' : 'bg-warning text-dark';
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['dt']) ?></td>
                    <td><span class="badge bg-primary">M<?= htmlspecialchars($r['machine']) ?></span></td>
                    <td><span class="badge" style="background:#1e3a5f;color:#93c5fd;border:1px solid #3b82f655">L<?= htmlspecialchars($r['Line']) ?></span></td>
                    <td><span class="badge bg-secondary">S<?= htmlspecialchars($r['Shift']) ?></span></td>
                    <td class="fw-semibold" style="color:#38bdf8"><?= number_format((float)$r['weight'], 1) ?></td>
                    <td><span style="color:#475569;font-size:.75rem">— N/A</span></td>
                    <td><?= (int)$r['rolls'] ?></td>
                    <td><code style="color:#a78bfa;font-size:.75rem"><?= htmlspecialchars($r['fabric']) ?></code></td>
                    <td><span style="color:#475569;font-size:.75rem">— N/A</span></td>
                    <td><span class="badge <?= $rb ?> process-badge"><?= htmlspecialchars($rem ?: 'N/A') ?></span></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->
</div><!-- /app-layout -->

<?php
$upload_module='weaving';$upload_label='Weaving';
$upload_sample='ID | Series_Number | Date_Harvested | Time_Harvested | Line | Machine_NO. | Shift | Loom_Batch_Code | Length(M) | Weight(Kg) | Width(mm) | Denier | NO_of_roll | Classification_of_roll | Fabric_GSM | Remarks | YARN_BATCHCODE_(WARP1) | ...';
require_once __DIR__.'/../includes/upload_modal.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
const CM = <?= json_encode($c_machine) ?>;
const CL = <?= json_encode($c_line)    ?>;
const CS = <?= json_encode($c_shift)   ?>;
const CF = <?= json_encode($c_fabric)  ?>;

// 1. Machine — bar (Output kg)
new Chart(document.getElementById('cMachine'), {
    type: 'bar',
    data: { labels: CM.labels, datasets: [
        barDataset('Output (kg)', CM.v1, PQM_COLORS.blue),
    ]},
    options: { responsive:true, maintainAspectRatio:false,
        animation:{ duration:800 },
        plugins:{ legend:{ position:'top' } },
        scales:{
            x:{ grid:{ color:PQM_COLORS.grid } },
            y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                ticks:{ callback: v => v.toLocaleString() + ' kg' } }
        }
    }
});

// 2. Line — doughnut (Output kg)
new Chart(document.getElementById('cLine'), {
    type: 'doughnut',
    data: { labels: CL.labels, datasets:[{
        data: CL.v1,
        backgroundColor:['#3b82f6','#0ea5e9','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#f97316','#84cc16','#ec4899'],
        borderWidth:2, borderColor:'#162032', hoverOffset:8
    }]},
    options:{ responsive:true, maintainAspectRatio:false,
        animation:{ animateRotate:true, duration:900 },
        plugins:{ legend:{ position:'right', labels:{ boxWidth:12, padding:8 } },
                  tooltip:{ callbacks:{ label: ctx => ctx.label + ': ' + ctx.parsed.toLocaleString() + ' kg' } } },
        cutout:'60%'
    }
});

// 3. Shift — bar (Output kg)
new Chart(document.getElementById('cShift'), {
    type: 'bar',
    data: { labels: CS.labels, datasets:[
        barDataset('Output (kg)', CS.v1, PQM_COLORS.green),
    ]},
    options:{ responsive:true, maintainAspectRatio:false,
        animation:{ duration:700 },
        plugins:{ legend:{ position:'top' } },
        scales:{
            x:{ grid:{ color:PQM_COLORS.grid } },
            y:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                ticks:{ callback: v => v.toLocaleString() + ' kg' } }
        }
    }
});

// 4. Fabric — horizontal bar (Output kg)
new Chart(document.getElementById('cFabric'), {
    type: 'bar',
    data: { labels: CF.labels, datasets:[ barDataset('Output (kg)', CF.v1, PQM_COLORS.purple) ]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
        animation:{ duration:800 },
        plugins:{ legend:{ display:false },
                  tooltip:{ callbacks:{ label: ctx => ctx.parsed.x.toLocaleString() + ' kg' } } },
        scales:{
            x:{ grid:{ color:PQM_COLORS.grid }, beginAtZero:true,
                ticks:{ callback: v => v.toLocaleString() + ' kg' } },
            y:{ grid:{ color:PQM_COLORS.grid } }
        }
    }
});

// Live table search
document.getElementById('tblSearch').addEventListener('input', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('#wTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<style>
.icon-purple { background: rgba(168,85,247,.18); color: #c084fc; }
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
