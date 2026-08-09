<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$purchase_id = intval($_GET['id'] ?? 0);

if ($purchase_id > 0) {
    try {
        $pdo->beginTransaction();

        // 1. Fetch purchase items to revert (subtract) stock
        $stmt_items = $pdo->prepare("SELECT product_id, quantity FROM purchase_items WHERE purchase_id = ?");
        $stmt_items->execute([$purchase_id]);
        $items = $stmt_items->fetchAll();

        // 2. Fetch invoice info for logging
        $stmt_invoice = $pdo->prepare("SELECT invoice_no, supplier_id, total_amount FROM purchases WHERE id = ?");
        $stmt_invoice->execute([$purchase_id]);
        $invoice = $stmt_invoice->fetch();

        if ($invoice) {
            // 3. Revert stock levels (subtract purchase quantity from current stock)
            $upd_stock = $pdo->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?");
            foreach ($items as $item) {
                $upd_stock->execute([$item['quantity'], $item['product_id']]);
            }

            // 4. Delete purchase record (cascade deletes purchase_items)
            $del_stmt = $pdo->prepare("DELETE FROM purchases WHERE id = ?");
            $del_stmt->execute([$purchase_id]);

            log_activity($pdo, "Deleted purchase invoice #{$invoice['invoice_no']} (ID {$purchase_id}). Reverted items from stock levels.");
            
            $pdo->commit();
            set_flash_message('success', "Purchase transaction deleted successfully and inventory levels reverted.");
        } else {
            throw new Exception("Purchase record not found.");
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        set_flash_message('danger', "Failed to delete purchase record: " . $e->getMessage());
    }
} else {
    set_flash_message('danger', "Invalid purchase ID parameter.");
}

header("Location: index.php");
exit();
?>
