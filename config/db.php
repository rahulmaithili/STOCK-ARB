<?php
require_once __DIR__ . '/config.php';

try {
    // Connect to MySQL server without selecting DB first
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create database if it does not exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    // Check if the 'users' table exists, indicating migrations have run
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $users_table_exists = $stmt->rowCount() > 0;

    if (!$users_table_exists) {
        $schemaPath = dirname(__DIR__) . '/schema.sql';
        if (file_exists($schemaPath)) {
            $sql = file_get_contents($schemaPath);
            // Execute the schema scripts
            $pdo->exec($sql);
            
            // Seed the default system administrator account
            $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
            $seed_stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
            $seed_stmt->execute(['System Administrator', 'admin@stock.com', $hashed_password]);
        } else {
            throw new Exception("Migration schema script (schema.sql) not found at " . $schemaPath);
        }
    }
} catch (Exception $e) {
    die("<div style='font-family: sans-serif; padding: 20px; background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; border-radius: 8px; margin: 20px;'>
            <h3 style='margin-top: 0;'>Database Setup Error</h3>
            <p>Could not connect to or auto-migrate the MySQL server. Please make sure MySQL is running in your server panel (XAMPP/WAMP) and database connection values are correct in <code>config/config.php</code>.</p>
            <p><strong>Error details:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
         </div>");
}
?>
