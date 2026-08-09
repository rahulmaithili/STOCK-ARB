<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: " . BASE_URL . "modules/dashboard/index.php");
    exit();
}

$error = '';

// Handle Login POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    // Validate CSRF
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Please reload the page.";
    } elseif (empty($email) || empty($password)) {
        $error = "Please fill in all details.";
    } else {
        try {
            // Retrieve user credentials
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Set Session Variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];

                // Log Activity log
                log_activity($pdo, "User logged in", $user['id']);

                // Redirect to dashboard
                header("Location: " . BASE_URL . "modules/dashboard/index.php");
                exit();
            } else {
                $error = "Invalid Email address or Password.";
            }
        } catch (PDOException $e) {
            $error = "System connection failure: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Stock Register Management System</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome CDNs -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Style CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/custom.css">
</head>
<body class="auth-page-bg">

    <div class="auth-card">
        <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle mb-3" style="width: 50px; height: 50px;">
                <i class="fa-solid fa-boxes-stacked fa-lg"></i>
            </div>
            <h3 class="fw-bold mb-1" style="color: var(--navy-dark);">Welcome Back</h3>
            <p class="text-muted" style="font-size: 0.9rem;">Sign in to your Stock Register account</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2" role="alert" style="font-size: 0.85rem; border-radius: 8px;">
                <i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <!-- CSRF protection -->
            <?php echo csrf_input(); ?>

            <div class="mb-3">
                <label for="email" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">EMAIL ADDRESS</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted" style="border-radius: 10px 0 0 10px;"><i class="fa-regular fa-envelope"></i></span>
                    <input type="email" class="form-control form-control-premium border-start-0 ps-0" id="email" name="email" required placeholder="name@stock.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" style="border-radius: 0 10px 10px 0;">
                </div>
            </div>

            <div class="mb-4">
                <div class="d-flex justify-content-between">
                    <label for="password" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">PASSWORD</label>
                </div>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted" style="border-radius: 10px 0 0 10px;"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" class="form-control form-control-premium border-start-0 ps-0" id="password" name="password" required placeholder="••••••••" style="border-radius: 0 10px 10px 0;">
                </div>
            </div>

            <button type="submit" class="btn btn-accent w-100 py-2 fw-semibold">Sign In</button>
        </form>
        
        <div class="text-center mt-4">
            <span class="text-muted" style="font-size: 0.8rem;">Default Credentials:</span><br>
            <code class="text-secondary" style="font-size: 0.85rem;">admin@stock.com / admin123</code>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
