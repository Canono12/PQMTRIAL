<?php
// Database Configuration
define('DB_HOST', 'sql108.infinityfree.com');
define('DB_USER', 'if0_41924253');
define('DB_PASS', 'aCxJHRM3E4');
define('DB_NAME', 'if0_41924253_pqm_data');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

$conn->set_charset('utf8mb4');
