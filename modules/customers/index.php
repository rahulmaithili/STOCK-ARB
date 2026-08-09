<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$error = '';
$edit_mode = false;
$edit_customer = null;

// Handle Add / Edit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $action = trim($_POST['action'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $id = intval($_POST['id'] ?? 0);

        if (empty($name)) {
            $error = "Customer Name is required.";
        } else {
            if ($action === 'add') {
                try {
                    $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $email, $address]);
                    log_activity($pdo, "Created customer: " . $name);
                    set_flash_message('success', "Customer '$name' added successfully.");
                    header("Location: index.php");
                    exit();
                } catch (PDOException $e) {
                    $error = "Failed to add customer: " . $e->getMessage();
                }
            } elseif ($action === 'edit' && $id > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
                    $stmt->execute([$name, $phone, $email, $address, $id]);
                    log_activity($pdo, "Updated customer: " . $name);
                    set_flash_message('success', "Customer details updated.");
                    header("Location: index.php");
                    exit();
                } catch (PDOException $e) {
                    $error = "Failed to update customer: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            $chk = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
            $chk->execute([$id]);
            $cust_name = $chk->fetchColumn();
            if ($cust_name) {
                $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
                $stmt->execute([$id]);
                log_activity($pdo, "Deleted customer: " . $cust_name);
                set_flash_message('success', "Customer '$cust_name' deleted successfully.");
            }
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            set_flash_message('danger', "Failed to delete customer. Linked invoice records exist.");
            header("Location: index.php");
            exit();
        }
    }
}

