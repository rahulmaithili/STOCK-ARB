<?php
// Initialize session with unique name to prevent localhost session collision
if (session_status() == PHP_SESSION_NONE) {
    session_name('STOCKFLOW_SESSID');
    session_start();
}

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'stock_management');

// Base URL (modify if host path changes)
define('BASE_URL', 'http://localhost/stock-register/');

// Error Reporting (Development Mode)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
