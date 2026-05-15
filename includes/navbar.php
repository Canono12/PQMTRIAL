<?php
$current_page = $current_page ?? basename($_SERVER['SCRIPT_NAME']);
$process_pages = [
    'extrusion.php'  => ['label' => 'Extrusion',  'icon' => 'bi-fire'],
    'weaving.php'    => ['label' => 'Weaving',    'icon' => 'bi-diagram-3'],
    'lamination.php' => ['label' => 'Lamination', 'icon' => 'bi-layers'],
    'printing.php'   => ['label' => 'Printing',   'icon' => 'bi-printer'],
    'conversion.php' => ['label' => 'Conversion', 'icon' => 'bi-arrow-repeat'],
    'ctsw.php'       => ['label' => 'CTSW',        'icon' => 'bi-scissors'],
    'fqc.php'        => ['label' => 'FQC',         'icon' => 'bi-clipboard2-check'],
    'packing.php'    => ['label' => 'Packing',     'icon' => 'bi-box-seam'],
];

$current_process = null;
foreach ($process_pages as $file => $info) {
    if ($current_page === $file) {
        $current_process = $info['label'];
        break;
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-dark pqm-navbar px-3 py-2">
    <!-- Brand -->
    <a class="navbar-brand d-flex align-items-center gap-2 me-4" href="<?= $base_path ?? '' ?>index.php">
        <div class="brand-icon"><i class="bi bi-graph-up-arrow"></i></div>
        <div>
            <div class="brand-title">PQM Dashboard</div>
            <div class="brand-sub">Manufacturing Analytics</div>
        </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
        <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav me-auto">
            <!-- Home -->
            <li class="nav-item">
                <a class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>"
                   href="<?= $base_path ?? '' ?>index.php">
                    <i class="bi bi-house-door me-1"></i> Home
                </a>
            </li>

            <!-- Select Process Dropdown -->
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle <?= $current_process ? 'active' : '' ?>"
                   href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-cpu me-1"></i>
                    <?= $current_process ? 'Process: ' . $current_process : 'Select Process' ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-dark pqm-dropdown">
                    <li><h6 class="dropdown-header"><i class="bi bi-sliders me-1"></i> Manufacturing Processes</h6></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php foreach ($process_pages as $file => $info): ?>
                    <li>
                        <a class="dropdown-item <?= $current_page === $file ? 'active' : '' ?>"
                           href="<?= ($base_path ?? '') ?>pages/<?= $file ?>">
                            <i class="<?= $info['icon'] ?> me-2"></i><?= $info['label'] ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        </ul>

        <!-- Right side -->
        <ul class="navbar-nav ms-auto align-items-center gap-2">
            <li class="nav-item">
                <span class="nav-link text-secondary">
                    <i class="bi bi-calendar3 me-1"></i>
                    <?= date('M d, Y') ?>
                </span>
            </li>
            <li class="nav-item">
                <span class="badge bg-success px-3 py-2">
                    <i class="bi bi-circle-fill me-1" style="font-size:.55rem"></i> Live
                </span>
            </li>
            <!-- Role indicator -->
            <li class="nav-item">
                <?php if (IS_ADMIN): ?>
                <span class="badge px-3 py-2" style="background:rgba(59,130,246,.25);color:#93c5fd;border:1px solid rgba(59,130,246,.35);">
                    <i class="bi bi-shield-lock-fill me-1"></i> Admin
                </span>
                <?php else: ?>
                <span class="badge px-3 py-2" style="background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.25);">
                    <i class="bi bi-eye-fill me-1"></i> Viewer
                </span>
                <?php endif; ?>
            </li>
            <!-- Logout -->
            <li class="nav-item">
                <a href="<?= $base_path ?? '' ?>logout.php"
                   class="btn btn-sm px-3"
                   style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;border-radius:8px;">
                    <i class="bi bi-box-arrow-right me-1"></i> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>