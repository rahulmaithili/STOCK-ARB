<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$error = '';

// Fetch all categories for selection dropdown
try {
    $cat_stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
    $categories = $cat_stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $name = trim($_POST['name'] ?? '');
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $category_id = intval($_POST['category_id'] ?? 0);
        $unit = trim($_POST['unit'] ?? 'pcs');
        $purchase_price = floatval($_POST['purchase_price'] ?? 0);
        $selling_price = floatval($_POST['selling_price'] ?? 0);
        $opening_stock = intval($_POST['opening_stock'] ?? 0);
        $reorder_level = intval($_POST['reorder_level'] ?? 10);
        $description = trim($_POST['description'] ?? '');
        $product_type = trim($_POST['product_type'] ?? 'standard');
        $defective_stock = intval($_POST['defective_stock'] ?? 0);
        
        $image_path = null;

        // Validations
        if (empty($name) || empty($sku)) {
            $error = "Product Name and SKU code are required.";
        } else {
            // Check if SKU exists
            try {
                $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
                $chk_stmt->execute([$sku]);
                if ($chk_stmt->fetchColumn() > 0) {
                    $error = "A product with SKU '$sku' already exists.";
                }
            } catch (PDOException $e) {
                $error = "Database validation failed: " . $e->getMessage();
            }

            // Image Upload validation and processing
            if (empty($error) && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['image'];
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowed_extensions)) {
                    $error = "Invalid file type. Only JPG, PNG, WEBP, and GIF are allowed.";
                } elseif ($file['size'] > 2 * 1024 * 1024) { // 2MB limit
                    $error = "File size cannot exceed 2MB.";
                } else {
                    // Create upload path if not exists
                    $upload_dir = dirname(dirname(__DIR__)) . '/uploads/products/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Generate unique filename
                    $filename = uniqid('prod_') . '.' . $ext;
                    $target_file = $upload_dir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $image_path = 'uploads/products/' . $filename;
                    } else {
                        $error = "Failed to upload image file.";
                    }
                }
            }

            // Save Product if no errors
            if (empty($error)) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO products 
                        (category_id, name, sku, unit, purchase_price, selling_price, opening_stock, current_stock, reorder_level, image, description, product_type, defective_stock) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $category_id > 0 ? $category_id : null,
                        $name,
                        $sku,
                        $unit,
                        $purchase_price,
                        $selling_price,
                        $opening_stock,
                        $opening_stock, // initial current stock equals opening stock
                        $reorder_level,
                        $image_path,
                        !empty($description) ? $description : null,
                        $product_type,
                        $defective_stock
                    ]);

                    log_activity($pdo, "Added product: " . $name . " (SKU: " . $sku . ")");
                    set_flash_message('success', "Product '$name' added successfully!");
                    header("Location: index.php");
                    exit();
                } catch (PDOException $e) {
                    $error = "Failed to save product details: " . $e->getMessage();
                }
            }
        }
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-circle-plus me-2 text-success"></i>Add New Product</h3>
            <p class="text-muted" style="font-size: 0.9rem;">Register a new inventory item to the master database.</p>
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
        <form action="add.php" method="POST" enctype="multipart/form-data">
            <!-- CSRF protection -->
            <?php echo csrf_input(); ?>

            <div class="row g-4">
                <!-- Left Side Form Fields -->
                <div class="col-12 col-md-8">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label for="name" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">PRODUCT NAME *</label>
                            <input type="text" class="form-control form-control-premium" id="name" name="name" required placeholder="e.g. LPG Cylinder 15kg" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                        </div>
                        
                        <div class="col-12 col-sm-6">
                            <label for="sku" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">SKU / PRODUCT CODE *</label>
                            <input type="text" class="form-control form-control-premium" id="sku" name="sku" required placeholder="e.g. LPG15KG" value="<?php echo isset($_POST['sku']) ? htmlspecialchars($_POST['sku']) : ''; ?>">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="category_id" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CATEGORY</label>
                            <select class="form-select form-control-premium" id="category_id" name="category_id">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="unit" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">UNIT MEASUREMENT</label>
                            <input type="text" class="form-control form-control-premium" id="unit" name="unit" placeholder="e.g. pcs, kg, box" value="<?php echo isset($_POST['unit']) ? htmlspecialchars($_POST['unit']) : 'pcs'; ?>">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="product_type" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">PRODUCT CLASSIFICATION *</label>
                            <select class="form-select form-control-premium" id="product_type" name="product_type" required>
                                <option value="standard" <?php echo (isset($_POST['product_type']) && $_POST['product_type'] === 'standard') ? 'selected' : ''; ?>>Standard Product</option>
                                <option value="regulator" <?php echo (isset($_POST['product_type']) && $_POST['product_type'] === 'regulator') ? 'selected' : ''; ?>>Regulator (Replacement Workflow)</option>
                                <option value="ftl_regulator" <?php echo (isset($_POST['product_type']) && $_POST['product_type'] === 'ftl_regulator') ? 'selected' : ''; ?>>FTL Regulator (Direct Sale only)</option>
                            </select>
                        </div>

                        <div class="col-12 col-sm-6" id="defective_stock_group" style="display: none;">
                            <label for="defective_stock" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">INITIAL DEFECTIVE STOCK</label>
                            <input type="number" min="0" class="form-control form-control-premium" id="defective_stock" name="defective_stock" placeholder="0" value="<?php echo isset($_POST['defective_stock']) ? htmlspecialchars($_POST['defective_stock']) : '0'; ?>">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="purchase_price" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">BUYING PRICE (₹) *</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-premium" id="purchase_price" name="purchase_price" required placeholder="0.00" value="<?php echo isset($_POST['purchase_price']) ? htmlspecialchars($_POST['purchase_price']) : ''; ?>">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="selling_price" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">SELLING PRICE (₹) *</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-premium" id="selling_price" name="selling_price" required placeholder="0.00" value="<?php echo isset($_POST['selling_price']) ? htmlspecialchars($_POST['selling_price']) : ''; ?>">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="opening_stock" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">OPENING STOCK (INITIAL VALUE) *</label>
                            <input type="number" min="0" class="form-control form-control-premium" id="opening_stock" name="opening_stock" required placeholder="0" value="<?php echo isset($_POST['opening_stock']) ? htmlspecialchars($_POST['opening_stock']) : '0'; ?>">
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="reorder_level" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">REORDER THRESHOLD LEVEL *</label>
                            <input type="number" min="0" class="form-control form-control-premium" id="reorder_level" name="reorder_level" required placeholder="10" value="<?php echo isset($_POST['reorder_level']) ? htmlspecialchars($_POST['reorder_level']) : '10'; ?>">
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">PRODUCT DESCRIPTION</label>
                            <textarea class="form-control form-control-premium" id="description" name="description" rows="3" placeholder="Enter product specifications, features, or details..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Right Side File Inputs -->
                <div class="col-12 col-md-4 border-start-md ps-md-4">
                    <div class="mb-3">
                        <label for="image" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">PRODUCT IMAGE</label>
                        <input class="form-control form-control-premium" type="file" id="image" name="image" accept="image/*">
                        <small class="text-muted mt-2 d-block" style="font-size: 0.75rem;">Supported formats: JPG, PNG, WEBP, GIF. Max file size: 2MB.</small>
                    </div>
                    
                    <div class="p-3 bg-light rounded-3 mt-4" style="border: 1px dashed var(--border-color);">
                        <strong class="text-dark d-block mb-1" style="font-size: 0.85rem;"><i class="fa-solid fa-circle-info me-1 text-primary"></i>Opening Stock Warning:</strong>
                        <span class="text-muted" style="font-size: 0.8rem;">Entering an opening stock will instantly initialize the product's inventory level. To record vendor invoice detail, create a Purchase Transaction instead.</span>
                    </div>
                </div>
            </div>

            <hr class="my-4" style="color: var(--border-color);">

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-accent">Save Product</button>
                <a href="index.php" class="btn btn-light text-muted">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const productTypeSelect = document.getElementById('product_type');
    const defectiveStockGroup = document.getElementById('defective_stock_group');

    function toggleDefectiveField() {
        if (productTypeSelect.value === 'regulator') {
            defectiveStockGroup.style.display = 'block';
        } else {
            defectiveStockGroup.style.display = 'none';
        }
    }

    productTypeSelect.addEventListener('change', toggleDefectiveField);
    toggleDefectiveField(); // Initial check on load
});
</script>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
