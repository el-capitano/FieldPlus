<?php
// web-admin/public/audit_logs.php
$pageTitle = 'Security & Audit Logs';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$currentUser = requirePermission('audit_view', 'You do not have permission to view security and audit logs.');
$pdo = getDbConnection();

$actionSuccess = '';
$actionError = '';

// Handle Reset Rate-Limit Lockout POST action
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $action = $_POST['action'];

    if ($action === 'clear_lockout') {
        if (!isAdmin($currentUser)) {
            $actionError = 'Only administrators can unlock rate-limited IP addresses or usernames.';
        } else {
            $unlockTarget = trim($_POST['unlock_target'] ?? '');
            if ($unlockTarget !== '') {
                $delStmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = :target OR username = :target");
                $delStmt->execute(['target' => $unlockTarget]);
                $clearedCount = $delStmt->rowCount();

                logSecurityEvent('SECURITY_LOCKOUT_CLEARED', "Admin cleared {$clearedCount} login attempt records for target: '{$unlockTarget}'", (int)$currentUser['id'], $currentUser['username']);
                $actionSuccess = "Rate-limit lockout successfully cleared for '{$unlockTarget}' ({$clearedCount} attempts reset).";
            }
        }
    } elseif ($action === 'clear_old_logs') {
        if (!isAdmin($currentUser)) {
            $actionError = 'Only administrators can purge archived logs.';
        } else {
            $days = (int)($_POST['purge_days'] ?? 30);
            if ($days < 7) {
                $days = 7; // Prevent accidental wipe of recent logs
            }
            $purgeStmt = $pdo->prepare("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
            $purgeStmt->execute(['days' => $days]);
            $purgedCount = $purgeStmt->rowCount();

            logSecurityEvent('AUDIT_LOGS_PURGED', "Admin purged {$purgedCount} audit logs older than {$days} days", (int)$currentUser['id'], $currentUser['username']);
            $actionSuccess = "Successfully purged {$purgedCount} audit log records older than {$days} days.";
        }
    }
}

// Filters
$filterAction = trim($_GET['action_filter'] ?? '');
$filterUser = trim($_GET['user_filter'] ?? '');
$search = trim($_GET['search'] ?? '');
$export = $_GET['export'] ?? '';

// Build Query
$sql = "SELECT * FROM audit_logs WHERE 1=1";
$params = [];

if ($filterAction !== '') {
    $sql .= " AND action = :act";
    $params['act'] = $filterAction;
}

if ($filterUser !== '') {
    $sql .= " AND username = :uname";
    $params['uname'] = $filterUser;
}

if ($search !== '') {
    $sql .= " AND (details LIKE :search OR ip_address LIKE :search OR user_agent LIKE :search)";
    $params['search'] = "%{$search}%";
}

$sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export CSV handler
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=security_audit_logs_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Timestamp', 'User ID', 'Username', 'Action', 'Details', 'IP Address', 'User Agent']);

    foreach ($logs as $l) {
        fputcsv($output, [
            $l['id'],
            $l['created_at'],
            $l['user_id'] ?? 'N/A',
            $l['username'] ?? 'Anonymous',
            $l['action'],
            $l['details'],
            $l['ip_address'],
            $l['user_agent']
        ]);
    }
    fclose($output);
    exit();
}

