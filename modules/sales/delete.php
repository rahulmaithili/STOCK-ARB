<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$sale_id = intval($_GET['id'] ?? 0);

if ($sale_id > 0) {
    try {
        $pdo->beginTransaction();

        // 1. Fetch sale items to revert stock
        $stmt_items = $pdo->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
        $stmt_items->execute([$sale_id]);
        $items = $stmt_items->fetchAll();

        // 2. Fetch invoice info for logging
        $stmt_invoice = $pdo->prepare("SELECT invoice_no, customer_id, total_amount FROM sales WHERE id = ?");
        $stmt_invoice->execute([$sale_id]);
        $invoice = $stmt_invoice->fetch();

        if ($invoice) {
            // 3. Revert stock levels
            $upd_stock = $pdo->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?");
            foreach ($items as $item) {
                $upd_stock->execute([$item['quantity'], $item['product_id']]);
            }

            // 4. Delete invoice record (cascade deletes sale_items)
            $del_stmt = $pdo->prepare("DELETE FROM sales WHERE id = ?");
            $del_stmt->execute([$sale_id]);

            log_activity($pdo, "Deleted invoice #{$invoice['invoice_no']} (ID {$sale_id}). Reverted items back to stock levels.");
            
            $pdo->commit();
            set_flash_message('success', "Invoice deleted successfully and inventory levels reverted.");
        } else {
            throw new Exception("Invoice not found.");
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        set_flash_message('danger', "Failed to delete invoice: " . $e->getMessage());
    }
} else {
    set_flash_message('danger', "Invalid invoice ID parameter.");
}

header("Location: index.php");
exit();
?>
