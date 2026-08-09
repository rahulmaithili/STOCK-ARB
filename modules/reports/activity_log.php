<?php
require_once dirname(dirname(__DIR__)) . '/config/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();
has_role(['admin']);

$error = '';
$success = '';

// -------- BULK DELETE HANDLER --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security validation failed. Reload the page.";
    } else {
        $action = $_POST['bulk_action'] ?? '';

        if ($action === 'delete_selected') {
            $ids = $_POST['log_ids'] ?? [];
            $ids = array_filter(array_map('intval', $ids));

            if (empty($ids)) {
                $error = "Please select at least one log entry to delete.";
            } else {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $del = $pdo->prepare("DELETE FROM activity_log WHERE id IN ($placeholders)");
                $del->execute($ids);
                $count = $del->rowCount();
                log_activity($pdo, "Bulk deleted $count audit log entries.");
                $success = "$count log entries deleted successfully.";
            }

        } elseif ($action === 'delete_all') {
            $pdo->exec("TRUNCATE TABLE activity_log");
            log_activity($pdo, "Cleared all system audit trail logs.");
            $success = "All audit trail logs cleared successfully.";

        } elseif ($action === 'delete_older') {
            $days = intval($_POST['older_than_days'] ?? 30);
            $del = $pdo->prepare("DELETE FROM activity_log WHERE logged_at < NOW() - INTERVAL ? DAY");
            $del->execute([$days]);
            $count = $del->rowCount();
            log_activity($pdo, "Deleted $count audit logs older than $days days.");
            $success = "$count old log entries (older than $days days) deleted.";
        }
    }
}

// -------- FILTER PARAMS --------
$filter_user = trim($_GET['user'] ?? '');
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');
$filter_keyword = trim($_GET['keyword'] ?? '');

// -------- FETCH LOGS --------
try {
    $where = [];
    $params = [];

    if (!empty($filter_user)) {
        $where[] = "u.name LIKE ?";
        $params[] = "%$filter_user%";
    }
    if (!empty($filter_date_from)) {
        $where[] = "al.logged_at >= ?";
        $params[] = $filter_date_from . ' 00:00:00';
    }
    if (!empty($filter_date_to)) {
        $where[] = "al.logged_at <= ?";
        $params[] = $filter_date_to . ' 23:59:59';
    }
    if (!empty($filter_keyword)) {
        $where[] = "al.action LIKE ?";
        $params[] = "%$filter_keyword%";
    }

    $sql = "
        SELECT al.*, u.name as user_name, u.email as user_email
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
    ";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY al.logged_at DESC LIMIT 1000";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Total count
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM activity_log");
    $total_logs = $total_stmt->fetchColumn();

} catch (PDOException $e) {
    $logs = [];
    $total_logs = 0;
    $error = "Failed to load audit logs: " . $e->getMessage();
}

// Fetch unique users for filter dropdown
try {
    $users_stmt = $pdo->query("SELECT DISTINCT u.name FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE u.name IS NOT NULL ORDER BY u.name");
    $filter_users_list = $users_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $filter_users_list = [];
}

