<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

// Fetch dynamic company details
$company = get_company_profile($pdo);

// Fetch all active products
try {
    $prod_stmt = $pdo->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.name ASC");
    $products = $prod_stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

// Get filter inputs
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-t');

// Auto-select the first product if none is selected
if ($product_id <= 0 && !empty($products)) {
    $product_id = $products[0]['id'];
}

$ledger_data = [];
$initial_stock = 0;
$selected_product = null;

if ($product_id > 0) {
    // Find current product meta
    foreach ($products as $p) {
        if ($p['id'] == $product_id) {
            $selected_product = $p;
            break;
        }
    }

    try {
        if ($selected_product['product_type'] === 'regulator') {
            // Helper function to create empty regulator transaction data for a day
            if (!function_exists('create_regulator_empty_day')) {
                function create_regulator_empty_day() {
                    return [
                        'good_purchase' => 0,
                        'good_sale' => 0,
                        'good_out_nc' => 0,
                        'good_out_tvi' => 0,
                        'good_out_swap' => 0,
                        'good_plant_in' => 0,
                        'defect_in_tvo' => 0,
                        'defect_in_swap' => 0,
                        'defect_plant_out' => 0,
                        'adjustment' => 0,
                        'remarks' => []
                    ];
                }
            }

            // A. Calculate Good Stock Initial Balance
            // Good Stock In from purchases before date_from
            $pur_prior = $pdo->prepare("
                SELECT COALESCE(SUM(pi.quantity), 0)
                FROM purchase_items pi
                JOIN purchases p ON pi.purchase_id = p.id
                WHERE pi.product_id = ? AND p.purchase_date < ?
            ");
            $pur_prior->execute([$product_id, $date_from]);
            $prior_purchases = intval($pur_prior->fetchColumn());

            // Good Stock In from plant returns before date_from
            $pr_prior = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0)
                FROM plant_replacements
                WHERE product_id = ? AND return_date < ?
            ");
            $pr_prior->execute([$product_id, $date_from]);
            $prior_plant_returns = intval($pr_prior->fetchColumn());

            // Good Stock Out from sales before date_from
            $sal_prior = $pdo->prepare("
                SELECT COALESCE(SUM(si.quantity), 0)
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.id
                WHERE si.product_id = ? AND s.sale_date < ?
            ");
            $sal_prior->execute([$product_id, $date_from]);
            $prior_sales = intval($sal_prior->fetchColumn());

            // Good Stock Out from customer NC/TVI/Swap replacements before date_from
            $cr_prior_good = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0)
                FROM customer_replacements
                WHERE product_id = ? AND replacement_date < ? AND swap_type IN ('replacement', 'new_connection', 'tv_in')
            ");
            $cr_prior_good->execute([$product_id, $date_from]);
            $prior_customer_good_out = intval($cr_prior_good->fetchColumn());

            // Good Stock adjustments before date_from
            $adj_prior = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0)
                FROM stock_adjustments
                WHERE product_id = ? AND DATE(adjusted_at) < ?
            ");
            $adj_prior->execute([$product_id, $date_from]);
            $prior_adjustments = intval($adj_prior->fetchColumn());

            $initial_good_stock = $selected_product['opening_stock'] + $prior_purchases + $prior_plant_returns - $prior_sales - $prior_customer_good_out + $prior_adjustments;

            // B. Calculate Defective Stock Initial Balance (Back-calculated from current defective_stock)
            // Defective Stock In from customer tv_out & swap replacements on/after date_from
            $cr_defect_after = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0)
                FROM customer_replacements
                WHERE product_id = ? AND replacement_date >= ? AND swap_type IN ('replacement', 'tv_out')
            ");
            $cr_defect_after->execute([$product_id, $date_from]);
            $def_in_after = intval($cr_defect_after->fetchColumn());

            // Defective Stock Out from plant returns on/after date_from
            $pr_defect_after = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0)
                FROM plant_replacements
                WHERE product_id = ? AND return_date >= ?
            ");
            $pr_defect_after->execute([$product_id, $date_from]);
            $def_out_after = intval($pr_defect_after->fetchColumn());

            $initial_defective_stock = $selected_product['defective_stock'] - $def_in_after + $def_out_after;

            // C. Fetch Movements within date range
            // Purchases in range
            $pur_range = $pdo->prepare("
                SELECT p.purchase_date as txn_date, pi.quantity, p.invoice_no, s.name as partner_name
                FROM purchase_items pi
                JOIN purchases p ON pi.purchase_id = p.id
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                WHERE pi.product_id = ? AND p.purchase_date BETWEEN ? AND ?
            ");
            $pur_range->execute([$product_id, $date_from, $date_to]);
            $tx_purchases = $pur_range->fetchAll();

            // Sales in range
            $sal_range = $pdo->prepare("
                SELECT s.sale_date as txn_date, si.quantity, s.invoice_no, c.name as partner_name
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.id
                LEFT JOIN customers c ON s.customer_id = c.id
                WHERE si.product_id = ? AND s.sale_date BETWEEN ? AND ?
            ");
            $sal_range->execute([$product_id, $date_from, $date_to]);
            $tx_sales = $sal_range->fetchAll();

            // Customer Swaps & Connection logs in range
            $cr_range = $pdo->prepare("
                SELECT cr.replacement_date as txn_date, cr.quantity, cr.swap_type, cr.consumer_number, cr.customer_name
                FROM customer_replacements cr
                WHERE cr.product_id = ? AND cr.replacement_date BETWEEN ? AND ?
            ");
            $cr_range->execute([$product_id, $date_from, $date_to]);
            $tx_customer = $cr_range->fetchAll();

            // Plant Returns in range
            $pr_range = $pdo->prepare("
                SELECT pr.return_date as txn_date, pr.quantity, s.name as supplier_name
                FROM plant_replacements pr
                LEFT JOIN suppliers s ON pr.supplier_id = s.id
                WHERE pr.product_id = ? AND pr.return_date BETWEEN ? AND ?
            ");
            $pr_range->execute([$product_id, $date_from, $date_to]);
            $tx_plant = $pr_range->fetchAll();

            // Adjustments in range
            $adj_range = $pdo->prepare("
                SELECT DATE(adjusted_at) as txn_date, quantity, reason
                FROM stock_adjustments
                WHERE product_id = ? AND DATE(adjusted_at) BETWEEN ? AND ?
            ");
            $adj_range->execute([$product_id, $date_from, $date_to]);
            $tx_adjustments = $adj_range->fetchAll();

            // Group all by Date
            $grouped = [];
            foreach ($tx_purchases as $t) {
                $date = $t['txn_date'];
                if (!isset($grouped[$date])) $grouped[$date] = create_regulator_empty_day();
                $grouped[$date]['good_purchase'] += $t['quantity'];
                $grouped[$date]['remarks'][] = "Purchased Qty {$t['quantity']} (Inv #{$t['invoice_no']})";
            }
            foreach ($tx_sales as $t) {
                $date = $t['txn_date'];
                if (!isset($grouped[$date])) $grouped[$date] = create_regulator_empty_day();
                $grouped[$date]['good_sale'] += $t['quantity'];
                $grouped[$date]['remarks'][] = "Sold Qty {$t['quantity']} (Inv #{$t['invoice_no']})";
            }
            foreach ($tx_customer as $t) {
                $date = $t['txn_date'];
                if (!isset($grouped[$date])) $grouped[$date] = create_regulator_empty_day();
                
                $c_name = htmlspecialchars($t['customer_name'] ?: 'Retail Swap');
                if ($t['swap_type'] === 'new_connection') {
                    $grouped[$date]['good_out_nc'] += $t['quantity'];
                    $grouped[$date]['remarks'][] = "NC Connection Qty {$t['quantity']} to {$c_name} (Cons: {$t['consumer_number']})";
                } elseif ($t['swap_type'] === 'tv_in') {
                    $grouped[$date]['good_out_tvi'] += $t['quantity'];
                    $grouped[$date]['remarks'][] = "TV In Qty {$t['quantity']} to {$c_name} (Cons: {$t['consumer_number']})";
                } elseif ($t['swap_type'] === 'tv_out') {
                    $grouped[$date]['defect_in_tvo'] += $t['quantity'];
                    $grouped[$date]['remarks'][] = "TV Out Defective returned Qty {$t['quantity']} from {$c_name} (Cons: {$t['consumer_number']})";
                } elseif ($t['swap_type'] === 'replacement') {
                    $grouped[$date]['good_out_swap'] += $t['quantity'];
                    $grouped[$date]['defect_in_swap'] += $t['quantity'];
                    $grouped[$date]['remarks'][] = "Exchanged Swap Qty {$t['quantity']} with {$c_name} (Cons: {$t['consumer_number']})";
                }
            }
            foreach ($tx_plant as $t) {
                $date = $t['txn_date'];
                if (!isset($grouped[$date])) $grouped[$date] = create_regulator_empty_day();
                $grouped[$date]['good_plant_in'] += $t['quantity'];
                $grouped[$date]['defect_plant_out'] += $t['quantity'];
                $grouped[$date]['remarks'][] = "Defective returned to Plant, received Good Qty {$t['quantity']} ({$t['supplier_name']})";
            }
            foreach ($tx_adjustments as $t) {
                $date = $t['txn_date'];
                if (!isset($grouped[$date])) $grouped[$date] = create_regulator_empty_day();
                $grouped[$date]['adjustment'] += $t['quantity'];
                $label = $t['quantity'] > 0 ? "Good Stock ADJ +" : "Good Stock ADJ -";
                $grouped[$date]['remarks'][] = "$label" . abs($t['quantity']) . " ({$t['reason']})";
            }

            // Sort chronologically
            ksort($grouped);

            $running_good = $initial_good_stock;
            $running_def = $initial_defective_stock;

            foreach ($grouped as $date => $data) {
                $open_good = $running_good;
                $open_def = $running_def;

                $good_in = $data['good_purchase'] + $data['good_plant_in'] + ($data['adjustment'] > 0 ? $data['adjustment'] : 0);
                $good_out = $data['good_sale'] + $data['good_out_nc'] + $data['good_out_tvi'] + $data['good_out_swap'] + ($data['adjustment'] < 0 ? abs($data['adjustment']) : 0);
                $close_good = $open_good + $good_in - $good_out;

                $def_in = $data['defect_in_tvo'] + $data['defect_in_swap'];
                $def_out = $data['defect_plant_out'];
                $close_def = $open_def + $def_in - $def_out;

                $ledger_data[] = [
                    'date' => $date,
                    'open_good' => $open_good,
                    'good_purchase' => $data['good_purchase'],
                    'good_plant_in' => $data['good_plant_in'],
                    'good_adjustment' => $data['adjustment'],
                    'good_in' => $good_in,
                    'good_sale' => $data['good_sale'],
                    'good_out_nc' => $data['good_out_nc'],
                    'good_out_tvi' => $data['good_out_tvi'],
                    'good_out_swap' => $data['good_out_swap'],
                    'good_out' => $good_out,
                    'close_good' => $close_good,
                    'open_def' => $open_def,
                    'def_in_tvo' => $data['defect_in_tvo'],
                    'def_in_swap' => $data['defect_in_swap'],
                    'def_in' => $def_in,
                    'def_out' => $def_out,
                    'close_def' => $close_def,
                    'remarks' => implode(' | ', array_unique($data['remarks']))
                ];

                $running_good = $close_good;
                $running_def = $close_def;
            }

        } else {
            // STANDARD PRODUCT LEDGER (Pipe, Stove etc)
            // Purchases before start date
            $pur_stmt = $pdo->prepare("
                SELECT COALESCE(SUM(pi.quantity), 0)
                FROM purchase_items pi
                JOIN purchases p ON pi.purchase_id = p.id
                WHERE pi.product_id = ? AND p.purchase_date < ?
            ");
            $pur_stmt->execute([$product_id, $date_from]);
            $prior_purchases = intval($pur_stmt->fetchColumn());

            // Sales before start date
            $sal_stmt = $pdo->prepare("
                SELECT COALESCE(SUM(si.quantity), 0)
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.id
                WHERE si.product_id = ? AND s.sale_date < ?
            ");
            $sal_stmt->execute([$product_id, $date_from]);
            $prior_sales = intval($sal_stmt->fetchColumn());

            // Adjustments before start date
            $adj_stmt = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0)
                FROM stock_adjustments
                WHERE product_id = ? AND DATE(adjusted_at) < ?
            ");
            $adj_stmt->execute([$product_id, $date_from]);
            $prior_adjustments = intval($adj_stmt->fetchColumn());

            $initial_stock = $selected_product['opening_stock'] + $prior_purchases - $prior_sales + $prior_adjustments;

            // 2. Fetch Movements within date range
            $pur_range = $pdo->prepare("
                SELECT p.purchase_date as txn_date, 'IN' as txn_type, pi.quantity, p.invoice_no, s.name as partner_name
                FROM purchase_items pi
                JOIN purchases p ON pi.purchase_id = p.id
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                WHERE pi.product_id = ? AND p.purchase_date BETWEEN ? AND ?
            ");
            $pur_range->execute([$product_id, $date_from, $date_to]);
            $tx_purchases = $pur_range->fetchAll();

            $sal_range = $pdo->prepare("
                SELECT s.sale_date as txn_date, 'OUT' as txn_type, si.quantity, s.invoice_no, c.name as partner_name
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.id
                LEFT JOIN customers c ON s.customer_id = c.id
                WHERE si.product_id = ? AND s.sale_date BETWEEN ? AND ?
            ");
            $sal_range->execute([$product_id, $date_from, $date_to]);
            $tx_sales = $sal_range->fetchAll();

            $adj_range = $pdo->prepare("
                SELECT DATE(adjusted_at) as txn_date, 'ADJ' as txn_type, quantity, 'ADJUST' as invoice_no, reason as partner_name
                FROM stock_adjustments
                WHERE product_id = ? AND DATE(adjusted_at) BETWEEN ? AND ?
            ");
            $adj_range->execute([$product_id, $date_from, $date_to]);
            $tx_adjustments = $adj_range->fetchAll();

            $all_tx = array_merge($tx_purchases, $tx_sales, $tx_adjustments);
            
            $grouped = [];
            foreach ($all_tx as $tx) {
                $date = $tx['txn_date'];
                if (!isset($grouped[$date])) {
                    $grouped[$date] = [
                        'purchase' => 0,
                        'sale' => 0,
                        'adjustment' => 0,
                        'remarks' => []
                    ];
                }
                if ($tx['txn_type'] === 'IN') {
                    $grouped[$date]['purchase'] += $tx['quantity'];
                    $grouped[$date]['remarks'][] = "Recd Inv #{$tx['invoice_no']} from {$tx['partner_name']}";
                } elseif ($tx['txn_type'] === 'OUT') {
                    $grouped[$date]['sale'] += $tx['quantity'];
                    $grouped[$date]['remarks'][] = "Sold Inv #{$tx['invoice_no']} to {$tx['partner_name']}";
                } elseif ($tx['txn_type'] === 'ADJ') {
                    $grouped[$date]['adjustment'] += $tx['quantity'];
                    $label = ($tx['quantity'] > 0) ? "Correction +" : "Deduction -";
                    $grouped[$date]['remarks'][] = "{$label}" . abs($tx['quantity']) . " ({$tx['partner_name']})";
                }
            }
            
            ksort($grouped);

            $running_stock = $initial_stock;
            foreach ($grouped as $date => $data) {
                $opening = $running_stock;
                $stock_in = $data['purchase'] + ($data['adjustment'] > 0 ? $data['adjustment'] : 0);
                $total = $opening + $stock_in;
                $stock_out = $data['sale'] + ($data['adjustment'] < 0 ? abs($data['adjustment']) : 0);
                $closing = $total - $stock_out;

                $ledger_data[] = [
                    'date' => $date,
                    'opening' => $opening,
                    'purchase' => $stock_in,
                    'total' => $total,
                    'sale' => $stock_out,
                    'closing' => $closing,
                    'remarks' => implode(', ', array_unique($data['remarks']))
                ];

                $running_stock = $closing;
            }
        }
    } catch (PDOException $e) {
        $error_msg = "Failed to calculate ledger data: " . $e->getMessage();
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<!-- Print Style Custom overrides -->
<style>
    @media print {
        .sidebar-wrapper, .top-navbar, .no-print, #sidebar-left-panel {
            display: none !important;
        }
        .main-content-wrapper {
            margin: 0 !important;
            padding: 0 !important;
        }
        #register-right-panel {
            width: 100% !important;
            flex: 0 0 100% !important;
            max-width: 100% !important;
        }
        .card-premium {
            border: none !important;
            box-shadow: none !important;
        }
        .card-premium-body {
            padding: 0 !important;
        }
        .d-print-block {
            display: block !important;
        }
    }
</style>

<div class="row mb-4 no-print">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-book-open me-2 text-success"></i>Stock Register Ledger</h3>
            <p class="text-muted" style="font-size: 0.9rem;">View running stock balance history logs for all products.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Reports Dashboard</a>
        </div>
    </div>
</div>

<div class="row g-4">
    
    <!-- Left Column: Products Selection List (Hidden in print) -->
    <div class="col-12 col-lg-3 no-print" id="sidebar-left-panel">
        <div class="card card-premium">
            <div class="card-premium-header">
                <h5 class="card-premium-title fw-bold"><i class="fa-solid fa-boxes-stacked me-2 text-success"></i>Product Items</h5>
            </div>
            <div class="card-premium-body p-2" style="max-height: 550px; overflow-y: auto;">
                <?php if (empty($products)): ?>
                    <p class="text-muted text-center py-4 mb-0" style="font-size: 0.85rem;">No products found.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($products as $p): 
                            $isActive = ($p['id'] == $product_id);
                            $isLowStock = ($p['current_stock'] <= $p['reorder_level']);
                        ?>
                            <a href="stock_register.php?product_id=<?php echo $p['id']; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" 
                               class="list-group-item list-group-item-action border-0 py-3 px-3 d-flex justify-content-between align-items-center <?php echo $isActive ? 'bg-light text-dark fw-bold border-start border-success border-4' : 'text-secondary'; ?>" 
                               style="border-radius: 8px; margin-bottom: 6px; transition: all 0.2s ease;">
                                <div style="min-width: 0;">
                                    <div class="lh-1 text-truncate" style="font-size: 0.9rem;"><?php echo htmlspecialchars($p['name']); ?></div>
                                    <small class="text-muted" style="font-size: 0.75rem;">SKU: <?php echo htmlspecialchars($p['sku']); ?></small>
                                </div>
                                <?php if ($isLowStock): ?>
                                    <span class="badge bg-danger-light text-danger rounded-pill px-2 py-1 ms-2" style="font-size: 0.65rem;">Low</span>
                                <?php else: ?>
                                    <span class="badge bg-success-light text-success rounded-pill px-2 py-1 ms-2" style="font-size: 0.65rem;">OK</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Ledger Report & Filters -->
    <div class="col-12 col-lg-9" id="register-right-panel">

        <!-- Filters Panel (Hidden in print) -->
        <div class="card card-premium mb-4 no-print">
            <div class="card-premium-header py-3">
                <h5 class="card-premium-title fw-bold" style="font-size: 0.95rem;"><i class="fa-solid fa-filter me-2 text-success"></i>Date Range Filter</h5>
            </div>
            <div class="card-premium-body py-3">
                <form method="GET" action="stock_register.php" class="row g-3 align-items-end">
                    <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                    
                    <div class="col-6 col-md-5">
                        <label for="date_from" class="form-label fw-semibold text-muted mb-1" style="font-size: 0.75rem;">START DATE</label>
                        <input type="date" class="form-control form-control-premium py-1" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>

                    <div class="col-6 col-md-5">
                        <label for="date_to" class="form-label fw-semibold text-muted mb-1" style="font-size: 0.75rem;">END DATE</label>
                        <input type="date" class="form-control form-control-premium py-1" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>

                    <div class="col-12 col-md-2 d-grid">
                        <button type="submit" class="btn btn-accent py-1">Apply Date</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Ledger View Card -->
        <?php if ($product_id > 0 && $selected_product): ?>
            <div class="card card-premium" id="printableTableSection">
                <div class="card-premium-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-premium-title fw-bold" style="font-size: 1.15rem;"><i class="fa-solid fa-file-invoice text-success me-2"></i>Daily Stock Ledger Sheet</h5>
                        <small class="text-muted mt-1 d-block">
                            Product Item: <strong><?php echo htmlspecialchars($selected_product['name']); ?></strong> (SKU: <strong><?php echo htmlspecialchars($selected_product['sku']); ?></strong>)
                        </small>
                    </div>
                    <div class="no-print d-flex gap-2">
                        <button id="toggleProductSidebar" class="btn btn-outline-primary btn-sm px-3" style="border-radius: 8px;">
                            <i class="fa-solid fa-arrows-left-right me-1"></i> Hide Products
                        </button>
                        <button onclick="exportTableToExcel('stockRegisterTable', 'Stock_Register_<?php echo htmlspecialchars($selected_product['sku']); ?>');" class="btn btn-outline-success btn-sm px-3" style="border-radius: 8px;">
                            <i class="fa-solid fa-file-excel me-1"></i> Export Excel
                        </button>
                        <button onclick="window.print();" class="btn btn-outline-secondary btn-sm px-3" style="border-radius: 8px;">
                            <i class="fa-solid fa-print me-1"></i> Print / PDF
                        </button>
                    </div>
                </div>

                <div class="card-premium-body">
                    <!-- Period Summary Meta -->
                    <?php if ($selected_product['product_type'] === 'regulator'): ?>
                        <div class="p-3 bg-light rounded-3 border d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                            <div style="font-size: 0.85rem;">
                                Period: <strong><?php echo date('d-M-Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d-M-Y', strtotime($date_to)); ?></strong>
                            </div>
                            <div style="font-size: 0.85rem;">
                                Opening Balance: <strong class="text-primary"><?php echo number_format($initial_good_stock); ?> Fresh Good</strong> / <strong class="text-warning"><?php echo number_format($initial_defective_stock); ?> Defective</strong>
                            </div>
                            <div style="font-size: 0.85rem;">
                                Closing Balance: <strong class="text-success">
                                    <?php 
                                    $final_good = !empty($ledger_data) ? end($ledger_data)['close_good'] : $initial_good_stock;
                                    echo number_format($final_good);
                                    ?> Fresh Good</strong> / <strong class="text-danger">
                                    <?php 
                                    $final_def = !empty($ledger_data) ? end($ledger_data)['close_def'] : $initial_defective_stock;
                                    echo number_format($final_def);
                                    ?> Defective</strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="p-3 bg-light rounded-3 border d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                            <div style="font-size: 0.88rem;">
                                Period: <strong><?php echo date('d-M-Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d-M-Y', strtotime($date_to)); ?></strong>
                            </div>
                            <div style="font-size: 0.88rem;">
                                Opening Stock: <strong class="text-primary"><?php echo number_format($initial_stock); ?></strong>
                            </div>
                            <div style="font-size: 0.88rem;">
                                Closing Stock: <strong class="text-success">
                                    <?php 
                                    $final_closing = !empty($ledger_data) ? end($ledger_data)['closing'] : $initial_stock;
                                    echo number_format($final_closing);
                                    ?>
                                </strong>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Ledger Table -->
                    <div class="table-responsive">
                        <?php if ($selected_product['product_type'] === 'regulator'): ?>
                            <!-- REGULATOR DUAL BALANCES LEDGER TABLE -->
                            <table class="table table-bordered align-middle text-center" id="stockRegisterTable" style="font-size: 0.78rem;">
                                <thead class="table-light text-uppercase text-muted" style="font-size: 0.65rem; letter-spacing: 0.05em; font-weight: 700; vertical-align: middle;">
                                    <tr>
                                        <th rowspan="2" style="min-width: 80px;">Date</th>
                                        <th colspan="6" class="text-success" style="background-color: #f0fdf4;">A. Fresh Good Stock Ledger</th>
                                        <th colspan="5" class="text-danger" style="background-color: #fef2f2;">B. Defective Stock Ledger</th>
                                        <th rowspan="2" style="min-width: 150px;">Remarks</th>
                                    </tr>
                                    <tr>
                                        <!-- Good sub-headers -->
                                        <th style="background-color: #f0fdf4;">Opening</th>
                                        <th style="background-color: #f0fdf4; color: #15803d;">Good In (Purch/Plant)</th>
                                        <th style="background-color: #f0fdf4; color: #b91c1c;">Out (NC)</th>
                                        <th style="background-color: #f0fdf4; color: #7e22ce;">Out (TVI)</th>
                                        <th style="background-color: #f0fdf4; color: #b45309;">Out (Swap/Sale)</th>
                                        <th style="background-color: #f0fdf4; font-weight: bold;">Closing Good</th>
                                        
                                        <!-- Def sub-headers -->
                                        <th style="background-color: #fef2f2;">Opening</th>
                                        <th style="background-color: #fef2f2; color: #dc2626;">In (TVO)</th>
                                        <th style="background-color: #fef2f2; color: #ea580c;">In (Swap)</th>
                                        <th style="background-color: #fef2f2; color: #0284c7;">Out (Plant)</th>
                                        <th style="background-color: #fef2f2; font-weight: bold;">Closing Defect</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ledger_data)): ?>
                                        <tr>
                                            <td colspan="13" class="text-center py-4 text-muted">
                                                No transaction movements recorded for this regulator during the selected period.
                                            </td>
                                        </tr>
                                        <tr class="table-light fw-bold" style="border-top: 2px solid #cbd5e1;">
                                            <td>Cumulative Balance</td>
                                            <td><?php echo number_format($initial_good_stock); ?></td>
                                            <td class="text-success">0</td>
                                            <td class="text-danger">0</td>
                                            <td style="color: #7e22ce;">0</td>
                                            <td style="color: #b45309;">0</td>
                                            <td><?php echo number_format($initial_good_stock); ?></td>
                                            
                                            <td><?php echo number_format($initial_defective_stock); ?></td>
                                            <td class="text-danger">0</td>
                                            <td style="color: #ea580c;">0</td>
                                            <td class="text-primary">0</td>
                                            <td><?php echo number_format($initial_defective_stock); ?></td>
                                            <td class="text-muted text-start">No movements.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $total_good_in = 0;
                                        $total_nc_out = 0;
                                        $total_tvi_out = 0;
                                        $total_good_out_other = 0;
                                        
                                        $total_tvo_in = 0;
                                        $total_swap_in = 0;
                                        $total_def_plant_out = 0;
                                        
                                        foreach ($ledger_data as $row): 
                                            $good_in_qty = $row['good_purchase'] + $row['good_plant_in'] + ($row['good_adjustment'] > 0 ? $row['good_adjustment'] : 0);
                                            $good_out_other = $row['good_out_swap'] + $row['good_sale'] + ($row['good_adjustment'] < 0 ? abs($row['good_adjustment']) : 0);
                                            
                                            $total_good_in += $good_in_qty;
                                            $total_nc_out += $row['good_out_nc'];
                                            $total_tvi_out += $row['good_out_tvi'];
                                            $total_good_out_other += $good_out_other;
                                            
                                            $total_tvo_in += $row['def_in_tvo'];
                                            $total_swap_in += $row['def_in_swap'];
                                            $total_def_plant_out += $row['def_out'];
                                        ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                                
                                                <!-- Good Stock values -->
                                                <td><?php echo number_format($row['open_good']); ?></td>
                                                <td class="text-success fw-medium"><?php echo ($good_in_qty > 0) ? '+' . number_format($good_in_qty) : '-'; ?></td>
                                                <td class="text-danger fw-medium"><?php echo ($row['good_out_nc'] > 0) ? '-' . number_format($row['good_out_nc']) : '-'; ?></td>
                                                <td style="color: #7e22ce;" class="fw-medium"><?php echo ($row['good_out_tvi'] > 0) ? '-' . number_format($row['good_out_tvi']) : '-'; ?></td>
                                                <td style="color: #b45309;" class="fw-medium"><?php echo ($good_out_other > 0) ? '-' . number_format($good_out_other) : '-'; ?></td>
                                                <td class="fw-bold text-success"><?php echo number_format($row['close_good']); ?></td>
                                                
                                                <!-- Defective Stock values -->
                                                <td><?php echo number_format($row['open_def']); ?></td>
                                                <td class="text-danger fw-medium"><?php echo ($row['def_in_tvo'] > 0) ? '+' . number_format($row['def_in_tvo']) : '-'; ?></td>
                                                <td style="color: #ea580c;" class="fw-medium"><?php echo ($row['def_in_swap'] > 0) ? '+' . number_format($row['def_in_swap']) : '-'; ?></td>
                                                <td class="text-primary fw-medium"><?php echo ($row['def_out'] > 0) ? '-' . number_format($row['def_out']) : '-'; ?></td>
                                                <td class="fw-bold text-danger"><?php echo number_format($row['close_def']); ?></td>
                                                
                                                <td class="text-muted text-start" style="font-size: 0.72rem;"><?php echo htmlspecialchars($row['remarks']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Regulator Summary Cumulative Row -->
                                        <tr class="table-light fw-bold" style="border-top: 2px solid #cbd5e1;">
                                            <td>Cumulative Summary</td>
                                            <td><?php echo number_format($initial_good_stock); ?></td>
                                            <td class="text-success">+<?php echo number_format($total_good_in); ?></td>
                                            <td class="text-danger">-<?php echo number_format($total_nc_out); ?></td>
                                            <td style="color: #7e22ce;">-<?php echo number_format($total_tvi_out); ?></td>
                                            <td style="color: #b45309;">-<?php echo number_format($total_good_out_other); ?></td>
                                            <td class="text-success"><?php echo number_format($final_good); ?></td>
                                            
                                            <td><?php echo number_format($initial_defective_stock); ?></td>
                                            <td class="text-danger">+<?php echo number_format($total_tvo_in); ?></td>
                                            <td style="color: #ea580c;">+<?php echo number_format($total_swap_in); ?></td>
                                            <td class="text-primary">-<?php echo number_format($total_def_plant_out); ?></td>
                                            <td class="text-danger"><?php echo number_format($final_def); ?></td>
                                            <td class="text-secondary text-start" style="font-size: 0.75rem;">Calculated dual-balances.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <!-- STANDARD PRODUCT LEDGER TABLE -->
                            <table class="table table-bordered align-middle" id="stockRegisterTable">
                                <thead class="table-light text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: 700;">
                                    <tr>
                                        <th>Date</th>
                                        <th style="text-align: right;">Opening Stock</th>
                                        <th style="text-align: right; color: #047857;">Stock In (Qty) Purchase</th>
                                        <th style="text-align: right; font-weight: bold;">Total</th>
                                        <th style="text-align: right; color: #b91c1c;">Stock Out (Qty) Sale</th>
                                        <th style="text-align: right; font-weight: bold;">Closing Stock</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ledger_data)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted" style="font-size: 0.9rem;">
                                                No transaction movements recorded for this product during the selected period.
                                            </td>
                                        </tr>
                                        <tr class="table-light fw-bold" style="border-top: 2px solid #cbd5e1;">
                                            <td>Cumulative Period Balance</td>
                                            <td style="text-align: right;"><?php echo number_format($initial_stock); ?></td>
                                            <td style="text-align: right; color: #047857;">0</td>
                                            <td style="text-align: right;"><?php echo number_format($initial_stock); ?></td>
                                            <td style="text-align: right; color: #b91c1c;">0</td>
                                            <td style="text-align: right;"><?php echo number_format($initial_stock); ?></td>
                                            <td class="text-muted" style="font-size: 0.8rem;">No movements.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $total_in = 0;
                                        $total_out = 0;
                                        foreach ($ledger_data as $row): 
                                            $total_in += $row['purchase'];
                                            $total_out += $row['sale'];
                                        ?>
                                            <tr>
                                                <td style="font-size: 0.88rem; font-weight: 500;"><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                                <td style="text-align: right; font-weight: 500; font-size: 0.9rem;"><?php echo number_format($row['opening']); ?></td>
                                                <td style="text-align: right; color: #047857; font-weight: 600; font-size: 0.9rem;">
                                                    <?php echo ($row['purchase'] > 0) ? '+' . number_format($row['purchase']) : '-'; ?>
                                                </td>
                                                <td style="text-align: right; font-weight: 500; font-size: 0.9rem;"><?php echo number_format($row['total']); ?></td>
                                                <td style="text-align: right; color: #b91c1c; font-weight: 600; font-size: 0.9rem;">
                                                    <?php echo ($row['sale'] > 0) ? '-' . number_format($row['sale']) : '-'; ?>
                                                </td>
                                                <td style="text-align: right; font-weight: 700; color: var(--navy-dark); font-size: 0.95rem;"><?php echo number_format($row['closing']); ?></td>
                                                <td>
                                                    <small class="text-muted" style="font-size: 0.78rem;"><?php echo htmlspecialchars($row['remarks'] ?: '-'); ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Summary row -->
                                        <tr class="table-light fw-bold" style="border-top: 2px solid #cbd5e1;">
                                            <td>Cumulative Summary</td>
                                            <td style="text-align: right; font-size: 0.9rem;"><?php echo number_format($initial_stock); ?></td>
                                            <td style="text-align: right; color: #047857; font-size: 0.9rem;">+<?php echo number_format($total_in); ?></td>
                                            <td style="text-align: right; font-size: 0.9rem;"><?php echo number_format($initial_stock + $total_in); ?></td>
                                            <td style="text-align: right; color: #b91c1c; font-size: 0.9rem;">-<?php echo number_format($total_out); ?></td>
                                            <td style="text-align: right; font-size: 0.95rem;"><?php echo number_format($final_closing); ?></td>
                                            <td class="text-secondary" style="font-size: 0.8rem;">Calculated period values.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Selection Prompt -->
            <div class="card card-premium text-center py-5 text-muted">
                <i class="fa-solid fa-box-open fa-4x mb-3 text-secondary"></i>
                <h5>No products registered yet.</h5>
                <p style="font-size: 0.9rem;">Please create a product inside the Products menu first.</p>
            </div>
        <?php endif; ?>
        
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleProductSidebar');
    const leftPanel = document.getElementById('sidebar-left-panel');
    const rightPanel = document.getElementById('register-right-panel');

    if (toggleBtn && leftPanel && rightPanel) {
        // Function to set sidebar state
        function setSidebarState(isCollapsed) {
            if (isCollapsed) {
                leftPanel.classList.add('d-none');
                rightPanel.classList.remove('col-lg-9');
                rightPanel.classList.add('col-lg-12');
                toggleBtn.innerHTML = '<i class="fa-solid fa-arrows-left-right me-1"></i> Show Products';
                localStorage.setItem('product_sidebar_state', 'collapsed');
            } else {
                leftPanel.classList.remove('d-none');
                rightPanel.classList.remove('col-lg-12');
                rightPanel.classList.add('col-lg-9');
                toggleBtn.innerHTML = '<i class="fa-solid fa-arrows-left-right me-1"></i> Hide Products';
                localStorage.setItem('product_sidebar_state', 'expanded');
            }
        }

        // Apply saved state on load
        const savedState = localStorage.getItem('product_sidebar_state');
        if (savedState === 'collapsed') {
            setSidebarState(true);
        } else {
            setSidebarState(false);
        }

        // Toggle click handler
        toggleBtn.addEventListener('click', function() {
            const isCurrentlyCollapsed = leftPanel.classList.contains('d-none');
            setSidebarState(!isCurrentlyCollapsed);
        });
    }
});
</script>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
