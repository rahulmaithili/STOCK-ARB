<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin', 'manager']);

$product_id = intval($_GET['id'] ?? 0);

if ($product_id > 0) {
    try {
        // Fetch image path and product details for cleanup and logging
        $stmt = $pdo->prepare("SELECT name, sku, image FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if ($product) {
            // Delete product image file if it exists
            if (!empty($product['image']) && file_exists(dirname(dirname(__DIR__)) . '/' . $product['image'])) {
                unlink(dirname(dirname(__DIR__)) . '/' . $product['image']);
            }

            // Delete product database record
            $delete_stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $delete_stmt->execute([$product_id]);

            log_activity($pdo, "Deleted product: " . $product['name'] . " (SKU: " . $product['sku'] . ")");
            set_flash_message('success', "Product '" . $product['name'] . "' deleted successfully.");
        } else {
            set_flash_message('danger', "Product not found.");
        }
    } catch (PDOException $e) {
        set_flash_message('danger', "Failed to delete product: " . $e->getMessage());
    }
} else {
    set_flash_message('danger', "Invalid product parameter.");
}

header("Location: index.php");
exit();
?>
