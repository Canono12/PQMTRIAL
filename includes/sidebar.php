<?php
if (!isset($current_page)) {
    $current_page = basename($_SERVER['PHP_SELF']);
}

$nav_items = [
    ['href' => ($base_path ?? '') . 'index.php',          'icon' => 'bi-bar-chart-line',    'label' => 'Dashboard'],
    ['href' => ($base_path ?? '') . 'pages/extrusion.php', 'icon' => 'bi-fire',              'label' => 'Extrusion'],
    ['href' => ($base_path ?? '') . 'pages/weaving.php',   'icon' => 'bi-diagram-3',         'label' => 'Weaving'],
    ['href' => ($base_path ?? '') . 'pages/lamination.php','icon' => 'bi-layers',            'label' => 'Lamination'],
    ['href' => ($base_path ?? '') . 'pages/printing.php',  'icon' => 'bi-printer',           'label' => 'Printing'],
    ['href' => ($base_path ?? '') . 'pages/conversion.php','icon' => 'bi-arrow-repeat',      'label' => 'Conversion'],
    ['href' => ($base_path ?? '') . 'pages/ctsw.php',      'icon' => 'bi-scissors',          'label' => 'CTSW'],
    ['href' => ($base_path ?? '') . 'pages/fqc.php',       'icon' => 'bi-clipboard2-check',  'label' => 'FQC'],
    ['href' => ($base_path ?? '') . 'pages/packing.php',   'icon' => 'bi-box-seam',          'label' => 'Packing'],
];
?>
<aside class="pqm-sidebar d-flex flex-column">
    <div class="sidebar-section-label">NAVIGATION</div>
    <nav class="sidebar-nav flex-grow-1">
        <?php foreach ($nav_items as $item): ?>
        <a href="<?= $item['href'] ?>"
           class="sidebar-link <?= $current_page === basename($item['href']) ? 'active' : '' ?>">
            <i class="<?= $item['icon'] ?> sidebar-icon"></i>
            <span><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-section-label">SYSTEM</div>
        <!-- Logged-in user -->
        <div class="d-flex align-items-center gap-2 px-2 py-1 mb-1">
            <i class="bi <?= IS_ADMIN ? 'bi-shield-lock-fill' : 'bi-eye-fill' ?>"
               style="font-size:.8rem;color:<?= IS_ADMIN ? '#60a5fa' : '#34d399' ?>"></i>
            <small style="color:<?= IS_ADMIN ? '#60a5fa' : '#34d399' ?>">
                <?= htmlspecialchars($_SESSION['pqm_user'] ?? 'Guest') ?>
            </small>
        </div>
        <div class="d-flex align-items-center gap-2 px-2 py-1">
            <div class="status-dot bg-success"></div>
            <small class="text-secondary">DB Connected</small>
        </div>
        <div class="d-flex align-items-center gap-2 px-2 py-1">
            <i class="bi bi-database text-secondary" style="font-size:.8rem"></i>
            <small class="text-secondary">pqm_data</small>
        </div>
    </div>
</aside>