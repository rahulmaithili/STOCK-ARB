<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$error = '';

// Fetch active products and suppliers
try {
    $prod_stmt = $pdo->query("SELECT id, name, sku, purchase_price FROM products ORDER BY name ASC");
    $products = $prod_stmt->fetchAll();

    $sup_stmt = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC");
    $suppliers = $sup_stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
    $suppliers = [];
    $error = "Failed to load master records: " . $e->getMessage();
}

// Process Purchase POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $invoice_no = trim($_POST['invoice_no'] ?? '');
        $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
        
        $item_products = $_POST['prod_id'] ?? [];
        $item_quantities = $_POST['qty'] ?? [];
        $item_rates = $_POST['rate'] ?? [];

        if ($supplier_id <= 0 || empty($purchase_date) || empty($item_products)) {
            $error = "Please choose a supplier, date, and add at least one line item.";
        } else {
            // Run transaction database operations
            try {
                $pdo->beginTransaction();

                // 1. Calculate total amount
                $total_amount = 0;
                $lines = [];
                for ($i = 0; $i < count($item_products); $i++) {
                    $pid = intval($item_products[$i]);
                    $qty = intval($item_quantities[$i]);
                    $rate = floatval($item_rates[$i]);
                    if ($pid > 0 && $qty > 0 && $rate >= 0) {
                        $amt = $qty * $rate;
                        $total_amount += $amt;
                        $lines[] = [
                            'product_id' => $pid,
                            'quantity' => $qty,
                            'rate' => $rate,
                            'amount' => $amt
                        ];
                    }
                }

                if (empty($lines)) {
                    throw new Exception("Invoice contains no valid line items.");
                }

                // 2. Insert main purchase log
                $ins_stmt = $pdo->prepare("
                    INSERT INTO purchases (supplier_id, invoice_no, purchase_date, total_amount, created_by) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ins_stmt->execute([
                    $supplier_id,
                    $invoice_no,
                    $purchase_date,
                    $total_amount,
                    $_SESSION['user_id']
                ]);
                $purchase_id = $pdo->lastInsertId();

                // 3. Insert items and update stock quantities
                $ins_item = $pdo->prepare("
                    INSERT INTO purchase_items (purchase_id, product_id, quantity, rate, amount) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $upd_stock = $pdo->prepare("
                    UPDATE products 
                    SET current_stock = current_stock + ? 
                    WHERE id = ?
                ");

                foreach ($lines as $ln) {
                    $ins_item->execute([
                        $purchase_id,
                        $ln['product_id'],
                        $ln['quantity'],
                        $ln['rate'],
                        $ln['amount']
                    ]);

                    $upd_stock->execute([
                        $ln['quantity'],
                        $ln['product_id']
                    ]);
                }

                // 4. Activity logging and commit
                log_activity($pdo, "Recorded purchase receipt (ID $purchase_id) from supplier ID $supplier_id. Total: ₹" . number_format($total_amount, 2));
                
                $pdo->commit();
                
                set_flash_message('success', "Stock In purchase transaction successfully saved.");
                header("Location: index.php");
                exit();

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Transaction failed: " . $e->getMessage();
            }
        }
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-circle-down me-2 text-success"></i>Record Purchase Receipt</h3>
            <p class="text-muted" style="font-size: 0.9rem;">Increase inventory counts by entering supplier billing bills.</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Cancel & Return</a>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form action="add.php" method="POST" id="purchaseForm">
    <?php echo csrf_input(); ?>

    <div class="row g-4">
        <!-- Invoice Details Panel -->
        <div class="col-12 col-lg-4">
            <div class="card card-premium shadow-sm">
                <div class="card-premium-header">
                    <h5 class="card-premium-title"><i class="fa-solid fa-file-invoice me-2 text-success"></i>Invoice Information</h5>
                </div>
                <div class="card-premium-body">
                    <div class="mb-3">
                        <label for="supplier_id" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">SUPPLIER / VENDOR *</label>
                        <select class="form-select form-control-premium" id="supplier_id" name="supplier_id" required>
                            <option value="">-- Choose Supplier --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="invoice_no" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">INVOICE / BILL NO</label>
                        <input type="text" class="form-control form-control-premium" id="invoice_no" name="invoice_no" placeholder="e.g. BILL-9872" value="<?php echo isset($_POST['invoice_no']) ? htmlspecialchars($_POST['invoice_no']) : ''; ?>">
                    </div>

                    <div class="mb-3">
                        <label for="purchase_date" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">BILLING DATE *</label>
                        <input type="date" class="form-control form-control-premium" id="purchase_date" name="purchase_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items Panel -->
        <div class="col-12 col-lg-8">
            <div class="card card-premium shadow-sm">
                <div class="card-premium-header d-flex justify-content-between align-items-center">
                    <h5 class="card-premium-title"><i class="fa-solid fa-list-check me-2 text-success"></i>Invoice Items</h5>
                    <button type="button" class="btn btn-outline-success btn-sm" id="add-item-row-btn"><i class="fa-solid fa-plus me-1"></i>Add Item Row</button>
                </div>
                <div class="card-premium-body">
                    <div class="table-responsive">
                        <table class="table align-middle" id="itemsTable">
                            <thead>
                                <tr>
                                    <th>Product Description</th>
                                    <th style="width: 100px;">Qty</th>
                                    <th style="width: 130px;">Rate (₹)</th>
                                    <th style="width: 130px;">Amount (₹)</th>
                                    <th style="width: 50px; text-align: center;"></th>
                                </tr>
                            </thead>
                            <tbody id="line-items-tbody">
                                <!-- Row Template will be inserted dynamically by JS -->
                            </tbody>
                        </table>
                    </div>

                    <div class="border-top pt-3 d-flex justify-content-end align-items-center">
                        <div class="text-end">
                            <span class="text-muted fw-semibold d-block" style="font-size: 0.8rem;">TOTAL AMOUNT (TAX INCL.)</span>
                            <span class="fs-4 fw-bold text-success" id="grandTotalDisplay">₹0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <hr class="my-4" style="color: var(--border-color);">

    <div class="d-flex gap-2 mb-5">
        <button type="submit" class="btn btn-accent px-4 py-2">Save Receipt</button>
        <a href="index.php" class="btn btn-light text-muted px-4 py-2">Cancel</a>
    </div>
</form>

<!-- Row template template script for JavaScript -->
<script>
    const productsList = <?php echo json_encode($products); ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('line-items-tbody');
    const addButton = document.getElementById('add-item-row-btn');
    const grandTotalDisplay = document.getElementById('grandTotalDisplay');

    // Add first row automatically
    addRow();

    // Event Listeners
    addButton.addEventListener('click', addRow);
    tbody.addEventListener('click', removeRow);
    tbody.addEventListener('change', handleProductChange);
    tbody.addEventListener('input', calculateAmounts);

    function addRow() {
        const rowId = Date.now();
        const tr = document.createElement('tr');
        tr.id = 'row-' + rowId;
        
        let productOptions = '<option value="">-- Choose --</option>';
        productsList.forEach(p => {
            productOptions += `<option value="${p.id}" data-price="${p.purchase_price}">${p.name} (SKU: ${p.sku})</option>`;
        });

        tr.innerHTML = `
            <td>
                <select class="form-select form-control-premium prod-select" name="prod_id[]" required>
                    ${productOptions}
                </select>
            </td>
            <td>
                <input type="number" min="1" class="form-control form-control-premium qty-input" name="qty[]" required placeholder="0">
            </td>
            <td>
                <input type="number" step="0.01" min="0" class="form-control form-control-premium rate-input" name="rate[]" required placeholder="0.00">
            </td>
            <td>
                <input type="text" class="form-control form-control-premium bg-light amount-input" readonly placeholder="0.00">
            </td>
            <td style="text-align: center;">
                <button type="button" class="btn btn-outline-danger btn-sm delete-row-btn" style="border-radius: 6px;"><i class="fa-solid fa-xmark"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
    }

    function removeRow(e) {
        if (e.target.closest('.delete-row-btn')) {
            const row = e.target.closest('tr');
            if (tbody.children.length > 1) {
                row.remove();
                calculateAmounts();
            } else {
                alert("At least one line item is required.");
            }
        }
    }

    function handleProductChange(e) {
        if (e.target.classList.contains('prod-select')) {
            const select = e.target;
            const selectedOption = select.options[select.selectedIndex];
            const price = selectedOption.getAttribute('data-price') || 0;
            const tr = select.closest('tr');
            const rateInput = tr.querySelector('.rate-input');
            
            rateInput.value = parseFloat(price).toFixed(2);
            calculateAmounts();
        }
    }

    function calculateAmounts() {
        let grandTotal = 0;
        const rows = tbody.querySelectorAll('tr');
        
        rows.forEach(tr => {
            const qty = parseInt(tr.querySelector('.qty-input').value) || 0;
            const rate = parseFloat(tr.querySelector('.rate-input').value) || 0;
            const amountInput = tr.querySelector('.amount-input');
            
            const total = qty * rate;
            amountInput.value = total.toFixed(2);
            grandTotal += total;
        });

        grandTotalDisplay.textContent = '₹' + grandTotal.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
});
</script>
