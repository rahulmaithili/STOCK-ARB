<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

// Handle consumer replacements verification search
$search_consumer = trim($_GET['search_consumer'] ?? '');
$search_results = [];
$search_count = 0;

try {
    if (!empty($search_consumer)) {
        $search_stmt = $pdo->prepare("
            SELECT cr.*, c.name as customer_name, p.name as product_name, p.sku as product_sku, u.name as user_name
            FROM customer_replacements cr
            LEFT JOIN customers c ON cr.customer_id = c.id
            LEFT JOIN products p ON cr.product_id = p.id
            LEFT JOIN users u ON cr.created_by = u.id
            WHERE cr.consumer_number = ?
            ORDER BY cr.replacement_date DESC, cr.created_at DESC
        ");
        $search_stmt->execute([$search_consumer]);
        $search_results = $search_stmt->fetchAll();
        $search_count = count($search_results);
    }

    // 1. Total Defective stock of all Regulator products
    $stmt = $pdo->query("SELECT SUM(defective_stock) FROM products WHERE product_type = 'regulator'");
    $defective_in_hand = intval($stmt->fetchColumn() ?? 0);

    // 2. Total customer replacements count
    $stmt = $pdo->query("SELECT COUNT(*) FROM customer_replacements");
    $total_customer_replacements = intval($stmt->fetchColumn());

    // 2b. Breakdown counts of transaction types
    $stmt = $pdo->query("SELECT SUM(quantity) FROM customer_replacements WHERE swap_type = 'new_connection'");
    $total_new_connections = intval($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->query("SELECT SUM(quantity) FROM customer_replacements WHERE swap_type = 'tv_in'");
    $total_tv_in = intval($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->query("SELECT SUM(quantity) FROM customer_replacements WHERE swap_type = 'tv_out'");
    $total_tv_out = intval($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->query("SELECT SUM(quantity) FROM customer_replacements WHERE swap_type = 'replacement'");
    $total_swaps = intval($stmt->fetchColumn() ?? 0);

    // 3. Total plant returns count
    $stmt = $pdo->query("SELECT COUNT(*) FROM plant_replacements");
    $total_plant_returns = intval($stmt->fetchColumn());

    // 4. Fetch customer replacement logs
    $cust_logs_stmt = $pdo->query("
        SELECT cr.*, c.name as customer_name, p.name as product_name, p.sku as product_sku, u.name as user_name
        FROM customer_replacements cr
        LEFT JOIN customers c ON cr.customer_id = c.id
        LEFT JOIN products p ON cr.product_id = p.id
        LEFT JOIN users u ON cr.created_by = u.id
        ORDER BY cr.replacement_date DESC, cr.created_at DESC
        LIMIT 500
    ");
    $customer_logs = $cust_logs_stmt->fetchAll();

    // 5. Fetch plant return logs
    $plant_logs_stmt = $pdo->query("
        SELECT pr.*, s.name as supplier_name, p.name as product_name, p.sku as product_sku, u.name as user_name
        FROM plant_replacements pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.id
        LEFT JOIN products p ON pr.product_id = p.id
        LEFT JOIN users u ON pr.created_by = u.id
        ORDER BY pr.return_date DESC, pr.created_at DESC
        LIMIT 500
    ");
    $plant_logs = $plant_logs_stmt->fetchAll();

    // 6. Fetch regulator products with current stock and defective stock for quick view
    $regulator_stmt = $pdo->query("
        SELECT id, name, sku, current_stock, defective_stock, unit 
        FROM products 
        WHERE product_type = 'regulator' 
        ORDER BY name ASC
    ");
    $regulator_products = $regulator_stmt->fetchAll();

} catch (PDOException $e) {
    $defective_in_hand = 0;
    $total_customer_replacements = 0;
    $total_plant_returns = 0;
    $customer_logs = [];
    $plant_logs = [];
    $regulator_products = [];
    $error = "Database error: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-start align-sm-items-center gap-3">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-arrows-spin me-2 text-warning"></i>Regulator Replacements</h3>
            <p class="text-muted" style="font-size: 0.95rem; margin: 0;">Manage and audit Customer defective replacements and Plant swap-back transactions.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="customer.php" class="btn btn-warning text-white d-inline-flex align-items-center gap-1">
                <i class="fa-solid fa-user-tag"></i> Customer Swap
            </a>
            <a href="plant.php" class="btn btn-accent d-inline-flex align-items-center gap-1">
                <i class="fa-solid fa-industry"></i> Plant Swap-Back
            </a>
        </div>
    </div>
</div>

<!-- Consumer Replacement Verification Search -->
<div class="card card-premium mb-4 no-print" style="border-radius: 14px;">
    <div class="card-premium-body">
        <form action="index.php" method="GET" class="row g-3 align-items-center">
            <div class="col-12 col-md-8">
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted border-end-0"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" class="form-control form-control-premium border-start-0 ps-0" name="search_consumer" id="search_consumer" required placeholder="Enter Consumer Connection Number to check history... (e.g. CX-99988)" value="<?php echo htmlspecialchars($search_consumer); ?>">
                </div>
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-accent w-100"><i class="fa-solid fa-search me-1"></i>Verify Consumer</button>
                <?php if (!empty($search_consumer)): ?>
                    <a href="index.php" class="btn btn-light text-muted"><i class="fa-solid fa-xmark"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Conditional Search Results Panel -->
<?php if (!empty($search_consumer)): ?>
    <div class="card card-premium border-warning mb-4 no-print" style="border: 1px solid var(--warning) !important; border-radius: 14px; background-color: #fffbeb;">
        <div class="card-premium-header bg-warning bg-opacity-10 py-3 d-flex justify-content-between align-items-center">
            <h6 class="card-premium-title fw-bold text-dark mb-0">
                <i class="fa-solid fa-id-card-clip me-2 text-warning"></i>Verification Results for Consumer: <strong><?php echo htmlspecialchars($search_consumer); ?></strong>
            </h6>
            <span class="badge bg-warning text-dark py-1.5 px-3 fw-bold" style="border-radius: 8px; font-size: 0.82rem;">
                Total Swaps/Transactions: <?php echo $search_count; ?> times
            </span>
        </div>
        <div class="card-premium-body p-0 bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Customer Name</th>
                            <th>Mobile</th>
                            <th>Regulator Item</th>
                            <th>Type</th>
                            <th>Old Serial #</th>
                            <th>New Serial #</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($search_results)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No transaction records found for this Consumer Number.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($search_results as $sr): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo date('d-M-Y', strtotime($sr['replacement_date'])); ?></div>
                                        <small class="text-muted"><?php echo date('h:i A', strtotime($sr['created_at'])); ?></small>
                                    </td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($sr['customer_name'] ?: 'Retail Swap'); ?></td>
                                    <td><code><?php echo htmlspecialchars($sr['mobile_number'] ?: 'N/A'); ?></code></td>
                                    <td><?php echo htmlspecialchars($sr['product_name']); ?></td>
                                    <td>
                                        <?php if ($sr['swap_type'] === 'new_connection'): ?>
                                            <span class="badge bg-success-light text-success border border-success-subtle py-0.5 px-2">New Conn</span>
                                        <?php elseif ($sr['swap_type'] === 'tv_in'): ?>
                                            <span class="badge border py-0.5 px-2" style="font-size: 0.65rem; border-radius: 4px; background-color: #faf5ff; color: #7e22ce; border-color: #e9d5ff !important;">TV In</span>
                                        <?php elseif ($sr['swap_type'] === 'tv_out'): ?>
                                            <span class="badge bg-danger-light text-danger border border-danger-subtle py-0.5 px-2">TV Out</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-light text-warning border border-warning-subtle py-0.5 px-2">Exchange</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-danger fw-semibold"><?php echo htmlspecialchars($sr['old_regulator_no'] ?: 'N/A'); ?></td>
                                    <td class="text-success fw-semibold"><?php echo htmlspecialchars($sr['new_regulator_no'] ?: 'N/A'); ?></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($sr['notes'] ?: '-'); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Statistics Summary -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm text-white" style="border-radius: 12px; background: linear-gradient(135deg, #7c2d12, #c2410c);">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1"><?php echo number_format($defective_in_hand); ?></h3>
                    <small class="text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em; opacity: 0.9;">Defective in Hand (Warehouse)</small>
                </div>
                <div class="bg-white bg-opacity-25 p-3 rounded-circle" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm text-white" style="border-radius: 12px; background: linear-gradient(135deg, #1e3a8a, #3b82f6);">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1"><?php echo number_format($total_customer_replacements); ?></h3>
                    <small class="text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em; opacity: 0.9;">Total Customer Swaps</small>
                </div>
                <div class="bg-white bg-opacity-25 p-3 rounded-circle" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-people-carry-box fa-lg"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm text-white" style="border-radius: 12px; background: linear-gradient(135deg, #064e3b, #10b981);">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1"><?php echo number_format($total_plant_returns); ?></h3>
                    <small class="text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em; opacity: 0.9;">Total Plant Returns</small>
                </div>
                <div class="bg-white bg-opacity-25 p-3 rounded-circle" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-truck-ramp-box fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Regulator Operations Breakdown Summary -->
<div class="row g-3 mb-4 no-print">
    <!-- New Connections -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm" style="border-radius: 12px; background-color: #f0fdf4; border: 1px solid #bbf7d0 !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-bold" style="font-size: 0.73rem; letter-spacing: 0.03em; text-transform: uppercase; color: #15803d;">New Connections</span>
                    <i class="fa-solid fa-circle-plus" style="font-size: 0.95rem; color: #16a34a;"></i>
                </div>
                <h4 class="fw-bold mb-0" style="color: #15803d;"><?php echo number_format($total_new_connections); ?> <span style="font-size: 0.75rem; font-weight: normal;">pcs</span></h4>
                <small class="text-muted" style="font-size: 0.72rem;">Fresh issued</small>
            </div>
        </div>
    </div>
    <!-- TV In -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm" style="border-radius: 12px; background-color: #faf5ff; border: 1px solid #e9d5ff !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-bold" style="font-size: 0.73rem; letter-spacing: 0.03em; text-transform: uppercase; color: #7e22ce;">TV In (Trans. In)</span>
                    <i class="fa-solid fa-arrow-right-to-bracket" style="font-size: 0.95rem; color: #9333ea;"></i>
                </div>
                <h4 class="fw-bold mb-0" style="color: #7e22ce;"><?php echo number_format($total_tv_in); ?> <span style="font-size: 0.75rem; font-weight: normal;">pcs</span></h4>
                <small class="text-muted" style="font-size: 0.72rem;">Fresh issued</small>
            </div>
        </div>
    </div>
    <!-- TV Out -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm" style="border-radius: 12px; background-color: #fef2f2; border: 1px solid #fecaca !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-bold" style="font-size: 0.73rem; letter-spacing: 0.03em; text-transform: uppercase; color: #b91c1c;">TV Out (Trans. Out)</span>
                    <i class="fa-solid fa-arrow-right-from-bracket" style="font-size: 0.95rem; color: #dc2626;"></i>
                </div>
                <h4 class="fw-bold mb-0" style="color: #b91c1c;"><?php echo number_format($total_tv_out); ?> <span style="font-size: 0.75rem; font-weight: normal;">pcs</span></h4>
                <small class="text-muted" style="font-size: 0.72rem;">Defective received</small>
            </div>
        </div>
    </div>
    <!-- Replacement Swaps -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm" style="border-radius: 12px; background-color: #fffbeb; border: 1px solid #fef3c7 !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-bold" style="font-size: 0.73rem; letter-spacing: 0.03em; text-transform: uppercase; color: #b45309;">Exchanges (Swaps)</span>
                    <i class="fa-solid fa-arrows-spin" style="font-size: 0.95rem; color: #d97706;"></i>
                </div>
                <h4 class="fw-bold mb-0" style="color: #b45309;"><?php echo number_format($total_swaps); ?> <span style="font-size: 0.75rem; font-weight: normal;">pcs</span></h4>
                <small class="text-muted" style="font-size: 0.72rem;">Exchange swaps</small>
            </div>
        </div>
    </div>
</div>

<!-- Interactive Workflow Guide -->
<div class="card card-premium mb-4 overflow-hidden border-0 no-print" style="box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-radius: 16px;">
    <div class="card-header text-white border-0 py-3" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
        <h5 class="card-premium-title mb-0" style="font-size: 0.95rem; color: #fff !important;"><i class="fa-solid fa-circle-info me-2 text-warning"></i>Regulator & FTL Regulator Workflow Guide</h5>
    </div>
    <div class="card-premium-body bg-white p-4">
        <div class="row g-4">
            <!-- Customer Swap -->
            <div class="col-12 col-md-4">
                <div class="p-3 rounded-4 h-100 d-flex flex-column" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 28px; height: 28px; background: #3b82f6; font-weight: bold; font-size: 0.8rem; border-radius: 50%;">1</div>
                        <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.88rem;">Customer Regulator Swap</h6>
                    </div>
                    <p class="text-muted mb-3 flex-grow-1" style="font-size: 0.8rem; line-height: 1.4;">Customer returns a **Defective Regulator** and receives a **Fresh Regulator** from stock.</p>
                    <div class="bg-white p-2 rounded-3 border" style="font-size: 0.72rem; border-radius: 8px;">
                        <span class="badge bg-danger-light text-danger mb-1" style="font-size: 0.68rem; border-radius: 4px;">Good Stock -1</span><br>
                        <span class="badge bg-warning-light text-warning" style="font-size: 0.68rem; border-radius: 4px;">Defective Stock +1</span>
                    </div>
                </div>
            </div>

            <!-- Plant Swap -->
            <div class="col-12 col-md-4">
                <div class="p-3 rounded-4 h-100 d-flex flex-column" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 28px; height: 28px; background: #10b981; font-weight: bold; font-size: 0.8rem; border-radius: 50%;">2</div>
                        <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.88rem;">Plant Swap-Back (Factory)</h6>
                    </div>
                    <p class="text-muted mb-3 flex-grow-1" style="font-size: 0.8rem; line-height: 1.4;">We take accumulated **Defective Regulators** to the plant, and receive **Fresh Regulators**.</p>
                    <div class="bg-white p-2 rounded-3 border" style="font-size: 0.72rem; border-radius: 8px;">
                        <span class="badge bg-warning-light text-warning mb-1" style="font-size: 0.68rem; border-radius: 4px;">Defective Stock -1</span><br>
                        <span class="badge bg-success-light text-success" style="font-size: 0.68rem; border-radius: 4px;">Good Stock +1</span>
                    </div>
                </div>
            </div>

            <!-- FTL Regulator -->
            <div class="col-12 col-md-4">
                <div class="p-3 rounded-4 h-100 d-flex flex-column" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 28px; height: 28px; background: #f59e0b; font-weight: bold; font-size: 0.8rem; border-radius: 50%;">3</div>
                        <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.88rem;">FTL Regulator Direct Sales</h6>
                    </div>
                    <p class="text-muted mb-3 flex-grow-1" style="font-size: 0.8rem; line-height: 1.4;">FTL Regulators are sold directly. **No defective returns are accepted** from customers.</p>
                    <div class="bg-white p-2 rounded-3 border" style="font-size: 0.72rem; border-radius: 8px;">
                        <span class="badge bg-danger-light text-danger mb-1" style="font-size: 0.68rem; border-radius: 4px;">Good Stock -1</span><br>
                        <span class="badge bg-secondary-light text-secondary" style="font-size: 0.68rem; border-radius: 4px;">Defective: No Action</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Regulator Stock Quick Status -->
<div class="card card-premium mb-4">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-warehouse me-2"></i>Swappable Regulators Stock Summary</h5>
    </div>
    <div class="card-premium-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th>Product Item</th>
                        <th>SKU</th>
                        <th style="text-align: center;">Fresh Good Stock</th>
                        <th style="text-align: center; color: var(--danger-dark);">Defective Stock</th>
                        <th style="text-align: center;">Total Registered</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($regulator_products)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">No Regulator type products registered in catalog.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($regulator_products as $rp): ?>
                            <tr>
                                <td class="fw-semibold text-dark"><?php echo htmlspecialchars($rp['name']); ?></td>
                                <td><code><?php echo htmlspecialchars($rp['sku']); ?></code></td>
                                <td style="text-align: center; font-weight: 600;" class="text-success">
                                    <?php echo number_format($rp['current_stock']); ?> <small class="text-muted" style="font-weight:400;"><?php echo htmlspecialchars($rp['unit']); ?></small>
                                </td>
                                <td style="text-align: center; font-weight: 600;" class="text-danger">
                                    <?php echo number_format($rp['defective_stock']); ?> <small class="text-muted" style="font-weight:400;"><?php echo htmlspecialchars($rp['unit']); ?></small>
                                </td>
                                <td style="text-align: center; font-weight: 700;">
                                    <?php echo number_format($rp['current_stock'] + $rp['defective_stock']); ?> <small class="text-muted" style="font-weight:400;"><?php echo htmlspecialchars($rp['unit']); ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Logs Tabs -->
<div class="row g-4 mb-5">
    <!-- Customer Replacement Logs -->
    <div class="col-12 col-xl-6">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-header py-3">
                <h6 class="card-premium-title fw-bold text-dark"><i class="fa-solid fa-people-carry-box me-2 text-primary"></i>Customer Replacement Logs</h6>
            </div>
            <div class="card-premium-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.83rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Regulator</th>
                                <th style="text-align: center;">Qty</th>
                                <th>Logger</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customer_logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">No customer replacement logs found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($customer_logs as $cl): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo date('d-M-Y', strtotime($cl['replacement_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('h:i A', strtotime($cl['created_at'])); ?></small>
                                        </td>
                                        <td class="fw-semibold text-dark">
                                            <?php echo htmlspecialchars($cl['customer_name'] ?: 'Retail Swap'); ?>
                                            <?php if (!empty($cl['consumer_number'])): ?>
                                                <div class="mt-1"><span class="badge bg-light text-dark border fw-normal" style="font-size: 0.68rem; border-radius: 4px;"><i class="fa-solid fa-hashtag me-1 text-muted"></i>Cons: <?php echo htmlspecialchars($cl['consumer_number']); ?></span></div>
                                            <?php endif; ?>
                                            <?php if (!empty($cl['mobile_number'])): ?>
                                                <small class="text-muted d-block mt-0.5" style="font-size: 0.72rem;"><i class="fa-solid fa-phone me-1 text-muted"></i><?php echo htmlspecialchars($cl['mobile_number']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($cl['product_name']); ?></div>
                                            <div class="mt-1 flex-wrap d-flex gap-1 align-items-center">
                                                <?php if ($cl['swap_type'] === 'new_connection'): ?>
                                                    <span class="badge bg-success-light text-success border border-success-subtle py-0.5 px-2" style="font-size: 0.65rem; border-radius: 4px;">New Conn</span>
                                                <?php elseif ($cl['swap_type'] === 'tv_in'): ?>
                                                    <span class="badge border py-0.5 px-2" style="font-size: 0.65rem; border-radius: 4px; background-color: #faf5ff; color: #7e22ce; border-color: #e9d5ff !important;">TV In</span>
                                                <?php elseif ($cl['swap_type'] === 'tv_out'): ?>
                                                    <span class="badge bg-danger-light text-danger border border-danger-subtle py-0.5 px-2" style="font-size: 0.65rem; border-radius: 4px;">TV Out</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-light text-warning border border-warning-subtle py-0.5 px-2" style="font-size: 0.65rem; border-radius: 4px;">Exchange</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($cl['old_regulator_no'])): ?>
                                                <small class="text-danger d-block mt-1" style="font-size: 0.72rem; line-height: 1.1;"><i class="fa-solid fa-arrow-turn-down text-danger-subtle me-1"></i>Old SN: <strong><?php echo htmlspecialchars($cl['old_regulator_no']); ?></strong></small>
                                            <?php endif; ?>
                                            <?php if (!empty($cl['new_regulator_no'])): ?>
                                                <small class="text-success d-block mt-0.5" style="font-size: 0.72rem; line-height: 1.1;"><i class="fa-solid fa-arrow-turn-up text-success-subtle me-1"></i>New SN: <strong><?php echo htmlspecialchars($cl['new_regulator_no']); ?></strong></small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center; font-weight: 700; font-size: 0.8rem;">
                                            <?php if ($cl['swap_type'] === 'new_connection' || $cl['swap_type'] === 'tv_in'): ?>
                                                <span class="text-danger">-<?php echo number_format($cl['quantity']); ?> Good</span>
                                            <?php elseif ($cl['swap_type'] === 'tv_out'): ?>
                                                <span class="text-success">+<?php echo number_format($cl['quantity']); ?> Defective</span>
                                            <?php else: ?>
                                                <span class="text-secondary" style="font-size:0.75rem;">-<?php echo number_format($cl['quantity']); ?> Good / +<?php echo number_format($cl['quantity']); ?> Defective</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($cl['user_name'] ?: 'System'); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Plant Return Logs -->
    <div class="col-12 col-xl-6">
        <div class="card card-premium shadow-sm h-100">
            <div class="card-premium-header py-3">
                <h6 class="card-premium-title fw-bold text-dark"><i class="fa-solid fa-industry me-2 text-success"></i>Plant Return Swap-Back Logs</h6>
            </div>
            <div class="card-premium-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.83rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Plant (Supplier)</th>
                                <th>Regulator</th>
                                <th style="text-align: center;">Qty</th>
                                <th>Logger</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($plant_logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">No plant return logs found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($plant_logs as $pl): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo date('d-M-Y', strtotime($pl['return_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('h:i A', strtotime($pl['created_at'])); ?></small>
                                        </td>
                                        <td class="fw-semibold text-dark"><?php echo htmlspecialchars($pl['supplier_name'] ?: 'Primary Plant'); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($pl['product_name']); ?></div>
                                            <small class="text-muted">SKU: <?php echo htmlspecialchars($pl['product_sku']); ?></small>
                                        </td>
                                        <td style="text-align: center; font-weight: 700; color: var(--success-dark);">-<?php echo number_format($pl['quantity']); ?> Defective / +<?php echo number_format($pl['quantity']); ?> Good</td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($pl['user_name'] ?: 'System'); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
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
