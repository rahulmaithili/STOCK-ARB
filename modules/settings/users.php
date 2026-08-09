<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin']); // Only administrators can manage roles

$error = '';
$success = '';

// Process POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_user') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = trim($_POST['role'] ?? 'staff');
            $password = trim($_POST['password'] ?? '');

            if (empty($name) || empty($email) || empty($password)) {
                $error = "All fields are required to create a user.";
            } elseif (!in_array($role, ['admin', 'manager', 'staff'])) {
                $error = "Invalid role value.";
            } else {
                try {
                    // Check if email already exists
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                    $chk->execute([$email]);
                    if ($chk->fetchColumn() > 0) {
                        $error = "Email address already registered.";
                    } else {
                        $hashed = password_hash($password, PASSWORD_BCRYPT);
                        $ins = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
                        $ins->execute([$name, $email, $hashed, $role]);

                        log_activity($pdo, "Created user account: $email ($role)");
                        $success = "User account created successfully.";
                    }
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        } elseif ($action === 'edit_user') {
            $user_id = intval($_POST['user_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = trim($_POST['role'] ?? 'staff');
            $password = trim($_POST['password'] ?? '');

            if ($user_id <= 0 || empty($name) || empty($email)) {
                $error = "All fields are required.";
            } elseif ($user_id == $_SESSION['user_id'] && $role !== 'admin') {
                $error = "You cannot demote yourself from the Administrator role.";
            } else {
                try {
                    // Check duplicate email
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                    $chk->execute([$email, $user_id]);
                    if ($chk->fetchColumn() > 0) {
                        $error = "Email address is already in use by another user.";
                    } else {
                        if (!empty($password)) {
                            $hashed = password_hash($password, PASSWORD_BCRYPT);
                            $upd = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ? WHERE id = ?");
                            $upd->execute([$name, $email, $hashed, $role, $user_id]);
                        } else {
                            $upd = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
                            $upd->execute([$name, $email, $role, $user_id]);
                        }

                        log_activity($pdo, "Updated user account: $email ($role)");
                        $success = "User details updated successfully.";
                    }
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_user') {
            $user_id = intval($_POST['user_id'] ?? 0);

            if ($user_id == $_SESSION['user_id']) {
                $error = "You cannot delete your own active administrator session account.";
            } else {
                try {
                    // Fetch user info for logging
                    $u_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                    $u_stmt->execute([$user_id]);
                    $user_email = $u_stmt->fetchColumn();

                    if ($user_email) {
                        $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $del->execute([$user_id]);

                        log_activity($pdo, "Deleted user account: $user_email");
                        $success = "User account deleted successfully.";
                    } else {
                        $error = "User not found.";
                    }
                } catch (PDOException $e) {
                    $error = "Failed to delete user: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch all users in system
try {
    $stmt = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $users = [];
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-start align-sm-items-center gap-3">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-users-gear me-2 text-primary"></i>Users / Staff</h3>
            <p class="text-muted" style="font-size: 0.95rem; margin: 0;">Manage company user access privileges and status logs.</p>
        </div>
        <div class="d-flex gap-2">
            <!-- Add User Trigger Modal (Mockup Identical Button) -->
            <button class="btn btn-accent bg-primary d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fa-solid fa-user-plus"></i> Add User
            </button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center"><i class="fa-solid fa-arrow-left me-1"></i>Settings</a>
        </div>
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
    <!-- User Directory Table (Full Width) -->
    <div class="col-12">
        <div class="card card-premium shadow-sm border-0" style="border-radius: 12px;">
            <div class="card-premium-header">
                <h5 class="card-premium-title"><i class="fa-solid fa-id-badge me-2 text-primary"></i>Users / Staff Log Sheet</h5>
            </div>
            <div class="card-premium-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle datatable">
                        <thead class="table-light text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: 700;">
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th style="text-align: right; width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Pre-fetch activity counts for all users
                            $user_act_counts = [];
                            foreach ($users as $u) {
                                $user_act_counts[$u['id']] = 0;
                                try {
                                    $act_stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ?");
                                    $act_stmt->execute([$u['id']]);
                                    $user_act_counts[$u['id']] = intval($act_stmt->fetchColumn());
                                } catch (PDOException $e) {}
                            }
                            foreach ($users as $u):
                            ?>
                                <tr>
                                    <td><?php echo $u['id']; ?></td>
                                    <td class="fw-semibold text-dark"><?php echo htmlspecialchars($u['name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td class="text-lowercase"><?php echo htmlspecialchars($u['role']); ?></td>
                                    <td>
                                        <span class="badge bg-success-light text-success border border-success-subtle px-2 py-1" style="font-size: 0.75rem;">active</span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('d M Y', strtotime($u['created_at'])); ?></small></td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button class="btn btn-link text-secondary btn-sm p-1" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $u['id']; ?>" title="View User Profile"><i class="fa-solid fa-eye fa-lg text-primary"></i></button>
                                        <a href="../reports/activity_log.php" class="btn btn-link text-secondary btn-sm p-1" title="User Activity logs"><i class="fa-solid fa-clock-rotate-left fa-lg"></i></a>
                                        
                                        <!-- Actions Dropdown -->
                                        <div class="dropdown d-inline-block ms-1">
                                            <button class="btn btn-light btn-sm text-secondary px-2" type="button" data-bs-toggle="dropdown" data-bs-boundary="document" data-bs-auto-close="true" aria-expanded="false" style="border-radius: 6px;">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 10px; font-size: 0.85rem;">
                                                <li><h6 class="dropdown-header" style="font-size: 0.72rem;">OPEN</h6></li>
                                                <li><button class="dropdown-item py-2" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $u['id']; ?>"><i class="fa-solid fa-eye me-2 text-primary"></i> View Profile</button></li>
                                                <li><a class="dropdown-item py-2" href="../reports/activity_log.php"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i> Activity Log</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><h6 class="dropdown-header" style="font-size: 0.72rem;">MANAGE</h6></li>
                                                <li><button class="dropdown-item py-2" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $u['id']; ?>"><i class="fa-solid fa-user-pen me-2 text-warning"></i> Edit Details</button></li>
                                                <li><button class="dropdown-item py-2 text-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $u['id']; ?>"><i class="fa-solid fa-trash-can me-2"></i> Delete Account</button></li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== USER MODALS (outside table, before footer) ===== -->
<?php foreach ($users as $u):
    $act_count = $user_act_counts[$u['id']];
?>

<!-- View Modal -->
<div class="modal fade" id="viewModal<?php echo $u['id']; ?>" tabindex="-1" aria-labelledby="viewModalLabel<?php echo $u['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
            <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-user-tie text-white"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="viewModalLabel<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?></h6>
                        <small style="opacity: 0.7; font-size: 0.75rem;"><?php echo htmlspecialchars($u['email']); ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <ul class="nav nav-tabs px-4 pt-3 border-bottom bg-light" role="tablist">
                    <li class="nav-item"><button class="nav-link active border-0 fw-bold px-3 py-2" data-bs-toggle="tab" data-bs-target="#profile-panel<?php echo $u['id']; ?>" type="button" style="font-size: 0.88rem;">Profile</button></li>
                    <li class="nav-item"><button class="nav-link border-0 fw-bold px-3 py-2" data-bs-toggle="tab" data-bs-target="#activity-panel<?php echo $u['id']; ?>" type="button" style="font-size: 0.88rem;">Activity <span class="badge bg-secondary ms-1" style="font-size: 0.68rem;"><?php echo $act_count; ?></span></button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active p-4" id="profile-panel<?php echo $u['id']; ?>">
                        <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                            <div class="text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; background: linear-gradient(135deg, #0f172a, #1e293b);">
                                <i class="fa-solid fa-user-tie fa-lg"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($u['name']); ?> <span class="badge bg-light text-secondary border ms-1" style="font-size: 0.68rem;"><?php echo htmlspecialchars($u['role']); ?></span></h6>
                                <small class="text-muted"><i class="fa-solid fa-envelope me-1"></i><?php echo htmlspecialchars($u['email']); ?></small>
                            </div>
                        </div>
                        <table class="table table-sm table-borderless mb-0" style="font-size: 0.88rem;">
                            <tbody>
                                <tr class="border-bottom"><td class="text-muted fw-semibold py-2">Role</td><td class="text-end text-dark fw-semibold"><?php echo htmlspecialchars($u['role']); ?></td></tr>
                                <tr class="border-bottom"><td class="text-muted fw-semibold py-2">Status</td><td class="text-end"><span class="badge bg-success-light text-success">active</span></td></tr>
                                <tr class="border-bottom"><td class="text-muted fw-semibold py-2">Created</td><td class="text-end text-dark"><?php echo date('d M Y, H:i', strtotime($u['created_at'])); ?></td></tr>
                                <tr><td class="text-muted fw-semibold py-2">Activity Count</td><td class="text-end text-dark"><?php echo $act_count; ?> actions</td></tr>
                            </tbody>
                        </table>
                        <div class="alert alert-light border py-2 mt-4 mb-0 text-muted" style="font-size: 0.78rem; border-radius: 8px;">
                            Access is governed by the <strong><?php echo htmlspecialchars($u['role']); ?></strong> role — manage it on the Roles & Permissions page.
                        </div>
                    </div>
                    <div class="tab-pane fade p-4" id="activity-panel<?php echo $u['id']; ?>">
                        <h6 class="fw-bold text-dark mb-3">Recent Activity</h6>
                        <p class="text-muted mb-0">This user has performed <strong><?php echo $act_count; ?></strong> logged actions. <a href="../reports/activity_log.php">View full activity log →</a></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 py-3" style="background: #f8fafc;">
                <button class="btn btn-accent btn-sm" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $u['id']; ?>"><i class="fa-solid fa-user-pen me-1"></i>Edit</button>
                <button type="button" class="btn btn-light btn-sm text-muted" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal<?php echo $u['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?php echo $u['id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
            <form action="users.php" method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                    <h5 class="modal-title fw-bold" id="editModalLabel<?php echo $u['id']; ?>"><i class="fa-solid fa-user-pen me-2"></i>Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3 px-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">USER FULL NAME *</label>
                        <input type="text" class="form-control form-control-premium" name="name" required value="<?php echo htmlspecialchars($u['name']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">EMAIL ADDRESS *</label>
                        <input type="email" class="form-control form-control-premium" name="email" required value="<?php echo htmlspecialchars($u['email']); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">ACCESS ROLE *</label>
                        <select class="form-select form-control-premium" name="role" required>
                            <option value="admin" <?php echo ($u['role'] === 'admin') ? 'selected' : ''; ?>>Administrator (Full Control)</option>
                            <option value="manager" <?php echo ($u['role'] === 'manager') ? 'selected' : ''; ?>>Manager (Invoicing & Products)</option>
                            <option value="staff" <?php echo ($u['role'] === 'staff') ? 'selected' : ''; ?>>Staff (Invoicing Only)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">RESET PASSWORD (LEAVE EMPTY TO KEEP SAME)</label>
                        <input type="password" class="form-control form-control-premium" name="password" placeholder="New Password">
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 py-3" style="background: #f8fafc;">
                    <button type="submit" class="btn btn-accent px-4">Save Changes</button>
                    <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal<?php echo $u['id']; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
            <form action="users.php" method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #7f1d1d, #b91c1c);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-triangle-exclamation me-2"></i>Delete User Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3 px-4">
                    <p class="mb-0">Are you sure you want to permanently delete: <strong><?php echo htmlspecialchars($u['email']); ?></strong>?</p>
                    <small class="text-danger d-block mt-2">This action is irreversible.</small>
                </div>
                <div class="modal-footer border-0 px-4 py-3" style="background: #fff5f5;">
                    <button type="submit" class="btn btn-danger px-4">Confirm Delete</button>
                    <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endforeach; ?>

<!-- Mockup Identical Add User Modal Popup (Clean Modal structure) -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <form action="users.php" method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="add_user">
                
                <div class="modal-header border-0 text-white" style="background-color: var(--navy-sidebar) !important; padding: 18px 24px;">
                    <h5 class="modal-title fw-bold" id="addUserModalLabel"><i class="fa-solid fa-user-plus me-2"></i>Create New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body py-3">
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">USER FULL NAME *</label>
                        <input type="text" class="form-control form-control-premium" id="name" name="name" required placeholder="e.g. Rahul Kumar">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">EMAIL ADDRESS *</label>
                        <input type="email" class="form-control form-control-premium" id="email" name="email" required placeholder="e.g. rahul@test.com">
                    </div>

                    <div class="mb-3">
                        <label for="role" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">ACCESS ROLE *</label>
                        <select class="form-select form-control-premium" id="role" name="role" required>
                            <option value="staff">Staff (Invoicing Only)</option>
                            <option value="manager">Manager (Invoicing & Products)</option>
                            <option value="admin">Administrator (Full Control)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">PASSWORD *</label>
                        <input type="password" class="form-control form-control-premium" id="password" name="password" required placeholder="Password">
                    </div>
                </div>

                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-accent bg-primary px-4">Create Account</button>
                    <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
