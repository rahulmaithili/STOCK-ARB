<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
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
                </ul>
            </div>
            
            <div class="card-premium-body">
                <div class="tab-content" id="settingsTabContent">
                    
                    <!-- Tab 1: Profile Settings (Change Password) -->
                    <div class="tab-pane fade show active" id="profile-panel" role="tabpanel" aria-labelledby="profile-tab">
                        <div style="max-width: 500px;">
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

                    <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        // Check if running inside Electron desktop app wrapper
                        if (window.electronAPI) {
                            document.getElementById('gdrive-sync-section').style.display = 'block';
                            document.getElementById('gdrive-web-notice').style.display = 'none';

                            // Load current Google Drive connection status
                            refreshGDriveStatus();
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
