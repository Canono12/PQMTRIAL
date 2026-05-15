<?php
/**
 * PQM Auth Helper
 * Include this at the top of every protected page.
 * - Viewers: no password needed, session role = 'viewer'
 * - Admin: requires login with password, session role = 'admin'
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If not logged in at all, redirect to login
if (empty($_SESSION['pqm_role'])) {
    $loginPath = (isset($base_path) ? $base_path : '') . 'login.php';
    header('Location: ' . $loginPath);
    exit;
}

define('IS_ADMIN',  $_SESSION['pqm_role'] === 'admin');
define('IS_VIEWER', $_SESSION['pqm_role'] === 'viewer');
