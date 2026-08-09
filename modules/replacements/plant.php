<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$error = '';
$success = '';

// Fetch suppliers (Plants)
try {
    $supp_stmt = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC");
    $suppliers = $supp_stmt->fetchAll();
} catch (PDOException $e) {
    $suppliers = [];
}

// Fetch Regulator type products ONLY
try {
    $prod_stmt = $pdo->query("SELECT id, name, sku, defective_stock, unit FROM products WHERE product_type = 'regulator' ORDER BY name ASC");
    $regulator_products = $prod_stmt->fetchAll();
} catch (PDOException $e) {
    $regulator_products = [];
}

// Process replacement transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $return_date = $_POST['return_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $created_by = $_SESSION['user_id'] ?? null;

        if ($product_id <= 0 || $quantity <= 0) {
            $error = "Please select a valid Regulator product and enter a quantity greater than zero.";
        } else {
            try {
                // Fetch product stock details
                $check_stmt = $pdo->prepare("SELECT name, defective_stock FROM products WHERE id = ? AND product_type = 'regulator'");
                $check_stmt->execute([$product_id]);
                $product = $check_stmt->fetch();

                if (!$product) {
                    $error = "Selected product is not a valid Regulator.";
                } elseif ($product['defective_stock'] < $quantity) {
                    $error = "Insufficient defective stock! Available in warehouse: " . $product['defective_stock'] . ". Requested return quantity: " . $quantity;
                } else {
                    $pdo->beginTransaction();

                    // 1. Deduct defective stock, add to current good stock
                    $upd_stmt = $pdo->prepare("
                        UPDATE products 
                        SET defective_stock = defective_stock - ?, 
                            current_stock = current_stock + ? 
                        WHERE id = ?
                    ");
                    $upd_stmt->execute([$quantity, $quantity, $product_id]);

                    // 2. Log in plant_replacements
                    $ins_stmt = $pdo->prepare("
                        INSERT INTO plant_replacements 
                        (supplier_id, product_id, quantity, return_date, notes, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $ins_stmt->execute([$supplier_id, $product_id, $quantity, $return_date, $notes, $created_by]);

                    // 3. Log System Activity
                    log_activity($pdo, "Logged Plant Return: " . $quantity . "x " . $product['name']);

                    $pdo->commit();
                    set_flash_message('success', "Plant Return logged successfully! Stock adjusted (-$quantity Defective / +$quantity Good).");
                    header("Location: index.php");
                    exit();
                }

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Transaction failed: " . $e->getMessage();
            }
        }
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-industry me-2 text-warning"></i>Plant Regulator Swap-Back Form</h3>
            <p class="text-muted" style="font-size: 0.9rem;">Record returning defective regulators to the manufacturing plant/supplier and receiving fresh good replacements.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Cancel & Back</a>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="card card-premium">
    <div class="card-premium-body">
        <form action="plant.php" method="POST">
            <!-- CSRF protection -->
            <?php echo csrf_input(); ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="supplier_id" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">PLANT (SUPPLIER) *</label>
                    <select class="form-select form-control-premium" id="supplier_id" name="supplier_id" required>
                        <option value="">-- Select Plant/Supplier --</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label for="product_id" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">REGULATOR PRODUCT *</label>
                    <select class="form-select form-control-premium" id="product_id" name="product_id" required>
                        <option value="">-- Select Regulator --</option>
                        <?php foreach ($regulator_products as $rp): ?>
                            <option value="<?php echo $rp['id']; ?>">
                                <?php echo htmlspecialchars($rp['name']); ?> [SKU: <?php echo htmlspecialchars($rp['sku']); ?>] (Defective Available: <?php echo $rp['defective_stock']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label for="quantity" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">RETURN QUANTITY *</label>
                    <input type="number" min="1" class="form-control form-control-premium" id="quantity" name="quantity" required placeholder="Enter quantity to swap back">
                </div>

                <div class="col-12 col-md-6">
                    <label for="return_date" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">RETURN DATE *</label>
                    <input type="date" class="form-control form-control-premium" id="return_date" name="return_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="col-12">
                    <label for="notes" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">NOTES / REMARKS</label>
                    <textarea class="form-control form-control-premium" id="notes" name="notes" rows="3" placeholder="Enter truck number, gate pass details, etc."></textarea>
                </div>
            </div>

            <hr class="my-4" style="color: var(--border-color);">

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-accent">Log Plant Return</button>
                <a href="index.php" class="btn btn-light text-muted">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
