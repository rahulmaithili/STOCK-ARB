<?php
require_once __DIR__ . '/db.php';

// Fetch all products for dropdown
try {
    $stmt = $pdo->query("SELECT id, name, sku FROM products ORDER BY name ASC");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

// Get filter inputs
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-t');

$ledger_data = [];
$initial_stock = 0;
$selected_product = null;

if ($product_id > 0) {
    // Fetch product details
    foreach ($products as $p) {
        if ($p['id'] == $product_id) {
            $selected_product = $p;
            break;
        }
    }

    try {
        // 1. Calculate Initial Stock before the 'date_from'
        $stmt_initial = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'IN' THEN quantity ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = 'OUT' THEN quantity ELSE 0 END), 0) as starting_stock
            FROM stock_transactions 
            WHERE product_id = ? AND transaction_date < ?
        ");
        $stmt_initial->execute([$product_id, $date_from]);
        $initial_stock = intval($stmt_initial->fetchColumn());

        // 2. Fetch all transactions inside the range
        $stmt_tx = $pdo->prepare("
            SELECT transaction_date, type, quantity, remarks 
            FROM stock_transactions 
            WHERE product_id = ? AND transaction_date BETWEEN ? AND ? 
            ORDER BY transaction_date ASC, created_at ASC
        ");
        $stmt_tx->execute([$product_id, $date_from, $date_to]);
        $raw_tx = $stmt_tx->fetchAll();

        // 3. Group transactions by Date
        $grouped = [];
        foreach ($raw_tx as $tx) {
            $date = $tx['transaction_date'];
            if (!isset($grouped[$date])) {
                $grouped[$date] = [
                    'purchase' => 0,
                    'sale' => 0,
                    'remarks' => []
                ];
            }
            if ($tx['type'] == 'IN') {
                $grouped[$date]['purchase'] += $tx['quantity'];
            } else {
                $grouped[$date]['sale'] += $tx['quantity'];
            }
            if (!empty($tx['remarks'])) {
                $grouped[$date]['remarks'][] = $tx['remarks'];
            }
        }

        // 4. Calculate running balance day-by-day
        $running_stock = $initial_stock;
        
        // Let's sort the grouped array by date just in case
        ksort($grouped);

        foreach ($grouped as $date => $data) {
            $opening = $running_stock;
            $purchase = $data['purchase'];
            $total = $opening + $purchase;
            $sale = $data['sale'];
            $closing = $total - $sale;

            // Save records
            $ledger_data[] = [
                'date' => $date,
                'opening' => $opening,
                'purchase' => $purchase,
                'total' => $total,
                'sale' => $sale,
                'closing' => $closing,
                'remarks' => implode('; ', array_unique($data['remarks']))
            ];

            // Next day opening is today's closing
            $running_stock = $closing;
        }

    } catch (PDOException $e) {
        $error_message = "Error generating report: " . htmlspecialchars($e->getMessage());
    }
}

require_once __DIR__ . '/header.php';
?>

