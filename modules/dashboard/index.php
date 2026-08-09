<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';

// Auth checks
require_login();
$role = $_SESSION['user_role'] ?? 'staff';
$username = $_SESSION['user_name'] ?? 'Owner';

// Fetch Live Statistics
try {
    // 1. Total Products
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    $total_products = intval($stmt->fetchColumn());

    // 2. Stock Value (current_stock * purchase_price)
    $stmt = $pdo->query("SELECT SUM(current_stock * purchase_price) FROM products");
    $total_stock_value = floatval($stmt->fetchColumn() ?? 0);

    // 3. Low Stock Items count
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE current_stock <= reorder_level");
    $low_stock_count = intval($stmt->fetchColumn());

    // 4. Today's Sales (₹)
    $stmt = $pdo->query("SELECT SUM(total_amount) FROM sales WHERE sale_date = CURDATE()");
    $today_sales = floatval($stmt->fetchColumn() ?? 0);

    // 5. Today's Transactions Count
    $stmt = $pdo->query("SELECT COUNT(*) FROM sales WHERE sale_date = CURDATE()");
    $today_transactions = intval($stmt->fetchColumn());

    // 6. Fetch Low Stock Alerts list
    $low_stock_list_stmt = $pdo->query("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.current_stock <= p.reorder_level
        ORDER BY p.current_stock ASC
        LIMIT 5
    ");
    $low_stock_list = $low_stock_list_stmt->fetchAll();

    // 7. Fetch Recent Sales
    $recent_sales_stmt = $pdo->query("
        SELECT s.*, c.name as customer_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        ORDER BY s.sale_date DESC, s.created_at DESC
        LIMIT 5
    ");
    $recent_sales = $recent_sales_stmt->fetchAll();

    // 8. Fetch last 6 months sales and purchases comparison data
    $sales_monthly = [];
    $purchases_monthly = [];
    $months_label = [];
    for ($i = 5; $i >= 0; $i--) {
        $month_start = date('Y-m-01', strtotime("-$i months"));
        $month_end = date('Y-m-t', strtotime("-$i months"));
        $label = date('M Y', strtotime("-$i months"));
        $months_label[] = $label;

        // Sales total
        $s_stmt = $pdo->prepare("SELECT SUM(total_amount) FROM sales WHERE sale_date BETWEEN ? AND ?");
        $s_stmt->execute([$month_start, $month_end]);
        $sales_monthly[] = floatval($s_stmt->fetchColumn() ?? 0);

        // Purchases total
        $p_stmt = $pdo->prepare("SELECT SUM(total_amount) FROM purchases WHERE purchase_date BETWEEN ? AND ?");
        $p_stmt->execute([$month_start, $month_end]);
        $purchases_monthly[] = floatval($p_stmt->fetchColumn() ?? 0);
    }

    // 9. Fetch top 5 selling products
    $top_products_stmt = $pdo->query("
        SELECT SUM(si.quantity) as total_qty, p.name as product_name
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        GROUP BY si.product_id
        ORDER BY total_qty DESC
        LIMIT 5
    ");
    $top_products = $top_products_stmt->fetchAll();
    
    $top_products_labels = [];
    $top_products_data = [];
    foreach ($top_products as $tp) {
        $top_products_labels[] = $tp['product_name'] ?: 'Unknown';
        $top_products_data[] = intval($tp['total_qty']);
    }

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error loading dashboard stats: " . htmlspecialchars($e->getMessage()) . "</div>";
    $total_products = 0;
    $total_stock_value = 0;
    $low_stock_count = 0;
    $today_sales = 0;
    $today_transactions = 0;
    $low_stock_list = [];
    $recent_sales = [];
    $sales_monthly = array_fill(0, 6, 0);
    $purchases_monthly = array_fill(0, 6, 0);
    $months_label = [];
    $top_products_labels = [];
    $top_products_data = [];
}
?>

<!-- Premium Greeting & Action Header Row -->
<div class="row mb-4 bg-navy-sidebar text-white py-3 px-2 rounded-3 shadow-sm align-items-center" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
    <div class="col-12 col-md-6 mb-2 mb-md-0">
        <h4 class="fw-bold mb-1">StockFlow Control Hub</h4>
        <small class="text-secondary" style="color: #94a3b8 !important;">Good afternoon, <strong><?php echo htmlspecialchars($username); ?></strong> — <?php echo date('l, d F Y'); ?></small>
    </div>
    <div class="col-12 col-md-6 text-md-end d-flex flex-wrap gap-2 justify-content-md-end no-print">
        <a href="<?php echo BASE_URL; ?>modules/purchases/add.php" class="btn btn-sm btn-success px-3 py-2 fw-semibold" style="border-radius: 6px;"><i class="fa-solid fa-circle-down me-1"></i> New Purchase</a>
        <a href="<?php echo BASE_URL; ?>modules/sales/add.php" class="btn btn-sm btn-danger px-3 py-2 fw-semibold" style="border-radius: 6px;"><i class="fa-solid fa-circle-up me-1"></i> New Sale</a>
        <a href="<?php echo BASE_URL; ?>modules/customers/index.php" class="btn btn-sm btn-light px-3 py-2 fw-semibold" style="border-radius: 6px;"><i class="fa-solid fa-users me-1"></i> Customers</a>
        <a href="<?php echo BASE_URL; ?>modules/reports/index.php" class="btn btn-sm btn-secondary px-3 py-2 fw-semibold" style="border-radius: 6px;"><i class="fa-solid fa-file-invoice me-1"></i> Reports</a>
    </div>
</div>

<!-- Mockup Colored KPI Cards -->
<div class="row g-3 mb-4">
    <!-- Today's Sales (Green background) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card border-0 text-white shadow-sm" style="background-color: #10b981; border-radius: 12px;">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1">₹<?php echo number_format($today_sales, 2); ?></h3>
                    <small class="text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em; opacity: 0.9;">Today's Sales</small>
                </div>
                <div class="bg-white bg-opacity-25 p-3 rounded-circle" style="width: 54px; height: 54px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-cash-register fa-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Low Stock (Red background) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card border-0 text-white shadow-sm" style="background-color: #ef4444; border-radius: 12px;">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1"><?php echo number_format($low_stock_count); ?></h3>
                    <small class="text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em; opacity: 0.9;">Low Stock Items</small>
                </div>
                <div class="bg-white bg-opacity-25 p-3 rounded-circle" style="width: 54px; height: 54px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-triangle-exclamation fa-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Products (Dark Navy background) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card border-0 text-white shadow-sm" style="background-color: #1e293b; border-radius: 12px;">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1"><?php echo number_format($total_products); ?></h3>
                    <small class="text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em; opacity: 0.9;">Total Products</small>
                </div>
                <div class="bg-white bg-opacity-25 p-3 rounded-circle" style="width: 54px; height: 54px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-box fa-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Inventory Value (Yellow background) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card border-0 text-white shadow-sm" style="background-color: #f59e0b; border-radius: 12px;">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1">₹<?php echo number_format($total_stock_value, 2); ?></h3>
                    <small class="text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em; opacity: 0.9;">Stock Asset Value</small>
                </div>
                <div class="bg-white bg-opacity-25 p-3 rounded-circle" style="width: 54px; height: 54px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-indian-rupee-sign fa-xl"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Comparison Row -->
<div class="row g-4 mb-4">
    <!-- Line Chart: Monthly Trend -->
    <div class="col-12 col-lg-8">
        <div class="card card-premium h-100 shadow-sm border-0" style="border-radius: 12px;">
            <div class="card-premium-header py-3" style="border-bottom: 1px solid var(--border-color);">
                <h6 class="card-premium-title fw-bold text-dark"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Sales & Purchases Comparison (Last 6 Months)</h6>
            </div>
            <div class="card-premium-body">
                <canvas id="monthlyTrendChart" style="max-height: 280px; width: 100%;"></canvas>
            </div>
        </div>
    </div>
    <!-- Doughnut Chart: Top Products -->
    <div class="col-12 col-lg-4">
        <div class="card card-premium h-100 shadow-sm border-0" style="border-radius: 12px;">
            <div class="card-premium-header py-3" style="border-bottom: 1px solid var(--border-color);">
                <h6 class="card-premium-title fw-bold text-dark"><i class="fa-solid fa-chart-pie me-2 text-success"></i>Top Selling Products</h6>
            </div>
            <div class="card-premium-body d-flex align-items-center justify-content-center">
                <?php if (empty($top_products)): ?>
                    <p class="text-muted py-4 mb-0">No sales recorded yet.</p>
                <?php else: ?>
                    <div style="position: relative; height:280px; width:100%;">
                        <canvas id="topProductsChart" style="max-height: 280px; width: 100%;"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Panels: Alerts & Recent Transactions -->
<div class="row g-4 mb-5">
    <!-- Low Stock Alert Panel -->
    <div class="col-12 col-lg-6">
        <div class="card card-premium h-100 shadow-sm border-0" style="border-radius: 12px;">
            <div class="card-premium-header py-3" style="border-bottom: 1px solid var(--border-color);">
                <h6 class="card-premium-title fw-bold text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Critical Low Stock Warnings</h6>
            </div>
            <div class="card-premium-body">
                <?php if (empty($low_stock_list)): ?>
                    <div class="text-center py-4 text-muted" style="font-size: 0.88rem;">
                        <i class="fa-solid fa-circle-check text-success fa-2x mb-2"></i>
                        <p class="mb-0">All items are at safe inventory levels.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="font-size: 0.88rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Product Item</th>
                                    <th>SKU</th>
                                    <th style="text-align: center;">Min Reorder</th>
                                    <th style="text-align: center; color: #b91c1c;">Stock Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($low_stock_list as $p): ?>
                                    <tr>
                                        <td class="fw-semibold text-dark"><?php echo htmlspecialchars($p['name']); ?></td>
                                        <td><code><?php echo htmlspecialchars($p['sku']); ?></code></td>
                                        <td style="text-align: center;"><?php echo number_format($p['reorder_level']); ?></td>
                                        <td style="text-align: center; font-weight: 700; color: #b91c1c;"><?php echo number_format($p['current_stock']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Sales Panel -->
    <div class="col-12 col-lg-6">
        <div class="card card-premium h-100 shadow-sm border-0" style="border-radius: 12px;">
            <div class="card-premium-header py-3" style="border-bottom: 1px solid var(--border-color);">
                <h6 class="card-premium-title fw-bold text-primary"><i class="fa-solid fa-file-invoice me-2"></i>Recent Sales Invoice Logs</h6>
            </div>
            <div class="card-premium-body">
                <?php if (empty($recent_sales)): ?>
                    <p class="text-muted text-center py-4 mb-0" style="font-size: 0.88rem;">No sales invoices logged yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="font-size: 0.88rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Invoice Date</th>
                                    <th>Invoice No</th>
                                    <th>Customer Partner</th>
                                    <th style="text-align: right;">Amount (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_sales as $s): ?>
                                    <tr>
                                        <td><?php echo date('d-M-Y', strtotime($s['sale_date'])); ?></td>
                                        <td><code><?php echo htmlspecialchars($s['invoice_no'] ?: 'INV-' . $s['id']); ?></code></td>
                                        <td class="fw-medium text-dark"><?php echo htmlspecialchars($s['customer_name'] ?: 'Retail Client'); ?></td>
                                        <td style="text-align: right; font-weight: 700; color: var(--navy-dark);">₹<?php echo number_format($s['total_amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Extra Stats Summary Row -->
<div class="row g-3 mb-5 text-center">
    <div class="col-6 col-md-3">
        <div class="p-3 bg-white border rounded-3 shadow-sm">
            <span class="text-muted d-block uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">Today's Invoices</span>
            <strong class="fs-4 text-dark"><?php echo number_format($today_transactions); ?></strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 bg-white border rounded-3 shadow-sm">
            <span class="text-muted d-block uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">Today's Income</span>
            <strong class="fs-4 text-success">₹<?php echo number_format($today_sales, 2); ?></strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 bg-white border rounded-3 shadow-sm">
            <span class="text-muted d-block uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">Catalog Count</span>
            <strong class="fs-4 text-primary"><?php echo number_format($total_products); ?> Items</strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="p-3 bg-white border rounded-3 shadow-sm">
            <span class="text-muted d-block uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">Warehouse Assets</span>
            <strong class="fs-4 text-warning">₹<?php echo number_format($total_stock_value, 2); ?></strong>
        </div>
    </div>
</div>

<!-- Pass dynamic data securely to window scope for Chart.js rendering -->
<script>
    window.stockflowChartsData = {
        months: <?php echo json_encode($months_label); ?>,
        sales: <?php echo json_encode($sales_monthly); ?>,
        purchases: <?php echo json_encode($purchases_monthly); ?>,
        topLabels: <?php echo json_encode($top_products_labels); ?>,
        topData: <?php echo json_encode($top_products_data); ?>
    };
</script>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
