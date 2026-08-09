<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Register Management System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- Sidebar Navigation -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <span>📦 Stock Register</span>
            </a>
        </div>
        <ul class="sidebar-menu">
            <li class="sidebar-item <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                <a href="index.php">📊 Dashboard</a>
            </li>
            <li class="sidebar-item <?php echo ($current_page == 'products.php') ? 'active' : ''; ?>">
                <a href="products.php">🏷️ Products</a>
            </li>
            <li class="sidebar-item <?php echo ($current_page == 'stock_entry.php') ? 'active' : ''; ?>">
                <a href="stock_entry.php">🔄 Stock Entry</a>
            </li>
            <li class="sidebar-item <?php echo ($current_page == 'register.php') ? 'active' : ''; ?>">
                <a href="register.php">📁 Stock Register</a>
            </li>
        </ul>
    </aside>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <header class="top-header">
            <h1 class="page-title">
                <?php
                switch($current_page) {
                    case 'index.php':
                        echo "Dashboard Overview";
                        break;
                    case 'products.php':
                        echo "Product Directory";
                        break;
                    case 'stock_entry.php':
                        echo "Record Stock Movements";
                        break;
                    case 'register.php':
                        echo "Stock Register Ledger";
                        break;
                    default:
                        echo "Stock Register System";
                }
                ?>
            </h1>
            <div style="font-size: 0.95rem; color: var(--text-muted); font-weight: 500;">
                📅 <?php echo date('d M, Y'); ?>
            </div>
        </header>
        <main class="content-container">