// Fetch customers
try {
    $stmt = $pdo->query("SELECT * FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll();
} catch (PDOException $e) {
    $customers = [];
    $error = "Database error: " . $e->getMessage();
}

// Prefetch all customer stats upfront to avoid nested queries in view
$cust_stats = [];
foreach ($customers as $c) {
    try {
        $inv = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0), COALESCE(SUM(cash_paid+online_paid),0), COUNT(*), MAX(sale_date) FROM sales WHERE customer_id = ?");
        $inv->execute([$c['id']]);
        $row = $inv->fetch(PDO::FETCH_NUM);
        $total_invoiced = floatval($row[0]);
        $total_paid = floatval($row[1]);
        $orders_count = intval($row[2]);
        $last_activity_date = $row[3];

        $sl = $pdo->prepare("SELECT * FROM sales WHERE customer_id = ? ORDER BY sale_date DESC LIMIT 5");
        $sl->execute([$c['id']]);
        $sales_list = $sl->fetchAll();

        $pl = $pdo->prepare("
            SELECT sale_date, 'Cash' as payment_mode, cash_paid as amount, invoice_no FROM sales WHERE customer_id = ? AND cash_paid > 0
            UNION ALL
            SELECT sale_date, 'Online' as payment_mode, online_paid as amount, invoice_no FROM sales WHERE customer_id = ? AND online_paid > 0
            ORDER BY sale_date DESC LIMIT 5
        ");
        $pl->execute([$c['id'], $c['id']]);
        $payments_list = $pl->fetchAll();

        $cust_stats[$c['id']] = [
            'total_invoiced' => $total_invoiced,
            'total_paid'     => $total_paid,
            'outstanding'    => $total_invoiced - $total_paid,
            'orders'         => $orders_count,
            'loyalty'        => floor($total_invoiced / 10),
            'last_activity'  => $last_activity_date ? date('d M Y', strtotime($last_activity_date)) : 'No activity',
            'sales'          => $sales_list,
            'payments'       => $payments_list,
        ];
    } catch (PDOException $e) {
        $cust_stats[$c['id']] = [
            'total_invoiced' => 0, 'total_paid' => 0, 'outstanding' => 0,
            'orders' => 0, 'loyalty' => 0, 'last_activity' => 'Error',
            'sales' => [], 'payments' => [],
        ];
    }
}

// Check Edit state
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    foreach ($customers as $cust) {
        if ($cust['id'] == $edit_id) {
            $edit_customer = $cust;
            $edit_mode = true;
            break;
        }
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-users me-2 text-success"></i>Customer Directory</h3>
        <p class="text-muted" style="font-size: 0.95rem;">Manage company client databases and contact lists.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Form -->
    <div class="col-12 col-md-4">
        <div class="card card-premium">
            <div class="card-premium-header">
                <h5 class="card-premium-title">
                    <?php echo $edit_mode ? '<i class="fa-solid fa-user-pen me-2 text-success"></i>Edit Customer' : '<i class="fa-solid fa-user-plus me-2 text-success"></i>Add Customer'; ?>
                </h5>
            </div>
            <div class="card-premium-body">
                <form action="index.php" method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="<?php echo $edit_mode ? 'edit' : 'add'; ?>">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_customer['id']; ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CUSTOMER NAME *</label>
                        <input type="text" class="form-control form-control-premium" name="name" required placeholder="e.g. Ramesh Kumar" value="<?php echo $edit_mode ? htmlspecialchars($edit_customer['name']) : ''; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CONTACT PHONE</label>
                        <input type="text" class="form-control form-control-premium" name="phone" placeholder="e.g. +91 9998887776" value="<?php echo $edit_mode ? htmlspecialchars($edit_customer['phone']) : ''; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">EMAIL ADDRESS</label>
                        <input type="email" class="form-control form-control-premium" name="email" placeholder="e.g. customer@domain.com" value="<?php echo $edit_mode ? htmlspecialchars($edit_customer['email'] ?? '') : ''; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">ADDRESS</label>
                        <textarea class="form-control form-control-premium" name="address" rows="3" placeholder="Billing / Delivery address..."><?php echo $edit_mode ? htmlspecialchars($edit_customer['address']) : ''; ?></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-accent"><?php echo $edit_mode ? 'Update Customer' : 'Save Customer'; ?></button>
                        <?php if ($edit_mode): ?>
                            <a href="index.php" class="btn btn-light text-muted">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="col-12 col-md-8">
        <div class="card card-premium">
            <div class="card-premium-header">
                <h5 class="card-premium-title"><i class="fa-solid fa-list me-2"></i>Registered Customers</h5>
            </div>
            <div class="card-premium-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle datatable" style="font-size: 0.88rem;">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th style="text-align: right; width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $c): ?>
                                <tr>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($c['name']); ?></td>
                                    <td><?php echo htmlspecialchars($c['phone'] ?: '-'); ?></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($c['address'] ?: '-'); ?></small></td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button class="btn btn-outline-primary btn-sm px-2 me-1"
                                            data-bs-toggle="modal"
                                            data-bs-target="#custModal<?php echo $c['id']; ?>"
                                            style="border-radius: 6px;" title="View Customer Profile">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <a href="index.php?action=edit&id=<?php echo $c['id']; ?>" class="btn btn-outline-secondary btn-sm me-1" style="border-radius: 6px;"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <a href="index.php?action=delete&id=<?php echo $c['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this customer?');" style="border-radius: 6px;"><i class="fa-solid fa-trash-can"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== CUSTOMER VIEW MODALS (outside table) ===== -->
<?php foreach ($customers as $c):
    $st = $cust_stats[$c['id']];
?>
<div class="modal fade" id="custModal<?php echo $c['id']; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">

            <!-- Header -->
            <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-user text-white"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0"><?php echo htmlspecialchars($c['name']); ?></h6>
                        <small style="opacity: 0.7; font-size: 0.75rem;"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($c['phone'] ?: 'N/A'); ?></small>
                    </div>
                </div>
                <div class="d-flex gap-2 ms-auto me-2">
                    <?php if (!empty($c['phone'])): ?>
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $c['phone']); ?>" target="_blank" class="btn btn-sm btn-light btn-outline-success d-flex align-items-center justify-content-center" style="width:32px;height:32px;border-radius:8px;padding:0;" title="WhatsApp">
                            <i class="fa-brands fa-whatsapp text-success"></i>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($c['email'])): ?>
                        <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>" class="btn btn-sm btn-light d-flex align-items-center justify-content-center" style="width:32px;height:32px;border-radius:8px;padding:0;" title="Email">
                            <i class="fa-solid fa-envelope text-primary"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0">
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs px-4 pt-3 border-bottom bg-light" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active border-0 fw-bold px-3 py-2" data-bs-toggle="tab" data-bs-target="#cust-overview-<?php echo $c['id']; ?>" type="button" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-circle-info me-1"></i> Overview
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link border-0 fw-bold px-3 py-2" data-bs-toggle="tab" data-bs-target="#cust-sales-<?php echo $c['id']; ?>" type="button" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-receipt me-1"></i> Sales
                            <span class="badge bg-danger ms-1" style="font-size: 0.68rem;"><?php echo $st['orders']; ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link border-0 fw-bold px-3 py-2" data-bs-toggle="tab" data-bs-target="#cust-payments-<?php echo $c['id']; ?>" type="button" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-money-bill me-1"></i> Payments
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Overview Tab -->
                    <div class="tab-pane fade show active p-4" id="cust-overview-<?php echo $c['id']; ?>">
                        <!-- KPI Grid -->
                        <div class="row g-2 mb-4 text-center">
                            <div class="col-4 col-md-2">
                                <div class="p-2 bg-light rounded-3 border h-100">
                                    <div class="text-muted fw-semibold" style="font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.05em;">Outstanding</div>
                                    <strong class="d-block text-danger" style="font-size: 0.9rem;">₹<?php echo number_format($st['outstanding'], 2); ?></strong>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="p-2 bg-light rounded-3 border h-100">
                                    <div class="text-muted fw-semibold" style="font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.05em;">Invoiced</div>
                                    <strong class="d-block text-dark" style="font-size: 0.9rem;">₹<?php echo number_format($st['total_invoiced'], 2); ?></strong>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="p-2 bg-light rounded-3 border h-100">
                                    <div class="text-muted fw-semibold" style="font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.05em;">Paid</div>
                                    <strong class="d-block text-success" style="font-size: 0.9rem;">₹<?php echo number_format($st['total_paid'], 2); ?></strong>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="p-2 bg-light rounded-3 border h-100">
                                    <div class="text-muted fw-semibold" style="font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.05em;">Orders</div>
                                    <strong class="d-block text-dark" style="font-size: 0.9rem;"><?php echo $st['orders']; ?></strong>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="p-2 bg-light rounded-3 border h-100">
                                    <div class="text-muted fw-semibold" style="font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.05em;">Credit</div>
                                    <strong class="d-block text-dark" style="font-size: 0.9rem;">₹0.00</strong>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="p-2 bg-light rounded-3 border h-100">
                                    <div class="text-muted fw-semibold" style="font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.05em;">Loyalty</div>
                                    <strong class="d-block text-dark" style="font-size: 0.9rem;"><?php echo $st['loyalty']; ?> pts</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Details -->
                        <table class="table table-sm table-borderless mb-0" style="font-size: 0.88rem;">
                            <tbody>
                                <tr class="border-bottom">
                                    <td class="text-muted fw-semibold py-2">Address</td>
                                    <td class="text-end text-dark"><?php echo htmlspecialchars($c['address'] ?: 'Not entered'); ?></td>
                                </tr>
                                <tr class="border-bottom">
                                    <td class="text-muted fw-semibold py-2">Last Activity</td>
                                    <td class="text-end text-dark"><?php echo $st['last_activity']; ?></td>
                                </tr>
                                <tr class="border-bottom">
                                    <td class="text-muted fw-semibold py-2">Status</td>
                                    <td class="text-end"><span class="badge bg-success-light text-success">active</span></td>
                                </tr>
                                <tr>
                                    <td class="text-muted fw-semibold py-2">Customer Since</td>
                                    <td class="text-end text-dark"><?php echo date('d M Y', strtotime($c['created_at'])); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Sales Tab -->
                    <div class="tab-pane fade p-4" id="cust-sales-<?php echo $c['id']; ?>">
                        <h6 class="fw-bold text-dark mb-3">Invoice History</h6>
                        <?php if (empty($st['sales'])): ?>
                            <p class="text-muted text-center py-4 mb-0">No sales invoices recorded.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle mb-0" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr><th>Date</th><th>Invoice</th><th style="text-align:right;">Total</th><th style="text-align:right;">Paid</th><th style="text-align:right;">Balance</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($st['sales'] as $sl):
                                            $paid = $sl['cash_paid'] + $sl['online_paid'];
                                            $bal = $sl['total_amount'] - $paid;
                                        ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($sl['sale_date'])); ?></td>
                                                <td><code><?php echo htmlspecialchars($sl['invoice_no'] ?: 'INV-' . $sl['id']); ?></code></td>
                                                <td style="text-align:right;">₹<?php echo number_format($sl['total_amount'], 2); ?></td>
                                                <td style="text-align:right;color:#047857;">₹<?php echo number_format($paid, 2); ?></td>
                                                <td style="text-align:right;color:#b91c1c;font-weight:600;">₹<?php echo number_format($bal, 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Payments Tab -->
                    <div class="tab-pane fade p-4" id="cust-payments-<?php echo $c['id']; ?>">
                        <h6 class="fw-bold text-dark mb-3">Payments Received</h6>
                        <?php if (empty($st['payments'])): ?>
                            <p class="text-muted text-center py-4 mb-0">No payments recorded.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle mb-0" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr><th>Date</th><th>Invoice Ref</th><th>Mode</th><th style="text-align:right;">Amount</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($st['payments'] as $pl): ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($pl['sale_date'])); ?></td>
                                                <td><code><?php echo htmlspecialchars($pl['invoice_no']); ?></code></td>
                                                <td class="text-uppercase"><?php echo htmlspecialchars($pl['payment_mode']); ?></td>
                                                <td style="text-align:right;font-weight:600;color:#047857;">₹<?php echo number_format($pl['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-0 px-4 py-3" style="background: #f8fafc;">
                <button type="button" class="btn btn-light btn-sm text-muted" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
