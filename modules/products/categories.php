<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

// Auth checks
require_login();
$role = $_SESSION['user_role'] ?? 'staff';

// Process Actions
$error = '';
$success = '';

// Add / Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin', 'manager'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $action = trim($_POST['action'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $id = intval($_POST['id'] ?? 0);

        if (empty($name)) {
            $error = "Category Name is required.";
        } else {
            if ($action === 'add') {
                try {
                    $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                    $stmt->execute([$name]);
                    log_activity($pdo, "Created category: " . $name);
                    set_flash_message('success', "Category '$name' created successfully.");
                    header("Location: categories.php");
                    exit();
                } catch (PDOException $e) {
                    $error = "Failed to add category: " . $e->getMessage();
                }
            } elseif ($action === 'edit' && $id > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
                    $stmt->execute([$name, $id]);
                    log_activity($pdo, "Updated category (ID $id) to: " . $name);
                    set_flash_message('success', "Category updated to '$name'.");
                    header("Location: categories.php");
                    exit();
                } catch (PDOException $e) {
                    $error = "Failed to update category: " . $e->getMessage();
                }
            }
        }
    }
}

// Delete Category
if (isset($_GET['action']) && $_GET['action'] === 'delete' && in_array($role, ['admin', 'manager'])) {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            // Get category name for logging
            $chk = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $chk->execute([$id]);
            $cat_name = $chk->fetchColumn();

            if ($cat_name) {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->execute([$id]);
                log_activity($pdo, "Deleted category: " . $cat_name);
                set_flash_message('success', "Category '$cat_name' deleted successfully.");
            }
            header("Location: categories.php");
            exit();
        } catch (PDOException $e) {
            set_flash_message('danger', "Failed to delete category. It might be in use.");
            header("Location: categories.php");
            exit();
        }
    }
}

// Fetch all categories
try {
    $stmt = $pdo->query("
        SELECT c.*, COUNT(p.id) as product_count 
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
    $error = "Could not fetch categories: " . $e->getMessage();
}

// Check if we are editing
$edit_mode = false;
$edit_category = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && in_array($role, ['admin', 'manager'])) {
    $edit_id = intval($_GET['id']);
    foreach ($categories as $cat) {
        if ($cat['id'] == $edit_id) {
            $edit_category = $cat;
            $edit_mode = true;
            break;
        }
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-tags me-2 text-success"></i>Category Management</h3>
            <p class="text-muted" style="font-size: 0.9rem;">Organize products into distinct classifications.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Products</a>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Form Panel -->
    <?php if (in_array($role, ['admin', 'manager'])): ?>
        <div class="col-12 col-md-4">
            <div class="card card-premium">
                <div class="card-premium-header">
                    <h5 class="card-premium-title">
                        <?php echo $edit_mode ? '<i class="fa-solid fa-pen-to-square me-2"></i>Edit Category' : '<i class="fa-solid fa-plus me-2"></i>Add New Category'; ?>
                    </h5>
                </div>
                <div class="card-premium-body">
                    <form action="categories.php" method="POST">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="<?php echo $edit_mode ? 'edit' : 'add'; ?>">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="id" value="<?php echo $edit_category['id']; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CATEGORY NAME</label>
                            <input type="text" class="form-control form-control-premium" id="name" name="name" required placeholder="e.g. Gas Cylinders" value="<?php echo $edit_mode ? htmlspecialchars($edit_category['name']) : ''; ?>">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-accent"><?php echo $edit_mode ? 'Update Category' : 'Create Category'; ?></button>
                            <?php if ($edit_mode): ?>
                                <a href="categories.php" class="btn btn-light text-muted">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Table Panel -->
    <div class="col-12 <?php echo in_array($role, ['admin', 'manager']) ? 'col-md-8' : 'col-md-12'; ?>">
        <div class="card card-premium">
            <div class="card-premium-header">
                <h5 class="card-premium-title"><i class="fa-solid fa-list me-2"></i>Active Categories</h5>
            </div>
            <div class="card-premium-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle datatable">
                        <thead>
                            <tr>
                                <th>Category Name</th>
                                <th>Linked Products</th>
                                <?php if (in_array($role, ['admin', 'manager'])): ?>
                                    <th style="text-align: right;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($cat['name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-3 py-2 fw-semibold" style="border-radius: 6px;">
                                            <?php echo $cat['product_count']; ?> Products
                                        </span>
                                    </td>
                                    <?php if (in_array($role, ['admin', 'manager'])): ?>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <a href="categories.php?action=edit&id=<?php echo $cat['id']; ?>" class="btn btn-outline-secondary btn-sm me-1" style="border-radius: 6px;"><i class="fa-solid fa-pen-to-square"></i></a>
                                            <a href="categories.php?action=delete&id=<?php echo $cat['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to delete this category? Linked products will have their category set to Uncategorized.');" style="border-radius: 6px;"><i class="fa-solid fa-trash-can"></i></a>
                                        </td>
                                    <?php endif; ?>
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
