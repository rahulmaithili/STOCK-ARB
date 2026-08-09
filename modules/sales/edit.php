<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$error = '';
$sale_id = intval($_GET['id'] ?? 0);

if ($sale_id <= 0) {
    set_flash_message('danger', 'Invalid invoice parameter.');
    header("Location: index.php");
    exit();
}

// Fetch invoice and details
try {
    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $stmt->execute([$sale_id]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        set_flash_message('danger', 'Invoice not found.');
        header("Location: index.php");
        exit();
    }

    // Fetch invoice line items
    $items_stmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $items_stmt->execute([$sale_id]);
    $invoice_items = $items_stmt->fetchAll();

} catch (PDOException $e) {
    set_flash_message('danger', 'Database error: ' . $e->getMessage());
    header("Location: index.php");
    exit();
}

// Fetch active products and customers
try {
    $prod_stmt = $pdo->query("SELECT id, name, sku, selling_price, current_stock FROM products ORDER BY name ASC");
    $products = $prod_stmt->fetchAll();

    $cust_stmt = $pdo->query("SELECT id, name FROM customers ORDER BY name ASC");
    $customers = $cust_stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
    $customers = [];
    $error = "Failed to load master records: " . $e->getMessage();
}

// Process Sales EDIT Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $invoice_no = trim($_POST['invoice_no'] ?? '');
        $sale_date = trim($_POST['sale_date'] ?? date('Y-m-d'));
        $payment_type = trim($_POST['payment_type'] ?? 'cash');
        
        $discount = floatval($_POST['discount'] ?? 0);
        $cash_paid = floatval($_POST['cash_paid'] ?? 0);
        $online_paid = floatval($_POST['online_paid'] ?? 0);

        $item_products = $_POST['prod_id'] ?? [];
        $item_quantities = $_POST['qty'] ?? [];
        $item_rates = $_POST['rate'] ?? [];

        if ($customer_id <= 0 || empty($sale_date) || empty($item_products)) {
            $error = "Please choose a customer, date, and add at least one line item.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Revert OLD stock quantities first (add back to current_stock)
                $revert_stmt = $pdo->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
                foreach ($invoice_items as $old_item) {
                    $revert_stmt->execute([$old_item['quantity'], $old_item['product_id']]);
                }

                // 2. Validate new stock levels (after temp reversion) and compute totals
                $total_amount = 0;
                $lines = [];
                for ($i = 0; $i < count($item_products); $i++) {
                    $pid = intval($item_products[$i]);
                    $qty = intval($item_quantities[$i]);
                    $rate = floatval($item_rates[$i]);
                    if ($pid > 0 && $qty > 0 && $rate >= 0) {
                        // Check stock level in database
                        $stock_stmt = $pdo->prepare("SELECT name, current_stock FROM products WHERE id = ? FOR UPDATE");
                        $stock_stmt->execute([$pid]);
                        $product_db = $stock_stmt->fetch();

                        if (!$product_db) {
                            throw new Exception("Product ID $pid not found.");
                        }

                        if ($qty > $product_db['current_stock']) {
                            throw new Exception("Insufficient stock for product '{$product_db['name']}'. In Hand: {$product_db['current_stock']}, Requested: $qty");
                        }

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

                // 3. Delete old sale items
                $del_items_stmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?");
                $del_items_stmt->execute([$sale_id]);

                // 4. Insert new items and deduct stock quantities
                $ins_item = $pdo->prepare("
                    INSERT INTO sale_items (sale_id, product_id, quantity, rate, amount) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $deduct_stock = $pdo->prepare("
                    UPDATE products 
                    SET current_stock = current_stock - ? 
                    WHERE id = ?
                ");

                foreach ($lines as $ln) {
                    $ins_item->execute([
                        $sale_id,
                        $ln['product_id'],
                        $ln['quantity'],
                        $ln['rate'],
                        $ln['amount']
                    ]);

                    $deduct_stock->execute([
                        $ln['quantity'],
                        $ln['product_id']
                    ]);
                }

                // 5. Update main invoice log details
                $net_payable = $total_amount - $discount;
                $upd_sale_stmt = $pdo->prepare("
                    UPDATE sales SET 
                        customer_id = ?, 
                        invoice_no = ?, 
                        sale_date = ?, 
                        payment_type = ?, 
                        discount = ?, 
                        cash_paid = ?, 
                        online_paid = ?, 
                        total_amount = ? 
                    WHERE id = ?
                ");
                $upd_sale_stmt->execute([
                    $customer_id,
                    $invoice_no,
                    $sale_date,
                    $payment_type,
                    $discount,
                    $cash_paid,
                    $online_paid,
                    $net_payable,
                    $sale_id
                ]);

                // 6. Log activity and commit
                log_activity($pdo, "Updated invoice #{$invoice_no} (ID {$sale_id}) for customer ID {$customer_id}. Net Total: ₹" . number_format($net_payable, 2));

                $pdo->commit();

                set_flash_message('success', "Invoice updated and stock adjusted successfully.");
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
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-pen-to-square me-2 text-danger"></i>Edit Sales Invoice</h3>
            <p class="text-muted" style="font-size: 0.9rem;">Modify billing items and payment breakups for invoice: <strong><?php echo htmlspecialchars($invoice['invoice_no']); ?></strong></p>
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

<form action="edit.php?id=<?php echo $sale_id; ?>" method="POST" id="salesForm">
    <?php echo csrf_input(); ?>

    <div class="row g-4">
        <!-- Invoice Details Panel -->
        <div class="col-12 col-lg-4">
            <div class="card card-premium shadow-sm">
                <div class="card-premium-header">
                    <h5 class="card-premium-title"><i class="fa-solid fa-file-invoice me-2 text-danger"></i>Invoice Information</h5>
                </div>
                <div class="card-premium-body">
                    <div class="mb-3">
                        <label for="customer_id" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CUSTOMER *</label>
                        <select class="form-select form-control-premium" id="customer_id" name="customer_id" required>
                            <option value="">-- Choose Customer --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($invoice['customer_id'] == $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="invoice_no" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">INVOICE / BILL NO</label>
                        <input type="text" class="form-control form-control-premium" id="invoice_no" name="invoice_no" placeholder="INV-1002" value="<?php echo htmlspecialchars($invoice['invoice_no']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="sale_date" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">BILLING DATE *</label>
                        <input type="date" class="form-control form-control-premium" id="sale_date" name="sale_date" required value="<?php echo htmlspecialchars($invoice['sale_date']); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="payment_type" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">PAYMENT MODE *</label>
                        <select class="form-select form-control-premium" id="payment_type" name="payment_type" required>
                            <option value="cash" <?php echo ($invoice['payment_type'] === 'cash') ? 'selected' : ''; ?>>Cash Payment</option>
                            <option value="credit" <?php echo ($invoice['payment_type'] === 'credit') ? 'selected' : ''; ?>>On Credit</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items Panel -->
        <div class="col-12 col-lg-8">
            <div class="card card-premium shadow-sm">
                <div class="card-premium-header d-flex justify-content-between align-items-center">
                    <h5 class="card-premium-title"><i class="fa-solid fa-list-check me-2 text-danger"></i>Invoice Items</h5>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="add-item-row-btn"><i class="fa-solid fa-plus me-1"></i>Add Item Row</button>
                </div>
                <div class="card-premium-body">
                    <div class="table-responsive mb-3">
                        <table class="table align-middle" id="itemsTable">
                            <thead>
                                <tr>
                                    <th>Product Description</th>
                                    <th style="width: 100px;">Qty</th>
                                    <th style="width: 120px;">Rate (₹)</th>
                                    <th style="width: 120px;">Amount (₹)</th>
                                    <th style="width: 50px; text-align: center;"></th>
                                </tr>
                            </thead>
                            <tbody id="line-items-tbody">
                                <!-- Row Template will be inserted dynamically by JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Payment Breakups -->
                    <div class="border-top pt-4">
                        <div class="row justify-content-end">
                            <div class="col-12 col-md-8 col-xl-7">
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label for="discount" class="form-label fw-semibold text-muted" style="font-size: 0.75rem;">DISCOUNT (₹)</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-premium text-end" id="discount" name="discount" value="<?php echo htmlspecialchars($invoice['discount']); ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-semibold text-muted" style="font-size: 0.75rem;">SUBTOTAL (₹)</label>
                                        <input type="text" class="form-control form-control-premium bg-light text-end" id="subtotalDisplay" readonly value="0.00">
                                    </div>
                                </div>
                                
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label for="cash_paid" class="form-label fw-semibold text-muted" style="font-size: 0.75rem;">CASH PAID (₹)</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-premium text-end" id="cash_paid" name="cash_paid" value="<?php echo htmlspecialchars($invoice['cash_paid']); ?>">
                                    </div>
                                    <div class="col-6">
                                        <label for="online_paid" class="form-label fw-semibold text-muted" style="font-size: 0.75rem;">ONLINE PAID (₹)</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-premium text-end" id="online_paid" name="online_paid" value="<?php echo htmlspecialchars($invoice['online_paid']); ?>">
                                    </div>
                                </div>

                                <div class="row g-2 border-top pt-3 align-items-center">
                                    <div class="col-6 text-end">
                                        <span class="fw-bold text-muted" style="font-size: 0.85rem;">TOTAL PAYABLE:</span>
                                    </div>
                                    <div class="col-6 text-end">
                                        <span class="fs-5 fw-bold text-dark" id="netPayableDisplay">₹0.00</span>
                                    </div>
                                </div>
                                
                                <div class="row g-2 pt-1 align-items-center">
                                    <div class="col-6 text-end">
                                        <span class="fw-bold text-danger" style="font-size: 0.85rem;">BALANCE DUE (CREDIT):</span>
                                    </div>
                                    <div class="col-6 text-end">
                                        <span class="fs-5 fw-bold text-danger" id="balanceDueDisplay">₹0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <hr class="my-4" style="color: var(--border-color);">

    <div class="d-flex gap-2 mb-5">
        <button type="submit" class="btn btn-accent px-4 py-2 bg-danger">Update Invoice</button>
        <a href="index.php" class="btn btn-light text-muted px-4 py-2">Cancel</a>
    </div>
</form>

<script>
    const productsList = <?php echo json_encode($products); ?>;
    
    // Load pre-existing line items into JS array
    const savedItemsList = [];
    <?php foreach ($invoice_items as $item): ?>
        savedItemsList.push({
            product_id: <?php echo $item['product_id']; ?>,
            quantity: <?php echo $item['quantity']; ?>,
            rate: <?php echo $item['rate']; ?>
        });
    <?php endforeach; ?>
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('line-items-tbody');
    const addButton = document.getElementById('add-item-row-btn');
    
    // Summary inputs/displays
    const subtotalDisplay = document.getElementById('subtotalDisplay');
    const discountInput = document.getElementById('discount');
    const cashPaidInput = document.getElementById('cash_paid');
    const onlinePaidInput = document.getElementById('online_paid');
    const netPayableDisplay = document.getElementById('netPayableDisplay');
    const balanceDueDisplay = document.getElementById('balanceDueDisplay');

    // Populate pre-saved items
    if (savedItemsList.length > 0) {
        savedItemsList.forEach(item => {
            addRow(item.product_id, item.quantity, item.rate);
        });
    } else {
        addRow();
    }

    // Event Listeners
    addButton.addEventListener('click', () => addRow());
    tbody.addEventListener('click', removeRow);
    tbody.addEventListener('change', handleProductChange);
    tbody.addEventListener('input', calculateAmounts);
    
    discountInput.addEventListener('input', calculateAmounts);
    cashPaidInput.addEventListener('input', calculateAmounts);
    onlinePaidInput.addEventListener('input', calculateAmounts);

    calculateAmounts();

    function addRow(pId = '', qty = '', rate = '') {
        const rowId = Date.now();
        const tr = document.createElement('tr');
        tr.id = 'row-' + rowId;
        
        let productOptions = '<option value="">-- Choose --</option>';
        productsList.forEach(p => {
            // Find if this product is the saved item, adjust stock temporarily on client display
            let tempStock = p.current_stock;
            if (pId == p.id) {
                tempStock = parseInt(p.current_stock) + parseInt(qty); // restore stock limit on UI for validation
            }

            productOptions += `<option value="${p.id}" data-price="${p.selling_price}" data-stock="${tempStock}" ${pId == p.id ? 'selected' : ''}>${p.name} (SKU: ${p.sku}) [In Hand: ${tempStock}]</option>`;
        });

        tr.innerHTML = `
            <td>
                <select class="form-select form-control-premium prod-select" name="prod_id[]" required>
                    ${productOptions}
                </select>
            </td>
            <td>
                <input type="number" min="1" class="form-control form-control-premium qty-input" name="qty[]" required placeholder="0" value="${qty}">
            </td>
            <td>
                <input type="number" step="0.01" min="0" class="form-control form-control-premium rate-input" name="rate[]" required placeholder="0.00" value="${rate}">
            </td>
            <td>
                <input type="text" class="form-control form-control-premium bg-light amount-input" readonly value="0.00">
            </td>
            <td style="text-align: center;">
                <button type="button" class="btn btn-outline-danger btn-sm delete-row-btn" style="border-radius: 6px;"><i class="fa-solid fa-xmark"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
        calculateAmounts();
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
            const stock = selectedOption.getAttribute('data-stock') || 0;
            const tr = select.closest('tr');
            const rateInput = tr.querySelector('.rate-input');
            const qtyInput = tr.querySelector('.qty-input');
            
            rateInput.value = parseFloat(price).toFixed(2);
            qtyInput.max = stock; // set limits
            
            calculateAmounts();
        }
    }

    function calculateAmounts() {
        let subtotal = 0;
        const rows = tbody.querySelectorAll('tr');
        
        rows.forEach(tr => {
            const select = tr.querySelector('.prod-select');
            const selectedOption = select.options[select.selectedIndex];
            const stock = selectedOption ? parseInt(selectedOption.getAttribute('data-stock') || 0) : 999999;
            
            const qtyInput = tr.querySelector('.qty-input');
            let qty = parseInt(qtyInput.value) || 0;
            
            // UI limits check
            if (qty > stock) {
                alert(`Warning: You only have ${stock} units in hand. Entering ${qty} units will fail verification.`);
                qtyInput.value = stock;
                qty = stock;
            }

            const rate = parseFloat(tr.querySelector('.rate-input').value) || 0;
            const amountInput = tr.querySelector('.amount-input');
            
            const total = qty * rate;
            amountInput.value = total.toFixed(2);
            subtotal += total;
        });

        subtotalDisplay.value = subtotal.toFixed(2);

        const discount = parseFloat(discountInput.value) || 0;
        const cashPaid = parseFloat(cashPaidInput.value) || 0;
        const onlinePaid = parseFloat(onlinePaidInput.value) || 0;

        const netPayable = subtotal - discount;
        const balanceDue = netPayable - cashPaid - onlinePaid;

        netPayableDisplay.textContent = '₹' + netPayable.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        balanceDueDisplay.textContent = '₹' + balanceDue.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
});
</script>
