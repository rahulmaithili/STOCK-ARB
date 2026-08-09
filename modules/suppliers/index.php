<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$error = '';
$edit_mode = false;
$edit_supplier = null;

// Handle Add / Edit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $action = trim($_POST['action'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $id = intval($_POST['id'] ?? 0);

        if (empty($name)) {
            $error = "Supplier Name is required.";
        } else {
            if ($action === 'add') {
                try {
                    $stmt = $pdo->prepare("INSERT INTO suppliers (name, phone, address) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $phone, $address]);
                    log_activity($pdo, "Created supplier: " . $name);
                    set_flash_message('success', "Supplier '$name' added successfully.");
                    header("Location: index.php");
                    exit();
                } catch (PDOException $e) {
                    $error = "Failed to add supplier: " . $e->getMessage();
                }
            } elseif ($action === 'edit' && $id > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE suppliers SET name = ?, phone = ?, address = ? WHERE id = ?");
                    $stmt->execute([$name, $phone, $address, $id]);
                    log_activity($pdo, "Updated supplier: " . $name);
                    set_flash_message('success', "Supplier details updated.");
                    header("Location: index.php");
                    exit();
                } catch (PDOException $e) {
                    $error = "Failed to update supplier: " . $e->getMessage();
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
            $chk = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
            $chk->execute([$id]);
            $sup_name = $chk->fetchColumn();
            if ($sup_name) {
                $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
                $stmt->execute([$id]);
                log_activity($pdo, "Deleted supplier: " . $sup_name);
                set_flash_message('success', "Supplier '$sup_name' deleted successfully.");
            }
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            set_flash_message('danger', "Failed to delete supplier. Linked purchase history exists.");
            header("Location: index.php");
            exit();
        }
    }
}

// Fetch suppliers
try {
    $stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC");
    $suppliers = $stmt->fetchAll();
} catch (PDOException $e) {
    $suppliers = [];
    $error = "Database error: " . $e->getMessage();
}

// Prefetch purchase stats for all suppliers
$supplier_stats = [];
foreach ($suppliers as $s) {
    try {
        $ps = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0), COUNT(*) FROM purchases WHERE supplier_id = ?");
        $ps->execute([$s['id']]);
        $row = $ps->fetch(PDO::FETCH_NUM);
        $supplier_stats[$s['id']] = ['total' => floatval($row[0]), 'count' => intval($row[1])];

        $pl = $pdo->prepare("SELECT * FROM purchases WHERE supplier_id = ? ORDER BY purchase_date DESC LIMIT 5");
        $pl->execute([$s['id']]);
        $supplier_stats[$s['id']]['purchases'] = $pl->fetchAll();
    } catch (PDOException $e) {
        $supplier_stats[$s['id']] = ['total' => 0, 'count' => 0, 'purchases' => []];
    }
}

