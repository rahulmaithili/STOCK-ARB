<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
$role = $_SESSION['user_role'] ?? 'staff';

// Fetch products with their category names
try {
    $query = "
        SELECT p.*, c.name as category_name 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY p.name ASC
    ";
    $stmt = $pdo->query($query);
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
    $error = "Database error: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-start align-sm-items-center gap-3">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-box-open me-2 text-success"></i>Product Directory</h3>
            <p class="text-muted" style="font-size: 0.95rem; margin: 0;">Add and manage all inventory stock items.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="categories.php" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1" style="border-radius: 10px;">
                <i class="fa-solid fa-tags"></i> Categories
            </a>
            <?php if (in_array($role, ['admin', 'manager'])): ?>
                <a href="add.php" class="btn btn-accent d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-circle-plus"></i> Add Product
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Main Table Card -->
<div class="card card-premium">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-list-check me-2"></i>Product Catalog</h5>
    </div>
    <div class="card-premium-body">
        <?php if (empty($products)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-boxes-packing fa-3x mb-3 text-secondary"></i>
                <h5>No products registered yet.</h5>
                <?php if (in_array($role, ['admin', 'manager'])): ?>
                    <p style="font-size: 0.9rem;">Click "Add Product" above to build your catalog.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle datatable" style="font-size: 0.88rem;">
                    <thead>
                        <tr>
                            <th style="width: 54px;">Image</th>
                            <th>Product Name</th>
                            <th>SKU</th>
                            <th style="text-align: right;">Buy (₹)</th>
                            <th style="text-align: right;">Sell (₹)</th>
                            <th style="text-align: center;">Stock</th>
                            <th style="text-align: center;">Status</th>
                            <th style="text-align: right; width: 110px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($p['image']) && file_exists(dirname(dirname(__DIR__)) . '/' . $p['image'])): ?>
                                        <img src="<?php echo BASE_URL . $p['image']; ?>" alt="Product" class="rounded border" style="width: 44px; height: 44px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="bg-light rounded border d-flex align-items-center justify-content-center text-secondary" style="width: 44px; height: 44px;">
                                            <i class="fa-solid fa-image text-muted" style="font-size: 0.85rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($p['name']); ?></div>
                                    <div class="d-flex align-items-center gap-1.5 mt-1 flex-wrap">
                                        <small class="badge bg-light text-secondary border py-0.5 px-2" style="font-size: 0.68rem;"><?php echo htmlspecialchars($p['category_name'] ?: 'Uncategorized'); ?></small>
                                        <?php if ($p['product_type'] === 'regulator'): ?>
                                            <span class="badge bg-warning-light text-warning border border-warning-subtle py-0.5 px-2" style="font-size: 0.68rem; border-radius: 4px;">Regulator Swap</span>
                                        <?php elseif ($p['product_type'] === 'ftl_regulator'): ?>
                                            <span class="badge bg-info-light text-info border border-info-subtle py-0.5 px-2" style="font-size: 0.68rem; border-radius: 4px;">FTL Regulator</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <code class="bg-light text-secondary px-2 py-1 rounded" style="font-size: 0.78rem;">
                                        <?php echo htmlspecialchars($p['sku']); ?>
                                    </code>
                                </td>
                                <td style="text-align: right; font-weight: 500;">
                                    ₹<?php echo number_format($p['purchase_price'], 2); ?>
                                </td>
                                <td style="text-align: right; font-weight: 500;">
                                    ₹<?php echo number_format($p['selling_price'], 2); ?>
                                </td>
                                <td style="text-align: center;">
                                    <div class="fw-bold text-dark"><?php echo number_format($p['current_stock']); ?> <small class="text-muted" style="font-weight: 400; font-size: 0.72rem;"><?php echo htmlspecialchars($p['unit']); ?></small></div>
                                    <?php if ($p['product_type'] === 'regulator'): ?>
                                        <div class="mt-1"><small class="text-danger fw-semibold" style="font-size: 0.72rem;"><i class="fa-solid fa-triangle-exclamation text-danger-subtle me-1"></i>Defective: <?php echo number_format($p['defective_stock']); ?></small></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($p['current_stock'] <= $p['reorder_level']): ?>
                                        <span class="badge bg-danger-light text-danger border border-danger-subtle px-2 py-1 fw-semibold" style="border-radius: 6px; font-size: 0.72rem;">
                                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Low
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success-light text-success border border-success-subtle px-2 py-1 fw-semibold" style="border-radius: 6px; font-size: 0.72rem;">
                                            Healthy
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button class="btn btn-outline-primary btn-sm px-2 me-1"
                                        data-bs-toggle="modal"
                                        data-bs-target="#viewModal<?php echo $p['id']; ?>"
                                        style="border-radius: 6px;" title="View Product Details">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <?php if (in_array($role, ['admin', 'manager'])): ?>
                                        <a href="edit.php?id=<?php echo $p['id']; ?>" class="btn btn-outline-secondary btn-sm me-1" style="border-radius: 6px;" title="Edit Product"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <a href="delete.php?id=<?php echo $p['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this product?');" style="border-radius: 6px;" title="Delete Product"><i class="fa-solid fa-trash-can"></i></a>
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

<!-- ========== PRODUCT VIEW MODALS (outside table) ========== -->
<?php foreach ($products as $p): ?>
<div class="modal fade" id="viewModal<?php echo $p['id']; ?>" tabindex="-1" aria-labelledby="viewModalLabel<?php echo $p['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">

            <!-- Dark Blue Header -->
            <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-box text-white"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="viewModalLabel<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></h6>
                        <small style="opacity: 0.7; font-size: 0.75rem;"><code class="text-white">SKU: <?php echo htmlspecialchars($p['sku']); ?></code></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0">
                <!-- Product Image Banner -->
                <div class="text-center py-4 px-4 border-bottom" style="background: #f8fafc;">
                    <?php if (!empty($p['image']) && file_exists(dirname(dirname(__DIR__)) . '/' . $p['image'])): ?>
                        <img src="<?php echo BASE_URL . $p['image']; ?>" class="rounded-3 shadow-sm" style="max-height: 160px; max-width: 100%; object-fit: contain;" alt="Product Image">
                    <?php else: ?>
                        <div class="bg-white border rounded-3 d-inline-flex align-items-center justify-content-center" style="width: 120px; height: 120px; border: 2px dashed #e2e8f0 !important;">
                            <i class="fa-solid fa-image fa-3x text-muted" style="opacity: 0.4;"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- KPI Row -->
                <div class="row g-0 text-center border-bottom">
                    <div class="col-4 py-3 px-2 border-end">
                        <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Buy Price</div>
                        <div class="fw-bold text-dark" style="font-size: 1rem;">₹<?php echo number_format($p['purchase_price'], 2); ?></div>
                    </div>
                    <div class="col-4 py-3 px-2 border-end">
                        <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Sell Price</div>
                        <div class="fw-bold text-success" style="font-size: 1rem;">₹<?php echo number_format($p['selling_price'], 2); ?></div>
                    </div>
                    <div class="col-4 py-3 px-2">
                        <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">In Stock</div>
                        <div class="fw-bold <?php echo $p['current_stock'] <= $p['reorder_level'] ? 'text-danger' : 'text-dark'; ?>" style="font-size: 1rem;"><?php echo number_format($p['current_stock']); ?> <small class="text-muted" style="font-size: 0.7rem;"><?php echo htmlspecialchars($p['unit']); ?></small></div>
                    </div>
                </div>

                <!-- Details List -->
                <div class="px-4 py-3">
                    <table class="table table-sm table-borderless mb-0 align-middle" style="font-size: 0.88rem;">
                        <tbody>
                            <tr class="border-bottom">
                                <td class="text-muted fw-semibold py-2" style="width: 45%;">Category</td>
                                <td class="text-end text-dark fw-medium"><?php echo htmlspecialchars($p['category_name'] ?: 'Uncategorized'); ?></td>
                            </tr>
                            <tr class="border-bottom">
                                <td class="text-muted fw-semibold py-2">Classification</td>
                                <td class="text-end text-dark fw-semibold">
                                    <?php 
                                    if ($p['product_type'] === 'regulator') {
                                        echo 'Regulator (Swappable)';
                                    } elseif ($p['product_type'] === 'ftl_regulator') {
                                        echo 'FTL Regulator (Sale Only)';
                                    } else {
                                        echo 'Standard Product';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php if ($p['product_type'] === 'regulator'): ?>
                                <tr class="border-bottom">
                                    <td class="text-muted fw-semibold py-2">Defective Stock</td>
                                    <td class="text-end fw-bold text-danger"><?php echo number_format($p['defective_stock']); ?> <?php echo htmlspecialchars($p['unit']); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr class="border-bottom">
                                <td class="text-muted fw-semibold py-2">Reorder Alert</td>
                                <td class="text-end fw-bold text-danger"><?php echo number_format($p['reorder_level']); ?> <?php echo htmlspecialchars($p['unit']); ?></td>
                            </tr>
                            <tr class="border-bottom">
                                <td class="text-muted fw-semibold py-2">Stock Status</td>
                                <td class="text-end">
                                    <?php if ($p['current_stock'] <= $p['reorder_level']): ?>
                                        <span class="badge bg-danger-light text-danger border border-danger-subtle px-2">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-light text-success border border-success-subtle px-2">Healthy</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted fw-semibold py-2">Description</td>
                                <td class="text-end text-muted" style="font-size: 0.82rem; max-width: 180px; word-wrap: break-word;"><?php echo htmlspecialchars(!empty($p['description']) ? $p['description'] : 'No description provided.'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer border-0 px-4 py-3" style="background: #f8fafc;">
                <?php if (in_array($role, ['admin', 'manager'])): ?>
                    <a href="edit.php?id=<?php echo $p['id']; ?>" class="btn btn-accent btn-sm"><i class="fa-solid fa-pen-to-square me-1"></i>Edit</a>
                <?php endif; ?>
                <button type="button" class="btn btn-light btn-sm text-muted" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
