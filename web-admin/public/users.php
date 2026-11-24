<?php
// web-admin/public/users.php
$pageTitle = 'User & Staff Management';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

// Accessible by users with users_manage permission or admin
$currentUser = requirePermission('users_manage', 'Only Administrators and authorized staff with User Management permissions can access this module.');
$pdo = getDbConnection();

// Fetch all defined roles for dynamic role assignment
$rolesStmt = $pdo->query("SELECT * FROM roles ORDER BY is_system DESC, name ASC");
$allRoles = $rolesStmt->fetchAll();
$allRoleSlugs = array_column($allRoles, 'slug');
$rolesMap = [];
foreach ($allRoles as $r) {
    $rolesMap[$r['slug']] = $r;
}

$successMsg = '';
$errorMsg = '';

// Handle Actions (Create, Update, Delete, Toggle Status, Regenerate Token)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', $allRoleSlugs, true) ? $_POST['role'] : 'field_staff';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($username) || empty($fullName) || empty($email) || empty($password)) {
            $errorMsg = 'All fields (Username, Full Name, Email, Password) are required.';
        } elseif (strlen($password) < 6) {
            $errorMsg = 'Password must be at least 6 characters long.';
        } else {
            // Check username or email uniqueness
            $check = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
            $check->execute(['u' => $username, 'e' => $email]);
            if ($check->fetch()) {
                $errorMsg = 'A user with that username or email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $apiToken = bin2hex(random_bytes(32));

                $stmt = $pdo->prepare("
                    INSERT INTO users (username, full_name, email, password_hash, role, api_token, is_active)
                    VALUES (:username, :full_name, :email, :password_hash, :role, :api_token, :is_active)
                ");
                $stmt->execute([
                    'username' => $username,
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => $hash,
                    'role' => $role,
                    'api_token' => $apiToken,
                    'is_active' => $isActive
                ]);
                logSecurityEvent('USER_CREATED', "Created account '{$username}' with role '{$role}'", (int)$currentUser['id'], $currentUser['username']);
                $successMsg = "User account '{$username}' created successfully.";
            }
        }
    } elseif ($action === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['role'] ?? '', $allRoleSlugs, true) ? $_POST['role'] : 'field_staff';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = $_POST['new_password'] ?? '';

        if (!$userId || empty($fullName) || empty($email)) {
            $errorMsg = 'User ID, Full Name, and Email are required.';
        } else {
            // Check email uniqueness excluding current user
            $check = $pdo->prepare("SELECT id FROM users WHERE email = :e AND id != :id LIMIT 1");
            $check->execute(['e' => $email, 'id' => $userId]);
            if ($check->fetch()) {
                $errorMsg = 'Another account already uses that email address.';
            } else {
                // Prevent admin from demoting or deactivating their own currently logged-in account
                if ($userId === (int)$currentUser['id']) {
                    $role = 'admin';
                    $isActive = 1;
                }

                if (!empty($newPassword)) {
                    if (strlen($newPassword) < 6) {
                        $errorMsg = 'New password must be at least 6 characters.';
                    } else {
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("
                            UPDATE users 
                            SET full_name = :full_name, email = :email, role = :role, is_active = :is_active, password_hash = :hash
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'full_name' => $fullName,
                            'email' => $email,
                            'role' => $role,
                            'is_active' => $isActive,
                            'hash' => $hash,
                            'id' => $userId
                        ]);
                        logSecurityEvent('USER_PASSWORD_RESET', "Password reset and profile updated for user ID #{$userId}", (int)$currentUser['id'], $currentUser['username']);
                        $successMsg = "User and password updated successfully.";
                    }
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET full_name = :full_name, email = :email, role = :role, is_active = :is_active
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'full_name' => $fullName,
                        'email' => $email,
                        'role' => $role,
                        'is_active' => $isActive,
                        'id' => $userId
                    ]);
                    logSecurityEvent('USER_UPDATED', "Updated user details for user ID #{$userId}", (int)$currentUser['id'], $currentUser['username']);
                    $successMsg = "User details updated successfully.";
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)$currentUser['id']) {
            $errorMsg = 'You cannot deactivate your own administrative account.';
        } elseif ($userId) {
            $stmt = $pdo->prepare("UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            logSecurityEvent('USER_STATUS_TOGGLED', "Toggled active state for user ID #{$userId}", (int)$currentUser['id'], $currentUser['username']);
            $successMsg = "User status toggled.";
        }
    } elseif ($action === 'regenerate_token') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId) {
            $newToken = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("UPDATE users SET api_token = :token WHERE id = :id");
            $stmt->execute(['token' => $newToken, 'id' => $userId]);
            logSecurityEvent('API_TOKEN_REGENERATED', "Regenerated mobile pairing API token for user ID #{$userId}", (int)$currentUser['id'], $currentUser['username']);
            $successMsg = "API token regenerated successfully.";
        }
    } elseif ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)$currentUser['id']) {
            $errorMsg = 'You cannot delete your own administrative account.';
        } elseif ($userId) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            logSecurityEvent('USER_DELETED', "Permanently deleted user account ID #{$userId}", (int)$currentUser['id'], $currentUser['username']);
            $successMsg = "User deleted successfully.";
        }
    }
}

