<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

// Fetch product wise stock totals and valuations
try {
    $query = "
        SELECT p.*, c.name as category_name,
            COALESCE((SELECT SUM(pi.quantity) FROM purchase_items pi WHERE pi.product_id = p.id), 0) as total_purchases,
            COALESCE((SELECT SUM(si.quantity) FROM sale_items si WHERE si.product_id = p.id), 0) as total_sales,
            COALESCE((SELECT SUM(quantity) FROM stock_adjustments sa WHERE sa.product_id = p.id), 0) as total_adjustments
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY p.name ASC
    ";
    $stmt = $pdo->query($query);
    $product_report = $stmt->fetchAll();
} catch (PDOException $e) {
    $product_report = [];
    $error = "Failed to query database: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4 no-print">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-boxes-stacked me-2 text-success"></i>Product-Wise Stock Report</h3>
            <p class="text-muted" style="font-size: 0.9rem;">View stock movements and current asset financial valuations.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Reports Dashboard</a>
        </div>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card card-premium">
    <div class="card-premium-header d-flex justify-content-between align-items-center">
        <h5 class="card-premium-title"><i class="fa-solid fa-calculator me-2 text-success"></i>Warehouse Inventory Valuation</h5>
        <div class="no-print d-flex gap-2">
            <button onclick="exportTableToExcel('productWiseTable', 'Product_Wise_Inventory_Report');" class="btn btn-outline-success btn-sm px-3" style="border-radius: 6px;">
                <i class="fa-solid fa-file-excel me-1"></i> Export Excel
            </button>
            <button onclick="window.print();" class="btn btn-outline-secondary btn-sm px-3" style="border-radius: 6px;">
                <i class="fa-solid fa-print me-1"></i> Print PDF
            </button>
        </div>
    </div>
    
    <div class="card-premium-body">
        <!-- Live summary metrics -->
        <?php 
        $grand_total_items = 0;
        $grand_total_valuation = 0;
        foreach ($product_report as $p) {
            $grand_total_items += $p['current_stock'];
            $grand_total_valuation += ($p['current_stock'] * $p['purchase_price']);
        }
        ?>
        <div class="row g-3 mb-4 no-print">
            <div class="col-12 col-sm-6">
                <div class="p-3 bg-light rounded-3 border">
                    <span class="text-muted d-block" style="font-size: 0.8rem; font-weight: 500;">TOTAL WAREHOUSE STOCK ITEMS</span>
                    <strong class="fs-4 text-dark"><?php echo number_format($grand_total_items); ?> Units</strong>
                </div>
            </div>
            <div class="col-12 col-sm-6">
                <div class="p-3 bg-light rounded-3 border">
                    <span class="text-muted d-block" style="font-size: 0.8rem; font-weight: 500;">TOTAL INVENTORY ASSET VALUE (BUYING PRICE)</span>
                    <strong class="fs-4 text-primary">₹<?php echo number_format($grand_total_valuation, 2); ?></strong>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle table-hover" id="productWiseTable">
                <thead class="table-light text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: 700;">
                    <tr>
                        <th>Product Details</th>
                        <th>Category</th>
                        <th style="text-align: right; width: 110px;">Buying (₹)</th>
                        <th style="text-align: right; width: 110px;">Selling (₹)</th>
                        <th style="text-align: center; width: 90px;">Opening</th>
                        <th style="text-align: center; width: 90px;">Purchased</th>
                        <th style="text-align: center; width: 90px;">Sold</th>
                        <th style="text-align: center; width: 90px;">Adjusted</th>
                        <th style="text-align: center; width: 90px;">In Hand</th>
                        <th style="text-align: right; width: 140px;">Valuation (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($product_report)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4 text-muted">No items in inventory.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($product_report as $row): 
                            $val = $row['current_stock'] * $row['purchase_price'];
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($row['name']); ?></div>
                                    <small class="text-muted">SKU: <?php echo htmlspecialchars($row['sku']); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border px-2 py-1">
                                        <?php echo htmlspecialchars($row['category_name'] ?: 'Uncategorized'); ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">₹<?php echo number_format($row['purchase_price'], 2); ?></td>
                                <td style="text-align: right;">₹<?php echo number_format($row['selling_price'], 2); ?></td>
                                <td style="text-align: center;"><?php echo number_format($row['opening_stock']); ?></td>
                                <td style="text-align: center; color: #047857; font-weight: 500;">+<?php echo number_format($row['total_purchases']); ?></td>
                                <td style="text-align: center; color: #b91c1c; font-weight: 500;">-<?php echo number_format($row['total_sales']); ?></td>
                                <td style="text-align: center; font-weight: 500; color: #475569;">
                                    <?php echo ($row['total_adjustments'] > 0) ? '+' . $row['total_adjustments'] : (($row['total_adjustments'] < 0) ? $row['total_adjustments'] : '-'); ?>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: var(--navy-dark);"><?php echo number_format($row['current_stock']); ?></td>
                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?php echo number_format($val, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Summary valuation -->
                        <tr class="table-light fw-bold">
                            <td colspan="4">Total Summarized Asset Count</td>
                            <td style="text-align: center;">-</td>
                            <td style="text-align: center;">-</td>
                            <td style="text-align: center;">-</td>
                            <td style="text-align: center;">-</td>
                            <td style="text-align: center; font-weight: 700;"><?php echo number_format($grand_total_items); ?></td>
                            <td style="text-align: right; font-weight: 700; color: #047857;">₹<?php echo number_format($grand_total_valuation, 2); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
