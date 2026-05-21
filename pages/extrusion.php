<?php
$page_title = 'Extrusion';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$f_month      = $_GET['month']      ?? '';   // YYYY-MM
$f_date_from  = $_GET['date_from']  ?? '';   // YYYY-MM-DD
$f_date_to    = $_GET['date_to']    ?? '';   // YYYY-MM-DD
$f_machine    = $_GET['machine']    ?? '';
$f_shift      = $_GET['shift']      ?? '';
$f_line       = array_filter(array_map('trim', (array)($_GET['line'] ?? [])));
$f_denier     = $_GET['denier']     ?? '';
$f_bobbin     = $_GET['bobbin']     ?? '';
$f_search     = trim($_GET['search']    ?? '');

$where  = ['1=1'];
$params = [];
$types  = '';

if ($f_month)     { $where[] = "`date` LIKE ?";                              $params[] = $f_month . '%'; $types .= 's'; }
if ($f_date_from) { $where[] = "`date` >= ?";                               $params[] = $f_date_from;   $types .= 's'; }
if ($f_date_to)   { $where[] = "`date` <= ?";                               $params[] = $f_date_to;     $types .= 's'; }
if ($f_shift)     { $where[] = "`shift` = ?";                               $params[] = $f_shift;       $types .= 's'; }
if (!empty($f_line)) {
    $placeholders = implode(',', array_fill(0, count($f_line), '?'));
    $where[] = "`line` IN ($placeholders)";
    foreach ($f_line as $l) { $params[] = $l; $types .= 's'; }
}
if ($f_denier)    { $where[] = "`denier` = ?";                              $params[] = $f_denier;      $types .= 's'; }
if ($f_bobbin)    { $where[] = "`bobbin_type` = ?";                         $params[] = $f_bobbin;      $types .= 's'; }
if ($f_search)    {
    $like = '%' . $f_search . '%';
    $where[] = "(`bobbin_batchcode` LIKE ? OR `line` LIKE ? OR `bobbin_type` LIKE ? OR `qc_remarks` LIKE ? OR `denier` LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sssss';
}

$wsql = implode(' AND ', $where);

// ── Query helper ──────────────────────────────────────────────────────────────
function exqry($conn, $sql, $t = '', $p = []) {
    $s = $conn->prepare($sql);
    if (!$s) die('<pre style="color:red">SQL Error: ' . htmlspecialchars($conn->error) . '<br>Query: ' . htmlspecialchars($sql) . '</pre>');
    if ($t && $p) $s->bind_param($t, ...$p);
    $s->execute();
    return $s->get_result();
}

// ── Detect actual columns in the extrusion table ──────────────────────────────
$col_res   = $conn->query("SHOW COLUMNS FROM `extrusion`");
$db_cols   = [];
if ($col_res) { while ($c = $col_res->fetch_assoc()) $db_cols[] = $c['Field']; }

// Map actual DB columns (all lowercase in this schema)
$col_output = 'no_pcs';
$col_weight = 'net_wt';
$col_denier = 'denier';
$col_bobbin = in_array('bobbin_type', $db_cols) ? 'bobbin_type' : null;
$col_line   = in_array('line',        $db_cols) ? 'line'        : null;
$col_shift  = in_array('shift',       $db_cols) ? 'shift'       : null;
$col_machine= null; // no machine_no column in this schema

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi_sql = "SELECT
        COUNT(*) AS recs,
        SUM(`$col_output`) AS tot_output,
        SUM(`$col_weight`) AS tot_weight"
    . ($col_shift  ? ", COUNT(DISTINCT `$col_shift`) AS shifts"       : ", 0 AS shifts")
    . ($col_line   ? ", COUNT(DISTINCT `$col_line`) AS tot_lines"     : ", 0 AS tot_lines")
    . ($col_denier ? ", COUNT(DISTINCT `$col_denier`) AS tot_deniers" : ", 0 AS tot_deniers")
    . ($col_bobbin ? ", COUNT(DISTINCT `$col_bobbin`) AS tot_bobbins" : ", 0 AS tot_bobbins")
    . " FROM extrusion WHERE $wsql AND `date` != '0000-00-00'";

$kpi     = exqry($conn, $kpi_sql, $types, $params)->fetch_assoc();
$has_data = ($kpi['recs'] ?? 0) > 0;

// ── Chart data ────────────────────────────────────────────────────────────────
$c_machine_labels = $c_machine_output = $c_machine_weight = '[]';
$c_line_labels    = $c_line_output    = '[]';
$c_shift_labels   = $c_shift_output   = $c_shift_weight   = '[]';
$c_denier_labels  = $c_denier_output  = $c_denier_weight  = '[]';
$c_bobbin_labels  = $c_bobbin_output  = $c_bobbin_weight  = '[]';

