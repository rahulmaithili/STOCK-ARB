<?php
require_once __DIR__ . '/config.php';

try {
    // Connect to local SQLite file database.db in the app directory
    $dbPath = dirname(__DIR__) . '/database.db';
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enable foreign keys constraints in SQLite
    $pdo->exec("PRAGMA foreign_keys = ON");

    // Register custom SQLite functions for MySQL compatibility
    $pdo->sqliteCreateFunction('CURDATE', function() {
        return date('Y-m-d');
    });
    $pdo->sqliteCreateFunction('NOW', function() {
        return date('Y-m-d H:i:s');
    });

    // Check if the 'users' table exists, indicating migrations have run
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    $users_table_exists = (bool)$stmt->fetch();

    if (!$users_table_exists) {
        $schemaPath = dirname(__DIR__) . '/schema_sqlite.sql';
        if (file_exists($schemaPath)) {
            $sql = file_get_contents($schemaPath);
            // Execute the schema scripts
            $pdo->exec($sql);
            
            // Seed the default system administrator account
            $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
            $seed_stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
            $seed_stmt->execute(['System Administrator', 'admin@stock.com', $hashed_password]);
        } else {
            throw new Exception("Migration schema script (schema_sqlite.sql) not found at " . $schemaPath);
        }
    }
} catch (Exception $e) {
    die("<div style='font-family: sans-serif; padding: 20px; background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; border-radius: 8px; margin: 20px;'>
            <h3 style='margin-top: 0;'>Database Setup Error</h3>
            <p>Could not connect to or auto-migrate the local SQLite database. Please check your file permissions.</p>
            <p><strong>Error details:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
         </div>");
}
?>
