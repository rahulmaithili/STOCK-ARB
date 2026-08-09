<?php
require_once dirname(__DIR__) . '/config/config.php';

/**
 * Sanitize User Inputs
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Check if a user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Redirect to login if user is not authenticated
 */
function require_login() {
    if (!is_logged_in()) {
        header("Location: " . BASE_URL . "modules/auth/login.php");
        exit();
    }
}

/**
 * Check if logged in user has specific role permissions
 */
function has_role($allowed_roles = []) {
    require_login();
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowed_roles)) {
        // Redirect to dashboard or logout if role unauthorized
        $_SESSION['flash_msg'] = "You do not have access to that module.";
        $_SESSION['flash_type'] = "danger";
        header("Location: " . BASE_URL . "modules/dashboard/index.php");
        exit();
    }
}

function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function is_manager() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'manager']);
}

/**
 * CSRF Protection Helpers
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_input() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Log System Activity
 */
function log_activity($pdo, $action, $user_id = null) {
    if ($user_id === null) {
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    }
    
    if ($user_id !== null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action) VALUES (?, ?)");
            $stmt->execute([$user_id, $action]);
        } catch (PDOException $e) {
            // Silently fail logging rather than breaking user operations
        }
    }
}

/**
 * Flash Notification Messages
 */
function set_flash_message($type, $message) {
    $_SESSION['flash_msg'] = $message;
    $_SESSION['flash_type'] = $type; // e.g. success, danger, warning, info
}

function display_flash_message() {
    if (isset($_SESSION['flash_msg'])) {
        $type = $_SESSION['flash_type'] ?? 'success';
        $msg = $_SESSION['flash_msg'];
        
        unset($_SESSION['flash_msg']);
        unset($_SESSION['flash_type']);

        return '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">
                    ' . $msg . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
    }
    return '';
}

/**
 * Retrieve Dynamic Company Profile
 */
function get_company_profile($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM company_profile WHERE id = 1");
        $profile = $stmt->fetch();
        if ($profile) {
            return $profile;
        }
    } catch (PDOException $e) {
        // Fallback below
    }
    return [
        'company_name' => 'StockFlow Agency',
        'phone' => '+91 9999888877',
        'email' => 'info@stockflow.com',
        'address' => 'Sector-4, Noida',
        'gstin' => '07AAAAA1111A1Z1',
        'logo' => null
    ];
}

/**
 * Verify Dynamic Role Permissions Matrix
 */
function has_permission($pdo, $permission_key) {
    if (!is_logged_in()) return false;
    $role = $_SESSION['user_role'] ?? 'staff';
    if ($role === 'admin') return true; // Admin has full access
    
    try {
        $stmt = $pdo->prepare("SELECT is_allowed FROM role_permissions WHERE role = ? AND permission_key = ?");
        $stmt->execute([$role, $permission_key]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}
?>