// Fetch all users with counts of forms created and submissions collected
$usersStmt = $pdo->query("
    SELECT 
        u.*,
        (SELECT COUNT(*) FROM forms f WHERE f.created_by = u.id) AS forms_count,
        (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS submissions_count
    FROM users u
    ORDER BY u.role ASC, u.created_at DESC
");
$users = $usersStmt->fetchAll();

// Statistics
$totalUsers = count($users);
$adminCount = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
$staffCount = count(array_filter($users, fn($u) => in_array($u['role'], ['field_staff', 'surveyor'])));
$activeCount = count(array_filter($users, fn($u) => (int)$u['is_active'] === 1));

// Current host for QR generation
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
$apiUrl = "http://{$host}/api/";

require_once __DIR__ . '/header.php';
?>

<!-- Include QRCode.js for user connect modal -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-1">
            <i class="fa-solid fa-users-gear text-primary me-2"></i>User &amp; Field Staff Management
        </h2>
        <p class="text-muted mb-0">Create staff accounts, assign custom roles, configure form creation permissions, and issue mobile pairing QR codes.</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
            <i class="fa-solid fa-user-plus me-1"></i> Add New User
        </button>
    </div>
</div>

<!-- Navigation Tabs for Users vs Roles -->
<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <a class="nav-link active" href="users.php">
            <i class="fa-solid fa-users me-1"></i> Users &amp; Staff Accounts
        </a>
    </li>
    <?php if (isAdmin() || hasPermission('roles_manage')): ?>
    <li class="nav-item">
        <a class="nav-link text-dark" href="roles.php">
            <i class="fa-solid fa-shield-halved me-1"></i> Roles &amp; Permissions Matrix
        </a>
    </li>
    <?php endif; ?>
</ul>

<?php if ($successMsg): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($successMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($errorMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Summary Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card p-3 border-0 shadow-sm border-start border-primary border-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase">Total Users</span>
                    <h3 class="fw-bold mb-0 mt-1"><?= $totalUsers ?></h3>
                </div>
                <div class="bg-primary-subtle text-primary p-3 rounded-circle">
                    <i class="fa-solid fa-users fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 border-0 shadow-sm border-start border-danger border-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase">Administrators</span>
                    <h3 class="fw-bold mb-0 mt-1"><?= $adminCount ?></h3>
                </div>
                <div class="bg-danger-subtle text-danger p-3 rounded-circle">
                    <i class="fa-solid fa-shield-halved fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 border-0 shadow-sm border-start border-info border-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase">Field Staff</span>
                    <h3 class="fw-bold mb-0 mt-1"><?= $staffCount ?></h3>
                </div>
                <div class="bg-info-subtle text-info p-3 rounded-circle">
                    <i class="fa-solid fa-person-walking-luggage fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 border-0 shadow-sm border-start border-success border-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase">Active Accounts</span>
                    <h3 class="fw-bold mb-0 mt-1"><?= $activeCount ?></h3>
                </div>
                <div class="bg-success-subtle text-success p-3 rounded-circle">
                    <i class="fa-solid fa-user-check fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Users Table Card -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-bottom">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0">System Accounts &amp; Permissions</h5>
            <span class="text-muted small">Showing <?= count($users) ?> registered users</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">User</th>
                    <th>Role &amp; Permissions</th>
                    <th>Status</th>
                    <th class="text-center">Forms Created</th>
                    <th class="text-center">Submissions</th>
                    <th>Last Login</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php 
                        $isSelf = ((int)$u['id'] === (int)$currentUser['id']);
                        $role = $u['role'];
                        $isAdminRole = ($role === 'admin');
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm"
                                     style="width: 42px; height: 42px; background: <?= $isAdminRole ? 'linear-gradient(135deg, #ef4444, #dc2626)' : 'linear-gradient(135deg, #0ea5e9, #0284c7)' ?>; font-size: 1rem;">
                                    <?= strtoupper(substr($u['full_name'] ?: $u['username'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                        <?= htmlspecialchars($u['full_name']) ?>
                                        <?php if ($isSelf): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-0" style="font-size: 0.65rem;">You</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fa-solid fa-at text-muted me-1"></i><?= htmlspecialchars($u['username']) ?> &bull; 
                                        <?= htmlspecialchars($u['email']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php 
                                $rInfo = $rolesMap[$role] ?? null;
                                $rName = $rInfo ? $rInfo['name'] : ucfirst(str_replace('_', ' ', $role));
                                $rDesc = $rInfo['description'] ?? '';
                                if ($isAdminRole) {
                                    $badgeClass = 'bg-danger-subtle text-danger border border-danger-subtle';
                                    $iconClass = 'fa-shield-halved';
                                } elseif (strpos($role, 'supervisor') !== false) {
                                    $badgeClass = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                                    $iconClass = 'fa-user-tie';
                                } elseif (strpos($role, 'viewer') !== false || strpos($role, 'auditor') !== false) {
                                    $badgeClass = 'bg-secondary-subtle text-secondary border border-secondary-subtle';
                                    $iconClass = 'fa-eye';
                                } else {
                                    $badgeClass = 'bg-info-subtle text-info-emphasis border border-info-subtle';
                                    $iconClass = 'fa-person-digging';
                                }
                            ?>
                            <span class="badge <?= $badgeClass ?> px-2 py-1">
                                <i class="fa-solid <?= $iconClass ?> me-1"></i> <?= htmlspecialchars($rName) ?>
                            </span>
                            <div class="small text-muted mt-1" style="font-size: 11px;">
                                <?= htmlspecialchars($rDesc ?: 'Custom configured permissions') ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                    <i class="fa-solid fa-circle-check me-1"></i> Active
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">
                                    <i class="fa-solid fa-ban me-1"></i> Inactive
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="forms.php?creator_id=<?= $u['id'] ?>" class="btn btn-sm btn-light border fw-semibold" title="View forms created by this user">
                                <i class="fa-solid fa-clipboard-list text-primary me-1"></i> <?= $u['forms_count'] ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <a href="submissions.php?user_id=<?= $u['id'] ?>" class="btn btn-sm btn-light border fw-semibold" title="View submissions collected by this user">
                                <i class="fa-solid fa-inbox text-success me-1"></i> <?= $u['submissions_count'] ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($u['last_login_at']): ?>
                                <span class="small text-dark"><?= date('M j, Y H:i', strtotime($u['last_login_at'])) ?></span>
                            <?php else: ?>
                                <span class="small text-muted">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-inline-flex gap-1">
                                <!-- QR Mobile Connect Code -->
                                <button type="button" class="btn btn-sm btn-outline-warning" 
                                        onclick="showUserQrModal(<?= htmlspecialchars(json_encode([
                                            'id' => $u['id'],
                                            'username' => $u['username'],
                                            'full_name' => $u['full_name'],
                                            'role' => $u['role'],
                                            'api_token' => $u['api_token'],
                                            'server_url' => $apiUrl
                                        ])) ?>)"
                                        title="View Mobile App QR Connect Code">
                                    <i class="fa-solid fa-qrcode"></i>
                                </button>

                                <!-- Edit Modal Trigger -->
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u)) ?>)"
                                        title="Edit User">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>

                                <!-- Status Toggle -->
                                <?php if (!$isSelf): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Change active status for this user?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                                                title="<?= $u['is_active'] ? 'Deactivate Account' : 'Activate Account' ?>">
                                            <i class="fa-solid <?= $u['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                        </button>
                                    </form>

                                    <!-- Delete Button -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete user <?= htmlspecialchars($u['username']) ?>?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Account">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Create New User -->
<div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_user">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="createUserModalLabel">
                        <i class="fa-solid fa-user-plus text-primary me-2"></i>Create New User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" placeholder="e.g. Sarah Jenkins" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" placeholder="e.g. sjenkins" required pattern="[A-Za-z0-9_\-\.]{3,30}" title="3-30 letters, numbers, dot, dash, or underscore">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role &amp; Permissions <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" required>
                                <?php foreach ($allRoles as $r): ?>
                                    <option value="<?= htmlspecialchars($r['slug']) ?>" <?= $r['slug'] === 'field_staff' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" placeholder="sarah@example.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="password" placeholder="At least 6 characters" required minlength="6">
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="createIsActive" value="1" checked>
                        <label class="form-check-label fw-semibold" for="createIsActive">Active Account (Can log in &amp; sync)</label>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i> Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit User -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="editUserModalLabel">
                        <i class="fa-solid fa-pen-to-square text-primary me-2"></i>Edit User Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control bg-light" id="editUsername" disabled>
                        <small class="text-muted" style="font-size: 11px;">Username cannot be changed.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="editFullName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" id="editEmail" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role &amp; Permissions <span class="text-danger">*</span></label>
                        <select class="form-select" name="role" id="editRole" required>
                            <?php foreach ($allRoles as $r): ?>
                                <option value="<?= htmlspecialchars($r['slug']) ?>">
                                    <?= htmlspecialchars($r['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reset Password (Optional)</label>
                        <input type="password" class="form-control" name="new_password" placeholder="Leave blank to keep existing password" minlength="6">
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive" value="1">
                        <label class="form-check-label fw-semibold" for="editIsActive">Active Account</label>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: User Mobile App QR Code -->
<div class="modal fade" id="userQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-qrcode text-warning me-2"></i>Mobile App QR Connect
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <h6 class="fw-bold mb-1" id="qrUserName"></h6>
                <p class="text-muted small mb-3">Scan this code with the FieldPlus Android app to authenticate this user immediately.</p>
                <div class="p-3 bg-white rounded-3 border d-inline-block shadow-sm mb-3">
                    <div id="modalQrCanvas" class="d-flex justify-content-center"></div>
                </div>
                <div class="text-start bg-light p-3 rounded border font-monospace small">
                    <div class="mb-1"><strong>Server:</strong> <span id="qrServerUrl"></span></div>
                    <div class="mb-1"><strong>Role:</strong> <span id="qrUserRole"></span></div>
                    <div><strong>Bearer Token:</strong> <span id="qrUserToken" class="text-break text-muted"></span></div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <form method="POST" onsubmit="return confirm('Regenerating will invalidate the current token on mobile devices. Continue?');" class="me-auto">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="regenerate_token">
                    <input type="hidden" name="user_id" id="qrRegenUserId">
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> Regenerate Token
                    </button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openEditUserModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editFullName').value = user.full_name;
    document.getElementById('editEmail').value = user.email;
    document.getElementById('editRole').value = user.role;
    document.getElementById('editIsActive').checked = parseInt(user.is_active) === 1;

    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

let qrCodeInstance = null;

function showUserQrModal(info) {
    document.getElementById('qrUserName').textContent = info.full_name + ' (@' + info.username + ')';
    document.getElementById('qrServerUrl').textContent = info.server_url;
    document.getElementById('qrUserRole').textContent = info.role;
    document.getElementById('qrUserToken').textContent = info.api_token;
    document.getElementById('qrRegenUserId').value = info.id;

    const container = document.getElementById('modalQrCanvas');
    container.innerHTML = '';

    const payload = JSON.stringify({
        server_url: info.server_url,
        api_token: info.api_token,
        surveyor_name: info.full_name,
        username: info.username,
        role: info.role
    });

    qrCodeInstance = new QRCode(container, {
        text: payload,
        width: 200,
        height: 200,
        colorDark: "#0f172a",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.M
    });

    const modal = new bootstrap.Modal(document.getElementById('userQrModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
