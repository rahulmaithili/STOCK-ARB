<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
$role = $_SESSION['user_role'] ?? 'staff';

// Fetch all sales invoices
try {
    $stmt = $pdo->query("
        SELECT s.*, c.name as customer_name, u.name as user_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN users u ON s.created_by = u.id
        ORDER BY s.sale_date DESC, s.created_at DESC
    ");
    $sales = $stmt->fetchAll();
} catch (PDOException $e) {
    $sales = [];
    $error = "Database error: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-start align-sm-items-center gap-3">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-circle-up me-2 text-danger"></i>Stock Out (Sales)</h3>
            <p class="text-muted" style="font-size: 0.95rem; margin: 0;">Record invoices and sales orders delivered to customers.</p>
        </div>
        <div>
            <a href="add.php" class="btn btn-accent d-inline-flex align-items-center gap-1">
                <i class="fa-solid fa-file-invoice"></i> Create Invoice
            </a>
        </div>
    </div>
</div>

<div class="card card-premium">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-list me-2"></i>Sales Invoice Log</h5>
    </div>
    <div class="card-premium-body">
        <?php if (empty($sales)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-receipt fa-3x mb-3 text-secondary"></i>
                <h5>No sales transactions recorded yet.</h5>
                <p style="font-size: 0.9rem;">Click "Create Invoice" above to log your first sale.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle datatable">
                    <thead>
                        <tr>
                            <th>Invoice Date</th>
                            <th>Invoice / Bill No</th>
                            <th>Customer Partner</th>
                            <th>Payment Mode</th>
                            <th style="text-align: right;">Discount (₹)</th>
                            <th style="text-align: right;">Received (₹)</th>
                            <th style="text-align: right;">Total Bill (₹)</th>
                            <th style="text-align: right; width: 120px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales as $s): 
                            $received = $s['cash_paid'] + $s['online_paid'];
                            $due = $s['total_amount'] - $received;
                        ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?php echo date('d-M-Y', strtotime($s['sale_date'])); ?>
                                </td>
                                <td>
                                    <code class="bg-light text-secondary px-2 py-1 rounded" style="font-size: 0.85rem; font-weight: 500;">
                                        <?php echo htmlspecialchars($s['invoice_no'] ?: 'N/A'); ?>
                                    </code>
                                </td>
                                <td>
                                    <div class="text-dark fw-semibold"><?php echo htmlspecialchars($s['customer_name'] ?: 'Retail Cash Customer'); ?></div>
                                </td>
                                <td>
                                    <?php if ($s['payment_type'] === 'cash'): ?>
                                        <span class="badge bg-success-light text-success border border-success-subtle px-3 py-1">CASH</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-light text-warning border border-warning-subtle px-3 py-1">CREDIT</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; color: var(--text-muted);">
                                    ₹<?php echo number_format($s['discount'], 2); ?>
                                </td>
                                <td style="text-align: right; color: var(--success-dark); font-weight: 500;">
                                    ₹<?php echo number_format($received, 2); ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: var(--navy-dark);">
                                    ₹<?php echo number_format($s['total_amount'], 2); ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button class="btn btn-outline-primary btn-sm px-2 me-1" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $s['id']; ?>" style="border-radius: 6px;" title="View Invoice details">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <?php if (in_array($role, ['admin', 'manager'])): ?>
                                        <a href="edit.php?id=<?php echo $s['id']; ?>" class="btn btn-outline-secondary btn-sm me-1" style="border-radius: 6px;" title="Edit Invoice Details"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <a href="delete.php?id=<?php echo $s['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to delete this invoice? Items quantity will be reverted back to product stock.');" style="border-radius: 6px;" title="Delete Invoice Log"><i class="fa-solid fa-trash-can"></i></a>
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

<!-- ===== VIEW SALES MODALS (outside table, before footer) ===== -->
<?php foreach ($sales as $s): 
    $received = $s['cash_paid'] + $s['online_paid'];
    $due = $s['total_amount'] - $received;
?>
<div class="modal fade" id="viewModal<?php echo $s['id']; ?>" tabindex="-1" aria-labelledby="viewModalLabel<?php echo $s['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
            <!-- Dark Header -->
            <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-receipt text-white"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="viewModalLabel<?php echo $s['id']; ?>">Sales Invoice Details</h6>
                        <small style="opacity: 0.7; font-size: 0.75rem;">Invoice No: <?php echo htmlspecialchars($s['invoice_no'] ?: 'N/A'); ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4">
                <div class="row g-3 mb-4 text-center">
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Invoice No</div>
                            <strong class="text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($s['invoice_no'] ?: 'N/A'); ?></strong>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Date</div>
                            <strong class="text-dark" style="font-size: 0.95rem;"><?php echo date('d-M-Y', strtotime($s['sale_date'])); ?></strong>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Customer Partner</div>
                            <strong class="text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($s['customer_name'] ?: 'Retail / Cash'); ?></strong>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Net Total</div>
                            <strong class="text-danger" style="font-size: 0.95rem;">₹<?php echo number_format($s['total_amount'], 2); ?></strong>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-list-check me-1 text-danger"></i>Invoice Line Items</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-hover align-middle table-sm border" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Product Item</th>
                                <th style="text-align: center; width: 100px;">Qty</th>
                                <th style="text-align: right; width: 120px;">Unit Rate (₹)</th>
                                <th style="text-align: right; width: 150px;">Total Price (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $item_stmt = $pdo->prepare("
                                    SELECT si.*, prod.name as product_name, prod.sku as product_sku
                                    FROM sale_items si
                                    LEFT JOIN products prod ON si.product_id = prod.id
                                    WHERE si.sale_id = ?
                                ");
                                $item_stmt->execute([$s['id']]);
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

                <!-- Payment Breakup Summary -->
                <div class="row justify-content-end border-top pt-3">
                    <div class="col-12 col-md-6 text-end" style="font-size: 0.88rem;">
                        <div class="mb-1 text-muted">Subtotal: <strong>₹<?php echo number_format($s['total_amount'] + $s['discount'], 2); ?></strong></div>
                        <div class="mb-1 text-muted">Discount Applied: <strong>₹<?php echo number_format($s['discount'], 2); ?></strong></div>
                        <div class="mb-1 text-success">Cash Amount Paid: <strong>₹<?php echo number_format($s['cash_paid'], 2); ?></strong></div>
                        <div class="mb-1 text-primary">Online Amount Paid: <strong>₹<?php echo number_format($s['online_paid'], 2); ?></strong></div>
                        <div class="mt-2 fw-bold text-danger" style="font-size: 1.05rem;">Remaining Balance Due: ₹<?php echo number_format($due, 2); ?></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 py-3" style="background: #f8fafc;">
                <a href="print.php?id=<?php echo $s['id']; ?>" target="_blank" class="btn btn-success btn-sm d-inline-flex align-items-center gap-1"><i class="fa-solid fa-print"></i> Print Invoice</a>
                <?php if (in_array($role, ['admin', 'manager'])): ?>
                    <a href="edit.php?id=<?php echo $s['id']; ?>" class="btn btn-accent btn-sm"><i class="fa-solid fa-pen-to-square me-1"></i>Edit</a>
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
