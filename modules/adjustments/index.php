<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$error = '';

// Fetch products for dropdown
try {
    $prod_stmt = $pdo->query("SELECT id, name, sku, current_stock FROM products ORDER BY name ASC");
    $products = $prod_stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($product_id <= 0 || $quantity == 0 || empty($reason)) {
            $error = "Product, non-zero Quantity, and Reason are required.";
        } else {
            try {
                $pdo->beginTransaction();

                // Check product availability if quantity is negative (deduction)
                if ($quantity < 0) {
                    $stock_stmt = $pdo->prepare("SELECT name, current_stock FROM products WHERE id = ? FOR UPDATE");
                    $stock_stmt->execute([$product_id]);
                    $prod_db = $stock_stmt->fetch();
                    
                    if (abs($quantity) > $prod_db['current_stock']) {
                        throw new Exception("Cannot deduct " . abs($quantity) . " units. Only {$prod_db['current_stock']} units available in stock.");
                    }
                }

                // Insert Adjustment
                $stmt = $pdo->prepare("
                    INSERT INTO stock_adjustments (product_id, quantity, reason, adjusted_by) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$product_id, $quantity, $reason, $_SESSION['user_id']]);

                // Update stock in hand
                $upd_stmt = $pdo->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
                $upd_stmt->execute([$quantity, $product_id]);

                // Log Activity
                $direction = ($quantity > 0) ? "Added" : "Deducted";
                log_activity($pdo, "Stock adjustment registered: $direction " . abs($quantity) . " units. Reason: " . $reason);

                $pdo->commit();
                
                set_flash_message('success', "Stock adjustment successfully applied.");
                header("Location: index.php");
                exit();

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to apply adjustment: " . $e->getMessage();
            }
        }
    }
}

// Fetch all adjustments
try {
    $adj_stmt = $pdo->query("
        SELECT sa.*, p.name as product_name, p.sku as product_sku, u.name as user_name
        FROM stock_adjustments sa
        LEFT JOIN products p ON sa.product_id = p.id
        LEFT JOIN users u ON sa.adjusted_by = u.id
        ORDER BY sa.adjusted_at DESC
    ");
    $adjustments = $adj_stmt->fetchAll();
} catch (PDOException $e) {
    $adjustments = [];
    $error = "Failed to load adjustments log: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-sliders me-2 text-success"></i>Stock Adjustments</h3>
        <p class="text-muted" style="font-size: 0.95rem;">Apply manual corrections (e.g. damages, returns, counting errors) directly to inventory stock levels.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Form Panel -->
    <div class="col-12 col-md-4">
        <div class="card card-premium">
            <div class="card-premium-header">
                <h5 class="card-premium-title"><i class="fa-solid fa-plus-minus me-2 text-success"></i>Record Adjustment</h5>
            </div>
            <div class="card-premium-body">
                <form action="index.php" method="POST">
                    <?php echo csrf_input(); ?>

                    <div class="mb-3">
                        <label for="product_id" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">SELECT PRODUCT *</label>
                        <select class="form-select form-control-premium" id="product_id" name="product_id" required>
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['name']); ?> (SKU: <?php echo htmlspecialchars($p['sku']); ?>) [Stock: <?php echo $p['current_stock']; ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="quantity" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">QUANTITY DIFFERENTIAL *</label>
                        <input type="number" class="form-control form-control-premium" id="quantity" name="quantity" required placeholder="Use -5 for deduction, 5 for addition">
                        <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">Negative (-) reduces stock level. Positive (+) increases stock.</small>
                    </div>

                    <div class="mb-4">
                        <label for="reason" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">REASON FOR CORRECTION *</label>
                        <input type="text" class="form-control form-control-premium" id="reason" name="reason" required placeholder="e.g. Damages, Recount, Return">
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-accent">Apply Correction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table Panel -->
    <div class="col-12 col-md-8">
        <div class="card card-premium">
            <div class="card-premium-header">
                <h5 class="card-premium-title"><i class="fa-solid fa-clock-rotate-left me-2"></i>Adjustment History Log</h5>
            </div>
            <div class="card-premium-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle datatable">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Product Item</th>
                                <th style="text-align: center;">Differential</th>
                                <th>Reason Logged</th>
                                <th>Corrected By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adjustments as $adj): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo date('d-M-Y', strtotime($adj['adjusted_at'])); ?></div>
                                        <small class="text-muted"><?php echo date('h:i A', strtotime($adj['adjusted_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($adj['product_name'] ?: 'Unknown'); ?></div>
                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($adj['product_sku'] ?: '-'); ?></small>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($adj['quantity'] > 0): ?>
                                            <span class="badge bg-success-light text-success border border-success-subtle px-3 py-1 fw-semibold">+<?php echo $adj['quantity']; ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-light text-danger border border-danger-subtle px-3 py-1 fw-semibold"><?php echo $adj['quantity']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-dark fw-medium" style="font-size: 0.9rem;"><?php echo htmlspecialchars($adj['reason']); ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($adj['user_name'] ?: 'System'); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
