<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin']); // Only Admin can manage Roles and Permissions

$error = '';
$success = '';

// Dynamic permissions list keys with readable labels
$perm_labels = [
    'products_view'      => ['label' => 'View Products Catalog', 'category' => 'Products & Inventory'],
    'products_manage'    => ['label' => 'Add/Edit Products', 'category' => 'Products & Inventory'],
    'products_delete'    => ['label' => 'Delete Products', 'category' => 'Products & Inventory'],
    
    'categories_view'    => ['label' => 'View Categories List', 'category' => 'Products & Inventory'],
    'categories_manage'  => ['label' => 'Manage Categories', 'category' => 'Products & Inventory'],
    
    'suppliers_view'     => ['label' => 'View Suppliers Directory', 'category' => 'Contacts & Partners'],
    'suppliers_manage'   => ['label' => 'Manage Suppliers CRUD', 'category' => 'Contacts & Partners'],
    
    'customers_view'     => ['label' => 'View Customers Directory', 'category' => 'Contacts & Partners'],
    'customers_manage'   => ['label' => 'Manage Customers CRUD', 'category' => 'Contacts & Partners'],
    
    'purchases_view'     => ['label' => 'View Purchases Logs', 'category' => 'Transactions'],
    'purchases_manage'   => ['label' => 'Create Purchase Receipts', 'category' => 'Transactions'],
    
    'sales_view'         => ['label' => 'View Sales Invoices', 'category' => 'Transactions'],
    'sales_manage'       => ['label' => 'Create & Edit Invoices', 'category' => 'Transactions'],
    'sales_delete'       => ['label' => 'Delete Invoices & Revert Stock', 'category' => 'Transactions'],
    
    'adjustments_manage' => ['label' => 'Create Stock Recount Adjustments', 'category' => 'Transactions'],
    'reports_view'       => ['label' => 'View Valuation & Register Reports', 'category' => 'Reports & Audits'],
    'logs_view'          => ['label' => 'View Activity Audit Trails', 'category' => 'Reports & Audits'],
    'settings_manage'    => ['label' => 'Manage System Profile & Branding', 'category' => 'System Control']
];

// Handle Permissions Matrix Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        // Retrieve checks from form submission
        // Structure: $_POST['perm'][role][permission_key] = "1"
        $form_perms = $_POST['perm'] ?? [];

        try {
            $pdo->beginTransaction();

            // Set all manager and staff permissions to 0 first, then enable the ones checked
            $reset_stmt = $pdo->prepare("UPDATE role_permissions SET is_allowed = 0 WHERE role IN ('manager', 'staff')");
            $reset_stmt->execute();

            $upd_stmt = $pdo->prepare("
                UPDATE role_permissions 
                SET is_allowed = 1 
                WHERE role = ? AND permission_key = ?
            ");

            // Loop and save checked values
            foreach ($form_perms as $role => $keys) {
                if (in_array($role, ['manager', 'staff'])) {
                    foreach ($keys as $key => $val) {
                        if (isset($perm_labels[$key]) && intval($val) === 1) {
                            $upd_stmt->execute([$role, $key]);
                        }
                    }
                }
            }

            log_activity($pdo, "Updated dynamic Roles & Permissions matrix");
            
            $pdo->commit();
            $success = "Roles and Permissions matrix updated successfully.";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Failed to save matrix: " . $e->getMessage();
        }
    }
}

// Fetch all current permissions from DB to display checks
try {
    $stmt = $pdo->query("SELECT role, permission_key, is_allowed FROM role_permissions");
    $db_perms = $stmt->fetchAll();
    
    // Structure into a neat associative array: $permissions[role][key] = 0 or 1
    $permissions = [];
    foreach ($db_perms as $dp) {
        $permissions[$dp['role']][$dp['permission_key']] = intval($dp['is_allowed']);
    }
} catch (PDOException $e) {
    $permissions = [];
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-shield-halved me-2 text-success"></i>Roles & Permissions</h3>
            <p class="text-muted" style="font-size: 0.95rem;">Configure dynamic feature access checks for system roles.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Settings</a>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<form action="roles.php" method="POST">
    <?php echo csrf_input(); ?>

    <div class="card card-premium shadow-sm border-0" style="border-radius: 12px;">
        <div class="card-premium-header">
            <h5 class="card-premium-title"><i class="fa-solid fa-table-list me-2 text-success"></i>Access Control Configuration Matrix</h5>
        </div>
        <div class="card-premium-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: 700;">
                        <tr>
                            <th>Feature / Module Access Key</th>
                            <th style="width: 140px; text-align: center;">Administrator</th>
                            <th style="width: 140px; text-align: center; color: var(--primary);">Manager</th>
                            <th style="width: 140px; text-align: center; color: var(--accent);">Staff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $current_cat = '';
                        foreach ($perm_labels as $key => $meta): 
                            // Add a header row for category grouping
                            if ($current_cat !== $meta['category']): 
                                $current_cat = $meta['category'];
                        ?>
                                <tr class="bg-light fw-bold" style="border-bottom: 2px solid #e2e8f0;">
                                    <td colspan="4" class="text-secondary" style="font-size: 0.85rem; letter-spacing: 0.02em; text-transform: uppercase;">
                                        <?php echo htmlspecialchars($current_cat); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($meta['label']); ?></div>
                                    <code class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($key); ?></code>
                                </td>
                                <!-- Admin: Always allowed (Read-only checked) -->
                                <td style="text-align: center; background-color: #f8fafc;">
                                    <div class="form-check d-flex justify-content-center">
                                        <input class="form-check-input bg-danger border-danger" type="checkbox" checked disabled style="cursor: not-allowed; width: 1.15rem; height: 1.15rem;">
                                    </div>
                                </td>
                                <!-- Manager -->
                                <td style="text-align: center;">
                                    <div class="form-check d-flex justify-content-center">
                                        <input class="form-check-input" type="checkbox" name="perm[manager][<?php echo $key; ?>]" value="1" 
                                            <?php echo (isset($permissions['manager'][$key]) && $permissions['manager'][$key] === 1) ? 'checked' : ''; ?>
                                            style="width: 1.15rem; height: 1.15rem; border-color: var(--primary);">
                                    </div>
                                </td>
                                <!-- Staff -->
                                <td style="text-align: center;">
                                    <div class="form-check d-flex justify-content-center">
                                        <input class="form-check-input" type="checkbox" name="perm[staff][<?php echo $key; ?>]" value="1" 
                                            <?php echo (isset($permissions['staff'][$key]) && $permissions['staff'][$key] === 1) ? 'checked' : ''; ?>
                                            style="width: 1.15rem; height: 1.15rem; border-color: var(--accent);">
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4 mb-5">
        <button type="submit" class="btn btn-accent px-5 py-2 bg-success"><i class="fa-solid fa-floppy-disk me-1"></i> Save Matrix Permissions</button>
        <a href="index.php" class="btn btn-light text-muted px-4 py-2 border">Cancel</a>
    </div>
</form>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
