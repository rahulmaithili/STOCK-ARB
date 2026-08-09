<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect to dashboard if logged in (require_login will redirect to modules/auth/login.php if not logged in)
require_login();

header("Location: " . BASE_URL . "modules/dashboard/index.php");
exit();
?>
