<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION = [];
session_destroy();

// Always redirect to the root login page
// Works whether logout is called from index.php or pages/*.php
$script = $_SERVER['SCRIPT_NAME']; // e.g. /finalpqm/logout.php
$root   = rtrim(dirname($script), '/\\'); // e.g. /finalpqm
header('Location: ' . $root . '/login.php');
exit;