<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
$role = $_SESSION['user_role'] ?? 'staff';

// Fetch all purchases
try {
    $stmt = $pdo->query("
        SELECT p.*, s.name as supplier_name, u.name as user_name
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN users u ON p.created_by = u.id
        ORDER BY p.purchase_date DESC, p.created_at DESC
    ");
    $purchases = $stmt->fetchAll();
} catch (PDOException $e) {
    $purchases = [];
    $error = "Database error: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-start align-sm-items-center gap-3">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-circle-down me-2 text-success"></i>Stock In (Purchases)</h3>
            <p class="text-muted" style="font-size: 0.95rem; margin: 0;">Record inventory received from vendors and suppliers.</p>
        </div>
        <div>
            <a href="add.php" class="btn btn-accent d-inline-flex align-items-center gap-1">
                <i class="fa-solid fa-circle-plus"></i> Record Purchase
            </a>
        </div>
    </div>
</div>

<div class="card card-premium">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-list me-2"></i>Purchase Log History</h5>
    </div>
    <div class="card-premium-body">
        <?php if (empty($purchases)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-file-invoice-dollar fa-3x mb-3 text-secondary"></i>
                <h5>No purchase logs recorded yet.</h5>
                <p style="font-size: 0.9rem;">Click "Record Purchase" above to add your first stock receipt.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle datatable">
                    <thead>
                        <tr>
                            <th>Receipt Date</th>
                            <th>Invoice / Bill No</th>
                            <th>Supplier Partner</th>
                            <th style="text-align: right;">Total Amount</th>
                            <th>Entered By</th>
                            <th style="text-align: right; width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purchases as $p): ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?php echo date('d-M-Y', strtotime($p['purchase_date'])); ?>
                                </td>
                                <td>
                                    <code class="bg-light text-secondary px-2 py-1 rounded" style="font-size: 0.85rem; font-weight: 500;">
                                        <?php echo htmlspecialchars($p['invoice_no'] ?: 'N/A'); ?>
                                    </code>
                                </td>
                                <td>
                                    <div class="text-dark fw-semibold"><?php echo htmlspecialchars($p['supplier_name'] ?: 'Unknown Supplier'); ?></div>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: var(--navy-dark);">
                                    ₹<?php echo number_format($p['total_amount'], 2); ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo htmlspecialchars($p['user_name'] ?: 'System'); ?></small>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button class="btn btn-outline-primary btn-sm px-2 me-1" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $p['id']; ?>" style="border-radius: 6px;" title="View Purchase Details">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <?php if (in_array($role, ['admin', 'manager'])): ?>
                                        <a href="edit.php?id=<?php echo $p['id']; ?>" class="btn btn-outline-secondary btn-sm me-1" style="border-radius: 6px;" title="Edit Purchase"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <a href="delete.php?id=<?php echo $p['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this purchase record?');" style="border-radius: 6px;" title="Delete Purchase"><i class="fa-solid fa-trash-can"></i></a>
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

<!-- ===== VIEW PURCHASE MODALS (outside table, before footer) ===== -->
<?php foreach ($purchases as $p): ?>
<div class="modal fade" id="viewModal<?php echo $p['id']; ?>" tabindex="-1" aria-labelledby="viewModalLabel<?php echo $p['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
            <!-- Dark Blue Header -->
            <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-file-lines text-white"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="viewModalLabel<?php echo $p['id']; ?>">Purchase Receipt Details</h6>
                        <small style="opacity: 0.7; font-size: 0.75rem;">Invoice No: <?php echo htmlspecialchars($p['invoice_no'] ?: 'N/A'); ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <div class="row g-3 mb-4 text-center">
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Invoice No</div>
                            <strong class="text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($p['invoice_no'] ?: 'N/A'); ?></strong>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Date</div>
                            <strong class="text-dark" style="font-size: 0.95rem;"><?php echo date('d-M-Y', strtotime($p['purchase_date'])); ?></strong>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Supplier Partner</div>
                            <strong class="text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($p['supplier_name'] ?: 'Unknown'); ?></strong>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Total Amount</div>
                            <strong class="text-success" style="font-size: 0.95rem;">₹<?php echo number_format($p['total_amount'], 2); ?></strong>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-list-check me-1 text-success"></i>Invoice Line Items</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle table-sm border" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Product Item</th>
                                <th style="text-align: center; width: 100px;">Qty</th>
                                <th style="text-align: right; width: 120px;">Unit Rate (₹)</th>
                                <th style="text-align: right; width: 150px;">Total Cost (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $item_stmt = $pdo->prepare("
                                    SELECT pi.*, prod.name as product_name, prod.sku as product_sku
                                    FROM purchase_items pi
                                    LEFT JOIN products prod ON pi.product_id = prod.id
                                    WHERE pi.purchase_id = ?
                                ");
                                $item_stmt->execute([$p['id']]);
                                $line_items = $item_stmt->fetchAll();
                                
                                foreach ($line_items as $item):
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                            <small class="text-muted">SKU: <?php echo htmlspecialchars($item['product_sku']); ?></small>
                                        </td>
                                        <td style="text-align: center; font-weight: 600;">
                                            <?php echo number_format($item['quantity']); ?>
                                        </td>
                                        <td style="text-align: right;">
                                            ₹<?php echo number_format($item['rate'], 2); ?>
                                        </td>
                                        <td style="text-align: right; font-weight: 700;">
                                            ₹<?php echo number_format($item['amount'], 2); ?>
                                        </td>
                                    </tr>
                                <?php 
                                endforeach;
                            } catch (PDOException $e) {
                                echo "<tr><td colspan='4' class='text-danger'>Error loading items: " . $e->getMessage() . "</td></tr>";
                            }
                            ?>
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
