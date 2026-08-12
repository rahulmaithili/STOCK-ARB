<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/config/recovery_util.php';

// Handle AJAX Forgot Password recovery flows
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax_action'];

    if ($action === 'get_request_code') {
        $email = trim($_GET['email'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'error' => 'Please enter email address.']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $req_code = generate_request_code($email);
            echo json_encode(['success' => true, 'request_code' => $req_code]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Entered Email is not registered in the database!']);
        }
        exit();
    }

    if ($action === 'reset_password_with_code') {
        $email = trim($_GET['email'] ?? '');
        $req_code = trim($_GET['request_code'] ?? '');
        $app_code = trim($_GET['approval_code'] ?? '');
        $new_pass = trim($_GET['new_password'] ?? '');

        if (empty($email) || empty($req_code) || empty($app_code) || empty($new_pass)) {
            echo json_encode(['success' => false, 'error' => 'All fields are required.']);
            exit();
        }

        // Verify cryptographic tokens offline
        if (intval(generate_request_code($email)) !== intval($req_code)) {
            echo json_encode(['success' => false, 'error' => 'Request Code does not match email.']);
            exit();
        }

        if (intval(generate_approval_code($req_code)) !== intval($app_code)) {
            echo json_encode(['success' => false, 'error' => 'Invalid Approval Code! Please contact Admin.']);
            exit();
        }

        // Update password in DB
        try {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->execute([$hashed, $email]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
}

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
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="password" class="form-label fw-semibold text-muted mb-0" style="font-size: 0.8rem;">PASSWORD</label>
                    <a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal" class="text-decoration-none fw-semibold" style="font-size: 0.78rem; color: var(--primary);">Forgot Password?</a>
                </div>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted" style="border-radius: 10px 0 0 10px;"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" class="form-control form-control-premium border-start-0 ps-0" id="password" name="password" required placeholder="••••••••" style="border-radius: 0 10px 10px 0;">
                </div>
            </div>

            <button type="submit" class="btn btn-accent w-100 py-2 fw-semibold">Sign In</button>
        </form>
    </div>

    <!-- Forgot Password Info Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="forgotPasswordModalLabel"><i class="fa-solid fa-key text-warning me-2"></i>Forgot Password?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body py-4">
                    <!-- Tab Headers to Switch between Staff and Admin -->
                    <ul class="nav nav-pills nav-fill mb-3 bg-light p-1 rounded-3" id="recoveryTabs" role="tablist" style="font-size: 0.82rem;">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active fw-bold py-1.5" id="staff-recovery-tab" data-bs-toggle="tab" data-bs-target="#staff-recovery-panel" type="button" role="tab">
                                <i class="fa-solid fa-user-shield me-1"></i> Staff / Manager
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold py-1.5" id="admin-recovery-tab" data-bs-toggle="tab" data-bs-target="#admin-recovery-panel" type="button" role="tab">
                                <i class="fa-solid fa-key me-1"></i> Admin Owner
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content pt-2" id="recoveryTabsContent">
                        <!-- Panel 1: Interactive Staff Reset -->
                        <div class="tab-pane fade show active" id="staff-recovery-panel" role="tabpanel">
                            <!-- Step 1: Input Email -->
                            <div id="recovery-step-1">
                                <p class="text-muted" style="font-size: 0.85rem; line-height: 1.5; margin-bottom: 15px;">
                                    Apni registered email id niche daalein aur request code generate karein:
                                </p>
                                <div class="mb-3">
                                    <label for="recovery_email" class="form-label fw-semibold text-muted" style="font-size: 0.76rem;">REGISTERED EMAIL *</label>
                                    <input type="email" class="form-control form-control-premium" id="recovery_email" placeholder="name@stock.com">
                                </div>
                                <button type="button" class="btn btn-accent w-100 py-2 fw-semibold" id="get-request-code-btn">Generate Request Code</button>
                            </div>

                            <!-- Step 2: Show Request Code & Enter Approval Code -->
                            <div id="recovery-step-2" style="display: none;">
                                <div class="alert alert-success py-2 mb-3 rounded-4 text-center">
                                    <small class="text-muted d-block" style="font-size: 0.75rem;">YOUR REQUEST CODE (Admin ko batayein):</small>
                                    <strong class="fs-4 text-success" id="display-request-code" style="letter-spacing: 2px;">00000</strong>
                                </div>
                                
                                <p class="text-muted" style="font-size: 0.82rem; line-height: 1.4; margin-bottom: 15px;">
                                    Admin (Owner) se is request code ka **Approval Code** lekar niche enter karein aur naya password banayein:
                                </p>

                                <div class="mb-2">
                                    <label for="recovery_approval_code" class="form-label fw-semibold text-muted mb-0.5" style="font-size: 0.76rem;">ENTER 5-DIGIT APPROVAL CODE *</label>
                                    <input type="text" class="form-control form-control-premium" id="recovery_approval_code" placeholder="e.g. 29481" maxlength="5">
                                </div>
                                <div class="mb-2">
                                    <label for="recovery_new_password" class="form-label fw-semibold text-muted mb-0.5" style="font-size: 0.76rem;">NEW PASSWORD *</label>
                                    <input type="password" class="form-control form-control-premium" id="recovery_new_password" placeholder="Min 6 characters">
                                </div>
                                <div class="mb-3">
                                    <label for="recovery_confirm_password" class="form-label fw-semibold text-muted mb-0.5" style="font-size: 0.76rem;">CONFIRM NEW PASSWORD *</label>
                                    <input type="password" class="form-control form-control-premium" id="recovery_confirm_password" placeholder="Confirm password">
                                </div>
                                <button type="button" class="btn btn-success w-100 py-2 fw-semibold" id="reset-password-submit-btn">Reset Password & Log In</button>
                            </div>
                        </div>

                        <!-- Panel 2: Admin Manual bat reset -->
                        <div class="tab-pane fade" id="admin-recovery-panel" role="tabpanel">
                            <p class="text-muted mb-3" style="font-size: 0.85rem; line-height: 1.5;">
                                Agar aap main Admin (Owner) hain aur password bhool gaye hain, toh backup recovery tool ka use karein:
                            </p>
                            <div class="bg-light p-3 rounded-4 border">
                                <h6 class="fw-bold text-dark mb-1.5" style="font-size: 0.85rem;"><i class="fa-solid fa-terminal text-success me-2"></i>Reset Admin bat Tool:</h6>
                                <ol class="text-muted ps-3 mb-0" style="font-size: 0.8rem; line-height: 1.5;">
                                    <li class="mb-1">Apne software folder (📁 <code>StockARB-win32-x64</code>) me jayein.</li>
                                    <li class="mb-1">Wahan **<code>Reset-Admin-Password.bat</code>** file par double-click karein.</li>
                                    <li>Aapka Admin password reset hokar default **<code>admin123</code>** ho jayega.</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-secondary px-4 py-2" data-bs-dismiss="modal" style="border-radius: 10px; font-size: 0.85rem;">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        let requestEmail = "";
        let requestCodeVal = "";

        const getRequestBtn = document.getElementById('get-request-code-btn');
        if (getRequestBtn) {
            getRequestBtn.addEventListener('click', async () => {
                const emailInput = document.getElementById('recovery_email');
                const email = emailInput.value.trim();

                if (!email) {
                    alert("Please enter your registered email address.");
                    return;
                }

                getRequestBtn.disabled = true;
                getRequestBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';

                try {
                    const response = await fetch(`login.php?ajax_action=get_request_code&email=${encodeURIComponent(email)}`);
                    const data = await response.json();

                    if (data.success) {
                        requestEmail = email;
                        requestCodeVal = data.request_code;
                        document.getElementById('display-request-code').textContent = data.request_code;
                        document.getElementById('recovery-step-1').style.display = 'none';
                        document.getElementById('recovery-step-2').style.display = 'block';
                    } else {
                        alert(data.error);
                    }
                } catch (e) {
                    alert("Failed to connect to backend server.");
                }

                getRequestBtn.disabled = false;
                getRequestBtn.innerHTML = 'Generate Request Code';
            });
        }

        const resetSubmitBtn = document.getElementById('reset-password-submit-btn');
        if (resetSubmitBtn) {
            resetSubmitBtn.addEventListener('click', async () => {
                const approvalCode = document.getElementById('recovery_approval_code').value.trim();
                const newPass = document.getElementById('recovery_new_password').value.trim();
                const confPass = document.getElementById('recovery_confirm_password').value.trim();

                if (!approvalCode || !newPass || !confPass) {
                    alert("Please fill in all fields.");
                    return;
                }

                if (newPass.length < 6) {
                    alert("Password must be at least 6 characters long.");
                    return;
                }

                if (newPass !== confPass) {
                    alert("Confirm password does not match new password.");
                    return;
                }

                resetSubmitBtn.disabled = true;
                resetSubmitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Resetting Password...';

                try {
                    const response = await fetch(`login.php?ajax_action=reset_password_with_code&email=${encodeURIComponent(requestEmail)}&request_code=${requestCodeVal}&approval_code=${approvalCode}&new_password=${encodeURIComponent(newPass)}`);
                    const data = await response.json();

                    if (data.success) {
                        alert("Password reset successful! You can now log in with your new password.");
                        window.location.reload();
                    } else {
                        alert(data.error);
                    }
                } catch (e) {
                    alert("Failed to reset password.");
                }

                resetSubmitBtn.disabled = false;
                resetSubmitBtn.innerHTML = 'Reset Password & Log In';
            });
        }
    });
    </script>
</body>
</html>