require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<!-- Page Header -->
<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
        <div>
            <h3 class="fw-bold" style="color: var(--navy-dark);"><i class="fa-solid fa-clock-rotate-left me-2 text-success"></i>System Audit Trail</h3>
            <p class="text-muted mb-0" style="font-size: 0.95rem;">Track all employee activities, login events, and manual adjustments.</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#clearOlderModal">
                <i class="fa-solid fa-broom"></i> Clear Older
            </button>
            <button class="btn btn-outline-danger btn-sm d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#clearAllModal">
                <i class="fa-solid fa-trash-can"></i> Clear All
            </button>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success d-flex align-items-center gap-2"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- KPI Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3 px-2" style="border-radius: 12px; background: linear-gradient(135deg, #0f172a, #1e293b);">
            <div class="text-white opacity-70 fw-semibold mb-1" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.07em;">Total Logs</div>
            <div class="text-white fw-bold" style="font-size: 1.5rem;"><?php echo number_format($total_logs); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3 px-2" style="border-radius: 12px; background: linear-gradient(135deg, #065f46, #047857);">
            <div class="text-white opacity-70 fw-semibold mb-1" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.07em;">Showing</div>
            <div class="text-white fw-bold" style="font-size: 1.5rem;"><?php echo count($logs); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3 px-2" style="border-radius: 12px; background: linear-gradient(135deg, #7c2d12, #c2410c);">
            <div class="text-white opacity-70 fw-semibold mb-1" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.07em;">Active Users</div>
            <div class="text-white fw-bold" style="font-size: 1.5rem;"><?php echo count($filter_users_list); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3 px-2" style="border-radius: 12px; background: linear-gradient(135deg, #1e3a5f, #2563eb);">
            <div class="text-white opacity-70 fw-semibold mb-1" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.07em;">Selected</div>
            <div class="text-white fw-bold" id="selectedCount" style="font-size: 1.5rem;">0</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card card-premium mb-4">
    <div class="card-premium-body">
        <form method="GET" action="activity_log.php" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold text-muted" style="font-size: 0.75rem;">FILTER BY USER</label>
                <select class="form-select form-control-premium" name="user">
                    <option value="">All Users</option>
                    <?php foreach ($filter_users_list as $u): ?>
                        <option value="<?php echo htmlspecialchars($u); ?>" <?php echo ($filter_user === $u) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold text-muted" style="font-size: 0.75rem;">FROM DATE</label>
                <input type="date" class="form-control form-control-premium" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold text-muted" style="font-size: 0.75rem;">TO DATE</label>
                <input type="date" class="form-control form-control-premium" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold text-muted" style="font-size: 0.75rem;">KEYWORD SEARCH</label>
                <input type="text" class="form-control form-control-premium" name="keyword" placeholder="e.g. login, invoice, delete..." value="<?php echo htmlspecialchars($filter_keyword); ?>">
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-accent flex-fill"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                <a href="activity_log.php" class="btn btn-light text-muted flex-fill"><i class="fa-solid fa-xmark"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Logs Table with Bulk Select -->
<form method="POST" action="activity_log.php" id="bulkForm">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="bulk_action" id="bulk_action_input" value="">

    <div class="card card-premium">
        <div class="card-premium-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h5 class="card-premium-title mb-0"><i class="fa-solid fa-shield-halved me-2"></i>Audit Log Entries</h5>
            <div class="d-flex gap-2 align-items-center" id="bulkActionsBar" style="display:none!important;">
                <span class="text-muted" style="font-size: 0.82rem;" id="selectedLabel">0 selected</span>
                <button type="button" class="btn btn-danger btn-sm px-3" onclick="confirmBulkDelete()" id="btnDeleteSelected" disabled>
                    <i class="fa-solid fa-trash-can me-1"></i> Delete Selected
                </button>
            </div>
        </div>
        <div class="card-premium-body p-0">
            <?php if (empty($logs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-folder-open fa-3x mb-3 text-secondary" style="opacity: 0.4;"></i>
                    <h5>No audit logs found.</h5>
                    <p style="font-size: 0.9rem;">Try adjusting your filters or the log table may be empty.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.87rem;">
                        <thead class="table-light text-uppercase text-muted" style="font-size: 0.7rem; letter-spacing: 0.05em; font-weight: 700;">
                            <tr>
                                <th style="width: 42px; text-align: center;">
                                    <input type="checkbox" id="selectAll" class="form-check-input" title="Select All" style="cursor: pointer; width: 16px; height: 16px;">
                                </th>
                                <th style="width: 160px;">Timestamp</th>
                                <th style="width: 180px;">Performed By</th>
                                <th>Action Logged</th>
                                <th style="width: 70px; text-align: center;">Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr class="log-row">
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="log_ids[]" value="<?php echo $log['id']; ?>" class="form-check-input log-checkbox" style="cursor: pointer; width: 16px; height: 16px;">
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo date('d M Y', strtotime($log['logged_at'])); ?></div>
                                        <small class="text-muted"><?php echo date('h:i:s A', strtotime($log['logged_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?php echo htmlspecialchars($log['user_name'] ?: 'System'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($log['user_email'] ?: '-'); ?></small>
                                    </td>
                                    <td>
                                        <span class="text-dark" style="font-size: 0.9rem;"><?php echo htmlspecialchars($log['action']); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <button type="button" class="btn btn-outline-danger btn-sm px-2 py-1 delete-single-btn"
                                            data-id="<?php echo $log['id']; ?>"
                                            style="border-radius: 6px;"
                                            title="Delete this log">
                                            <i class="fa-solid fa-trash-can" style="font-size: 0.75rem;"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- ===== MODALS ===== -->

<!-- Clear Older Logs Modal -->
<div class="modal fade" id="clearOlderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden;">
            <form method="POST" action="activity_log.php">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="bulk_action" value="delete_older">
                <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #78350f, #d97706);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-broom me-2"></i>Clear Old Logs</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <p class="text-muted mb-3">Delete all audit log entries older than a specified number of days.</p>
                    <label class="form-label fw-semibold text-muted" style="font-size: 0.8rem;">DELETE LOGS OLDER THAN</label>
                    <div class="input-group">
                        <input type="number" class="form-control form-control-premium" name="older_than_days" min="1" max="3650" value="30" required>
                        <span class="input-group-text bg-light text-muted">days</span>
                    </div>
                    <small class="text-muted d-block mt-2">E.g. enter 30 to delete all logs from 30+ days ago.</small>
                </div>
                <div class="modal-footer border-0 px-4 py-3" style="background: #fffbeb;">
                    <button type="submit" class="btn btn-warning px-4 fw-semibold"><i class="fa-solid fa-broom me-1"></i>Clear Old Logs</button>
                    <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Clear All Logs Modal -->
<div class="modal fade" id="clearAllModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden;">
            <form method="POST" action="activity_log.php">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="bulk_action" value="delete_all">
                <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #7f1d1d, #b91c1c);">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-triangle-exclamation me-2"></i>Clear ALL Logs</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <div class="alert alert-danger border-0 py-3 mb-3" style="border-radius: 10px; background: #fff5f5;">
                        <strong><i class="fa-solid fa-triangle-exclamation me-1"></i>Warning:</strong> This will permanently delete ALL <strong><?php echo number_format($total_logs); ?> audit log entries</strong> from the database. This cannot be undone.
                    </div>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Are you sure you want to completely wipe the system audit trail?</p>
                </div>
                <div class="modal-footer border-0 px-4 py-3" style="background: #fff5f5;">
                    <button type="submit" class="btn btn-danger px-4 fw-semibold"><i class="fa-solid fa-fire me-1"></i>Yes, Clear All Logs</button>
                    <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Delete Confirm Modal -->
<div class="modal fade" id="bulkDeleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header border-0 text-white px-4 py-3" style="background: linear-gradient(135deg, #7f1d1d, #b91c1c);">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-trash-can me-2"></i>Delete Selected Logs</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-4">
                <p class="mb-0">Are you sure you want to delete <strong id="bulkDeleteCount">0</strong> selected log entries? This action is irreversible.</p>
            </div>
            <div class="modal-footer border-0 px-4 py-3" style="background: #fff5f5;">
                <button type="button" class="btn btn-danger px-4 fw-semibold" onclick="submitBulkDelete()"><i class="fa-solid fa-trash-can me-1"></i>Confirm Delete</button>
                <button type="button" class="btn btn-light text-muted" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
// ---- Checkbox logic ----
const selectAll    = document.getElementById('selectAll');
const checkboxes   = document.querySelectorAll('.log-checkbox');
const selectedCount = document.getElementById('selectedCount');
const selectedLabel = document.getElementById('selectedLabel');
const btnDeleteSel  = document.getElementById('btnDeleteSelected');
const bulkBar       = document.getElementById('bulkActionsBar');

function updateSelectionUI() {
    const checked = document.querySelectorAll('.log-checkbox:checked');
    const count = checked.length;
    selectedCount.textContent = count;
    selectedLabel.textContent = count + ' selected';
    btnDeleteSel.disabled = count === 0;
    bulkBar.style.display = (count > 0) ? 'flex' : 'none';
    selectAll.indeterminate = count > 0 && count < checkboxes.length;
    selectAll.checked = count === checkboxes.length && checkboxes.length > 0;
}

selectAll.addEventListener('change', function () {
    checkboxes.forEach(cb => { cb.checked = this.checked; });
    updateSelectionUI();
});

checkboxes.forEach(cb => {
    cb.addEventListener('change', updateSelectionUI);
});

// Row click-to-select
document.querySelectorAll('.log-row').forEach(row => {
    row.addEventListener('click', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' || e.target.tagName === 'I') return;
        const cb = row.querySelector('.log-checkbox');
        if (cb) { cb.checked = !cb.checked; updateSelectionUI(); }
    });
});

// Bulk delete: open confirm modal
function confirmBulkDelete() {
    const count = document.querySelectorAll('.log-checkbox:checked').length;
    document.getElementById('bulkDeleteCount').textContent = count;
    const modal = new bootstrap.Modal(document.getElementById('bulkDeleteConfirmModal'));
    modal.show();
}

// Bulk delete: actually submit
function submitBulkDelete() {
    document.getElementById('bulk_action_input').value = 'delete_selected';
    document.getElementById('bulkForm').submit();
}

// Single row delete button
document.querySelectorAll('.delete-single-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const id = this.getAttribute('data-id');
        // Tick only this checkbox and submit
        checkboxes.forEach(cb => { cb.checked = (cb.value == id); });
        updateSelectionUI();
        confirmBulkDelete();
    });
});

// Initial state
updateSelectionUI();
</script>

<?php
require_once dirname(dirname(__DIR__)) . '/includes/footer.php';
?>
