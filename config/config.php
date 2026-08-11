<?php
// Initialize session with unique name to prevent localhost session collision
if (session_status() == PHP_SESSION_NONE) {
    session_name('STOCKFLOW_SESSID');
    session_start();
}

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'stock_management');

// Dynamic Base URL detection (supports local web server and Electron app)
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (strpos($host, '54321') !== false) {
    define('BASE_URL', 'http://' . $host . '/');
} else {
    define('BASE_URL', 'http://localhost/stock-register/');
}

// Error Reporting (Development Mode)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
