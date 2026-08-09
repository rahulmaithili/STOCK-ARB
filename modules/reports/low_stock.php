<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

// Fetch low stock products
try {
    $stmt = $pdo->query("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.current_stock <= p.reorder_level
        ORDER BY p.current_stock ASC
    ");
    $low_stock = $stmt->fetchAll();
} catch (PDOException $e) {
    $low_stock = [];
    $error = "Failed to query database: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4 no-print">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>Low Stock Warnings</h3>
            <p class="text-muted" style="font-size: 0.9rem;">Keep track of items that require replenishment.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Reports Dashboard</a>
        </div>
    </div>
</div>

<div class="card card-premium">
    <div class="card-premium-header d-flex justify-content-between align-items-center">
        <h5 class="card-premium-title text-danger"><i class="fa-solid fa-list-check me-2"></i>Critical Replenishment Alert Sheet</h5>
        <div class="no-print">
            <button onclick="window.print();" class="btn btn-outline-secondary btn-sm px-3" style="border-radius: 6px;"><i class="fa-solid fa-print me-1"></i>Print List</button>
        </div>
    </div>
    <div class="card-premium-body">
        <?php if (empty($low_stock)): ?>
            <div class="alert alert-success d-flex align-items-center mb-0" role="alert" style="border-radius: 12px; border-left: 5px solid var(--success);">
                <i class="fa-solid fa-circle-check fa-lg me-3 text-success"></i>
                <div>
                    <strong>Success!</strong> All products have safe inventory counts. No items are below reorder threshold levels.
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger d-flex align-items-center mb-4" role="alert" style="border-radius: 12px; border-left: 5px solid var(--danger);">
                <i class="fa-solid fa-circle-exclamation fa-lg me-3 text-danger"></i>
                <div>
                    <strong>Reorder Alerts!</strong> The following <strong><?php echo count($low_stock); ?></strong> product items require urgent replenishment.
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>SKU</th>
                            <th>Category</th>
                            <th style="text-align: center;">Reorder Threshold</th>
                            <th style="text-align: center;">In Hand Qty</th>
                            <th style="text-align: center;">Urgency Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($low_stock as $p): ?>
                            <tr>
                                <td class="fw-semibold text-dark">
                                    <?php echo htmlspecialchars($p['name']); ?>
                                </td>
                                <td>
                                    <code class="bg-light text-secondary px-2 py-1 rounded" style="font-size: 0.85rem; font-weight: 500;">
                                        <?php echo htmlspecialchars($p['sku']); ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border px-2 py-1">
                                        <?php echo htmlspecialchars($p['category_name'] ?: 'Uncategorized'); ?>
                                    </span>
                                </td>
                                <td style="text-align: center; font-weight: 500;">
                                    <?php echo number_format($p['reorder_level']); ?> <small class="text-muted"><?php echo htmlspecialchars($p['unit']); ?></small>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: #b91c1c;">
                                    <?php echo number_format($p['current_stock']); ?> <small class="text-muted" style="font-weight: 400;"><?php echo htmlspecialchars($p['unit']); ?></small>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($p['current_stock'] == 0): ?>
                                        <span class="badge bg-danger text-white px-3 py-2 fw-semibold" style="border-radius: 6px; letter-spacing: 0.02em;">
                                            OUT OF STOCK
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-light text-warning border border-warning-subtle px-3 py-2 fw-semibold" style="border-radius: 6px; letter-spacing: 0.02em; color: #b45309 !important;">
                                            RUNNING LOW
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
