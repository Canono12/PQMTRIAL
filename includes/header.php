<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Auth guard — redirects to login if no session
require_once __DIR__ . '/auth.php';
// Get current page for active nav state
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' — PQM Dashboard' : 'PQM Manufacturing Dashboard' ?></title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <!-- Custom CSS -->
    <link href="<?= $base_path ?? '' ?>assets/css/dashboard.css" rel="stylesheet">
</head>
<body>