if ($has_data) {

    // Output & Weight by Line (replaces machine chart since no machine col)
    if ($col_line) {
        $r = exqry($conn,
            "SELECT `$col_line` k, SUM(`$col_output`) v1, SUM(`$col_weight`) v2
             FROM extrusion WHERE $wsql AND `date` != '0000-00-00' GROUP BY `$col_line` ORDER BY `$col_line`",
            $types, $params);
        $ml = $mo = $mw = [];
        while ($row = $r->fetch_assoc()) { $ml[] = $row['k']; $mo[] = (float)$row['v1']; $mw[] = (float)$row['v2']; }
        $c_machine_labels = json_encode($ml);
        $c_machine_output = json_encode($mo);
        $c_machine_weight = json_encode($mw);
    }

    // Output per Shift
    if ($col_shift) {
        $r = exqry($conn,
            "SELECT `$col_shift` k, SUM(`$col_output`) v1, SUM(`$col_weight`) v2
             FROM extrusion WHERE $wsql AND `date` != '0000-00-00' GROUP BY `$col_shift` ORDER BY `$col_shift`",
            $types, $params);
        $sl = $so = $sw = [];
        while ($row = $r->fetch_assoc()) { $sl[] = 'Shift ' . $row['k']; $so[] = (float)$row['v1']; $sw[] = (float)$row['v2']; }
        $c_shift_labels = json_encode($sl);
        $c_shift_output = json_encode($so);
        $c_shift_weight = json_encode($sw);
    }

    // Output per Denier
    if ($col_denier) {
        $r = exqry($conn,
            "SELECT `$col_denier` k, SUM(`$col_output`) v1, SUM(`$col_weight`) v2
             FROM extrusion WHERE $wsql AND `date` != '0000-00-00' AND `$col_denier` IS NOT NULL AND `$col_denier` != ''
             GROUP BY `$col_denier` ORDER BY CAST(`$col_denier` AS UNSIGNED) LIMIT 15",
            $types, $params);
        $dl = $do = $dw = [];
        while ($row = $r->fetch_assoc()) { $dl[] = $row['k'] . 'D'; $do[] = (float)$row['v1']; $dw[] = (float)$row['v2']; }
        $c_denier_labels = json_encode($dl);
        $c_denier_output = json_encode($do);
        $c_denier_weight = json_encode($dw);
    }

    // Output per Bobbin Type
    if ($col_bobbin) {
        $r = exqry($conn,
            "SELECT `$col_bobbin` k, SUM(`$col_output`) v1, SUM(`$col_weight`) v2
             FROM extrusion WHERE $wsql AND `date` != '0000-00-00' AND `$col_bobbin` IS NOT NULL AND `$col_bobbin` != ''
             GROUP BY `$col_bobbin` ORDER BY v1 DESC",
            $types, $params);
        $bl = $bo = $bw = [];
        while ($row = $r->fetch_assoc()) { $bl[] = $row['k']; $bo[] = (float)$row['v1']; $bw[] = (float)$row['v2']; }
        $c_bobbin_labels = json_encode($bl);
        $c_bobbin_output = json_encode($bo);
        $c_bobbin_weight = json_encode($bw);
    }
}

// ── Filter option lists ───────────────────────────────────────────────────────
$opt_months_raw = $conn->query(
    "SELECT DISTINCT DATE_FORMAT(`date`,'%Y-%m') AS ym FROM extrusion
     WHERE `date` IS NOT NULL AND `date` != '0000-00-00'
     ORDER BY ym");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    $ym = $om['ym'];
    if (strlen($ym) === 7) {
        [$y, $m] = explode('-', $ym);
        $opt_months[$ym] = date('F Y', mktime(0, 0, 0, (int)$m, 1, (int)$y));
    }
}

$opt_machines = null; // no machine_no in this schema
$opt_shifts   = $col_shift   ? $conn->query("SELECT DISTINCT `$col_shift` v FROM extrusion WHERE `$col_shift` IS NOT NULL AND `$col_shift` != '' ORDER BY `$col_shift`")         : null;
$opt_lines    = $col_line    ? $conn->query("SELECT DISTINCT `$col_line` v FROM extrusion WHERE `$col_line` IS NOT NULL AND `$col_line` != '' ORDER BY `$col_line`")             : null;
$opt_deniers  = $col_denier  ? $conn->query("SELECT DISTINCT `$col_denier` v FROM extrusion WHERE `$col_denier` IS NOT NULL AND `$col_denier` != '' ORDER BY CAST(`$col_denier` AS UNSIGNED)") : null;
$opt_bobbins  = $col_bobbin  ? $conn->query("SELECT DISTINCT `$col_bobbin` v FROM extrusion WHERE `$col_bobbin` IS NOT NULL AND `$col_bobbin` != '' ORDER BY `$col_bobbin`")     : null;

