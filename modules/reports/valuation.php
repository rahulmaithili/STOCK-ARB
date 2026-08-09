<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$error = '';

// Fetch products with their categories and calculations
try {
    $stmt = $pdo->query("
        SELECT p.*, c.name as category_name 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY p.name ASC
    ");
    $products = $stmt->fetchAll();

    // Summary calculations
    $total_cost = 0;
    $total_sales = 0;
    $total_profit = 0;
    $total_qty = 0;

    foreach ($products as $p) {
        $qty = intval($p['current_stock']);
        $cost_val = $qty * floatval($p['purchase_price']);
        $sales_val = $qty * floatval($p['selling_price']);
        
        $total_qty += $qty;
        $total_cost += $cost_val;
        $total_sales += $sales_val;
        $total_profit += ($sales_val - $cost_val);
    }

} catch (PDOException $e) {
    $products = [];
    $error = "Failed to calculate stock valuation reports: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-start align-sm-items-center gap-3">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-calculator me-2 text-primary"></i>Stock Valuation Report</h3>
            <p class="text-muted" style="font-size: 0.95rem; margin: 0;">Calculate total capital asset value, potential retail returns, and projected margins.</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <button onclick="window.print();" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" style="border-radius: 10px;">
                <i class="fa-solid fa-print"></i> Print Report
            </button>
            <button onclick="exportTableToExcel('valuationTable', 'Stock_Valuation_Report')" class="btn btn-outline-success d-inline-flex align-items-center gap-1" style="border-radius: 10px;">
                <i class="fa-solid fa-file-excel"></i> Export Excel
            </button>
            <a href="index.php" class="btn btn-accent d-inline-flex align-items-center gap-1">
                <i class="fa-solid fa-arrow-left"></i> Reports Hub
            </a>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Valuation Summary Cards -->
<div class="row g-3 mb-4 text-center">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm py-3 px-2 h-100" style="border-radius: 12px; background: linear-gradient(135deg, #1e293b, #0f172a);">
            <span class="text-white opacity-70 fw-semibold mb-1 d-block" style="font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase;">Total Qty In Stock</span>
            <strong class="fs-3 text-white"><?php echo number_format($total_qty); ?> <small style="font-size: 0.8rem; font-weight:400;">units</small></strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm py-3 px-2 h-100" style="border-radius: 12px; background: linear-gradient(135deg, #4f46e5, #3730a3);">
            <span class="text-white opacity-70 fw-semibold mb-1 d-block" style="font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase;">Capital Valuation (Cost)</span>
            <strong class="fs-3 text-white">₹<?php echo number_format($total_cost, 2); ?></strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm py-3 px-2 h-100" style="border-radius: 12px; background: linear-gradient(135deg, #10b981, #065f46);">
            <span class="text-white opacity-70 fw-semibold mb-1 d-block" style="font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase;">Expected Value (Retail)</span>
            <strong class="fs-3 text-white">₹<?php echo number_format($total_sales, 2); ?></strong>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm py-3 px-2 h-100" style="border-radius: 12px; background: linear-gradient(135deg, #f59e0b, #b45309);">
            <span class="text-white opacity-70 fw-semibold mb-1 d-block" style="font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase;">Projected Profit Margin</span>
            <strong class="fs-3 text-white">₹<?php echo number_format($total_profit, 2); ?></strong>
        </div>
    </div>
</div>

<!-- Main Table Card -->
<div class="card card-premium">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-list-check me-2"></i>Itemized Valuation Details</h5>
    </div>
    <div class="card-premium-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable" id="valuationTable" style="font-size: 0.88rem;">
                <thead>
                    <tr>
                        <th>Product Item</th>
                        <th>SKU</th>
                        <th style="text-align: center;">Stock Qty</th>
                        <th style="text-align: right;">Cost Rate (₹)</th>
                        <th style="text-align: right;">Total Cost (₹)</th>
                        <th style="text-align: right;">Retail Rate (₹)</th>
                        <th style="text-align: right;">Total Retail (₹)</th>
                        <th style="text-align: right;">Profit Margin (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): 
                        $qty = intval($p['current_stock']);
                        $cost_val = $qty * floatval($p['purchase_price']);
                        $sales_val = $qty * floatval($p['selling_price']);
                        $profit_val = $sales_val - $cost_val;
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($p['name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($p['category_name'] ?: 'Uncategorized'); ?></small>
                            </td>
                            <td>
                                <code class="bg-light text-secondary px-2 py-1 rounded" style="font-size: 0.78rem;">
                                    <?php echo htmlspecialchars($p['sku']); ?>
                                </code>
                            </td>
                            <td style="text-align: center; font-weight: 600;">
                                <?php echo number_format($qty); ?> <small class="text-muted" style="font-size: 0.73rem;"><?php echo htmlspecialchars($p['unit']); ?></small>
                            </td>
                            <td style="text-align: right;">
                                ₹<?php echo number_format($p['purchase_price'], 2); ?>
                            </td>
                            <td style="text-align: right; font-weight: 500;">
                                ₹<?php echo number_format($cost_val, 2); ?>
                            </td>
                            <td style="text-align: right;">
                                ₹<?php echo number_format($p['selling_price'], 2); ?>
                            </td>
                            <td style="text-align: right; font-weight: 500;">
                                ₹<?php echo number_format($sales_val, 2); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: <?php echo $profit_val > 0 ? '#10b981' : ($profit_val < 0 ? '#ef4444' : '#64748b'); ?>;">
                                ₹<?php echo number_format($profit_val, 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
