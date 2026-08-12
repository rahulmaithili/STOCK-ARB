<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/config/recovery_util.php';

require_login();

// Handle AJAX Approval Code Generator
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'generate_approval') {
    header('Content-Type: application/json');
    $req_code = $_GET['request_code'] ?? '';
    if (!empty($req_code)) {
        $app_code = generate_approval_code($req_code);
        echo json_encode(['success' => true, 'approval_code' => $app_code]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Missing Request Code']);
    }
    exit();
}
$role = $_SESSION['user_role'] ?? 'staff';

// Handle Database Download Backup BEFORE any HTML output
if (isset($_GET['action']) && $_GET['action'] === 'download_backup') {
    $dbPath = dirname(dirname(__DIR__)) . '/database.db';
    if (file_exists($dbPath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="stock_backup_' . date('Y-m-d_H-i-s') . '.db"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($dbPath));
        readfile($dbPath);
        exit();
    }
}

$error = '';
$success = '';

// Get current company profile
$company = get_company_profile($pdo);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_avatar') {
            if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['avatar_file'];
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                
                if (!in_array($file['type'], $allowed_types)) {
                    $error = "Only JPG, PNG, and GIF images are allowed.";
                } elseif ($file['size'] > 2 * 1024 * 1024) {
                    $error = "Image size must be less than 2MB.";
                } else {
                    $upload_dir = dirname(dirname(__DIR__)) . '/uploads/avatars/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $dest_file = $upload_dir . 'avatar_' . $_SESSION['user_id'] . '.jpg';
                    if (move_uploaded_file($file['tmp_name'], $dest_file)) {
                        $success = "Profile photo updated successfully!";
                    } else {
                        $error = "Failed to save uploaded photo.";
                    }
                }
            } else {
                $error = "Please select a valid image file.";
            }
        }

        if ($action === 'update_profile') {
            // Update Password
            $current_pass = $_POST['current_password'] ?? '';
            $new_pass = $_POST['new_password'] ?? '';
            $confirm_pass = $_POST['confirm_password'] ?? '';

            if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
                $error = "Please fill in all password fields.";
            } elseif ($new_pass !== $confirm_pass) {
                $error = "New passwords do not match.";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $hashed = $stmt->fetchColumn();

                    if (!password_verify($current_pass, $hashed)) {
                        $error = "Incorrect current password.";
                    } else {
                        $new_hashed = password_hash($new_pass, PASSWORD_BCRYPT);
                        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $upd->execute([$new_hashed, $_SESSION['user_id']]);

                        log_activity($pdo, "Changed password");
                        $success = "Password changed successfully.";
                    }
                } catch (PDOException $e) {
                    $error = "Failed to update profile password: " . $e->getMessage();
                }
            }
        } elseif ($action === 'update_company') {
            // Check authorization
            if (!is_admin()) {
                $error = "Unauthorized: Only Administrators can modify Company Settings.";
            } else {
                $company_name = trim($_POST['company_name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $gstin = trim($_POST['gstin'] ?? '');

                if (empty($company_name)) {
                    $error = "Company name is required.";
                } else {
                    try {
                        $logo_path = $company['logo'];

                        // Handle logo upload
                        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
                            $fileTmpPath = $_FILES['company_logo']['tmp_name'];
                            $fileName = $_FILES['company_logo']['name'];
                            $fileSize = $_FILES['company_logo']['size'];
                            $fileType = $_FILES['company_logo']['type'];
                            
                            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

                            if (in_array($fileExtension, $allowedExtensions)) {
                                $upload_dir = dirname(dirname(__DIR__)) . '/uploads/company/';
                                if (!file_exists($upload_dir)) {
                                    mkdir($upload_dir, 0777, true);
                                }
                                
                                $new_fileName = 'logo.' . $fileExtension;
                                $dest_path = $upload_dir . $new_fileName;

                                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                                    // Save relative path for browser rendering
                                    $logo_path = 'uploads/company/' . $new_fileName;
                                } else {
                                    throw new Exception("Error moving logo file to uploads directory.");
                                }
                            } else {
                                throw new Exception("Invalid logo file extension. Allowed: JPG, PNG, GIF.");
                            }
                        }

                        // Update DB
                        $upd_company = $pdo->prepare("
                            UPDATE company_profile 
                            SET company_name = ?, phone = ?, email = ?, address = ?, gstin = ?, logo = ? 
                            WHERE id = 1
                        ");
                        $upd_company->execute([$company_name, $phone, $email, $address, $gstin, $logo_path]);
                        
                        log_activity($pdo, "Updated company profile details & logo");
                        $success = "Company profile settings updated successfully.";
                        
                        // Reload profile
                        $company = get_company_profile($pdo);

                    } catch (Exception $e) {
                        $error = "Failed to update company settings: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'restore_db') {
            if (!is_admin()) {
                $error = "Unauthorized: Only Administrators can restore backups.";
            } else {
                if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                    $tmpPath = $_FILES['backup_file']['tmp_name'];
                    $fileName = $_FILES['backup_file']['name'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    
                    if ($ext === 'db') {
                        $dbPath = dirname(dirname(__DIR__)) . '/database.db';
                        
                        // Close PDO connection to release file lock
                        $pdo = null;
                        
                        if (copy($tmpPath, $dbPath)) {
                            // Reconnect PDO
                            $pdo = new PDO("sqlite:" . $dbPath);
                            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                            $pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });
                            $pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });

                            $success = "Database backup restored successfully!";
                            log_activity($pdo, "Restored database backup: " . $fileName);
                        } else {
                            $error = "Failed to restore database file. Check write permissions.";
                        }
                    } else {
                        $error = "Invalid file type. Please select a .db backup file.";
                    }
                } else {
                    $error = "Please choose a valid database backup file to upload.";
                }
            }
        }
    }
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-gears me-2 text-secondary"></i>Settings Console</h3>
            <p class="text-muted" style="font-size: 0.95rem;">Manage security, user credentials, and branding settings.</p>
        </div>
        <?php if (is_admin()): ?>
            <div>
                <a href="users.php" class="btn btn-accent d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-users-gear"></i> Manage User Roles
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12">
        <div class="card card-premium">
            <div class="card-premium-header">
                <!-- Navigation Tabs -->
                <ul class="nav nav-tabs card-header-tabs border-0" id="settingsTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold border-0 px-4 py-2" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-panel" type="button" role="tab" aria-controls="profile-panel" aria-selected="true">
                            <i class="fa-solid fa-lock me-1"></i> Security Profile
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold border-0 px-4 py-2" id="company-tab" data-bs-toggle="tab" data-bs-target="#company-panel" type="button" role="tab" aria-controls="company-panel" aria-selected="false">
                            <i class="fa-solid fa-building me-1"></i> Company Profile & Logo
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold border-0 px-4 py-2" id="backup-tab" data-bs-toggle="tab" data-bs-target="#backup-panel" type="button" role="tab" aria-controls="backup-panel" aria-selected="false">
                            <i class="fa-solid fa-database me-1"></i> Database Backup & Restore
                        </button>
                    </li>
                    <li class="nav-item" role="presentation" id="lan-tab-item" style="display: none;">
                        <button class="nav-link fw-bold border-0 px-4 py-2" id="lan-tab" data-bs-toggle="tab" data-bs-target="#lan-panel" type="button" role="tab" aria-controls="lan-panel" aria-selected="false">
                            <i class="fa-solid fa-network-wired me-1"></i> LAN Connection
                        </button>
                    </li>
                </ul>
            </div>
            
            <div class="card-premium-body">
                <div class="tab-content" id="settingsTabContent">
                    
                    <!-- Tab 1: Profile Settings (Change Password) -->
                    <div class="tab-pane fade show active" id="profile-panel" role="tabpanel" aria-labelledby="profile-tab">
                        <div class="row g-4">
                            <!-- Left Side: Change Password -->
                            <div class="col-12 col-md-6">
                                <h5 class="fw-bold text-dark mb-3">Profile Photo</h5>
                                <div class="d-flex align-items-center gap-3 mb-4">
                                    <?php 
                                    $avatar_path = 'uploads/avatars/avatar_' . $_SESSION['user_id'] . '.jpg';
                                    $avatar_full_path = dirname(dirname(__DIR__)) . '/' . $avatar_path;
                                    if (file_exists($avatar_full_path)): ?>
                                        <img src="<?php echo BASE_URL . $avatar_path . '?v=' . time(); ?>" class="rounded-circle border" style="width: 70px; height: 70px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center border rounded-circle" style="width: 70px; height: 70px;">
                                            <i class="fa-solid fa-user fa-2x text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <form action="index.php" method="POST" enctype="multipart/form-data" class="flex-grow-1">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="update_avatar">
                                        <div class="input-group input-group-sm mb-2">
                                            <input type="file" class="form-control form-control-sm" name="avatar_file" accept="image/*" required style="border-radius: 8px 0 0 8px;">
                                            <button type="submit" class="btn btn-primary btn-sm px-3" style="border-radius: 0 8px 8px 0;">Upload</button>
                                        </div>
                                        <small class="text-muted d-block" style="font-size: 0.72rem; line-height: 1.2;">JPEG, PNG formats only. Max 2MB.</small>
                                    </form>
                                </div>
                                <hr class="my-4 text-muted">

                                <h5 class="fw-bold text-dark mb-4">Change User Password</h5>
                                <form action="index.php" method="POST">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_profile">

                                    <div class="mb-3">
                                        <label for="current_password" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CURRENT PASSWORD *</label>
                                        <input type="password" class="form-control form-control-premium" id="current_password" name="current_password" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="new_password" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">NEW PASSWORD *</label>
                                        <input type="password" class="form-control form-control-premium" id="new_password" name="new_password" required>
                                    </div>

                                    <div class="mb-4">
                                        <label for="confirm_password" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">CONFIRM NEW PASSWORD *</label>
                                        <input type="password" class="form-control form-control-premium" id="confirm_password" name="confirm_password" required>
                                    </div>

                                    <button type="submit" class="btn btn-accent px-4 py-2">Update Password</button>
                                </form>
                            </div>
                            
                            <!-- Right Side: Reset Approval Generator (Admin Only) -->
                            <?php if (is_admin()): ?>
                            <div class="col-12 col-md-6 border-start ps-md-5">
                                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-user-shield me-2 text-primary"></i>Password Reset Approval</h5>
                                <p class="text-muted" style="font-size: 0.85rem; line-height: 1.5; margin-bottom: 15px;">
                                    Agar koi staff member apna password bhool jata hai aur aapko apna <strong>Request Code</strong> batata hai, toh use niche enter karke reset validation code generate karein:
                                </p>
                                
                                <div class="bg-light p-4 rounded-4 border">
                                    <div class="mb-3">
                                        <label for="recovery_request_code" class="form-label fw-semibold text-muted mb-1" style="font-size: 0.76rem;">ENTER USER REQUEST CODE</label>
                                        <input type="text" class="form-control form-control-premium" id="recovery_request_code" placeholder="e.g. 58392" maxlength="5">
                                    </div>
                                    <button type="button" class="btn btn-primary w-100 py-2.5 d-inline-flex align-items-center justify-content-center gap-2 fw-semibold" id="generate-approval-btn">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate Approval Code
                                    </button>
                                    
                                    <div class="mt-4 text-center" id="approval-result-box" style="display: none;">
                                        <span class="text-muted d-block mb-1" style="font-size: 0.8rem;">APPROVAL CODE FOR USER:</span>
                                        <span class="fs-3 fw-bold text-success" id="approval-code-display" style="letter-spacing: 2px;">APP-00000</span>
                                        <small class="text-muted d-block mt-2" style="font-size: 0.72rem; line-height: 1.4;">
                                            User ko yeh 5-digit code batayein. Woh ise apne login recovery popup me enter karke naya password set kar sakega.
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Tab 2: Company Profile Settings -->
                    <div class="tab-pane fade" id="company-panel" role="tabpanel" aria-labelledby="company-tab">
                        <form action="index.php" method="POST" enctype="multipart/form-data">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="update_company">

                            <div class="row g-4">
                                <div class="col-12 col-md-8">
                                    <h5 class="fw-bold text-dark mb-4">Branding & Corporate Details</h5>
                                    
                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-sm-6">
                                            <label for="company_name" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">COMPANY NAME *</label>
                                            <input type="text" class="form-control form-control-premium" id="company_name" name="company_name" required value="<?php echo htmlspecialchars($company['company_name']); ?>" <?php echo !is_admin() ? 'readonly' : ''; ?>>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label for="gstin" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">GST / GSTIN NUMBER</label>
                                            <input type="text" class="form-control form-control-premium" id="gstin" name="gstin" value="<?php echo htmlspecialchars($company['gstin']); ?>" <?php echo !is_admin() ? 'readonly' : ''; ?>>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-sm-6">
                                            <label for="phone" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">OFFICIAL PHONE</label>
                                            <input type="text" class="form-control form-control-premium" id="phone" name="phone" value="<?php echo htmlspecialchars($company['phone']); ?>" <?php echo !is_admin() ? 'readonly' : ''; ?>>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label for="email" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">OFFICIAL EMAIL</label>
                                            <input type="email" class="form-control form-control-premium" id="email" name="email" value="<?php echo htmlspecialchars($company['email']); ?>" <?php echo !is_admin() ? 'readonly' : ''; ?>>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label for="address" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">OFFICIAL ADDRESS</label>
                                        <textarea class="form-control form-control-premium" id="address" name="address" rows="3" <?php echo !is_admin() ? 'readonly' : ''; ?>><?php echo htmlspecialchars($company['address']); ?></textarea>
                                    </div>

                                    <?php if (is_admin()): ?>
                                        <button type="submit" class="btn btn-accent px-4 py-2">Update Company Details</button>
                                    <?php else: ?>
                                        <div class="alert alert-warning py-2 mb-0" style="font-size: 0.85rem;">
                                            Only System Administrators can update company branding information.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Logo Upload Box -->
                                <div class="col-12 col-md-4 text-center border-start">
                                    <h5 class="fw-bold text-dark mb-4">Official Logo</h5>
                                    
                                    <div class="mb-3">
                                        <?php if (!empty($company['logo']) && file_exists(dirname(dirname(__DIR__)) . '/' . $company['logo'])): ?>
                                            <img src="<?php echo BASE_URL . $company['logo'] . '?v=' . time(); ?>" class="img-thumbnail bg-light mb-3" style="max-height: 140px; border-radius: 12px; object-fit: contain;" alt="Company Logo">
                                        <?php else: ?>
                                            <div class="bg-light d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 140px; height: 140px; border-radius: 12px; border: 2px dashed var(--border-color);">
                                                <i class="fa-solid fa-image fa-3x text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (is_admin()): ?>
                                        <div class="px-3">
                                            <label for="company_logo" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">UPLOAD NEW LOGO (PNG/JPG)</label>
                                            <input type="file" class="form-control form-control-sm" id="company_logo" name="company_logo">
                                            <small class="text-muted d-block mt-2" style="font-size: 0.72rem;">Suggested size: Square, max 300x300px.</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Tab 3: Database Backup & Restore -->
                    <div class="tab-pane fade" id="backup-panel" role="tabpanel" aria-labelledby="backup-tab">
                        <div class="row g-4 p-2">
                            <!-- Left Side: Local File Backup -->
                            <div class="col-12 col-md-6">
                                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-file-export me-2 text-success"></i>Local Backup (Offline)</h5>
                                <div class="bg-light p-3 rounded-4 mb-4">
                                    <p class="text-muted" style="font-size: 0.85rem; line-height: 1.5; margin-bottom: 12px;">
                                        Aap apne offline system ka manual backup file (<code>.db</code> extension) download karke Pendrive me save kar sakte hain.
                                    </p>
                                    <a href="index.php?action=download_backup" class="btn btn-success btn-sm px-3 py-2 d-inline-flex align-items-center gap-2">
                                        <i class="fa-solid fa-download"></i> Download Backup File (.db)
                                    </a>
                                </div>

                                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-file-import me-2 text-danger"></i>Local Restore (Offline)</h5>
                                <div class="bg-light p-3 rounded-4">
                                    <p class="text-muted" style="font-size: 0.85rem; line-height: 1.5; margin-bottom: 12px;">
                                        Kisi doosre computer se laya hua backup (<code>.db</code> file) yahan upload karke database restore kar sakte hain.
                                        <br>
                                        <span class="text-danger fw-bold"><i class="fa-solid fa-triangle-exclamation"></i> WARNING:</span> Baki sabhi records overwrite ho jayenge!
                                    </p>
                                    <form action="index.php" method="POST" enctype="multipart/form-data" style="max-width: 100%;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="restore_db">
                                        <div class="mb-3">
                                            <input type="file" class="form-control form-control-sm" id="backup_file" name="backup_file" accept=".db" required>
                                        </div>
                                        <button type="submit" class="btn btn-danger btn-sm px-3 py-2 d-inline-flex align-items-center gap-2" <?php echo !is_admin() ? 'disabled' : ''; ?>>
                                            <i class="fa-solid fa-upload"></i> Upload & Restore Database
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Right Side: Google Drive Cloud Backup (Desktop App Only) -->
                            <div class="col-12 col-md-6 border-start ps-md-5" id="gdrive-sync-section" style="display: none;">
                                <h5 class="fw-bold text-dark mb-3"><i class="fa-brands fa-google-drive me-2 text-primary"></i>Google Drive Cloud Sync</h5>
                                
                                <!-- Connection Status -->
                                <div class="alert alert-info py-3 mb-4 rounded-4 d-flex align-items-center gap-3" id="gdrive-status-alert">
                                    <i class="fa-brands fa-google-drive fa-2x text-primary" id="gdrive-status-icon"></i>
                                    <div>
                                        <h6 class="fw-bold mb-1" id="gdrive-status-title">Checking Google Drive Connection...</h6>
                                        <small class="text-muted d-block" id="gdrive-status-desc">Loading configuration settings...</small>
                                    </div>
                                </div>

                                <!-- Credentials Box (When not linked) -->
                                <div id="gdrive-credentials-form" style="display: none;">
                                    <div class="bg-light p-3 rounded-4 mb-3">
                                        <h6 class="fw-bold text-dark mb-2">Google Drive API Configuration</h6>
                                        <p class="text-muted" style="font-size: 0.78rem; line-height: 1.4; margin-bottom: 12px;">
                                            Google account link karne ke liye aapko pehle Google Developer Console se <strong>OAuth Client ID</strong> set karna hoga.
                                        </p>
                                        <div class="mb-2">
                                            <label for="gdrive_client_id" class="form-label fw-semibold text-muted" style="font-size: 0.72rem; margin-bottom: 4px;">GOOGLE CLIENT ID</label>
                                            <input type="text" class="form-control form-control-sm" id="gdrive_client_id" placeholder="Paste Client ID here...">
                                        </div>
                                        <div class="mb-3">
                                            <label for="gdrive_client_secret" class="form-label fw-semibold text-muted" style="font-size: 0.72rem; margin-bottom: 4px;">GOOGLE CLIENT SECRET</label>
                                            <input type="password" class="form-control form-control-sm" id="gdrive_client_secret" placeholder="Paste Client Secret here...">
                                        </div>
                                        <button type="button" class="btn btn-primary btn-sm px-3 py-2 d-inline-flex align-items-center gap-2" id="gdrive-connect-btn">
                                            <i class="fa-solid fa-link"></i> Link Google Drive Account
                                        </button>
                                    </div>
                                </div>

                                <!-- Actions Box (When linked) -->
                                <div id="gdrive-actions" style="display: none;">
                                    <div class="d-flex flex-column gap-3">
                                        <button type="button" class="btn btn-primary px-4 py-2.5 d-inline-flex align-items-center justify-content-center gap-2" id="gdrive-sync-btn">
                                            <i class="fa-solid fa-cloud-arrow-up"></i> Sync Database to Cloud
                                        </button>
                                        <button type="button" class="btn btn-outline-danger px-4 py-2.5 d-inline-flex align-items-center justify-content-center gap-2" id="gdrive-restore-btn">
                                            <i class="fa-solid fa-cloud-arrow-down"></i> Restore Database from Cloud
                                        </button>
                                        <button type="button" class="btn btn-link text-danger btn-sm mt-2" id="gdrive-disconnect-btn">
                                            Disconnect Google Account
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Notice for standard web browser view (not Electron) -->
                            <div class="col-12 col-md-6 border-start ps-md-5 text-center py-5" id="gdrive-web-notice">
                                <i class="fa-brands fa-google-drive fa-4x text-muted mb-3 opacity-50"></i>
                                <h6 class="fw-bold text-muted">Google Drive Sync (Desktop App Only)</h6>
                                <p class="text-muted mx-auto" style="font-size: 0.85rem; max-width: 320px;">
                                    Google Drive background automatic backup feature sirf desktop app software ke andar hi available hai.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 4: LAN Multi-PC Sync (Server/Client Setup) -->
                    <div class="tab-pane fade" id="lan-panel" role="tabpanel" aria-labelledby="lan-tab">
                        <div class="row g-4 p-2">
                            <div class="col-12 col-md-7">
                                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-network-wired me-2 text-primary"></i>LAN Network Settings</h5>
                                <p class="text-muted" style="font-size: 0.85rem; line-height: 1.5;">
                                    Apne local Wi-Fi / Router network par sabhi computers ko aapas me link karke ek hi central database par chalane ke liye settings save karein.
                                </p>

                                <div class="bg-light p-4 rounded-4 border">
                                    <!-- Mode Selection -->
                                    <div class="mb-3">
                                        <label for="network_mode" class="form-label fw-bold text-dark mb-1" style="font-size: 0.82rem;">CONNECTION MODE</label>
                                        <select class="form-select form-control-premium" id="network_mode">
                                            <option value="standalone">Standalone Mode (PC runs local database - Default)</option>
                                            <option value="client">Client Mode (PC connects to Main Server PC)</option>
                                        </select>
                                        <small class="text-muted d-block mt-1" style="font-size: 0.76rem; line-height: 1.4;">
                                            * Standalone: Yeh PC khud apna main server chalayega.<br>
                                            * Client: Yeh PC kisi doosre server computer ke database se connect karega.
                                        </small>
                                    </div>

                                    <!-- Server IP Input (Hidden/Disabled unless client) -->
                                    <div class="mb-4" id="server-ip-container" style="display: none;">
                                        <label for="server_ip" class="form-label fw-bold text-dark mb-1" style="font-size: 0.82rem;">MAIN SERVER PC IP ADDRESS *</label>
                                        <input type="text" class="form-control form-control-premium" id="server_ip" placeholder="e.g. 192.168.1.15">
                                        <small class="text-muted d-block mt-1" style="font-size: 0.74rem;">
                                            Main Server PC ki settings me likha hua IP Address yahan enter karein.
                                        </small>
                                    </div>

                                    <button type="button" class="btn btn-accent px-4 py-2.5 fw-semibold d-inline-flex align-items-center gap-2" id="save-network-btn">
                                        <i class="fa-solid fa-floppy-disk"></i> Save & Apply Configuration
                                    </button>
                                </div>
                            </div>

                            <div class="col-12 col-md-5 border-start ps-md-4">
                                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-circle-info me-2 text-info"></i>Host Identification</h5>
                                
                                <div class="alert alert-info py-3 rounded-4 mb-4">
                                    <span class="text-muted d-block mb-1" style="font-size: 0.78rem;">THIS PC'S LOCAL IP ADDRESS (LAN):</span>
                                    <strong class="fs-4 text-dark" id="display-local-ip">Checking...</strong>
                                    <small class="text-muted d-block mt-2" style="font-size: 0.74rem; line-height: 1.4;">
                                        Client computers par settings config set karte samay, unhe connect karne ke liye **yahi IP Address** enter karna hoga.
                                    </small>
                                </div>

                                <div class="bg-light p-3 rounded-4 border">
                                    <h6 class="fw-bold text-dark mb-2" style="font-size: 0.84rem;"><i class="fa-solid fa-check-double text-success me-2"></i>Quick Instructions:</h6>
                                    <ol class="text-muted ps-3 mb-0" style="font-size: 0.78rem; line-height: 1.5;">
                                        <li class="mb-1.5">Dono PCs **same Wi-Fi router** se connected hone chahiye.</li>
                                        <li class="mb-1.5">Main PC (Server) par is panel me dikh raha IP Address copy karein.</li>
                                        <li class="mb-1.5">Baki PCs (Client) me aakar Mode ko **Client** karein, aur wahi IP save kar dein.</li>
                                        <li>Client PCs automatic Main Server PC ke data se realtime me sync ho jayenge!</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        // Check if running inside Electron desktop app wrapper
                        if (window.electronAPI) {
                            document.getElementById('gdrive-sync-section').style.display = 'block';
                            document.getElementById('gdrive-web-notice').style.display = 'none';
                            
                            // Show LAN Setup tab menu
                            document.getElementById('lan-tab-item').style.display = 'block';

                            // Load current Google Drive connection status
                            refreshGDriveStatus();
                            // Load current LAN connection status
                            refreshNetworkStatus();
                        }

                        async function refreshGDriveStatus() {
                            const status = await window.electronAPI.getGoogleDriveStatus();
                            const alertBox = document.getElementById('gdrive-status-alert');
                            const statusTitle = document.getElementById('gdrive-status-title');
                            const statusDesc = document.getElementById('gdrive-status-desc');
                            const credentialsForm = document.getElementById('gdrive-credentials-form');
                            const actionButtons = document.getElementById('gdrive-actions');

                            if (status.linked) {
                                alertBox.className = "alert alert-success py-3 mb-4 rounded-4 d-flex align-items-center gap-3";
                                statusTitle.textContent = "Google Drive Linked";
                                statusDesc.innerHTML = `Linked account: <strong>${status.email}</strong><br>` + 
                                                       (status.lastSync ? `Last synced: ${status.lastSync}` : "Not synced yet.");
                                credentialsForm.style.display = 'none';
                                actionButtons.style.display = 'block';
                            } else {
                                alertBox.className = "alert alert-warning py-3 mb-4 rounded-4 d-flex align-items-center gap-3";
                                statusTitle.textContent = "Google Drive Not Linked";
                                statusDesc.textContent = "Please configure your client credentials and link your account.";
                                credentialsForm.style.display = 'block';
                                actionButtons.style.display = 'none';

                                // Prefill credentials if saved
                                if (status.credentials) {
                                    document.getElementById('gdrive_client_id').value = status.credentials.clientId || '';
                                    document.getElementById('gdrive_client_secret').value = status.credentials.clientSecret || '';
                                }
                            }
                        }

                        // Connect Action
                        const connectBtn = document.getElementById('gdrive-connect-btn');
                        if (connectBtn) {
                            connectBtn.addEventListener('click', async () => {
                                const clientId = document.getElementById('gdrive_client_id').value.trim();
                                const clientSecret = document.getElementById('gdrive_client_secret').value.trim();

                                if (!clientId || !clientSecret) {
                                    alert("Please fill in both Google Client ID and Client Secret.");
                                    return;
                                }

                                connectBtn.disabled = true;
                                connectBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Linking Account...';

                                const res = await window.electronAPI.linkGoogleDrive(clientId, clientSecret);
                                if (res.success) {
                                    alert("Google Drive linked successfully!");
                                    refreshGDriveStatus();
                                } else {
                                    alert("Authentication Failed: " + res.error);
                                }

                                connectBtn.disabled = false;
                                connectBtn.innerHTML = '<i class="fa-solid fa-link"></i> Link Google Drive Account';
                            });
                        }

                        // Sync Action
                        const syncBtn = document.getElementById('gdrive-sync-btn');
                        if (syncBtn) {
                            syncBtn.addEventListener('click', async () => {
                                syncBtn.disabled = true;
                                syncBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Syncing to Cloud...';

                                const res = await window.electronAPI.syncDatabaseToCloud();
                                if (res.success) {
                                    alert("Database backup synced successfully to Google Drive!");
                                    refreshGDriveStatus();
                                } else {
                                    alert("Sync Failed: " + res.error);
                                }

                                syncBtn.disabled = false;
                                syncBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Sync Database to Cloud';
                            });
                        }

                        // Restore Action
                        const restoreBtn = document.getElementById('gdrive-restore-btn');
                        if (restoreBtn) {
                            restoreBtn.addEventListener('click', async () => {
                                if (!confirm("Are you sure you want to restore? Your current database will be overwritten with the cloud backup!")) {
                                    return;
                                }

                                restoreBtn.disabled = true;
                                restoreBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Downloading & Restoring...';

                                const res = await window.electronAPI.restoreDatabaseFromCloud();
                                if (res.success) {
                                    alert("Database successfully restored from Google Drive! The application will reload.");
                                    window.location.reload();
                                } else {
                                    alert("Restore Failed: " + res.error);
                                }

                                restoreBtn.disabled = false;
                                restoreBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Restore Database from Cloud';
                            });
                        }

                        // Disconnect Action
                        const disconnectBtn = document.getElementById('gdrive-disconnect-btn');
                        if (disconnectBtn) {
                            disconnectBtn.addEventListener('click', async () => {
                                if (confirm("Disconnect Google Account? Your saved credentials and backup link will be removed locally.")) {
                                    await window.electronAPI.unlinkGoogleDrive();
                                    refreshGDriveStatus();
                                }
                            });
                        }

                        // Generate Approval Code Action (Plan B Recover tool)
                        const genBtn = document.getElementById('generate-approval-btn');
                        if (genBtn) {
                            genBtn.addEventListener('click', async () => {
                                const reqCode = document.getElementById('recovery_request_code').value.trim();
                                if (!reqCode) {
                                    alert("Please enter a valid 5-digit Request Code.");
                                    return;
                                }

                                genBtn.disabled = true;
                                genBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';

                                try {
                                    const response = await fetch(`index.php?ajax_action=generate_approval&request_code=${reqCode}`);
                                    const data = await response.json();
                                    
                                    if (data.success) {
                                        document.getElementById('approval-code-display').textContent = 'APP-' + data.approval_code;
                                        document.getElementById('approval-result-box').style.display = 'block';
                                    } else {
                                        alert("Error: " + data.error);
                                    }
                                } catch (e) {
                                    alert("Failed to connect to backend generator.");
                                }

                                genBtn.disabled = false;
                                genBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate Approval Code';
                            });
                        }

                        // LAN Network Config Actions
                        async function refreshNetworkStatus() {
                            const net = await window.electronAPI.getNetworkStatus();
                            document.getElementById('display-local-ip').textContent = net.localIp || '127.0.0.1';
                            document.getElementById('network_mode').value = net.mode || 'standalone';
                            document.getElementById('server_ip').value = net.serverIp || '';
                            
                            if (net.mode === 'client') {
                                document.getElementById('server-ip-container').style.display = 'block';
                            } else {
                                document.getElementById('server-ip-container').style.display = 'none';
                            }
                        }

                        const netModeDropdown = document.getElementById('network_mode');
                        if (netModeDropdown) {
                            netModeDropdown.addEventListener('change', (e) => {
                                if (e.target.value === 'client') {
                                    document.getElementById('server-ip-container').style.display = 'block';
                                } else {
                                    document.getElementById('server-ip-container').style.display = 'none';
                                }
                            });
                        }

                        const saveNetBtn = document.getElementById('save-network-btn');
                        if (saveNetBtn) {
                            saveNetBtn.addEventListener('click', async () => {
                                const mode = document.getElementById('network_mode').value;
                                const serverIp = document.getElementById('server_ip').value.trim();
                                
                                if (mode === 'client' && !serverIp) {
                                    alert("Please enter a valid Server IP Address.");
                                    return;
                                }

                                const config = { mode, serverIp };
                                saveNetBtn.disabled = true;
                                saveNetBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

                                const res = await window.electronAPI.saveNetworkConfig(config);
                                if (res.success) {
                                    alert("LAN Configuration saved successfully! The application will restart to apply the settings.");
                                    window.location.reload();
                                } else {
                                    alert("Save Failed.");
                                }
                                saveNetBtn.disabled = false;
                                saveNetBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save & Apply Configuration';
                            });
                        }
                    });
                    </script>

                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
