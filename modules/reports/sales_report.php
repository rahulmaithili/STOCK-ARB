<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-t');

try {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as customer_name,
            (SELECT GROUP_CONCAT(CONCAT(prod.name, ' (', si.quantity, ' ', prod.unit, ')') SEPARATOR ', ')
             FROM sale_items si
             JOIN products prod ON si.product_id = prod.id
             WHERE si.sale_id = s.id) as items_list
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.sale_date BETWEEN ? AND ?
        ORDER BY s.sale_date DESC, s.created_at DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $sales_report = $stmt->fetchAll();
} catch (PDOException $e) {
    $sales_report = [];
    $error = "Failed to load sales database: " . $e->getMessage();
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4 no-print">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-file-invoice-dollar me-2 text-danger"></i>Sales Billings Report</h3>
            <p class="text-muted" style="font-size: 0.9rem;">View customer invoice metrics and revenue totals.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Reports Dashboard</a>
        </div>
    </div>
</div>

<!-- Filters Panel (Hidden in print) -->
<div class="card card-premium mb-4 no-print">
    <div class="card-premium-header">
        <h5 class="card-premium-title"><i class="fa-solid fa-filter me-2 text-danger"></i>Filter Sales Period</h5>
    </div>
    <div class="card-premium-body">
        <form method="GET" action="sales_report.php" class="row g-3 align-items-end">
            <div class="col-6 col-md-5">
                <label for="date_from" class="form-label fw-semibold text-muted mb-1" style="font-size: 0.8rem;">START DATE</label>
                <input type="date" class="form-control form-control-premium" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>

            <div class="col-6 col-md-5">
                <label for="date_to" class="form-label fw-semibold text-muted mb-1" style="font-size: 0.8rem;">END DATE</label>
                <input type="date" class="form-control form-control-premium" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>

            <div class="col-12 col-md-2 d-grid">
                <button type="submit" class="btn btn-accent bg-danger">Filter Sales</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card card-premium">
    <div class="card-premium-header d-flex justify-content-between align-items-center">
        <h5 class="card-premium-title"><i class="fa-solid fa-receipt me-2 text-danger"></i>Sales Invoice List</h5>
        <div class="no-print d-flex gap-2">
            <button onclick="exportTableToExcel('salesReportTable', 'Sales_Transactions_Report');" class="btn btn-outline-success btn-sm px-3" style="border-radius: 6px;">
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
        $total_invoices = count($sales_report);
        $total_sales_value = 0;
        foreach ($sales_report as $s) {
            $total_sales_value += $s['total_amount'];
        }
        ?>
        <div class="row g-3 mb-4 no-print">
            <div class="col-12 col-sm-6">
                <div class="p-3 bg-light rounded-3 border">
                    <span class="text-muted d-block" style="font-size: 0.8rem; font-weight: 500;">TOTAL INVOICES IN PERIOD</span>
                    <strong class="fs-4 text-dark"><?php echo number_format($total_invoices); ?> Invoices</strong>
                </div>
            </div>
            <div class="col-12 col-sm-6">
                <div class="p-3 bg-light rounded-3 border">
                    <span class="text-muted d-block" style="font-size: 0.8rem; font-weight: 500;">TOTAL REVENUE GENERATED</span>
                    <strong class="fs-4 text-danger">₹<?php echo number_format($total_sales_value, 2); ?></strong>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle table-hover" id="salesReportTable">
                <thead class="table-light text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: 700;">
                    <tr>
                        <th style="width: 140px;">Invoice Date</th>
                        <th style="width: 130px;">Invoice No</th>
                        <th>Customer Partner</th>
                        <th>Invoice Items Details</th>
                        <th style="width: 110px;">Payment Mode</th>
                        <th style="text-align: right; width: 140px;">Total Bill (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales_report)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No sales invoices recorded for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales_report as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo date('d-M-Y', strtotime($row['sale_date'])); ?></td>
                                <td>
                                    <code class="bg-light text-secondary px-2 py-1 rounded" style="font-size: 0.85rem; font-weight: 500;">
                                        <?php echo htmlspecialchars($row['invoice_no'] ?: 'N/A'); ?>
                                    </code>
                                </td>
                                <td>
                                    <div class="text-dark fw-semibold"><?php echo htmlspecialchars($row['customer_name'] ?: 'Retail Client'); ?></div>
                                </td>
                                <td>
                                    <span style="font-size: 0.85rem;" class="text-dark fw-medium"><?php echo htmlspecialchars($row['items_list'] ?: '-'); ?></span>
                                </td>
                                <td class="text-uppercase" style="font-size: 0.85rem;">
                                    <?php if ($row['payment_type'] === 'cash'): ?>
                                        <span class="badge bg-success-light text-success px-2 py-1">CASH</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-light text-warning px-2 py-1" style="color: #b45309;">CREDIT</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #b91c1c;">₹<?php echo number_format($row['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Summary valuation -->
                        <tr class="table-light fw-bold">
                            <td colspan="5">Cumulative Gross Billings</td>
                            <td style="text-align: right; font-weight: 700; color: #b91c1c;">₹<?php echo number_format($total_sales_value, 2); ?></td>
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
