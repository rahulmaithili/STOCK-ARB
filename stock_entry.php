<?php
require_once __DIR__ . '/db.php';

$message = '';
$message_type = 'success';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM stock_transactions WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: stock_entry.php?msg=deleted");
        exit();
    } catch (PDOException $e) {
        $message = "Error deleting stock entry: " . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
    }
}

// Show success messages from redirects
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'added') {
        $message = "Stock movement recorded successfully!";
    } elseif ($_GET['msg'] == 'deleted') {
        $message = "Stock entry deleted successfully!";
    }
}

// Fetch all products for dropdown
try {
    $stmt = $pdo->query("SELECT id, name, sku FROM products ORDER BY name ASC");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $message = "Failed to load products: " . htmlspecialchars($e->getMessage());
    $message_type = 'danger';
    $products = [];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $product_id = intval($_POST['product_id']);
    $transaction_date = trim($_POST['transaction_date']);
    $type = trim($_POST['type']);
    $quantity = intval($_POST['quantity']);
    $remarks = trim($_POST['remarks']);

    // Validations
    if ($product_id <= 0 || empty($transaction_date) || !in_array($type, ['IN', 'OUT']) || $quantity <= 0) {
        $message = "Please fill all required fields with valid details.";
        $message_type = 'danger';
    } else {
        // Business logic validation: Stock Out must not exceed available stock in hand
        if ($type == 'OUT') {
            try {
                // Calculate stock in
                $in_stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM stock_transactions WHERE product_id = ? AND type = 'IN'");
                $in_stmt->execute([$product_id]);
                $stock_in = intval($in_stmt->fetchColumn());

                // Calculate stock out
                $out_stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM stock_transactions WHERE product_id = ? AND type = 'OUT'");
                $out_stmt->execute([$product_id]);
                $stock_out = intval($out_stmt->fetchColumn());

                $available_stock = $stock_in - $stock_out;

                if ($quantity > $available_stock) {
                    $message = "Insufficient Stock! Available stock for this product is only <strong>" . number_format($available_stock) . "</strong> units. Cannot process " . number_format($quantity) . " units stock out.";
                    $message_type = 'danger';
                }
            } catch (PDOException $e) {
                $message = "Stock verification failed: " . htmlspecialchars($e->getMessage());
                $message_type = 'danger';
            }
        }

        // If no validation errors, proceed to insert
        if ($message_type == 'success') {
            try {
                $stmt = $pdo->prepare("INSERT INTO stock_transactions (product_id, transaction_date, type, quantity, remarks) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$product_id, $transaction_date, $type, $quantity, $remarks]);
                header("Location: stock_entry.php?msg=added");
                exit();
            } catch (PDOException $e) {
                $message = "Database error: " . htmlspecialchars($e->getMessage());
                $message_type = 'danger';
            }
        }
    }
}

// Fetch all transactions with pagination/limit for listing
try {
    $tx_stmt = $pdo->query("
        SELECT t.*, p.name as product_name, p.sku as product_sku
        FROM stock_transactions t
        JOIN products p ON t.product_id = p.id
        ORDER BY t.transaction_date DESC, t.created_at DESC
        LIMIT 50
    ");
    $transactions = $tx_stmt->fetchAll();
} catch (PDOException $e) {
    $transactions = [];
    if (empty($message)) {
        $message = "Could not fetch recent transactions: " . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
    }
}

require_once __DIR__ . '/header.php';
?>

<!-- Alert Box -->
<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?>">
        <span><?php echo $message; ?></span>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 24px;">
    
    <!-- Stock Transaction Entry Form -->
    <div class="card" style="align-self: start;">
        <div class="card-header">
            <h2 class="card-title">🔄 Record New Stock Movement</h2>
        </div>
        <div class="card-body">
            <?php if (empty($products)): ?>
                <div class="alert alert-warning" style="margin-bottom: 0;">
                    No products available. Please <a href="products.php?action=new" style="font-weight: 600; text-decoration: underline;">create a product</a> first.
                </div>
            <?php else: ?>
                <form action="stock_entry.php" method="POST">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="product_id">Select Product *</label>
                        <select id="product_id" name="product_id" required>
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo (isset($_POST['product_id']) && $_POST['product_id'] == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['name']); ?> (SKU: <?php echo htmlspecialchars($p['sku']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="transaction_date">Transaction Date *</label>
                            <input type="date" id="transaction_date" name="transaction_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="type">Movement Type *</label>
                            <select id="type" name="type" required>
                                <option value="IN" <?php echo (isset($_POST['type']) && $_POST['type'] == 'IN') ? 'selected' : ''; ?>>Stock In (Purchase)</option>
                                <option value="OUT" <?php echo (isset($_POST['type']) && $_POST['type'] == 'OUT') ? 'selected' : ''; ?>>Stock Out (Sale)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="quantity">Quantity *</label>
                        <input type="number" id="quantity" name="quantity" required min="1" placeholder="e.g. 50" value="<?php echo isset($_POST['quantity']) ? intval($_POST['quantity']) : ''; ?>">
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="remarks">Remarks / Description</label>
                        <textarea id="remarks" name="remarks" placeholder="Enter Supplier name, Invoice number, or Customer reference..."><?php echo isset($_POST['remarks']) ? htmlspecialchars($_POST['remarks']) : ''; ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Transaction</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- History / Recent Ledger Entries -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">📜 Recent Entries (Max 50)</h2>
        </div>
        <div class="card-body">
            <?php if (empty($transactions)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 40px 0;">No transactions recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Date & Details</th>
                                <th>Type</th>
                                <th>Qty</th>
                                <th>Remarks</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 500; font-size: 0.9rem;"><?php echo date('d-m-Y', strtotime($tx['transaction_date'])); ?></div>
                                        <div style="font-weight: 600; color: var(--primary); font-size: 0.95rem; margin-top: 2px;">
                                            <?php echo htmlspecialchars($tx['product_name']); ?>
                                        </div>
                                        <small style="color: var(--text-muted); font-size: 0.75rem;">SKU: <?php echo htmlspecialchars($tx['product_sku']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($tx['type'] == 'IN'): ?>
                                            <span class="badge badge-in">In (Purchase)</span>
                                        <?php else: ?>
                                            <span class="badge badge-out">Out (Sale)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight: 700;"><?php echo number_format($tx['quantity']); ?></td>
                                    <td style="font-size: 0.85rem; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($tx['remarks']); ?>">
                                        <?php echo htmlspecialchars($tx['remarks'] ?: '-'); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="stock_entry.php?action=delete&id=<?php echo $tx['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this stock entry?');" style="padding: 4px 8px; font-size: 0.75rem;">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
