<?php
$page_title = 'Dashboard';
$base_path  = '';
require_once __DIR__ . '/includes/db.php';

// --- Processes in correct order ---
$processes = [
    'extrusion'  => ['label' => 'Extrusion',  'table' => 'extrusion',             'icon' => 'bi-fire',             'color' => 'icon-orange'],
    'weaving'    => ['label' => 'Weaving',    'table' => 'weaving',               'icon' => 'bi-diagram-3',        'color' => 'icon-blue'],
    'lamination' => ['label' => 'Lamination', 'table' => 'laminationimport_fixed', 'icon' => 'bi-layers',           'color' => 'icon-teal'],
    'printing'   => ['label' => 'Printing',   'table' => 'printing',               'icon' => 'bi-printer',          'color' => 'icon-green'],
    'conversion' => ['label' => 'Conversion', 'table' => 'welding',                'icon' => 'bi-arrow-repeat',     'color' => 'icon-amber'],
    'ctsw'       => ['label' => 'CTSW',       'table' => 'ctswtrial',              'icon' => 'bi-scissors',         'color' => 'icon-red'],
    'fqc'        => ['label' => 'FQC',        'table' => null,                    'icon' => 'bi-clipboard2-check', 'color' => 'icon-purple'],
    'packing'    => ['label' => 'Packing',    'table' => null,                    'icon' => 'bi-box-seam',         'color' => 'icon-pink'],
];

$page_map = [
    'extrusion'  => 'pages/extrusion.php',
    'weaving'    => 'pages/weaving.php',
    'lamination' => 'pages/lamination.php',
    'printing'   => 'pages/printing.php',
    'conversion' => 'pages/conversion.php',
    'ctsw'       => 'pages/ctsw.php',
    'fqc'        => 'pages/fqc.php',
    'packing'    => 'pages/packing.php',
];

$stats = [];
foreach ($processes as $key => $proc) {
    if ($proc['table'] === null) {
        $stats[$key] = 0;
    } else {
        $where = $key === 'extrusion' ? " WHERE `date` != '0000-00-00'" : '';
        $r = $conn->query("SELECT COUNT(*) AS total FROM `{$proc['table']}`$where");
        $stats[$key] = $r ? (int)$r->fetch_assoc()['total'] : 0;
    }
}

$total_records = array_sum($stats);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="app-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Page Heading -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <div class="page-heading"><i class="bi bi-speedometer2 me-2 text-primary"></i>Manufacturing Overview</div>
            <div class="page-subheading mt-1">Real-time production data across all processes</div>
        </div>
        <span class="badge bg-primary process-badge">
            <i class="bi bi-database me-1"></i><?= number_format($total_records) ?> Total Records
        </span>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <?php foreach ($processes as $key => $proc): ?>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="pqm-card stat-card" style="<?= $proc['table'] === null ? 'opacity:.7;' : '' ?>">
                <div class="stat-icon <?= $proc['color'] ?>">
                    <i class="<?= $proc['icon'] ?>"></i>
                </div>
                <div>
                    <div class="stat-value">
                        <?= number_format($stats[$key]) ?>
                        <?php if ($proc['table'] === null): ?>
                        <small style="font-size:.65rem;color:var(--muted);font-weight:400;vertical-align:middle;"> No data</small>
                        <?php endif; ?>
                    </div>
                    <div class="stat-label"><?= $proc['label'] ?> Records</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Total -->
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="pqm-card stat-card" style="border-color:rgba(37,99,235,.35);">
                <div class="stat-icon" style="background:rgba(37,99,235,.25);color:#60a5fa;">
                    <i class="bi bi-collection"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($total_records) ?></div>
                    <div class="stat-label">All Processes Combined</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Process Quick-nav Cards -->
    <div class="section-title"><i class="bi bi-grid-1x2"></i> Process Modules</div>
    <div class="row g-3 mb-4">
        <?php foreach ($processes as $key => $proc): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <a href="<?= $page_map[$key] ?>" class="text-decoration-none">
                <div class="pqm-card h-100" style="<?= $proc['table'] === null ? 'opacity:.75;' : '' ?>">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="stat-icon <?= $proc['color'] ?>">
                            <i class="<?= $proc['icon'] ?>"></i>
                        </div>
                        <div>
                            <div class="fw-bold" style="font-size:.95rem"><?= $proc['label'] ?></div>
                            <div class="stat-label">
                                <?php if ($proc['table'] === null): ?>
                                <span style="color:var(--muted);font-style:italic;">No data yet</span>
                                <?php else: ?>
                                <?= number_format($stats[$key]) ?> records
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($proc['table'] === null): ?>
                        <span class="ms-auto badge" style="background:rgba(255,255,255,.07);color:var(--muted);font-size:.7rem;">Coming</span>
                        <?php else: ?>
                        <i class="bi bi-arrow-right-circle ms-auto text-secondary fs-5"></i>
                        <?php endif; ?>
                    </div>
                    <div class="progress" style="height:4px;background:rgba(255,255,255,.06);">
                        <?php $pct = $total_records > 0 ? round($stats[$key] / $total_records * 100) : 0; ?>
                        <div class="progress-bar" style="width:<?= $pct ?>%;background:var(--accent)"></div>
                    </div>
                    <div class="mt-1 text-end stat-label"><?= $pct ?>% of total</div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Recent CTSW Entries Preview Table -->
    <div class="pqm-card">
        <div class="section-title"><i class="bi bi-clock-history"></i> Recent CTSW Entries</div>
        <div class="table-responsive">
            <table class="pqm-table">
                <thead>
                    <tr>
                        <th>Batch Code</th>
                        <th>Customer</th>
                        <th>Machine</th>
                        <th>Good Bags</th>
                        <th>Defects</th>
                        <th>Process Time</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = $conn->query("SELECT FINAL_BATCH_CODE, CUSTOMER, MACHIN_NUMBER,
                                                GOOD_BAGS_IN_COUNT, `DEFECTIVE_BAGS_(COUNT)`,
                                                `PROCESS_TIME`, PRODUCTION_REMARKS
                                         FROM ctswtrial ORDER BY Id DESC LIMIT 8");
                    while ($row = $rows->fetch_assoc()):
                        $remarks = trim($row['PRODUCTION_REMARKS'] ?? '');
                        $badge = match(strtolower($remarks)) {
                            'passed', 'passed '  => 'bg-success',
                            'good', 'good '       => 'bg-primary',
                            'n/a', ''             => 'bg-secondary',
                            default               => 'bg-warning text-dark',
                        };
                    ?>
                    <tr>
                        <td><code style="color:#60a5fa"><?= htmlspecialchars($row['FINAL_BATCH_CODE']) ?></code></td>
                        <td><?= htmlspecialchars($row['CUSTOMER']) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($row['MACHIN_NUMBER']) ?></span></td>
                        <td><?= number_format((int)$row['GOOD_BAGS_IN_COUNT']) ?></td>
                        <td>
                            <span class="<?= (int)$row['DEFECTIVE_BAGS_(COUNT)'] > 50 ? 'text-danger' : 'text-success' ?>">
                                <?= (int)$row['DEFECTIVE_BAGS_(COUNT)'] ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($row['PROCESS_TIME'] ?? '—') ?></td>
                        <td><span class="badge <?= $badge ?> process-badge"><?= htmlspecialchars($remarks ?: 'N/A') ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- main-content -->
<?php require_once __DIR__ . '/includes/footer.php'; ?>