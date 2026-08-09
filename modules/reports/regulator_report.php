<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

// Initial Filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$product_id = !empty($_GET['product_id']) ? intval($_GET['product_id']) : null;
$consumer_number = trim($_GET['consumer_number'] ?? '');

try {
    // 1. Fetch swappable regulator products for dropdown list
    $prod_stmt = $pdo->query("SELECT id, name, sku FROM products WHERE product_type = 'regulator' ORDER BY name ASC");
    $regulators = $prod_stmt->fetchAll();

    // Build query conditions for customer replacements
    $cust_conds = ["cr.replacement_date BETWEEN ? AND ?"];
    $cust_params = [$start_date, $end_date];

    if ($product_id) {
        $cust_conds[] = "cr.product_id = ?";
        $cust_params[] = $product_id;
    }
    if (!empty($consumer_number)) {
        $cust_conds[] = "cr.consumer_number LIKE ?";
        $cust_params[] = '%' . $consumer_number . '%';
    }

    $cust_where = implode(' AND ', $cust_conds);

    // Sum calculations for customer replacements
    // NC (New Connections)
    $nc_stmt = $pdo->prepare("SELECT SUM(quantity) FROM customer_replacements cr WHERE $cust_where AND cr.swap_type = 'new_connection'");
    $nc_stmt->execute($cust_params);
    $total_nc = intval($nc_stmt->fetchColumn() ?? 0);

    // TV In
    $tvi_stmt = $pdo->prepare("SELECT SUM(quantity) FROM customer_replacements cr WHERE $cust_where AND cr.swap_type = 'tv_in'");
    $tvi_stmt->execute($cust_params);
    $total_tvi = intval($tvi_stmt->fetchColumn() ?? 0);

    // TV Out (Returns)
    $tvo_stmt = $pdo->prepare("SELECT SUM(quantity) FROM customer_replacements cr WHERE $cust_where AND cr.swap_type = 'tv_out'");
    $tvo_stmt->execute($cust_params);
    $total_tvo = intval($tvo_stmt->fetchColumn() ?? 0);

    // Exchanges (Swaps)
    $swap_stmt = $pdo->prepare("SELECT SUM(quantity) FROM customer_replacements cr WHERE $cust_where AND cr.swap_type = 'replacement'");
    $swap_stmt->execute($cust_params);
    $total_swap = intval($swap_stmt->fetchColumn() ?? 0);

    // Fetch filtered customer transaction logs list
    $cust_logs_stmt = $pdo->prepare("
        SELECT cr.*, c.name as customer_name, p.name as product_name, p.sku as product_sku, u.name as user_name
        FROM customer_replacements cr
        LEFT JOIN customers c ON cr.customer_id = c.id
        LEFT JOIN products p ON cr.product_id = p.id
        LEFT JOIN users u ON cr.created_by = u.id
        WHERE $cust_where
        ORDER BY cr.replacement_date DESC, cr.created_at DESC
    ");
    $cust_logs_stmt->execute($cust_params);
    $customer_logs = $cust_logs_stmt->fetchAll();

    // Build query conditions for plant returns (Filter by date and product)
    $plant_conds = ["pr.return_date BETWEEN ? AND ?"];
    $plant_params = [$start_date, $end_date];

    if ($product_id) {
        $plant_conds[] = "pr.product_id = ?";
        $plant_params[] = $product_id;
    }

    $plant_where = implode(' AND ', $plant_conds);

    // Sum calculations for plant returns
    $plant_sum_stmt = $pdo->prepare("SELECT SUM(quantity) FROM plant_replacements pr WHERE $plant_where");
    $plant_sum_stmt->execute($plant_params);
    $total_plant_returns = intval($plant_sum_stmt->fetchColumn() ?? 0);

    // Fetch filtered plant return logs list
    $plant_logs_stmt = $pdo->prepare("
        SELECT pr.*, s.name as supplier_name, p.name as product_name, p.sku as product_sku, u.name as user_name
        FROM plant_replacements pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.id
        LEFT JOIN products p ON pr.product_id = p.id
        LEFT JOIN users u ON pr.created_by = u.id
        WHERE $plant_where
        ORDER BY pr.return_date DESC, pr.created_at DESC
    ");
    $plant_logs_stmt->execute($plant_params);
    $plant_logs = $plant_logs_stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Reporting query failed: " . $e->getMessage();
    $regulators = [];
    $customer_logs = [];
    $plant_logs = [];
    $total_nc = $total_tvi = $total_tvo = $total_swap = $total_plant_returns = 0;
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4 no-print">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-start align-sm-items-center gap-3">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-file-shield me-2 text-success"></i>Regulator Ledger & Replacement Register</h3>
            <p class="text-muted" style="font-size: 0.9rem; margin: 0;">Detailed register audits for New Connections (NC), TV In, TV Out, and Defective exchanges.</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print();" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1.5" style="border-radius: 10px;">
                <i class="fa-solid fa-print"></i> Print Report
            </button>
            <a href="index.php" class="btn btn-light text-muted" style="border-radius: 10px;"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
    </div>
</div>

<!-- Filters Panel -->
<div class="card card-premium mb-4 no-print" style="border-radius: 14px;">
    <div class="card-premium-body">
        <form method="GET" action="regulator_report.php" class="row g-3">
            <div class="col-12 col-sm-6 col-md-3">
                <label for="start_date" class="form-label fw-semibold text-muted" style="font-size: 0.78rem;">START DATE</label>
                <input type="date" class="form-control form-control-premium" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label for="end_date" class="form-label fw-semibold text-muted" style="font-size: 0.78rem;">END DATE</label>
                <input type="date" class="form-control form-control-premium" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label for="product_id" class="form-label fw-semibold text-muted" style="font-size: 0.78rem;">REGULATOR ITEM</label>
                <select class="form-select form-control-premium" id="product_id" name="product_id">
                    <option value="">-- All Regulators --</option>
                    <?php foreach ($regulators as $r): ?>
                        <option value="<?php echo $r['id']; ?>" <?php echo $product_id === intval($r['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($r['name']); ?> (<?php echo htmlspecialchars($r['sku']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label for="consumer_number" class="form-label fw-semibold text-muted" style="font-size: 0.78rem;">CONSUMER NUMBER</label>
                <input type="text" class="form-control form-control-premium" id="consumer_number" name="consumer_number" placeholder="Search consumer no..." value="<?php echo htmlspecialchars($consumer_number); ?>">
            </div>

            <div class="col-12 d-flex justify-content-end gap-2 mt-4">
                <a href="regulator_report.php" class="btn btn-light text-muted">Clear Filters</a>
                <button type="submit" class="btn btn-accent"><i class="fa-solid fa-filter me-1"></i>Apply Filter</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger no-print"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Summary Title for Printing -->
<div class="print-only-block d-none mb-3">
    <h5 class="fw-bold text-dark text-center text-uppercase" style="letter-spacing: 0.05em;">Regulator Ledger Register Report</h5>
    <div class="text-center text-muted" style="font-size: 0.8rem; margin-bottom: 20px;">
        Period: <?php echo date('d-M-Y', strtotime($start_date)); ?> to <?php echo date('d-M-Y', strtotime($end_date)); ?>
        <?php if ($product_id) echo " | Filtered by Regulator Item"; ?>
        <?php if (!empty($consumer_number)) echo " | Consumer: " . htmlspecialchars($consumer_number); ?>
    </div>
</div>

<!-- Ledger Summary Counters -->
<div class="row g-3 mb-4">
    <!-- New Connections issued count -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; background-color: #f0fdf4; border: 1px solid #bbf7d0 !important;">
            <span class="fw-semibold text-muted" style="font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase;">New Connections (NC)</span>
            <h3 class="fw-bold mb-0 text-success mt-1"><?php echo number_format($total_nc); ?> <span style="font-size: 0.8rem; font-weight: normal;">pcs</span></h3>
            <small class="text-muted mt-1" style="font-size: 0.7rem;">Fresh stock reduction</small>
        </div>
    </div>

    <!-- TV In issued count -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; background-color: #faf5ff; border: 1px solid #e9d5ff !important;">
            <span class="fw-semibold text-muted" style="font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase; color: #7e22ce;">TV In (Transfers)</span>
            <h3 class="fw-bold mb-0 mt-1" style="color: #7e22ce;"><?php echo number_format($total_tvi); ?> <span style="font-size: 0.8rem; font-weight: normal;">pcs</span></h3>
            <small class="text-muted mt-1" style="font-size: 0.7rem;">Fresh stock reduction</small>
        </div>
    </div>

    <!-- TV Out received count -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; background-color: #fef2f2; border: 1px solid #fecaca !important;">
            <span class="fw-semibold text-muted" style="font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase; color: #b91c1c;">TV Out (Transfers)</span>
            <h3 class="fw-bold mb-0 text-danger mt-1"><?php echo number_format($total_tvo); ?> <span style="font-size: 0.8rem; font-weight: normal;">pcs</span></h3>
            <small class="text-muted mt-1" style="font-size: 0.7rem;">Defective pool addition</small>
        </div>
    </div>

    <!-- Defective Exchanges count -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 12px; background-color: #fffbeb; border: 1px solid #fef3c7 !important;">
            <span class="fw-semibold text-muted" style="font-size: 0.72rem; letter-spacing: 0.03em; text-transform: uppercase; color: #b45309;">Defective Swaps</span>
            <h3 class="fw-bold mb-0 mt-1" style="color: #b45309;"><?php echo number_format($total_swap); ?> <span style="font-size: 0.8rem; font-weight: normal;">pcs</span></h3>
            <small class="text-muted mt-1" style="font-size: 0.7rem;">Exchange replacements</small>
        </div>
    </div>
</div>

<!-- Customer replacement ledger table card -->
<div class="card card-premium mb-4" style="border-radius: 14px;">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-people-carry-box me-2 text-primary"></i>Customer Swaps & Connection Issues Log</h5>
    </div>
    <div class="card-premium-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size: 0.84rem;">
                <thead class="table-light">
                    <tr>
                        <th style="padding: 10px 15px;">Date</th>
                        <th>Consumer #</th>
                        <th>Consumer Name</th>
                        <th>Mobile</th>
                        <th>Product Item</th>
                        <th>Type</th>
                        <th>Old Serial #</th>
                        <th>New Serial #</th>
                        <th style="text-align: center;">Qty</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customer_logs)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4 text-muted">No customer transactions logged during selected period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customer_logs as $cl): ?>
                            <tr>
                                <td style="padding: 10px 15px;">
                                    <div class="fw-semibold"><?php echo date('d-M-Y', strtotime($cl['replacement_date'])); ?></div>
                                </td>
                                <td><code><?php echo htmlspecialchars($cl['consumer_number']); ?></code></td>
                                <td class="fw-medium text-dark"><?php echo htmlspecialchars($cl['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($cl['mobile_number']); ?></td>
                                <td><?php echo htmlspecialchars($cl['product_name']); ?></td>
                                <td>
                                    <?php if ($cl['swap_type'] === 'new_connection'): ?>
                                        <span class="badge bg-success-light text-success border border-success-subtle py-0.5 px-2">New Conn</span>
                                    <?php elseif ($cl['swap_type'] === 'tv_in'): ?>
                                        <span class="badge border py-0.5 px-2" style="background-color: #faf5ff; color: #7e22ce; border-color: #e9d5ff !important;">TV In</span>
                                    <?php elseif ($cl['swap_type'] === 'tv_out'): ?>
                                        <span class="badge bg-danger-light text-danger border border-danger-subtle py-0.5 px-2">TV Out</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-light text-warning border border-warning-subtle py-0.5 px-2">Exchange</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-danger fw-semibold"><?php echo htmlspecialchars($cl['old_regulator_no'] ?: '-'); ?></td>
                                <td class="text-success fw-semibold"><?php echo htmlspecialchars($cl['new_regulator_no'] ?: '-'); ?></td>
                                <td style="text-align: center; font-weight: 700;"><?php echo number_format($cl['quantity']); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($cl['notes'] ?: '-'); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Plant Return Ledger card -->
<div class="card card-premium mb-5" style="border-radius: 14px;">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-industry me-2 text-success"></i>Plant Swaps & Defective Returns Log</h5>
    </div>
    <div class="card-premium-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size: 0.84rem;">
                <thead class="table-light">
                    <tr>
                        <th style="padding: 10px 15px;">Date</th>
                        <th>Plant/Supplier</th>
                        <th>Product Item</th>
                        <th style="text-align: center;">Qty Returned</th>
                        <th>Notes / Gate Pass Details</th>
                        <th class="no-print">Logged By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plant_logs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No plant returns logged during selected period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plant_logs as $pl): ?>
                            <tr>
                                <td style="padding: 10px 15px;">
                                    <div class="fw-semibold"><?php echo date('d-M-Y', strtotime($pl['return_date'])); ?></div>
                                </td>
                                <td class="fw-medium text-dark"><?php echo htmlspecialchars($pl['supplier_name']); ?></td>
                                <td><?php echo htmlspecialchars($pl['product_name']); ?></td>
                                <td style="text-align: center; font-weight: 700;" class="text-success"><?php echo number_format($pl['quantity']); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($pl['notes'] ?: '-'); ?></small></td>
                                <td class="no-print"><small class="text-secondary"><?php echo htmlspecialchars($pl['user_name']); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
