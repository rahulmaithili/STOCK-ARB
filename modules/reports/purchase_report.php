<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-t');

try {
    $stmt = $pdo->prepare("
        SELECT p.*, s.name as supplier_name,
            (SELECT GROUP_CONCAT(CONCAT(prod.name, ' (', pi.quantity, ' ', prod.unit, ')') SEPARATOR ', ')
             FROM purchase_items pi
             JOIN products prod ON pi.product_id = prod.id
             WHERE pi.purchase_id = p.id) as items_list
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.purchase_date BETWEEN ? AND ?
        ORDER BY p.purchase_date DESC, p.created_at DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $purchase_report = $stmt->fetchAll();
} catch (PDOException $e) {
    $purchase_report = [];
    $error = "Failed to load purchases database: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4 no-print">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-truck-ramp-box me-2 text-success"></i>Purchase Receipts Report</h3>
            <p class="text-muted" style="font-size: 0.9rem;">View vendor shipment deliveries and cost totals.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Reports Dashboard</a>
        </div>
    </div>
</div>

<!-- Filters Panel (Hidden in print) -->
<div class="card card-premium mb-4 no-print">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-filter me-2 text-success"></i>Filter Purchases Period</h5>
    </div>
    <div class="card-premium-body">
        <form method="GET" action="purchase_report.php" class="row g-3 align-items-end">
            <div class="col-6 col-md-5">
                <label for="date_from" class="form-label fw-semibold text-muted mb-1" style="font-size: 0.8rem;">START DATE</label>
                <input type="date" class="form-control form-control-premium" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>

            <div class="col-6 col-md-5">
                <label for="date_to" class="form-label fw-semibold text-muted mb-1" style="font-size: 0.8rem;">END DATE</label>
                <input type="date" class="form-control form-control-premium" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>

            <div class="col-12 col-md-2 d-grid">
                <button type="submit" class="btn btn-accent bg-success">Filter Receipts</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card card-premium">
    <div class="card-premium-header d-flex justify-content-between align-items-center">
        <h5 class="card-premium-title"><i class="fa-solid fa-file-invoice-dollar me-2 text-success"></i>Purchase Log List</h5>
        <div class="no-print d-flex gap-2">
            <button onclick="exportTableToExcel('purchasesReportTable', 'Purchases_Transactions_Report');" class="btn btn-outline-success btn-sm px-3" style="border-radius: 6px;">
                <i class="fa-solid fa-file-excel me-1"></i> Export Excel
            </button>
            <button onclick="window.print();" class="btn btn-outline-secondary btn-sm px-3" style="border-radius: 6px;">
                <i class="fa-solid fa-print me-1"></i> Print PDF
            </button>
        </div>
    </div>
    
    <div class="card-premium-body">
        <!-- Live summary stats metrics -->
        <?php 
        $total_purchases = count($purchase_report);
        $total_purchases_value = 0;
        foreach ($purchase_report as $p) {
            $total_purchases_value += $p['total_amount'];
        }
        ?>
        <div class="row g-3 mb-4 no-print">
            <div class="col-12 col-sm-6">
                <div class="p-3 bg-light rounded-3 border">
                    <span class="text-muted d-block" style="font-size: 0.8rem; font-weight: 500;">TOTAL DELIVERIES IN PERIOD</span>
                    <strong class="fs-4 text-dark"><?php echo number_format($total_purchases); ?> Deliveries</strong>
                </div>
            </div>
            <div class="col-12 col-sm-6">
                <div class="p-3 bg-light rounded-3 border">
                    <span class="text-muted d-block" style="font-size: 0.8rem; font-weight: 500;">TOTAL COST EXPENDITURE</span>
                    <strong class="fs-4 text-success">₹<?php echo number_format($total_purchases_value, 2); ?></strong>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle table-hover" id="purchasesReportTable">
                <thead class="table-light text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: 700;">
                    <tr>
                        <th style="width: 140px;">Delivery Date</th>
                        <th style="width: 130px;">Bill / Invoice No</th>
                        <th>Supplier Partner</th>
                        <th>Delivery Items Details</th>
                        <th style="text-align: right; width: 140px;">Total Cost (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchase_report)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">No purchase receipts recorded for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchase_report as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo date('d-M-Y', strtotime($row['purchase_date'])); ?></td>
                                <td>
                                    <code class="bg-light text-secondary px-2 py-1 rounded" style="font-size: 0.85rem; font-weight: 500;">
                                        <?php echo htmlspecialchars($row['invoice_no'] ?: 'N/A'); ?>
                                    </code>
                                </td>
                                <td>
                                    <div class="text-dark fw-semibold"><?php echo htmlspecialchars($row['supplier_name'] ?: 'Unknown Vendor'); ?></div>
                                </td>
                                <td>
                                    <span style="font-size: 0.85rem;" class="text-dark fw-medium"><?php echo htmlspecialchars($row['items_list'] ?: '-'); ?></span>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #047857;">₹<?php echo number_format($row['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Summary valuation -->
                        <tr class="table-light fw-bold">
                            <td colspan="4">Cumulative Gross Cost</td>
                            <td style="text-align: right; font-weight: 700; color: #047857;">₹<?php echo number_format($total_purchases_value, 2); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