// Check Edit state
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    foreach ($suppliers as $sup) {
        if ($sup['id'] == $edit_id) {
            $edit_supplier = $sup;
            $edit_mode = true;
            break;
        }
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-truck-ramp-box me-2 text-success"></i>Supplier Registry</h3>
        <p class="text-muted" style="font-size: 0.95rem;">Manage company vendor directories and supply contacts.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Add/Edit Form -->
    <div class="col-12 col-md-4">
        <div class="card card-premium">
            <div class="card-premium-header">
                <h5 class="card-premium-title">
                    <?php echo $edit_mode ? '<i class="fa-solid fa-user-pen me-2 text-success"></i>Edit Supplier' : '<i class="fa-solid fa-user-plus me-2 text-success"></i>Add Supplier'; ?>
                </h5>
            </div>
            <div class="card-premium-body">
                <form action="index.php" method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="<?php echo $edit_mode ? 'edit' : 'add'; ?>">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_supplier['id']; ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">SUPPLIER / COMPANY NAME *</label>
                        <input type="text" class="form-control form-control-premium" id="name" name="name" required placeholder="e.g. Indane Gas Distributor" value="<?php echo $edit_mode ? htmlspecialchars($edit_supplier['name']) : ''; ?>">
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CONTACT PHONE</label>
                        <input type="text" class="form-control form-control-premium" id="phone" name="phone" placeholder="e.g. +91 9876543210" value="<?php echo $edit_mode ? htmlspecialchars($edit_supplier['phone']) : ''; ?>">
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">ADDRESS</label>
                        <textarea class="form-control form-control-premium" id="address" name="address" rows="3" placeholder="Office/Warehouse address..."><?php echo $edit_mode ? htmlspecialchars($edit_supplier['address']) : ''; ?></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-accent"><?php echo $edit_mode ? 'Update Supplier' : 'Save Supplier'; ?></button>
                        <?php if ($edit_mode): ?>
                            <a href="index.php" class="btn btn-light text-muted">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Suppliers Table -->
    <div class="col-12 col-md-8">
        <div class="card card-premium">
            <div class="card-premium-header">
                <h5 class="card-premium-title"><i class="fa-solid fa-list me-2"></i>Registered Suppliers</h5>
            </div>
            <div class="card-premium-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle datatable" style="font-size: 0.88rem;">
                        <thead>
                            <tr>
                                <th>Supplier Name</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th style="text-align: right; width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suppliers as $s): ?>
                                <tr>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($s['name']); ?></td>
                                    <td><?php echo htmlspecialchars($s['phone'] ?: '-'); ?></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($s['address'] ?: '-'); ?></small></td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button class="btn btn-outline-primary btn-sm px-2 me-1"
                                            data-bs-toggle="modal"
                                            data-bs-target="#viewModal<?php echo $s['id']; ?>"
                                            style="border-radius: 6px;" title="View Supplier Profile">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <a href="index.php?action=edit&id=<?php echo $s['id']; ?>" class="btn btn-outline-secondary btn-sm me-1" style="border-radius: 6px;"><i class="fa-solid fa-pen-to-square"></i></a>
                                        <a href="index.php?action=delete&id=<?php echo $s['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this supplier?');" style="border-radius: 6px;"><i class="fa-solid fa-trash-can"></i></a>
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

<!-- ===== SUPPLIER VIEW MODALS (outside table, before footer) ===== -->
<?php foreach ($suppliers as $s):
    $stats = $supplier_stats[$s['id']];
?>
<div class="modal fade" id="viewModal<?php echo $s['id']; ?>" tabindex="-1" aria-labelledby="supModalLabel<?php echo $s['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">

            <!-- Dark Header -->
            <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-truck-fast text-white"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="supModalLabel<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></h6>
                        <small style="opacity: 0.7; font-size: 0.75rem;"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($s['phone'] ?: 'N/A'); ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0">
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs px-4 pt-3 border-bottom bg-light" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active border-0 fw-bold px-3 py-2" data-bs-toggle="tab" data-bs-target="#sup-overview-<?php echo $s['id']; ?>" type="button" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-circle-info me-1"></i> Overview
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link border-0 fw-bold px-3 py-2" data-bs-toggle="tab" data-bs-target="#sup-deliveries-<?php echo $s['id']; ?>" type="button" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-box-open me-1"></i> Deliveries
                            <span class="badge bg-secondary ms-1" style="font-size: 0.68rem;"><?php echo $stats['count']; ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Overview Tab -->
                    <div class="tab-pane fade show active p-4" id="sup-overview-<?php echo $s['id']; ?>">
                        <!-- Profile Row -->
                        <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                            <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; background: linear-gradient(135deg, #0f172a, #1e293b);">
                                <i class="fa-solid fa-truck-fast fa-lg"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($s['name']); ?></h6>
                                <small class="text-muted d-block"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($s['phone'] ?: 'Not provided'); ?></small>
                            </div>
                        </div>

                        <!-- KPI Summary -->
                        <div class="row g-3 mb-4 text-center">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Total Deliveries</div>
                                    <strong class="fs-4 text-dark"><?php echo $stats['count']; ?></strong>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="text-muted fw-semibold mb-1" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em;">Total Value Expended</div>
                                    <strong class="fs-4 text-success">₹<?php echo number_format($stats['total'], 2); ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Details -->
                        <table class="table table-sm table-borderless mb-0" style="font-size: 0.88rem;">
                            <tbody>
                                <tr class="border-bottom">
                                    <td class="text-muted fw-semibold py-2">Address</td>
                                    <td class="text-end text-dark"><?php echo htmlspecialchars($s['address'] ?: 'Not entered'); ?></td>
                                </tr>
                                <tr class="border-bottom">
                                    <td class="text-muted fw-semibold py-2">Status</td>
                                    <td class="text-end"><span class="badge bg-success-light text-success">active</span></td>
                                </tr>
                                <tr>
                                    <td class="text-muted fw-semibold py-2">Supplier Since</td>
                                    <td class="text-end text-dark"><?php echo date('d M Y', strtotime($s['created_at'])); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Deliveries Tab -->
                    <div class="tab-pane fade p-4" id="sup-deliveries-<?php echo $s['id']; ?>">
                        <h6 class="fw-bold text-dark mb-3">Purchase Delivery Logs</h6>
                        <?php if (empty($stats['purchases'])): ?>
                            <p class="text-muted text-center py-4 mb-0">No purchases recorded for this supplier.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle mb-0" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Invoice</th>
                                            <th style="text-align: right;">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['purchases'] as $pl): ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($pl['purchase_date'])); ?></td>
                                                <td><code><?php echo htmlspecialchars($pl['invoice_no'] ?: 'PUR-' . $pl['id']); ?></code></td>
                                                <td style="text-align: right; font-weight: 600; color: #047857;">₹<?php echo number_format($pl['total_amount'], 2); ?></td>
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
                <a href="index.php?action=edit&id=<?php echo $s['id']; ?>" class="btn btn-accent btn-sm"><i class="fa-solid fa-pen-to-square me-1"></i>Edit</a>
                <button type="button" class="btn btn-light btn-sm text-muted" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
