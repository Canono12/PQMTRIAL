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
            <div class="col-12 d-flex gap-2 mt-1">
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
</script>