// Distinct actions and usernames for filter dropdowns
$distinctActions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$distinctUsers = $pdo->query("SELECT DISTINCT username FROM audit_logs WHERE username IS NOT NULL AND username != '' ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);

// Active Login Lockouts / Failed Attempts Summary (last 15 minutes)
$lockouts = $pdo->query("
    SELECT ip_address, username, COUNT(*) as attempts_count, MAX(attempt_time) as last_attempt
    FROM login_attempts
    WHERE attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    GROUP BY ip_address, username
    HAVING attempts_count >= 3
    ORDER BY attempts_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Total logs count & 24h count
$totalLogsCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$recent24hCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

require_once __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-1">
            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Security &amp; Audit Logs
        </h2>
        <p class="text-muted mb-0">Real-time surveillance of user authentication, form operations, role assignments, and security alerts (OWASP A09).</p>
    </div>
    <div class="d-flex gap-2">
        <a href="?action_filter=<?= urlencode($filterAction) ?>&user_filter=<?= urlencode($filterUser) ?>&search=<?= urlencode($search) ?>&export=csv" class="btn btn-outline-success">
            <i class="fa-solid fa-file-csv me-1"></i> Export Filtered Logs (CSV)
        </a>
        <?php if (isAdmin($currentUser)): ?>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#purgeLogsModal">
                <i class="fa-solid fa-broom me-1"></i> Retention Maintenance
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($actionSuccess)): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($actionSuccess) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (!empty($actionError)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($actionError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Overview Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 stat-card" style="border-left-color: #2563eb;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Total Audit Events</span>
                    <h3 class="fw-bold mt-1 mb-0"><?= number_format($totalLogsCount) ?></h3>
                </div>
                <div class="bg-primary-subtle text-primary p-3 rounded-circle">
                    <i class="fa-solid fa-shield-halved fa-xl"></i>
                </div>
            </div>
            <div class="mt-2 text-muted" style="font-size: 0.8rem;">
                <i class="fa-solid fa-database text-primary me-1"></i> Immutable Trail
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 stat-card" style="border-left-color: #10b981;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Events Past 24h</span>
                    <h3 class="fw-bold mt-1 mb-0"><?= number_format($recent24hCount) ?></h3>
                </div>
                <div class="bg-success-subtle text-success p-3 rounded-circle">
                    <i class="fa-solid fa-bolt fa-xl"></i>
                </div>
            </div>
            <div class="mt-2 text-muted" style="font-size: 0.8rem;">
                <i class="fa-solid fa-chart-line text-success me-1"></i> Active Operations
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 stat-card" style="border-left-color: <?= count($lockouts) > 0 ? '#ef4444' : '#64748b' ?>;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Active Rate-Limit Alerts</span>
                    <h3 class="fw-bold mt-1 mb-0 text-<?= count($lockouts) > 0 ? 'danger' : 'secondary' ?>"><?= count($lockouts) ?></h3>
                </div>
                <div class="bg-<?= count($lockouts) > 0 ? 'danger' : 'secondary' ?>-subtle text-<?= count($lockouts) > 0 ? 'danger' : 'secondary' ?> p-3 rounded-circle">
                    <i class="fa-solid fa-user-lock fa-xl"></i>
                </div>
            </div>
            <div class="mt-2 text-muted" style="font-size: 0.8rem;">
                <i class="fa-solid fa-ban text-danger me-1"></i> Threshold &ge; 3 failed in 15m
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 stat-card" style="border-left-color: #f59e0b;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Displaying</span>
                    <h3 class="fw-bold mt-1 mb-0"><?= count($logs) ?> <span class="fs-6 fw-normal text-muted">records</span></h3>
                </div>
                <div class="bg-warning-subtle text-warning p-3 rounded-circle">
                    <i class="fa-solid fa-filter fa-xl"></i>
                </div>
            </div>
            <div class="mt-2 text-muted" style="font-size: 0.8rem;">
                <i class="fa-solid fa-magnifying-glass text-warning me-1"></i> Max 500 per query
            </div>
        </div>
    </div>
</div>

<!-- Active Lockouts Banner if any -->
<?php if (!empty($lockouts)): ?>
    <div class="card border-danger shadow-sm mb-4">
        <div class="card-header bg-danger text-white py-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold"><i class="fa-solid fa-triangle-exclamation me-2"></i>Rate-Limiting Lockout Triggers (Last 15 Minutes)</span>
            <span class="badge bg-white text-danger"><?= count($lockouts) ?> Suspicious IP / User Targets</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>IP Address</th>
                            <th>Username Target</th>
                            <th>Failed Attempts</th>
                            <th>Last Attempt</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lockouts as $lo): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($lo['ip_address']) ?></code></td>
                                <td><span class="badge bg-secondary">@<?= htmlspecialchars($lo['username']) ?></span></td>
                                <td><span class="badge bg-danger px-2 py-1"><?= (int)$lo['attempts_count'] ?> failures</span></td>
                                <td class="text-muted small"><?= htmlspecialchars($lo['last_attempt']) ?></td>
                                <td class="text-end pe-4">
                                    <?php if (isAdmin($currentUser)): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Unlock <?= htmlspecialchars($lo['ip_address']) ?> / <?= htmlspecialchars($lo['username']) ?> immediately?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="clear_lockout">
                                            <input type="hidden" name="unlock_target" value="<?= htmlspecialchars($lo['ip_address']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fa-solid fa-unlock me-1"></i> Reset Lockout (IP)
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">Admin Unlock Required</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Filter by Action Event</label>
                <select name="action_filter" class="form-select" onchange="this.form.submit()">
                    <option value="">All Events (<?= count($distinctActions) ?> types)</option>
                    <?php foreach ($distinctActions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Filter by Username</label>
                <select name="user_filter" class="form-select" onchange="this.form.submit()">
                    <option value="">All Users (<?= count($distinctUsers) ?>)</option>
                    <?php foreach ($distinctUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>>
                            @<?= htmlspecialchars($u) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold small">Search Details / IP / Agent</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search keywords..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-filter me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Audit Logs Table -->
<div class="card shadow-sm">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fa-solid fa-list-check me-2 text-primary"></i>Audit Events Stream</span>
        <span class="badge bg-secondary-subtle text-secondary"><?= count($logs) ?> Events Displayed</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width: 170px;">Timestamp</th>
                        <th style="width: 160px;">User</th>
                        <th style="width: 200px;">Event Action</th>
                        <th>Details</th>
                        <th style="width: 140px;">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-shield-cat fa-3x mb-3 text-secondary"></i>
                                <p class="mb-0">No audit log entries match your active filters.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $act = $log['action'];
                            $badgeColor = 'bg-secondary-subtle text-secondary border';
                            if (strpos($act, 'LOGIN_SUCCESS') !== false) {
                                $badgeColor = 'bg-success-subtle text-success border border-success-subtle';
                            } elseif (strpos($act, 'FAIL') !== false || strpos($act, 'REJECT') !== false || strpos($act, 'DELETE') !== false) {
                                $badgeColor = 'bg-danger-subtle text-danger border border-danger-subtle';
                            } elseif (strpos($act, 'UPDATE') !== false || strpos($act, 'CREATE') !== false) {
                                $badgeColor = 'bg-primary-subtle text-primary border border-primary-subtle';
                            } elseif (strpos($act, 'LOCKOUT') !== false || strpos($act, 'FLAG') !== false) {
                                $badgeColor = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                            }
                            ?>
                            <tr>
                                <td class="text-muted small">
                                    <i class="fa-regular fa-clock me-1"></i><?= htmlspecialchars($log['created_at']) ?>
                                </td>
                                <td>
                                    <?php if (!empty($log['username'])): ?>
                                        <span class="badge bg-light text-dark border">
                                            <i class="fa-solid fa-user me-1 text-secondary"></i>@<?= htmlspecialchars($log['username']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small"><em>System / Anonymous</em></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $badgeColor ?> fw-semibold"><?= htmlspecialchars($act) ?></span>
                                </td>
                                <td>
                                    <div class="text-break"><?= htmlspecialchars($log['details']) ?></div>
                                    <?php if (!empty($log['user_agent'])): ?>
                                        <small class="text-muted d-block text-truncate" style="max-width: 500px; font-size: 11px;" title="<?= htmlspecialchars($log['user_agent']) ?>">
                                            <i class="fa-solid fa-globe me-1"></i><?= htmlspecialchars($log['user_agent']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?= htmlspecialchars($log['ip_address'] ?: '127.0.0.1') ?></code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (isAdmin($currentUser)): ?>
    <!-- Modal: Retention Maintenance -->
    <div class="modal fade" id="purgeLogsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="clear_old_logs">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold text-danger">
                            <i class="fa-solid fa-trash-can me-2"></i>Audit Retention Maintenance
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Purging older audit logs helps optimize database storage while preserving your required regulatory compliance window.</p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Purge logs older than:</label>
                            <select name="purge_days" class="form-select">
                                <option value="90">90 Days (Recommended for production)</option>
                                <option value="60">60 Days</option>
                                <option value="30">30 Days</option>
                                <option value="14">14 Days</option>
                                <option value="7">7 Days (Minimum retention limit)</option>
                            </select>
                        </div>
                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> Purged log records are permanently deleted and cannot be recovered.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Execute Log Purge</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
