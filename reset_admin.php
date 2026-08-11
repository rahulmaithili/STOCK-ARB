<?php
// Set CLI mode text header
if (php_sapi_name() !== 'cli') {
    die("Access Denied: This script can only be run from the command line.");
}

require_once __DIR__ . '/config/db.php';

try {
    $default_email = 'admin@stock.com';
    $default_pass = 'admin123';
    $hashed_password = password_hash($default_pass, PASSWORD_DEFAULT);
    
    // Update the admin password in SQLite database.db
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ? AND role = 'admin'");
    $stmt->execute([$hashed_password, $default_email]);
    
    if ($stmt->rowCount() > 0) {
        echo "====================================================\n";
        echo "       ADMIN PASSWORD RESET SUCCESSFUL!\n";
        echo "====================================================\n";
        echo "Your main administrator account has been restored:\n";
        echo "  - Email:    " . $default_email . "\n";
        echo "  - Password: " . $default_pass . "\n";
        echo "====================================================\n";
    } else {
        // If admin account doesn't exist for some reason, insert a new one
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute(['System Administrator', $default_email, $hashed_password]);
        
        echo "====================================================\n";
        echo "    ADMIN ACCOUNT CREATED & RESET SUCCESSFUL!\n";
        echo "====================================================\n";
        echo "A new administrator account has been generated:\n";
        echo "  - Email:    " . $default_email . "\n";
        echo "  - Password: " . $default_pass . "\n";
        echo "====================================================\n";
    }
} catch (Exception $e) {
    echo "DATABASE ERROR: Could not reset password. Details: " . $e->getMessage() . "\n";
}
?>