$rows = exqry($conn, "SELECT * FROM extrusion WHERE $wsql AND `date` != '0000-00-00' ORDER BY `date` DESC, `shift` DESC, `time_end` DESC LIMIT 500", $types, $params);

// ── Upload modal vars ─────────────────────────────────────────────────────────
$upload_module = 'extrusion';
$upload_label  = 'Extrusion';
$upload_sample = 'line | date | shift | bobbin_batchcode | class | bobbin_type | gross_wt | no_pcs | bb_wt | pallet_wt | net_wt | denier | time_start | time_end | qc_remarks';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<script>window._pqmBasePath='<?= $base_path ?>';</script>
<div class="app-layout">
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-content">

  <!-- Page Header -->
  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
      <div class="page-heading"><i class="bi bi-fire me-2" style="color:#3b82f6"></i>Extrusion Module</div>
      <div class="page-subheading mt-1">Output &amp; weight analytics for the Extrusion process</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <!-- View Toggle -->
      <div class="ext-toggle-wrap">
        <button type="button" class="ext-toggle-btn active" id="btnCounts" onclick="setView('counts')">
          <i class="bi bi-boxes me-1"></i>Counts
        </button>
        <button type="button" class="ext-toggle-btn" id="btnWeight" onclick="setView('weight')">
          <i class="bi bi-box-seam me-1"></i>Weight
        </button>
      </div>
      <span class="badge process-badge" style="background:#1e3a5f;border:1px solid #3b82f6;color:#93c5fd">
        <i class="bi bi-database me-1"></i>extrusion
      </span>
      <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_extrusion">
        <i class="bi bi-file-earmark-excel"></i> Add Data (Excel)
      </a>
    </div>
  </div>

  <!-- Filters -->
  <div class="pqm-card mb-4">
    <div class="section-title mb-3"><i class="bi bi-funnel"></i> Filters</div>
    <form method="GET" class="row g-2 align-items-end">

      <!-- Row 1: all dropdowns -->
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label text-secondary" style="font-size:.72rem">MONTH</label>
        <select name="month" class="form-select form-select-sm pqm-input">
          <option value="">All Months</option>
          <?php foreach ($opt_months as $mv => $ml): ?>
            <option value="<?= htmlspecialchars($mv) ?>" <?= $f_month === $mv ? 'selected' : '' ?>>
              <?= htmlspecialchars($ml) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($opt_shifts): ?>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label text-secondary" style="font-size:.72rem">SHIFT</label>
        <select name="shift" class="form-select form-select-sm pqm-input">
          <option value="">All Shifts</option>
          <?php while ($o = $opt_shifts->fetch_assoc()): ?>
            <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_shift === $o['v'] ? 'selected' : '' ?>>
              Shift <?= htmlspecialchars($o['v']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($opt_lines): ?>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label text-secondary" style="font-size:.72rem">LINE</label>
        <div class="ctsw-multi-wrap" id="extLineWrap">
          <button type="button" class="ctsw-multi-btn pqm-input <?= !empty($f_line) ? 'active' : '' ?>" id="extLineBtn">
            <span id="extLineLabel">
              <?php if (!empty($f_line)): ?>
                <?= count($f_line) === 1 ? htmlspecialchars($f_line[0]) : count($f_line) . ' lines selected' ?>
              <?php else: ?>All Lines<?php endif; ?>
            </span>
            <i class="bi bi-chevron-down" style="font-size:.7rem;margin-left:auto;opacity:.6"></i>
          </button>
          <div class="ctsw-multi-panel" id="extLinePanel">
            <label class="ctsw-multi-opt">
              <input type="checkbox" id="extLineAll" class="ctsw-cb">
              <span style="color:#94a3b8;font-style:italic">All Lines</span>
            </label>
            <div style="border-top:1px solid rgba(255,255,255,.07);margin:3px 0"></div>
            <?php
            $lines_list = [];
            while ($o = $opt_lines->fetch_assoc()) $lines_list[] = $o['v'];
            foreach ($lines_list as $lv):
              $checked = in_array((string)$lv, array_map('strval', $f_line)) ? 'checked' : '';
            ?>
            <label class="ctsw-multi-opt">
              <input type="checkbox" name="line[]" value="<?= htmlspecialchars($lv) ?>"
                     class="ctsw-cb ext-line-cb" <?= $checked ?>>
              <span><?= htmlspecialchars($lv) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($opt_deniers): ?>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label text-secondary" style="font-size:.72rem">DENIER</label>
        <select name="denier" class="form-select form-select-sm pqm-input">
          <option value="">All Deniers</option>
          <?php while ($o = $opt_deniers->fetch_assoc()): ?>
            <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_denier === $o['v'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($o['v']) ?>D
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($opt_bobbins): ?>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label text-secondary" style="font-size:.72rem">BOBBIN TYPE</label>
        <select name="bobbin" class="form-select form-select-sm pqm-input">
          <option value="">All Bobbins</option>
          <?php while ($o = $opt_bobbins->fetch_assoc()): ?>
            <option value="<?= htmlspecialchars($o['v']) ?>" <?= $f_bobbin === $o['v'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($o['v']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <?php endif; ?>

      <!-- Row 2: date range + buttons -->
      <div class="col-12"></div><!-- force wrap -->
      <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label text-secondary" style="font-size:.72rem">
          <i class="bi bi-calendar-range me-1" style="color:#22c55e"></i>DATE RANGE
        </label>
        <input type="hidden" name="date_from" id="ext_date_from" value="<?= htmlspecialchars($f_date_from) ?>">
        <input type="hidden" name="date_to"   id="ext_date_to"   value="<?= htmlspecialchars($f_date_to) ?>">
        <input type="text" id="ext_date_range"
               class="form-control form-control-sm pqm-input"
               placeholder="Start date — End date"
               autocomplete="off" readonly>
      </div>
      <div class="col-auto d-flex gap-2 align-items-end">
        <button type="submit" class="btn btn-primary btn-sm px-3">
          <i class="bi bi-search me-1"></i> Apply
        </button>
        <a href="extrusion.php" class="btn btn-outline-secondary btn-sm px-3">
          <i class="bi bi-x-circle me-1"></i> Clear
        </a>
      </div>

    </form>
  </div>

  <?php if (!$has_data): ?>
  <!-- Empty state -->
  <div class="pqm-card">
    <div class="d-flex flex-column align-items-center justify-content-center py-5"
         style="border:1px dashed rgba(59,130,246,.3);border-radius:10px;background:rgba(30,58,95,.12);">
      <i class="bi bi-fire" style="font-size:3rem;color:#3b82f6;opacity:.4"></i>
      <div class="mt-3 fw-semibold" style="color:#94a3b8;font-size:1rem">No Extrusion Data Yet</div>
      <div class="mt-1" style="color:#64748b;font-size:.85rem">Upload an Excel file to populate charts and records.</div>
      <a href="#" class="pqm-upload-trigger-btn mt-3" data-bs-toggle="modal" data-bs-target="#uploadModal_extrusion">
        <i class="bi bi-file-earmark-excel"></i> Upload Excel File
      </a>
    </div>
  </div>

  <?php else: ?>

  <!-- ── KPI Cards ────────────────────────────────────────────────────────── -->
  <?php
  $tot_out = (float)($kpi['tot_output'] ?? 0);
  $tot_wt  = (float)($kpi['tot_weight'] ?? 0);
  ?>
  <!-- Counts KPIs -->
  <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 row-cols-xl-6 ext-kpi-row ext-view-counts">
    <?php
    $kpis_counts = [
        ['val' => number_format($kpi['recs']),    'label' => 'Total Records',     'icon' => 'bi-collection',          'cls' => 'icon-blue'],
        ['val' => number_format($tot_out),         'label' => 'Total Pcs (no_pcs)','icon' => 'bi-graph-up-arrow',      'cls' => 'icon-green'],
        ['val' => $kpi['shifts'],                  'label' => 'Active Shifts',     'icon' => 'bi-clock',               'cls' => 'icon-teal'],
        ['val' => $kpi['tot_lines'],               'label' => 'Active Lines',      'icon' => 'bi-layout-three-columns','cls' => 'icon-orange'],
        ['val' => $kpi['tot_deniers'],             'label' => 'Deniers',           'icon' => 'bi-rulers',              'cls' => 'icon-purple'],
        ['val' => $kpi['tot_bobbins'],             'label' => 'Bobbin Types',      'icon' => 'bi-circle',              'cls' => 'icon-green2'],
    ];
    foreach ($kpis_counts as $k): ?>
    <div class="col">
      <div class="pqm-card stat-card">
        <div class="stat-icon <?= $k['cls'] ?>"><i class="bi <?= $k['icon'] ?>"></i></div>
        <div>
          <div class="stat-value"><?= $k['val'] ?></div>
          <div class="stat-label"><?= $k['label'] ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <!-- Weight KPIs -->
  <div class="row g-2 mb-4 row-cols-2 row-cols-md-3 row-cols-xl-6 ext-kpi-row ext-view-weight" style="display:none!important">
    <?php
    $kpis_weight = [
        ['val' => number_format($kpi['recs']),    'label' => 'Total Records',     'icon' => 'bi-collection',          'cls' => 'icon-blue'],
        ['val' => number_format($tot_wt),          'label' => 'Total Net Wt (g)',  'icon' => 'bi-box-seam',            'cls' => 'icon-amber'],
        ['val' => $kpi['shifts'],                  'label' => 'Active Shifts',     'icon' => 'bi-clock',               'cls' => 'icon-teal'],
        ['val' => $kpi['tot_lines'],               'label' => 'Active Lines',      'icon' => 'bi-layout-three-columns','cls' => 'icon-orange'],
        ['val' => $kpi['tot_deniers'],             'label' => 'Deniers',           'icon' => 'bi-rulers',              'cls' => 'icon-purple'],
        ['val' => $kpi['tot_bobbins'],             'label' => 'Bobbin Types',      'icon' => 'bi-circle',              'cls' => 'icon-green2'],
    ];
    foreach ($kpis_weight as $k): ?>
    <div class="col">
      <div class="pqm-card stat-card">
        <div class="stat-icon <?= $k['cls'] ?>"><i class="bi <?= $k['icon'] ?>"></i></div>
        <div>
          <div class="stat-value"><?= $k['val'] ?></div>
          <div class="stat-label"><?= $k['label'] ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── COUNTS VIEW: Charts ──────────────────────────────────────────────── -->
  <div class="ext-view-counts">
    <div class="row g-4 mb-4">
      <div class="col-lg-8">
        <div class="pqm-card h-100">
          <div class="chart-title mb-3">
            <i class="bi bi-boxes me-2" style="color:#22c55e"></i>Output (Pcs) by Line
          </div>
          <canvas id="chartLineC" height="100"></canvas>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="pqm-card h-100">
          <div class="chart-title mb-3">
            <i class="bi bi-clock me-2" style="color:#fb923c"></i>Pcs per Shift
          </div>
          <canvas id="chartShiftC" height="200"></canvas>
        </div>
      </div>
    </div>
    <div class="row g-4 mb-4">
      <div class="col-lg-6">
        <div class="pqm-card h-100">
          <div class="chart-title mb-3">
            <i class="bi bi-rulers me-2" style="color:#e879f9"></i>Pcs per Denier
          </div>
          <canvas id="chartDenierC" height="220"></canvas>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="pqm-card h-100">
          <div class="chart-title mb-3">
            <i class="bi bi-circle me-2" style="color:#34d399"></i>Pcs per Bobbin Type
          </div>
          <canvas id="chartBobbinC" height="220"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- ── WEIGHT VIEW: Charts ───────────────────────────────────────────────── -->
  <div class="ext-view-weight" style="display:none">
    <div class="row g-4 mb-4">
      <div class="col-lg-8">
        <div class="pqm-card h-100">
          <div class="chart-title mb-3">
            <i class="bi bi-box-seam me-2" style="color:#f59e0b"></i>Net Weight (g) by Line
          </div>
          <canvas id="chartLineW" height="100"></canvas>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="pqm-card h-100">
          <div class="chart-title mb-3">
            <i class="bi bi-clock me-2" style="color:#fb923c"></i>Net Weight per Shift
          </div>
          <canvas id="chartShiftW" height="200"></canvas>
        </div>
      </div>
    </div>
    <div class="row g-4 mb-4">
      <div class="col-lg-6">
        <div class="pqm-card h-100">
          <div class="chart-title mb-3">
            <i class="bi bi-rulers me-2" style="color:#e879f9"></i>Net Weight per Denier
          </div>
          <canvas id="chartDenierW" height="220"></canvas>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="pqm-card h-100">
          <div class="chart-title mb-3">
            <i class="bi bi-circle me-2" style="color:#34d399"></i>Net Weight per Bobbin Type
          </div>
          <canvas id="chartBobbinW" height="220"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Records Table ────────────────────────────────────────────────────── -->
  <div class="pqm-card">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <div class="chart-title mb-0">
        <i class="bi bi-table"></i> Extrusion Records
        <span class="ms-1" style="font-size:.75rem;color:#22c55e;"><i class="bi bi-sort-down me-1"></i>Latest first</span>
        <span class="badge ms-2" style="background:rgba(59,130,246,.15);color:#93c5fd;font-size:.75rem;" id="extRowCount">
          <?= number_format($kpi['recs']) ?> records
        </span>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap">
        <div class="d-flex align-items-center gap-1" style="position:relative;">
          <input type="text" id="tableSearch" class="form-control form-control-sm pqm-input"
                 placeholder="Search table…" style="max-width:200px;padding-right:2rem;"
                 value="<?= htmlspecialchars($f_search) ?>"
                 oninput="extDoSearch(this.value)">
          <button id="tableSearchClear" onclick="extClearSearch()"
                  style="display:<?= $f_search ? 'flex' : 'none' ?>;position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;line-height:1;font-size:.85rem;"
                  title="Clear search"><i class="bi bi-x-circle-fill"></i></button>
        </div>
        <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_extrusion"
           style="white-space:nowrap;">
          <i class="bi bi-file-earmark-excel"></i> Add Data
        </a>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table pqm-table" id="extrusionTable">
        <thead><tr>
          <?php
          // Render headers dynamically from actual columns
          $header_map = [
              'line'             => 'Line',
              'date'             => 'Date',
              'shift'            => 'Shift',
              'bobbin_batchcode' => 'Batch Code',
              'class'            => 'Class',
              'bobbin_type'      => 'Bobbin Type',
              'gross_wt'         => 'Gross Wt (g)',
              'no_pcs'           => 'Output (Pcs)',
              'bb_wt'            => 'BB Wt (g)',
              'pallet_wt'        => 'Pallet Wt (g)',
              'net_wt'           => 'Net Wt (g)',
              'denier'           => 'Denier',
              'time_start'       => 'Time Start',
              'time_end'         => 'Time End',
              'qc_remarks'       => 'QC Remarks',
          ];
          foreach ($db_cols as $col) {
              $label = $header_map[$col] ?? $col;
              echo "<th>" . htmlspecialchars($label) . "</th>";
          }
          ?>
        </tr></thead>
        <tbody>
        <?php while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <?php foreach ($db_cols as $col): ?>
            <td><?= htmlspecialchars($r[$col] ?? '') ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div></div>

<?php require_once __DIR__ . '/../includes/upload_modal.php'; ?>

<?php if ($has_data): ?>
<script>
const CD = { color: '#94a3b8', grid: 'rgba(148,163,184,.08)' };
const PALETTE = [
  'rgba(59,130,246,.8)','rgba(34,197,94,.8)','rgba(245,158,11,.8)',
  'rgba(167,139,250,.8)','rgba(56,189,248,.8)','rgba(251,146,60,.8)',
  'rgba(232,121,249,.8)','rgba(52,211,153,.8)','rgba(248,113,113,.8)'
];
const scaleOpts = {
  x: { ticks: { color: CD.color }, grid: { color: CD.grid } },
  y: { ticks: { color: CD.color }, grid: { color: CD.grid } }
};

// ── DATA from PHP ─────────────────────────────────────────────────────────────
const LINE_LABELS  = <?= $c_machine_labels ?>;
const LINE_PCS     = <?= $c_machine_output ?>;
const LINE_WT      = <?= $c_machine_weight ?>;
const SHIFT_LABELS = <?= $c_shift_labels ?>;
const SHIFT_PCS    = <?= $c_shift_output ?>;
const SHIFT_WT     = <?= $c_shift_weight ?>;
const DENIER_LABELS= <?= $c_denier_labels ?>;
const DENIER_PCS   = <?= $c_denier_output ?>;
const DENIER_WT    = <?= $c_denier_weight ?>;
const BOBBIN_LABELS= <?= $c_bobbin_labels ?>;
const BOBBIN_PCS   = <?= $c_bobbin_output ?>;
const BOBBIN_WT    = <?= $c_bobbin_weight ?>;

// ── COUNTS CHARTS ─────────────────────────────────────────────────────────────
new Chart(document.getElementById('chartLineC'), {
  type: 'bar',
  data: { labels: LINE_LABELS, datasets: [
    { label: 'Output (Pcs)', data: LINE_PCS, backgroundColor: 'rgba(34,197,94,.75)', borderRadius: 4 }
  ]},
  options: { responsive: true, plugins: { legend: { labels: { color: CD.color } } }, scales: scaleOpts }
});

new Chart(document.getElementById('chartShiftC'), {
  type: 'doughnut',
  data: { labels: SHIFT_LABELS, datasets: [{ data: SHIFT_PCS, backgroundColor: PALETTE, borderWidth: 0 }] },
  options: { responsive: true, plugins: { legend: { labels: { color: CD.color } } } }
});

new Chart(document.getElementById('chartDenierC'), {
  type: 'bar',
  data: { labels: DENIER_LABELS, datasets: [
    { label: 'Pcs', data: DENIER_PCS, backgroundColor: 'rgba(232,121,249,.75)', borderRadius: 4 }
  ]},
  options: { responsive: true, plugins: { legend: { display: false } },
    scales: { x: { ticks: { color: CD.color, maxRotation: 45 }, grid: { color: CD.grid } }, y: scaleOpts.y } }
});

new Chart(document.getElementById('chartBobbinC'), {
  type: 'pie',
  data: { labels: BOBBIN_LABELS, datasets: [{ data: BOBBIN_PCS, backgroundColor: PALETTE, borderWidth: 0 }] },
  options: { responsive: true, plugins: { legend: { labels: { color: CD.color } } } }
});

// ── WEIGHT CHARTS ─────────────────────────────────────────────────────────────
new Chart(document.getElementById('chartLineW'), {
  type: 'bar',
  data: { labels: LINE_LABELS, datasets: [
    { label: 'Net Wt (g)', data: LINE_WT, backgroundColor: 'rgba(245,158,11,.75)', borderRadius: 4 }
  ]},
  options: { responsive: true, plugins: { legend: { labels: { color: CD.color } } }, scales: scaleOpts }
});

new Chart(document.getElementById('chartShiftW'), {
  type: 'doughnut',
  data: { labels: SHIFT_LABELS, datasets: [{ data: SHIFT_WT, backgroundColor: PALETTE, borderWidth: 0 }] },
  options: { responsive: true, plugins: { legend: { labels: { color: CD.color } } } }
});

new Chart(document.getElementById('chartDenierW'), {
  type: 'bar',
  data: { labels: DENIER_LABELS, datasets: [
    { label: 'Net Wt (g)', data: DENIER_WT, backgroundColor: 'rgba(245,158,11,.75)', borderRadius: 4 }
  ]},
  options: { responsive: true, plugins: { legend: { display: false } },
    scales: { x: { ticks: { color: CD.color, maxRotation: 45 }, grid: { color: CD.grid } }, y: scaleOpts.y } }
});

new Chart(document.getElementById('chartBobbinW'), {
  type: 'pie',
  data: { labels: BOBBIN_LABELS, datasets: [{ data: BOBBIN_WT, backgroundColor: PALETTE, borderWidth: 0 }] },
  options: { responsive: true, plugins: { legend: { labels: { color: CD.color } } } }
});

// ── TOGGLE ────────────────────────────────────────────────────────────────────
function setView(v) {
  document.querySelectorAll('.ext-view-counts').forEach(el => el.style.display = v === 'counts' ? '' : 'none');
  document.querySelectorAll('.ext-view-weight').forEach(el => el.style.display = v === 'weight' ? '' : 'none');
  document.getElementById('btnCounts').classList.toggle('active', v === 'counts');
  document.getElementById('btnWeight').classList.toggle('active', v === 'weight');
  localStorage.setItem('extView', v);
}
// Restore last view on load
(function(){ const v = localStorage.getItem('extView'); if (v) setView(v); })();

// Search — pure client-side, no page reload, no scroll jump
function extDoSearch(q) {
  const term = q.trim().toLowerCase();
  let visibleCount = 0;
  document.querySelectorAll('#extrusionTable tbody tr').forEach(tr => {
    const text = Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim()).join(' ').toLowerCase();
    const match = term === '' || text.includes(term);
    tr.style.display = match ? '' : 'none';
    if (match) visibleCount++;
  });
  const clearBtn = document.getElementById('tableSearchClear');
  if (clearBtn) clearBtn.style.display = q.trim() ? 'flex' : 'none';
  const countEl = document.getElementById('extRowCount');
  if (countEl) countEl.textContent = term ? visibleCount + ' result' + (visibleCount !== 1 ? 's' : '') : '<?= number_format($kpi["recs"]) ?> records';
}
function extClearSearch() {
  const input = document.getElementById('tableSearch');
  input.value = '';
  extDoSearch('');
  input.focus();
}
(function(){ const v = document.getElementById('tableSearch').value; if (v) extDoSearch(v); })();

// ── Flatpickr date range (same style as ctsw) ─────────────────────────────────
(function(){
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

        const fromHidden = document.getElementById('ext_date_from');
        const toHidden   = document.getElementById('ext_date_to');
        const rangeInput = document.getElementById('ext_date_range');

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

        document.querySelector('a[href="extrusion.php"]')?.addEventListener('click', function() {
            fromHidden.value = '';
            toHidden.value   = '';
        });
    });
})();
</script>

<style>
/* ── Stat KPI cards (ctsw style) ─────────────────────────────────── */
.ext-kpi-row .stat-card {
    display: flex;
    align-items: center;
    gap: .55rem;
    padding: .75rem .9rem;
    overflow: hidden;
}
.ext-kpi-row .stat-icon {
    width: 38px; height: 38px;
    font-size: 1rem;
    flex-shrink: 0;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
}
.ext-kpi-row .stat-card > div:last-child {
    min-width: 0; flex: 1; overflow: hidden;
}
.ext-kpi-row .stat-value {
    font-size: .95rem; font-weight: 700; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    color: #f1f5f9;
}
.ext-kpi-row .stat-label {
    font-size: .68rem; color: var(--text-muted, #94a3b8);
    margin-top: .1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
@media (max-width: 576px) {
    .ext-kpi-row .stat-value { font-size: .85rem; }
    .ext-kpi-row .stat-icon  { width: 32px; height: 32px; font-size: .9rem; }
}
/* ── Multi-select dropdown (ctsw style, blue accent) ─────────────────── */
.ctsw-multi-wrap { position: relative; }
.ctsw-multi-btn {
    width: 100%; display: flex; align-items: center; gap: 6px;
    padding: 4px 10px; font-size: .8rem; cursor: pointer; text-align: left;
    min-height: 31px; user-select: none; border-radius: 8px !important;
}
.ctsw-multi-btn.active { border-color: #3b82f6 !important; color: #93c5fd !important; }
.ctsw-multi-panel {
    display: none; position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 160px; max-height: 260px; overflow-y: auto; z-index: 1055;
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

.icon-blue   { background: rgba(59,130,246,.18);  color: #60a5fa; }
.icon-green  { background: rgba(34,197,94,.18);   color: #4ade80; }
.icon-green2 { background: rgba(52,211,153,.18);  color: #34d399; }
.icon-teal   { background: rgba(20,184,166,.18);  color: #2dd4bf; }
.icon-amber  { background: rgba(245,158,11,.18);  color: #fbbf24; }
.icon-orange { background: rgba(251,146,60,.18);  color: #fb923c; }
.icon-purple { background: rgba(168,85,247,.18);  color: #c084fc; }
.icon-red    { background: rgba(248,113,113,.18); color: #f87171; }

/* ── Table: override Bootstrap light-mode defaults ───────────────────── */
#extrusionTable,
#extrusionTable thead,
#extrusionTable tbody,
#extrusionTable tr,
#extrusionTable th,
#extrusionTable td {
    --bs-table-bg: transparent;
    --bs-table-color: #f1f5f9;
    --bs-table-border-color: rgba(255,255,255,.08);
    color: #f1f5f9 !important;
    background-color: transparent !important;
    border-color: rgba(255,255,255,.08) !important;
}
#extrusionTable thead th {
    color: #94a3b8 !important;
    background: #1a3358 !important;
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: .07em;
    white-space: nowrap;
}
#extrusionTable tbody tr:hover td {
    background: rgba(255,255,255,.04) !important;
}
.ext-toggle-wrap {
    display: flex; background: rgba(15,23,42,.6);
    border: 1px solid rgba(148,163,184,.15); border-radius: 10px; padding: 3px; gap: 2px;
}
.ext-toggle-btn {
    padding: 5px 18px; font-size: .8rem; font-weight: 600; border: none; cursor: pointer;
    border-radius: 8px; background: transparent; color: #64748b;
    transition: all .2s; letter-spacing: .02em;
}
.ext-toggle-btn.active {
    background: #1e3a5f; color: #93c5fd;
    box-shadow: 0 2px 8px rgba(59,130,246,.25);
}
.ext-toggle-btn:hover:not(.active) { color: #94a3b8; background: rgba(255,255,255,.04); }
/* pqm-input base */
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

<script>
(function () {
    const btn    = document.getElementById('extLineBtn');
    const panel  = document.getElementById('extLinePanel');
    const label  = document.getElementById('extLineLabel');
    const allCb  = document.getElementById('extLineAll');
    if (!btn) return;
    const getCbs = () => [...document.querySelectorAll('.ext-line-cb')];

    function updateLabel() {
        const checked = getCbs().filter(c => c.checked);
        if (checked.length === 0) {
            label.textContent = 'All Lines';
            btn.classList.remove('active');
        } else if (checked.length === 1) {
            label.textContent = checked[0].value;
            btn.classList.add('active');
        } else {
            label.textContent = checked.length + ' lines selected';
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
        if (!document.getElementById('extLineWrap').contains(e.target))
            panel.classList.remove('open');
    });

    syncAllCb();
    updateLabel();
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>