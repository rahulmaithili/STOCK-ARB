<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

// Fetch statistics
try {
    // 1. Total Products
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total_products = $stmt->fetch()['total'];

    // 2. Total Qty In
    $stmt = $pdo->query("SELECT SUM(quantity) as total_in FROM stock_transactions WHERE type = 'IN'");
    $total_in = $stmt->fetch()['total_in'] ?? 0;

    // 3. Total Qty Out
    $stmt = $pdo->query("SELECT SUM(quantity) as total_out FROM stock_transactions WHERE type = 'OUT'");
    $total_out = $stmt->fetch()['total_out'] ?? 0;

    // 4. Current Available Qty
    $current_stock = $total_in - $total_out;

    // Fetch Low Stock Products (Stock < 5)
    $low_stock_query = "
        SELECT p.id, p.name, p.sku,
            (COALESCE((SELECT SUM(quantity) FROM stock_transactions WHERE product_id = p.id AND type = 'IN'), 0) -
             COALESCE((SELECT SUM(quantity) FROM stock_transactions WHERE product_id = p.id AND type = 'OUT'), 0)) AS stock_level
        FROM products p
        HAVING stock_level < 5
        ORDER BY stock_level ASC
        LIMIT 5
    ";
    $low_stock_stmt = $pdo->query($low_stock_query);
    $low_stock_products = $low_stock_stmt->fetchAll();

    // Fetch Recent Activity (Latest 5 Transactions)
    $recent_stmt = $pdo->query("
        SELECT t.*, p.name as product_name, p.sku as product_sku
        FROM stock_transactions t
        JOIN products p ON t.product_id = p.id
        ORDER BY t.transaction_date DESC, t.created_at DESC
        LIMIT 5
    ");
    $recent_transactions = $recent_stmt->fetchAll();

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error loading dashboard data: " . htmlspecialchars($e->getMessage()) . "</div>";
    $total_products = 0;
    $total_in = 0;
    $total_out = 0;
    $current_stock = 0;
    $low_stock_products = [];
    $recent_transactions = [];
}
?>

<!-- Statistics Overview -->
<div class="dashboard-grid">
    <div class="stat-card">
        <div>
            <div class="stat-label">Total Products</div>
            <div class="stat-value"><?php echo number_format($total_products); ?></div>
        </div>
        <div class="stat-icon primary">📦</div>
    </div>
    
    <div class="stat-card stat-in">
        <div>
            <div class="stat-label">Stock In (Purchase)</div>
            <div class="stat-value"><?php echo number_format($total_in); ?></div>
        </div>
        <div class="stat-icon success">📈</div>
    </div>

    <div class="stat-card stat-out">
        <div>
            <div class="stat-label">Stock Out (Sale)</div>
            <div class="stat-value"><?php echo number_format($total_out); ?></div>
        </div>
        <div class="stat-icon danger">📉</div>
    </div>

    <div class="stat-card <?php echo ($current_stock < 10) ? 'stat-warning' : ''; ?>">
        <div>
            <div class="stat-label">Available Stock</div>
            <div class="stat-value"><?php echo number_format($current_stock); ?></div>
        </div>
        <div class="stat-icon <?php echo ($current_stock < 10) ? 'warning' : 'primary'; ?>">📊</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 24px;">
    <!-- Recent Transactions -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recent Stock Activities</h2>
            <a href="stock_entry.php" class="btn btn-primary btn-sm">+ Add Entry</a>
        </div>
        <div class="card-body">
            <?php if (empty($recent_transactions)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 20px 0;">No stock entries recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_transactions as $tx): ?>
                                <tr>
                                    <td><?php echo date('d M, Y', strtotime($tx['transaction_date'])); ?></td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($tx['product_name']); ?></div>
                                        <small style="color: var(--text-muted);"><?php echo htmlspecialchars($tx['product_sku']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($tx['type'] == 'IN'): ?>
                                            <span class="badge badge-in">Stock In</span>
                                        <?php else: ?>
                                            <span class="badge badge-out">Stock Out</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight: 600;"><?php echo number_format($tx['quantity']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Low Stock Alert and Quick Actions -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <!-- Low Stock Alerts -->
        <div class="card" style="flex: 1;">
            <div class="card-header">
                <h2 class="card-title" style="color: var(--danger-dark);">⚠️ Low Stock Warning</h2>
            </div>
            <div class="card-body">
                <?php if (empty($low_stock_products)): ?>
                    <div class="alert alert-success" style="margin-bottom: 0;">
                        <span>✅ All product stock levels are healthy!</span>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">The following products have stock levels below 5 units:</p>
                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($low_stock_products as $prod): ?>
                            <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background-color: var(--danger-light); border: 1px solid #fecaca; border-radius: var(--radius-sm);">
                                <div>
                                    <strong style="color: var(--danger-dark);"><?php echo htmlspecialchars($prod['name']); ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">SKU: <?php echo htmlspecialchars($prod['sku']); ?></div>
                                </div>
                                <span class="badge badge-out" style="font-size: 0.85rem; font-weight: 700;">
                                    <?php echo $prod['stock_level']; ?> left
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Setup -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Quick Actions</h2>
            </div>
            <div class="card-body" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <a href="products.php?action=new" class="btn btn-secondary" style="text-align: center; justify-content: center;">
                    ➕ New Product
                </a>
                <a href="stock_entry.php" class="btn btn-primary" style="text-align: center; justify-content: center;">
                    🔄 Stock Transaction
                </a>
                <a href="register.php" class="btn btn-secondary" style="grid-column: span 2; text-align: center; justify-content: center; background: var(--primary-light); color: var(--primary);">
                    📋 View Stock Register Report
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