<!-- Filter & Selection Section -->
<div class="card no-print">
    <div class="card-header">
        <h2 class="card-title">🔍 Filter Stock Register Report</h2>
    </div>
    <div class="card-body">
        <form method="GET" action="register.php" class="register-controls">
            <div class="form-group" style="flex: 1; min-width: 250px;">
                <label for="product_id">Choose Product *</label>
                <select id="product_id" name="product_id" required>
                    <option value="">-- Choose Product --</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo ($product_id == $p['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name']); ?> (SKU: <?php echo htmlspecialchars($p['sku']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="min-width: 150px;">
                <label for="date_from">Date From</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>

            <div class="form-group" style="min-width: 150px;">
                <label for="date_to">Date To</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>

            <div>
                <button type="submit" class="btn btn-primary">Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($error_message)): ?>
    <div class="alert alert-danger no-print">
        <span><?php echo $error_message; ?></span>
    </div>
<?php endif; ?>

<!-- Report Results -->
<?php if ($product_id > 0 && $selected_product): ?>
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 class="card-title" style="font-size: 1.3rem;">📋 Stock Ledger Register</h2>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                    Product: <strong><?php echo htmlspecialchars($selected_product['name']); ?></strong> (SKU: <?php echo htmlspecialchars($selected_product['sku']); ?>)
                </p>
            </div>
            <div class="no-print">
                <button onclick="window.print();" class="btn btn-secondary">🖨️ Print / Save PDF</button>
            </div>
        </div>
        
        <div class="card-body">
            <!-- Summary Info -->
            <div class="register-meta">
                <div class="meta-item">
                    Period: <strong><?php echo date('d-M-Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d-M-Y', strtotime($date_to)); ?></strong>
                </div>
                <div class="meta-item">
                    Opening Stock on Start: <strong><?php echo number_format($initial_stock); ?></strong>
                </div>
                <div class="meta-item">
                    Current Closing Stock: <strong>
                        <?php 
                        $final_closing = !empty($ledger_data) ? end($ledger_data)['closing'] : $initial_stock;
                        echo number_format($final_closing);
                        ?>
                    </strong>
                </div>
            </div>

            <!-- Ledger Table -->
            <div class="table-responsive" style="margin-top: 24px;">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th style="text-align: right;">Opening Stock</th>
                            <th style="text-align: right; color: var(--success-dark);">Stock In (Qty) Purchase</th>
                            <th style="text-align: right; font-weight: bold;">Total</th>
                            <th style="text-align: right; color: var(--danger-dark);">Stock Out (Qty) Sale</th>
                            <th style="text-align: right; font-weight: bold;">Closing Stock</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Initial Stock row if ledger is empty but we have initial stock -->
                        <?php if (empty($ledger_data)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    No stock movements recorded in this period.
                                </td>
                            </tr>
                            <tr class="row-total-summary">
                                <td>Cumulative Summary</td>
                                <td style="text-align: right;"><?php echo number_format($initial_stock); ?></td>
                                <td style="text-align: right; color: var(--success-dark);">0</td>
                                <td style="text-align: right;"><?php echo number_format($initial_stock); ?></td>
                                <td style="text-align: right; color: var(--danger-dark);">0</td>
                                <td style="text-align: right;"><?php echo number_format($initial_stock); ?></td>
                                <td>No active entries in range.</td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $total_purchase = 0;
                            $total_sale = 0;
                            foreach ($ledger_data as $row): 
                                $total_purchase += $row['purchase'];
                                $total_sale += $row['sale'];
                            ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                    <td style="text-align: right; font-weight: 500;"><?php echo number_format($row['opening']); ?></td>
                                    <td style="text-align: right; color: var(--success-dark); font-weight: 500;">
                                        <?php echo ($row['purchase'] > 0) ? '+' . number_format($row['purchase']) : '-'; ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 500;"><?php echo number_format($row['total']); ?></td>
                                    <td style="text-align: right; color: var(--danger-dark); font-weight: 500;">
                                        <?php echo ($row['sale'] > 0) ? '-' . number_format($row['sale']) : '-'; ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['closing']); ?></td>
                                    <td style="font-size: 0.85rem; max-width: 250px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" title="<?php echo htmlspecialchars($row['remarks']); ?>">
                                        <?php echo htmlspecialchars($row['remarks'] ?: '-'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- Total calculations summary row -->
                            <tr class="row-total-summary">
                                <td>Cumulative Total</td>
                                <td style="text-align: right;"><?php echo number_format($initial_stock); ?></td>
                                <td style="text-align: right; color: var(--success-dark);">+<?php echo number_format($total_purchase); ?></td>
                                <td style="text-align: right;"><?php echo number_format($initial_stock + $total_purchase); ?></td>
                                <td style="text-align: right; color: var(--danger-dark);">-<?php echo number_format($total_sale); ?></td>
                                <td style="text-align: right;"><?php echo number_format($final_closing); ?></td>
                                <td>Total period movements calculated.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Prompt to choose product -->
    <div class="card no-print">
        <div class="card-body" style="text-align: center; padding: 50px 20px; color: var(--text-muted);">
            <div style="font-size: 3rem; margin-bottom: 16px;">📈</div>
            <h3>Please choose a product and select date range to display the daily stock ledger register.</h3>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
