<?php
$current_uri = $_SERVER['REQUEST_URI'];
$role = $_SESSION['user_role'] ?? 'staff';
?>
<aside class="sidebar-wrapper" id="sidebar">
    <div class="sidebar-header d-flex justify-content-between align-items-center">
        <a href="<?php echo BASE_URL; ?>modules/dashboard/index.php" class="sidebar-brand" title="StockFlow System">
            <i class="fa-solid fa-cubes"></i> <span class="brand-text">Stock<span class="brand-accent">Flow</span></span>
        </a>
        <!-- Mobile Sidebar Close Button -->
        <button class="btn btn-link text-white d-lg-none p-0 border-0" id="sidebar-close-btn" style="font-size: 1.25rem; line-height: 1;">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    
    <ul class="sidebar-menu">
        <!-- Dashboard Link (accessible to all) -->
        <li class="sidebar-item <?php echo (strpos($current_uri, 'dashboard') !== false) ? 'active' : ''; ?>">
            <a href="<?php echo BASE_URL; ?>modules/dashboard/index.php" title="Dashboard">
                <i class="fa-solid fa-chart-pie"></i> <span class="menu-text">Dashboard</span>
            </a>
        </li>

        <li class="sidebar-category"><span class="category-text">Catalog & Directory</span></li>
        
        <li class="sidebar-item <?php echo (strpos($current_uri, 'products') !== false) ? 'active' : ''; ?>">
            <a href="<?php echo BASE_URL; ?>modules/products/index.php" title="Products Directory">
                <i class="fa-solid fa-box-open"></i> <span class="menu-text">Products</span>
            </a>
        </li>

        <?php if ($role === 'admin' || $role === 'manager'): ?>
            <li class="sidebar-item <?php echo (strpos($current_uri, 'suppliers') !== false) ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>modules/suppliers/index.php" title="Suppliers Registry">
                    <i class="fa-solid fa-truck-ramp-box"></i> <span class="menu-text">Suppliers</span>
                </a>
            </li>
            <li class="sidebar-item <?php echo (strpos($current_uri, 'customers') !== false) ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>modules/customers/index.php" title="Customers Directory">
                    <i class="fa-solid fa-users"></i> <span class="menu-text">Customers</span>
                </a>
            </li>
        <?php endif; ?>

        <li class="sidebar-category"><span class="category-text">Transactions</span></li>
        
        <li class="sidebar-item <?php echo (strpos($current_uri, 'purchases') !== false) ? 'active' : ''; ?>">
            <a href="<?php echo BASE_URL; ?>modules/purchases/index.php" title="Stock In (Purchases)">
                <i class="fa-solid fa-circle-down text-success"></i> <span class="menu-text">Stock In</span>
            </a>
        </li>

        <li class="sidebar-item <?php echo (strpos($current_uri, 'sales') !== false) ? 'active' : ''; ?>">
            <a href="<?php echo BASE_URL; ?>modules/sales/index.php" title="Stock Out (Sales)">
                <i class="fa-solid fa-circle-up text-danger"></i> <span class="menu-text">Stock Out</span>
            </a>
        </li>

        <?php if ($role === 'admin' || $role === 'manager'): ?>
            <li class="sidebar-item <?php echo (strpos($current_uri, 'adjustments') !== false) ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>modules/adjustments/index.php" title="Inventory Adjustments">
                    <i class="fa-solid fa-sliders"></i> <span class="menu-text">Adjustments</span>
                </a>
            </li>
        <?php endif; ?>

        <li class="sidebar-item <?php echo (strpos($current_uri, 'replacements') !== false) ? 'active' : ''; ?>">
            <a href="<?php echo BASE_URL; ?>modules/replacements/index.php" title="Regulator Swaps & Replacements">
                <i class="fa-solid fa-arrows-spin text-warning"></i> <span class="menu-text">Regulator Swaps</span>
            </a>
        </li>

        <li class="sidebar-category"><span class="category-text">Analytics & Logs</span></li>
        
        <li class="sidebar-item <?php echo (strpos($current_uri, 'reports') !== false) ? 'active' : ''; ?>">
            <a href="<?php echo BASE_URL; ?>modules/reports/index.php" title="Reports & PDF Exports">
                <i class="fa-solid fa-file-invoice"></i> <span class="menu-text">Reports</span>
            </a>
        </li>

        <?php if ($role === 'admin'): ?>
            <li class="sidebar-item <?php echo (strpos($current_uri, 'activity_log') !== false) ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>modules/reports/activity_log.php" title="System Audit Trail Logs">
                    <i class="fa-solid fa-clock-rotate-left"></i> <span class="menu-text">Activity Log</span>
                </a>
            </li>
            <li class="sidebar-item <?php echo (strpos($current_uri, 'settings') !== false) ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>modules/settings/index.php" title="Company Profile Settings">
                    <i class="fa-solid fa-gears"></i> <span class="menu-text">Settings</span>
                </a>
            </li>
            <li class="sidebar-item <?php echo (strpos($current_uri, 'roles') !== false) ? 'active' : ''; ?>">
                <a href="<?php echo BASE_URL; ?>modules/settings/roles.php" title="Roles & Permissions Control">
                    <i class="fa-solid fa-shield-halved"></i> <span class="menu-text">Permissions Matrix</span>
                </a>
            </li>
        <?php endif; ?>
    </ul>
</aside>
