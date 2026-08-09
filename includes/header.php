<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_login();

// Fetch low stock alerts for the header notification bell drawer
$notif_low_stock = [];
$notif_count = 0;
$company_profile = [];
try {
    if (isset($pdo)) {
        $notif_stmt = $pdo->query("SELECT id, name, sku, current_stock, reorder_level FROM products WHERE current_stock <= reorder_level ORDER BY current_stock ASC LIMIT 5");
        $notif_low_stock = $notif_stmt->fetchAll();
        
        $count_stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE current_stock <= reorder_level");
        $notif_count = intval($count_stmt->fetchColumn());

        // Fetch company profile for printing
        $company_profile = get_company_profile($pdo);
    }
} catch (PDOException $e) {
    // Fail silently
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Register — Premium Multi-Product System</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables Bootstrap 5 Styling CDN -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Custom Style -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/custom.css?v=<?php echo time(); ?>">
</head>
<body>

    <!-- Sidebar Integration -->
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content-wrapper">
        <!-- Top Navbar -->
        <nav class="top-navbar">
            <button class="btn btn-outline-secondary me-3" id="sidebar-toggle-btn">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="d-flex align-items-center bg-light border px-3 py-1.5 rounded-pill shadow-sm" style="font-size: 0.82rem; font-weight: 500; border-color: #e2e8f0 !important;">
                <i class="fa-regular fa-calendar-days text-success me-2" style="font-size: 0.9rem;"></i>
                <span class="text-muted me-1" style="font-size: 0.8rem;">Today:</span>
                <span class="fw-bold text-dark"><?php echo date('d M, Y'); ?></span>
            </div>
            
            <div class="d-flex align-items-center gap-3 ms-auto">
                <!-- Notification Bell Dropdown -->
                <div class="dropdown">
                    <a href="#" class="text-secondary position-relative d-inline-block p-1" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="System Notifications">
                        <i class="fa-regular fa-bell fa-lg" style="font-size: 1.15rem;"></i>
                        <?php if ($notif_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; padding: 3px 6px;">
                                <?php echo $notif_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2 mt-2" aria-labelledby="notificationDropdown" style="width: 320px; border-radius: 12px; font-size: 0.88rem;">
                        <li class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark"><i class="fa-solid fa-bell text-warning me-1"></i> Notifications</span>
                            <span class="badge bg-light text-secondary border"><?php echo $notif_count; ?> Alerts</span>
                        </li>
                        <div class="notif-scroll" style="max-height: 280px; overflow-y: auto;">
                            <?php if ($notif_count === 0): ?>
                                <li class="px-3 py-4 text-center text-muted">
                                    <i class="fa-solid fa-circle-check text-success fa-2x mb-2 d-block"></i>
                                    All stock levels are healthy!
                                </li>
                            <?php else: ?>
                                <?php foreach ($notif_low_stock as $ns): ?>
                                    <li class="border-bottom">
                                        <a class="dropdown-item px-3 py-2.5 d-flex gap-3 align-items-start text-wrap" href="<?php echo BASE_URL; ?>modules/products/index.php">
                                            <div class="bg-danger-light text-danger rounded-circle p-2 mt-0.5 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; flex-shrink:0;">
                                                <i class="fa-solid fa-triangle-exclamation" style="font-size: 0.85rem;"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold text-dark" style="font-size: 0.85rem; line-height: 1.2;"><?php echo htmlspecialchars($ns['name']); ?></div>
                                                <small class="text-muted d-block mt-0.5" style="font-size: 0.72rem;">SKU: <?php echo htmlspecialchars($ns['sku']); ?> • Stock: <span class="text-danger fw-bold"><?php echo $ns['current_stock']; ?></span></small>
                                            </div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <li class="text-center pt-2 pb-1">
                            <a class="text-primary fw-semibold text-decoration-none" href="<?php echo BASE_URL; ?>modules/products/index.php" style="font-size: 0.78rem;">View All Catalog Items →</a>
                        </li>
                    </ul>
                </div>

                <!-- User Profile Dropdown -->
                <div class="navbar-user-profile dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle text-dark" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="avatar-initials me-2">
                            <?php 
                            $initials = '';
                            if (isset($_SESSION['user_name'])) {
                                $words = explode(' ', $_SESSION['user_name']);
                                foreach ($words as $w) {
                                    $initials .= strtoupper(substr($w, 0, 1));
                                }
                            }
                            echo !empty($initials) ? substr($initials, 0, 2) : 'US';
                            ?>
                        </div>
                        <div class="d-none d-md-block text-start">
                            <div class="fw-semibold lh-1" style="font-size: 0.9rem;"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></div>
                            <small class="text-muted text-capitalize" style="font-size: 0.75rem;"><?php echo htmlspecialchars($_SESSION['user_role'] ?? 'Staff'); ?></small>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="profileDropdown" style="border-radius: 10px;">
                        <li><a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>modules/auth/logout.php"><i class="fa-solid fa-right-from-bracket me-2 text-danger"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Main Body -->
        <main class="content-body">
            <!-- Dedicated Printable Header (hidden on screen, visible only on print) -->
            <div class="print-header d-none" style="border-bottom: 2px solid #0f172a; padding-bottom: 15px; margin-bottom: 25px;">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <?php if (!empty($company_profile['logo']) && file_exists(dirname(__DIR__) . '/' . $company_profile['logo'])): ?>
                            <img src="<?php echo BASE_URL . $company_profile['logo']; ?>" alt="Logo" style="height: 50px; max-width: 130px; object-fit: contain;">
                        <?php else: ?>
                            <div class="rounded d-flex align-items-center justify-content-center text-white" style="width: 50px; height: 50px; background-color: #0f172a;">
                                <i class="fa-solid fa-cubes fa-lg"></i>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.25rem; letter-spacing: -0.01em;"><?php echo htmlspecialchars($company_profile['company_name'] ?? 'StockFlow Agency'); ?></h4>
                            <small class="text-muted d-block" style="font-size: 0.76rem;"><?php echo htmlspecialchars($company_profile['address'] ?? 'Sector-4, Noida'); ?></small>
                            <small class="text-muted d-block" style="font-size: 0.7rem; font-weight: 500;">GSTIN: <?php echo htmlspecialchars($company_profile['gstin'] ?? 'N/A'); ?></small>
                        </div>
                    </div>
                    <div style="text-align: right; line-height: 1.35; font-size: 0.76rem;">
                        <div class="fw-bold text-dark" style="font-size: 0.92rem; letter-spacing: 0.05em; text-transform: uppercase;">Inventory Flow Log</div>
                        <div class="text-muted">Generated: <?php echo date('d-M-Y h:i A'); ?></div>
                        <div class="text-muted">Phone: <?php echo htmlspecialchars($company_profile['phone'] ?? '+91 9999888877'); ?> | Email: <?php echo htmlspecialchars($company_profile['email'] ?? 'info@stockflow.com'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Flash Alerts -->
            <?php echo display_flash_message(); ?>
