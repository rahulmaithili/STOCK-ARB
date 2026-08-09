<?php
require_once __DIR__ . '/db.php';

$message = '';
$message_type = 'success';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: products.php?msg=deleted");
        exit();
    } catch (PDOException $e) {
        $message = "Error deleting product: " . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
    }
}

// Show success messages from redirects
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'added') {
        $message = "Product added successfully!";
    } elseif ($_GET['msg'] == 'updated') {
        $message = "Product updated successfully!";
    } elseif ($_GET['msg'] == 'deleted') {
        $message = "Product deleted successfully!";
    }
}

// Handle Form Submission (Add / Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $sku = strtoupper(trim($_POST['sku']));
    $description = trim($_POST['description']);
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if (empty($name) || empty($sku)) {
        $message = "Product Name and SKU are required fields.";
        $message_type = 'danger';
    } else {
        if ($id > 0) {
            // Edit Mode
            try {
                // Check if SKU exists on another product
                $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND id != ?");
                $chk->execute([$sku, $id]);
                if ($chk->fetchColumn() > 0) {
                    $message = "A product with SKU '$sku' already exists.";
                    $message_type = 'danger';
                } else {
                    $stmt = $pdo->prepare("UPDATE products SET name = ?, sku = ?, description = ? WHERE id = ?");
                    $stmt->execute([$name, $sku, $description, $id]);
                    header("Location: products.php?msg=updated");
                    exit();
                }
            } catch (PDOException $e) {
                $message = "Database error: " . htmlspecialchars($e->getMessage());
                $message_type = 'danger';
            }
        } else {
            // Add Mode
            try {
                // Check if SKU exists
                $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
                $chk->execute([$sku]);
                if ($chk->fetchColumn() > 0) {
                    $message = "A product with SKU '$sku' already exists.";
                    $message_type = 'danger';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO products (name, sku, description) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $sku, $description]);
                    header("Location: products.php?msg=added");
                    exit();
                }
            } catch (PDOException $e) {
                $message = "Database error: " . htmlspecialchars($e->getMessage());
                $message_type = 'danger';
            }
        }
    }
}

// Decide current state
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$edit_product = null;

if ($action == 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_product = $stmt->fetch();
    if (!$edit_product) {
        $message = "Product not found.";
        $message_type = 'danger';
        $action = 'list';
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

<?php if ($action == 'new' || $action == 'edit'): ?>
    <!-- Add or Edit Product Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?php echo ($action == 'edit') ? '✏️ Edit Product' : '➕ Add New Product'; ?></h2>
            <a href="products.php" class="btn btn-secondary btn-sm">Cancel & Return</a>
        </div>
        <div class="card-body">
            <form action="products.php" method="POST">
                <?php if ($action == 'edit'): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_product['id']; ?>">
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Product Name *</label>
                        <input type="text" id="name" name="name" required placeholder="e.g. Steel Pipe 2 inch" value="<?php echo $edit_product ? htmlspecialchars($edit_product['name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="sku">SKU / Product Code *</label>
                        <input type="text" id="sku" name="sku" required placeholder="e.g. SP2INCH" value="<?php echo $edit_product ? htmlspecialchars($edit_product['sku']) : ''; ?>" <?php echo $edit_product ? 'readonly style="background-color: #f1f5f9; cursor: not-allowed;"' : ''; ?>>
                        <?php if ($edit_product): ?>
                            <small style="color: var(--text-muted);">SKU cannot be changed after creation.</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group full-width" style="margin-bottom: 20px;">
                    <label for="description">Product Description</label>
                    <textarea id="description" name="description" placeholder="Enter product specification, brand, or location details..."><?php echo $edit_product ? htmlspecialchars($edit_product['description']) : ''; ?></textarea>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">
                        <?php echo ($action == 'edit') ? 'Update Product' : 'Add Product'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- Product Catalog List -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">All Products</h2>
            <a href="products.php?action=new" class="btn btn-primary">+ Add New Product</a>
        </div>
        <div class="card-body">
            <?php
            try {
                $query = "
                    SELECT p.*, 
                        COALESCE((SELECT SUM(quantity) FROM stock_transactions WHERE product_id = p.id AND type = 'IN'), 0) -
                        COALESCE((SELECT SUM(quantity) FROM stock_transactions WHERE product_id = p.id AND type = 'OUT'), 0) as current_stock
                    FROM products p
                    ORDER BY p.name ASC
                ";
                $stmt = $pdo->query($query);
                $products = $stmt->fetchAll();
            } catch (PDOException $e) {
                echo "<div class='alert alert-danger'>Failed to query products: " . htmlspecialchars($e->getMessage()) . "</div>";
                $products = [];
            }
            ?>

            <?php if (empty($products)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 40px 0;">No products found in database. Create your first product above!</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Product Details</th>
                                <th>SKU Code</th>
                                <th>Available Stock</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; font-size: 1.05rem; color: var(--text-main);">
                                            <?php echo htmlspecialchars($p['name']); ?>
                                        </div>
                                        <?php if (!empty($p['description'])): ?>
                                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                                                <?php echo htmlspecialchars($p['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code style="background-color: var(--border-color); padding: 4px 8px; border-radius: 4px; font-weight: 500; font-size: 0.85rem;">
                                            <?php echo htmlspecialchars($p['sku']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?php 
                                        $stock = intval($p['current_stock']);
                                        if ($stock < 5) {
                                            $badge_class = 'badge-out';
                                            $status = 'Low Stock';
                                        } else {
                                            $badge_class = 'badge-in';
                                            $status = 'In Stock';
                                        }
                                        ?>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-size: 1.1rem; font-weight: 700;"><?php echo number_format($stock); ?></span>
                                            <span class="badge <?php echo $badge_class; ?>"><?php echo $status; ?></span>
                                        </div>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <a href="products.php?action=edit&id=<?php echo $p['id']; ?>" class="btn btn-secondary btn-sm">Edit</a>
                                        <a href="products.php?action=delete&id=<?php echo $p['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this product? All of its stock entries will also be permanently deleted.');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
