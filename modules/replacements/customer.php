<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$error = '';
$success = '';

// Fetch customers
try {
    $cust_stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY name ASC");
    $customers = $cust_stmt->fetchAll();
} catch (PDOException $e) {
    $customers = [];
}

// Fetch Regulator type products ONLY
try {
    $prod_stmt = $pdo->query("SELECT id, name, sku, current_stock, unit FROM products WHERE product_type = 'regulator' ORDER BY name ASC");
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
        $customer_name = trim($_POST['customer_name'] ?? '');
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $swap_type = trim($_POST['swap_type'] ?? 'replacement');
        $consumer_number = trim($_POST['consumer_number'] ?? '');
        $mobile_number = trim($_POST['mobile_number'] ?? '');
        $old_regulator_no = trim($_POST['old_regulator_no'] ?? '');
        $new_regulator_no = trim($_POST['new_regulator_no'] ?? '');
        $replacement_date = $_POST['replacement_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $created_by = $_SESSION['user_id'] ?? null;

        $valid_types = ['replacement', 'new_connection', 'tv_in', 'tv_out'];
        if (!in_array($swap_type, $valid_types)) {
            $swap_type = 'replacement';
        }

        if (empty($customer_name)) {
            $error = "Customer Name is required.";
        } elseif ($product_id <= 0 || $quantity <= 0) {
            $error = "Please select a valid Regulator product and enter a quantity greater than zero.";
        } elseif (empty($consumer_number)) {
            $error = "Consumer Number is required to record this transaction.";
        } elseif (empty($mobile_number)) {
            $error = "Consumer Mobile Number is required.";
        } elseif ($swap_type === 'replacement' && (empty($old_regulator_no) || empty($new_regulator_no))) {
            $error = "For Replacement Swap, both Old Regulator Serial Number and New Regulator Serial Number are required.";
        } elseif (($swap_type === 'new_connection' || $swap_type === 'tv_in') && empty($new_regulator_no)) {
            $error = "For this transaction type, a New Regulator Serial Number is required.";
        } elseif ($swap_type === 'tv_out' && empty($old_regulator_no)) {
            $error = "For TV Out, the Old/Defective Regulator Serial Number is required.";
        } else {
            try {
                // Fetch product stock details
                $check_stmt = $pdo->prepare("SELECT name, current_stock FROM products WHERE id = ? AND product_type = 'regulator'");
                $check_stmt->execute([$product_id]);
                $product = $check_stmt->fetch();

                if (!$product) {
                    $error = "Selected product is not a valid Regulator.";
                } elseif (in_array($swap_type, ['replacement', 'new_connection', 'tv_in']) && $product['current_stock'] < $quantity) {
                    $error = "Insufficient fresh stock! Available: " . $product['current_stock'] . ". Requested quantity: " . $quantity;
                } else {
                    $pdo->beginTransaction();

                    // 1. Adjust Stock levels based on transaction type
                    if ($swap_type === 'replacement') {
                        // Deduct good stock, increment defective stock
                        $upd_stmt = $pdo->prepare("
                            UPDATE products 
                            SET current_stock = current_stock - ?, 
                                defective_stock = defective_stock + ? 
                            WHERE id = ?
                        ");
                        $upd_stmt->execute([$quantity, $quantity, $product_id]);
                    } elseif ($swap_type === 'new_connection' || $swap_type === 'tv_in') {
                        // Deduct good stock, do not change defective stock
                        $upd_stmt = $pdo->prepare("
                            UPDATE products 
                            SET current_stock = current_stock - ? 
                            WHERE id = ?
                        ");
                        $upd_stmt->execute([$quantity, $product_id]);
                    } elseif ($swap_type === 'tv_out') {
                        // Only add to defective stock (received pre-used regulator from customer)
                        $upd_stmt = $pdo->prepare("
                            UPDATE products 
                            SET defective_stock = defective_stock + ? 
                            WHERE id = ?
                        ");
                        $upd_stmt->execute([$quantity, $product_id]);
                    }

                    // 2. Log in customer_replacements including swap_type and serial logs
                    $ins_stmt = $pdo->prepare("
                        INSERT INTO customer_replacements 
                        (customer_name, product_id, quantity, swap_type, consumer_number, mobile_number, old_regulator_no, new_regulator_no, replacement_date, notes, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins_stmt->execute([
                        $customer_name, 
                        $product_id, 
                        $quantity, 
                        $swap_type, 
                        $consumer_number,
                        $mobile_number,
                        !empty($old_regulator_no) ? $old_regulator_no : null,
                        !empty($new_regulator_no) ? $new_regulator_no : null,
                        $replacement_date, 
                        $notes, 
                        $created_by
                    ]);

                    // 3. Log System Activity
                    log_activity($pdo, "Logged Consumer Regulator (" . strtoupper(str_replace('_', ' ', $swap_type)) . "): " . $quantity . "x " . $product['name'] . " (Consumer: " . $consumer_number . ")");

                    $pdo->commit();
                    
                    $msg_map = [
                        'replacement' => "-$quantity Good / +$quantity Defective",
                        'new_connection' => "-$quantity Good Stock",
                        'tv_in' => "-$quantity Good Stock",
                        'tv_out' => "+$quantity Defective Stock"
                    ];
                    
                    set_flash_message('success', "Regulator transaction for Consumer '$consumer_number' logged successfully! Impact: " . $msg_map[$swap_type]);
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
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-people-carry-box me-2 text-warning"></i>Customer Regulator Transaction Form</h3>
            <p class="text-muted" style="font-size: 0.9rem;">Record a customer regulator event: Replacement Swap, New Connection, TV In, or TV Out.</p>
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
        <form action="customer.php" method="POST">
            <!-- CSRF protection -->
            <?php echo csrf_input(); ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="customer_name" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CUSTOMER NAME *</label>
                    <input type="text" class="form-control form-control-premium" id="customer_name" name="customer_name" required placeholder="Enter Customer Name manually..." value="<?php echo isset($_POST['customer_name']) ? htmlspecialchars($_POST['customer_name']) : ''; ?>">
                </div>

                <div class="col-12 col-md-6">
                    <label for="product_id" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">REGULATOR PRODUCT *</label>
                    <select class="form-select form-control-premium" id="product_id" name="product_id" required>
                        <option value="">-- Select Swappable Regulator --</option>
                        <?php foreach ($regulator_products as $rp): ?>
                            <option value="<?php echo $rp['id']; ?>">
                                <?php echo htmlspecialchars($rp['name']); ?> [SKU: <?php echo htmlspecialchars($rp['sku']); ?>] (Fresh Stock: <?php echo $rp['current_stock']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label for="swap_type" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">TRANSACTION TYPE *</label>
                    <select class="form-select form-control-premium" id="swap_type" name="swap_type" required>
                        <option value="replacement" <?php echo (isset($_POST['swap_type']) && $_POST['swap_type'] === 'replacement') ? 'selected' : ''; ?>>Replacement Swap (Defective <=> Fresh)</option>
                        <option value="new_connection" <?php echo (isset($_POST['swap_type']) && $_POST['swap_type'] === 'new_connection') ? 'selected' : ''; ?>>New Connection (Fresh Issued, No Defective)</option>
                        <option value="tv_in" <?php echo (isset($_POST['swap_type']) && $_POST['swap_type'] === 'tv_in') ? 'selected' : ''; ?>>TV In (Fresh Issued, No Defective)</option>
                        <option value="tv_out" <?php echo (isset($_POST['swap_type']) && $_POST['swap_type'] === 'tv_out') ? 'selected' : ''; ?>>TV Out (Defective/Pre-used Regulator Received)</option>
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label for="consumer_number" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CONSUMER NUMBER *</label>
                    <input type="text" class="form-control form-control-premium" id="consumer_number" name="consumer_number" required placeholder="e.g. CX-99988" value="<?php echo isset($_POST['consumer_number']) ? htmlspecialchars($_POST['consumer_number']) : ''; ?>">
                </div>

                <div class="col-12 col-md-6">
                    <label for="mobile_number" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">MOBILE NUMBER *</label>
                    <input type="text" class="form-control form-control-premium" id="mobile_number" name="mobile_number" required placeholder="e.g. 9876543210" value="<?php echo isset($_POST['mobile_number']) ? htmlspecialchars($_POST['mobile_number']) : ''; ?>">
                </div>

                <div class="col-12 col-md-6">
                    <label for="quantity" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">QUANTITY *</label>
                    <input type="number" min="1" class="form-control form-control-premium" id="quantity" name="quantity" required placeholder="Enter quantity" value="<?php echo isset($_POST['quantity']) ? htmlspecialchars($_POST['quantity']) : '1'; ?>">
                </div>

                <div class="col-12 col-md-6" id="old_regulator_group">
                    <label for="old_regulator_no" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">OLD REGULATOR SERIAL NUMBER</label>
                    <input type="text" class="form-control form-control-premium" id="old_regulator_no" name="old_regulator_no" placeholder="e.g. OLD-REG-1234" value="<?php echo isset($_POST['old_regulator_no']) ? htmlspecialchars($_POST['old_regulator_no']) : ''; ?>">
                </div>

                <div class="col-12 col-md-6" id="new_regulator_group">
                    <label for="new_regulator_no" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">NEW REGULATOR SERIAL NUMBER</label>
                    <input type="text" class="form-control form-control-premium" id="new_regulator_no" name="new_regulator_no" placeholder="e.g. NEW-REG-5678" value="<?php echo isset($_POST['new_regulator_no']) ? htmlspecialchars($_POST['new_regulator_no']) : ''; ?>">
                </div>

                <div class="col-12 col-md-6">
                    <label for="replacement_date" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">TRANSACTION DATE *</label>
                    <input type="date" class="form-control form-control-premium" id="replacement_date" name="replacement_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="col-12">
                    <label for="notes" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">NOTES / REMARKS</label>
                    <textarea class="form-control form-control-premium" id="notes" name="notes" rows="3" placeholder="Enter defective reason, customer cylinder details, etc."></textarea>
                </div>
            </div>

            <hr class="my-4" style="color: var(--border-color);">

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-accent">Log Transaction</button>
                <a href="index.php" class="btn btn-light text-muted">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const swapTypeSelect = document.getElementById('swap_type');
    const oldRegGroup = document.getElementById('old_regulator_group');
    const newRegGroup = document.getElementById('new_regulator_group');
    const oldInput = document.getElementById('old_regulator_no');
    const newInput = document.getElementById('new_regulator_no');

    function toggleFields() {
        const val = swapTypeSelect.value;
        if (val === 'replacement') {
            oldRegGroup.style.display = 'block';
            newRegGroup.style.display = 'block';
            oldInput.required = true;
            newInput.required = true;
        } else if (val === 'new_connection' || val === 'tv_in') {
            oldRegGroup.style.display = 'none';
            newRegGroup.style.display = 'block';
            oldInput.required = false;
            newInput.required = true;
        } else if (val === 'tv_out') {
            oldRegGroup.style.display = 'block';
            newRegGroup.style.display = 'none';
            oldInput.required = true;
            newInput.required = false;
        }
    }

    swapTypeSelect.addEventListener('change', toggleFields);
    toggleFields(); // Initial check on load
});
</script>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
