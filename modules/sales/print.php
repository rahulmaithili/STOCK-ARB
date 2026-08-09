<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$sale_id = intval($_GET['id'] ?? 0);

if ($sale_id <= 0) {
    die("Invalid invoice parameter.");
}

// Fetch dynamic company settings profile
$company = get_company_profile($pdo);

// Fetch main sale record
try {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, u.name as user_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch();

    if (!$sale) {
        die("Invoice not found.");
    }

    // Fetch invoice line items
    $item_stmt = $pdo->prepare("
        SELECT si.*, prod.name as product_name, prod.sku as product_sku, prod.unit
        FROM sale_items si
        LEFT JOIN products prod ON si.product_id = prod.id
        WHERE si.sale_id = ?
    ");
    $item_stmt->execute([$sale_id]);
    $items = $item_stmt->fetchAll();

} catch (PDOException $e) {
    die("Database query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice — <?php echo htmlspecialchars($sale['invoice_no'] ?: 'INV-' . $sale['id']); ?></title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #fff;
            color: #000;
            font-family: sans-serif;
            font-size: 0.9rem;
            padding: 30px;
        }
        .invoice-title {
            font-size: 2.2rem;
            font-weight: 700;
            color: #1e293b;
        }
        .meta-label {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }
        .table th {
            background-color: #f1f5f9 !important;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem;
            color: #475569;
        }
        .signature-line {
            border-top: 1px solid #94a3b8;
            width: 200px;
            margin-top: 50px;
            text-align: center;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <!-- Print Button (Hidden during print) -->
    <div class="text-end mb-4 no-print">
        <button onclick="window.print();" class="btn btn-primary px-4"><i class="fa-solid fa-print"></i> Print Invoice</button>
        <button onclick="window.close();" class="btn btn-light text-muted border px-4">Close Window</button>
    </div>

    <!-- Invoice Header -->
    <div class="row border-bottom pb-4 mb-4 align-items-center">
        <div class="col-8 d-flex align-items-center gap-3">
            <?php if (!empty($company['logo']) && file_exists(dirname(dirname(__DIR__)) . '/' . $company['logo'])): ?>
                <img src="<?php echo BASE_URL . $company['logo']; ?>" style="max-height: 65px; max-width: 150px; object-fit: contain;" alt="Logo">
            <?php endif; ?>
            <div>
                <h1 class="invoice-title mb-0">INVOICE</h1>
                <div class="fw-bold fs-5 text-secondary text-uppercase"><?php echo htmlspecialchars($company['company_name']); ?></div>
                <small class="text-muted">GSTIN: <?php echo htmlspecialchars($company['gstin'] ?: 'N/A'); ?></small>
            </div>
        </div>
        <div class="col-4 text-end">
            <div class="mb-1"><span class="meta-label">Invoice No:</span> <strong class="text-dark"><?php echo htmlspecialchars($sale['invoice_no'] ?: 'INV-' . $sale['id']); ?></strong></div>
            <div class="mb-1"><span class="meta-label">Billing Date:</span> <strong class="text-dark"><?php echo date('d-M-Y', strtotime($sale['sale_date'])); ?></strong></div>
            <div class="mb-1"><span class="meta-label">Payment Mode:</span> <strong class="text-dark text-uppercase"><?php echo htmlspecialchars($sale['payment_type']); ?></strong></div>
        </div>
    </div>

    <!-- Client Info -->
    <div class="row mb-5">
        <div class="col-6">
            <div class="meta-label mb-2">Billed To Customer:</div>
            <div class="fw-bold fs-5 text-dark"><?php echo htmlspecialchars($sale['customer_name'] ?: 'Retail Cash Client'); ?></div>
            <?php if (!empty($sale['customer_phone'])): ?>
                <div class="text-muted">Phone: <?php echo htmlspecialchars($sale['customer_phone']); ?></div>
            <?php endif; ?>
            <?php if (!empty($sale['customer_address'])): ?>
                <div class="text-muted" style="max-width: 300px;"><?php echo nl2br(htmlspecialchars($sale['customer_address'])); ?></div>
            <?php endif; ?>
        </div>
        <div class="col-6 text-end">
            <div class="meta-label mb-2">Agency Details:</div>
            <div class="fw-bold fs-5 text-dark"><?php echo htmlspecialchars($company['company_name']); ?></div>
            <?php if (!empty($company['address'])): ?>
                <div class="text-muted"><?php echo nl2br(htmlspecialchars($company['address'])); ?></div>
            <?php endif; ?>
            <?php if (!empty($company['phone'])): ?>
                <div class="text-muted">Phone: <?php echo htmlspecialchars($company['phone']); ?></div>
            <?php endif; ?>
            <?php if (!empty($company['email'])): ?>
                <div class="text-muted">Email: <?php echo htmlspecialchars($company['email']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Items Table -->
    <table class="table table-bordered mb-4">
        <thead>
            <tr>
                <th>Product Description</th>
                <th style="text-align: center; width: 80px;">Unit</th>
                <th style="text-align: right; width: 120px;">Rate (₹)</th>
                <th style="text-align: center; width: 100px;">Quantity</th>
                <th style="text-align: right; width: 150px;">Total Price (₹)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($item['product_name']); ?></div>
                        <small class="text-muted">SKU: <?php echo htmlspecialchars($item['product_sku']); ?></small>
                    </td>
                    <td style="text-align: center;"><?php echo htmlspecialchars($item['unit'] ?: 'pcs'); ?></td>
                    <td style="text-align: right;">₹<?php echo number_format($item['rate'], 2); ?></td>
                    <td style="text-align: center;"><?php echo number_format($item['quantity']); ?></td>
                    <td style="text-align: right; font-weight: 600;">₹<?php echo number_format($item['amount'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Billing Summary breakup -->
    <div class="row justify-content-end mb-5">
        <div class="col-5 text-end">
            <div class="row mb-1">
                <div class="col-6 text-muted font-semibold">Subtotal:</div>
                <div class="col-6 text-dark fw-bold">₹<?php echo number_format($sale['total_amount'] + $sale['discount'], 2); ?></div>
            </div>
            <div class="row mb-1">
                <div class="col-6 text-muted font-semibold">Discount Applied:</div>
                <div class="col-6 text-dark fw-bold">-₹<?php echo number_format($sale['discount'], 2); ?></div>
            </div>
            <div class="row mb-1">
                <div class="col-6 text-muted font-semibold">Net Total Payable:</div>
                <div class="col-6 text-dark fw-bold">₹<?php echo number_format($sale['total_amount'], 2); ?></div>
            </div>
            <div class="row mb-1 text-success border-top pt-2">
                <div class="col-6 font-semibold">Cash Amount Paid:</div>
                <div class="col-6 fw-bold">₹<?php echo number_format($sale['cash_paid'], 2); ?></div>
            </div>
            <div class="row mb-1 text-primary">
                <div class="col-6 font-semibold">Online Amount Paid:</div>
                <div class="col-6 fw-bold">₹<?php echo number_format($sale['online_paid'], 2); ?></div>
            </div>
            
            <?php 
            $due = $sale['total_amount'] - ($sale['cash_paid'] + $sale['online_paid']);
            ?>
            <div class="row text-danger mt-2 border-top pt-2">
                <div class="col-6 fw-bold">Balance Due:</div>
                <div class="col-6 fw-bold fs-5">₹<?php echo number_format($due, 2); ?></div>
            </div>
        </div>
    </div>

    <!-- Footer Notes -->
    <div class="row mt-5">
        <div class="col-6 mt-5">
            <small class="text-muted d-block">Thank you for your business!</small>
            <small class="text-muted">Goods once sold cannot be returned or exchanged.</small>
        </div>
        <div class="col-6 d-flex flex-column align-items-end mt-5">
            <div class="signature-line">Authorized Signatory</div>
        </div>
    </div>

    <!-- Auto Print Script -->
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    </script>
</body>
</html>
