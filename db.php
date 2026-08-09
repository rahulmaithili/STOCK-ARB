<?php
require_once __DIR__ . '/config.php';

try {
    // Connect to MySQL server first (without database to ensure we can create it if missing)
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create database if it does not exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Select the database
    $pdo->exec("USE `" . DB_NAME . "`");
    
    // Create products table
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        sku VARCHAR(100) UNIQUE NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Create stock_transactions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        transaction_date DATE NOT NULL,
        type ENUM('IN', 'OUT') NOT NULL,
        quantity INT NOT NULL,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

} catch (PDOException $e) {
    // If the server connection itself fails (e.g. invalid username/password or MySQL isn't running)
    die("<div style='font-family: sans-serif; padding: 20px; background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; border-radius: 8px; margin: 20px;'>
            <h3 style='margin-top: 0;'>Database Connection Error</h3>
            <p>Could not connect to the MySQL database server. Please check your credentials in <code>config.php</code> and ensure your local MySQL server (like XAMPP, WAMP, or Wsl) is running.</p>
            <p><strong>Error details:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
         </div>");
}
?>
