<?php
// web-admin/public/roles.php
$pageTitle = 'Roles & Permissions Management';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

// Only users with roles_manage permission (or admin) can access
$currentUser = requirePermission('roles_manage', 'Only Administrators with role management permissions can access this page.');
$pdo = getDbConnection();

$successMsg = '';
$errorMsg = '';

$definedPermissions = getAllDefinedPermissions();
$allPermissionKeys = getAllPermissionKeys();

// Handle Actions (Create, Update, Delete Role)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_role') {
        $slug = strtolower(trim($_POST['slug'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $selectedPerms = $_POST['permissions'] ?? [];

        if (empty($slug) || empty($name)) {
            $errorMsg = 'Role identifier (slug) and display name are required.';
        } elseif (!preg_match('/^[a-z0-9_]{2,40}$/', $slug)) {
            $errorMsg = 'Role identifier must be 2-40 lowercase letters, numbers, or underscores (e.g. site_inspector).';
        } else {
            // Check uniqueness
            $chk = $pdo->prepare("SELECT id FROM roles WHERE slug = :slug LIMIT 1");
            $chk->execute(['slug' => $slug]);
            if ($chk->fetch()) {
                $errorMsg = "A role with identifier '{$slug}' already exists.";
            } else {
                // Filter only valid permissions
                $validPerms = array_values(array_intersect($selectedPerms, $allPermissionKeys));

                $stmt = $pdo->prepare("
                    INSERT INTO roles (slug, name, description, is_system, permissions_json)
                    VALUES (:slug, :name, :description, 0, :perms)
                ");
                $stmt->execute([
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $description,
                    'perms' => json_encode($validPerms)
                ]);
                logSecurityEvent('ROLE_CREATED', "Created role '{$slug}' ({$name})", (int)$currentUser['id'], $currentUser['username']);
                $successMsg = "Custom role '{$name}' created successfully.";
            }
        }
    } elseif ($action === 'update_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $selectedPerms = $_POST['permissions'] ?? [];

        $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch();

        if (!$role) {
            $errorMsg = 'Role not found.';
        } elseif (empty($name)) {
            $errorMsg = 'Display name cannot be empty.';
        } else {
            // If it is admin, all permissions stay enabled
            if ($role['slug'] === 'admin') {
                $validPerms = $allPermissionKeys;
            } else {
                $validPerms = array_values(array_intersect($selectedPerms, $allPermissionKeys));
            }

            $stmtUpd = $pdo->prepare("
                UPDATE roles 
                SET name = :name, description = :description, permissions_json = :perms
                WHERE id = :id
            ");
            $stmtUpd->execute([
                'name' => $name,
                'description' => $description,
                'perms' => json_encode($validPerms),
                'id' => $roleId
            ]);
            logSecurityEvent('ROLE_UPDATED', "Updated role permissions for '{$role['slug']}'", (int)$currentUser['id'], $currentUser['username']);
            $successMsg = "Role '{$name}' permissions updated successfully.";
        }
    } elseif ($action === 'delete_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch();

        if (!$role) {
            $errorMsg = 'Role not found.';
        } elseif ((int)$role['is_system'] === 1 || $role['slug'] === 'admin') {
            $errorMsg = 'System protected roles cannot be deleted.';
        } else {
            // Check if any users have this role assigned
            $userCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = :role");
            $userCheck->execute(['role' => $role['slug']]);
            $assignedCount = (int)$userCheck->fetchColumn();

            if ($assignedCount > 0) {
                $errorMsg = "Cannot delete role '{$role['name']}': {$assignedCount} active user(s) currently have this role. Reassign their roles first in User Management.";
            } else {
                $pdo->prepare("DELETE FROM roles WHERE id = :id")->execute(['id' => $roleId]);
                logSecurityEvent('ROLE_DELETED', "Deleted custom role '{$role['slug']}'", (int)$currentUser['id'], $currentUser['username']);
                $successMsg = "Role '{$role['name']}' deleted successfully.";
            }
        }
    }
}

// Fetch all roles with assigned users count
$rolesStmt = $pdo->query("
    SELECT r.*,
           (SELECT COUNT(*) FROM users u WHERE u.role = r.slug) AS users_count
    FROM roles r
    ORDER BY r.is_system DESC, r.name ASC
");
$roles = $rolesStmt->fetchAll();

require_once __DIR__ . '/header.php';
?>

<!-- Management Navigation Tabs -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-1">
            <i class="fa-solid fa-user-shield text-primary me-2"></i>Roles &amp; Module Permissions
        </h2>
        <p class="text-muted mb-0">Define custom staff roles, configure read/write permissions, and control access to organization modules.</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRoleModal">
            <i class="fa-solid fa-plus me-1"></i> Add Custom Role
        </button>
    </div>
</div>

<!-- Navigation Tabs for Users vs Roles -->
<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'users.php') ? 'active' : 'text-dark' ?>" href="users.php">
            <i class="fa-solid fa-users me-1"></i> Users &amp; Staff Accounts
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="roles.php">
            <i class="fa-solid fa-shield-halved me-1"></i> Roles &amp; Permissions Matrix
        </a>
    </li>
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

<!-- Roles Matrix Table Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0">Active System Roles</h5>
        <span class="text-muted small">Showing <?= count($roles) ?> configured roles</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">Role &amp; Identifier</th>
                    <th>Description</th>
                    <th class="text-center">Assigned Users</th>
                    <th>Permissions Granted</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $r): ?>
                    <?php
                        $isSys = ((int)$r['is_system'] === 1 || $r['slug'] === 'admin');
                        $perms = json_decode($r['permissions_json'], true) ?: [];
                        $permCount = count($perms);
                        $totalPossible = count($allPermissionKeys);
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-3 p-2 d-flex align-items-center justify-content-center text-white fw-bold shadow-sm"
                                     style="width: 38px; height: 38px; background: <?= $r['slug'] === 'admin' ? '#ef4444' : '#2563eb' ?>;">
                                    <i class="fa-solid <?= $r['slug'] === 'admin' ? 'fa-crown' : 'fa-user-tag' ?>"></i>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                        <?= htmlspecialchars($r['name']) ?>
                                        <?php if ($isSys): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-0" style="font-size: 0.65rem;">System</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-0" style="font-size: 0.65rem;">Custom</span>
                                        <?php endif; ?>
                                    </div>
                                    <code class="text-muted small">@<?= htmlspecialchars($r['slug']) ?></code>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="small text-muted"><?= htmlspecialchars($r['description'] ?: 'No description provided') ?></span>
                        </td>
                        <td class="text-center">
                            <a href="users.php" class="badge bg-light text-dark border text-decoration-none px-3 py-2 fw-semibold" title="View users with this role">
                                <i class="fa-solid fa-users me-1 text-primary"></i> <?= $r['users_count'] ?> users
                            </a>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height: 6px; width: 100px;">
                                    <div class="progress-bar bg-success" style="width: <?= round(($permCount / max($totalPossible, 1)) * 100) ?>%"></div>
                                </div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small">
                                    <?= $permCount ?> / <?= $totalPossible ?> permissions
                                </span>
                            </div>
                            <div class="mt-1 d-flex flex-wrap gap-1">
                                <?php if (in_array('forms_write', $perms)): ?>
                                    <span class="badge bg-light text-secondary border" style="font-size: 10px;">Forms: Write</span>
                                <?php elseif (in_array('forms_read', $perms)): ?>
                                    <span class="badge bg-light text-secondary border" style="font-size: 10px;">Forms: Read</span>
                                <?php endif; ?>

                                <?php if (in_array('submissions_write', $perms)): ?>
                                    <span class="badge bg-light text-secondary border" style="font-size: 10px;">Submissions: Audit</span>
                                <?php elseif (in_array('submissions_read', $perms)): ?>
                                    <span class="badge bg-light text-secondary border" style="font-size: 10px;">Submissions: Read</span>
                                <?php endif; ?>

                                <?php if (in_array('mobile_collect', $perms)): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size: 10px;">Mobile Collect</span>
                                <?php endif; ?>
                                <?php if (in_array('users_manage', $perms)): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 10px;">User Admin</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-inline-flex gap-1">
                                <!-- Edit Role Button -->
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="openEditRoleModal(<?= htmlspecialchars(json_encode([
                                            'id' => $r['id'],
                                            'slug' => $r['slug'],
                                            'name' => $r['name'],
                                            'description' => $r['description'],
                                            'is_system' => $r['is_system'],
                                            'permissions' => $perms
                                        ])) ?>)"
                                        title="Configure Role & Permissions">
                                    <i class="fa-solid fa-sliders me-1"></i> Configure
                                </button>

                                <!-- Delete Button (disabled for system roles) -->
                                <?php if (!$isSys): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete role \'<?= htmlspecialchars($r['name']) ?>\'?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_role">
                                        <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Role">
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

<!-- Modal: Create Custom Role -->
<div class="modal fade" id="createRoleModal" tabindex="-1" aria-labelledby="createRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" class="modal-content border-0 shadow">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_role">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold" id="createRoleModalLabel">
                    <i class="fa-solid fa-plus-circle text-primary me-2"></i>Create New System Role
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Role Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" placeholder="e.g. Quality Auditor" required>
                        <small class="text-muted" style="font-size: 11px;">Human-readable display title.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Identifier (Slug) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" name="slug" placeholder="e.g. quality_auditor" pattern="[a-z0-9_]{2,40}" required>
                        <small class="text-muted" style="font-size: 11px;">Unique internal identifier (lowercase letters &amp; underscores).</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" class="form-control" name="description" placeholder="Brief explanation of duties and access level">
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3 pt-2 border-top">
                    <h6 class="fw-bold mb-0"><i class="fa-solid fa-key text-warning me-2"></i>Module Permissions Matrix</h6>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" onclick="toggleAllPerms('create', true)">Select All</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="toggleAllPerms('create', false)">Clear All</button>
                    </div>
                </div>

                <?php foreach ($definedPermissions as $groupTitle => $groupPerms): ?>
                    <div class="card mb-3 border bg-light">
                        <div class="card-header bg-white py-2 fw-semibold text-primary">
                            <i class="fa-solid fa-layer-group me-1"></i> <?= htmlspecialchars($groupTitle) ?>
                        </div>
                        <div class="card-body p-3">
                            <div class="row g-2">
                                <?php foreach ($groupPerms as $permKey => $permInfo): ?>
                                    <div class="col-md-6">
                                        <div class="form-check p-2 rounded bg-white border">
                                            <input class="form-check-input create-perm-cb ms-1" type="checkbox" name="permissions[]" value="<?= $permKey ?>" id="create_perm_<?= $permKey ?>">
                                            <label class="form-check-label ps-2 user-select-none" for="create_perm_<?= $permKey ?>">
                                                <span class="fw-semibold d-block text-dark"><?= htmlspecialchars($permInfo['label']) ?></span>
                                                <small class="text-muted d-block" style="font-size: 11px;"><?= htmlspecialchars($permInfo['description']) ?></small>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check me-1"></i> Create Role</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Existing Role & Permissions -->
<div class="modal fade" id="editRoleModal" tabindex="-1" aria-labelledby="editRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" class="modal-content border-0 shadow">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="role_id" id="editRoleId">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold" id="editRoleModalLabel">
                    <i class="fa-solid fa-sliders text-primary me-2"></i>Configure Role &amp; Permissions
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Role Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="editRoleName" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Identifier (Slug)</label>
                        <input type="text" class="form-control font-monospace bg-light" id="editRoleSlug" disabled>
                        <small class="text-muted" style="font-size: 11px;">Role identifiers cannot be changed.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" class="form-control" name="description" id="editRoleDescription">
                    </div>
                </div>

                <div id="adminLockedNotice" class="alert alert-info py-2 px-3 small d-none mb-3">
                    <i class="fa-solid fa-lock me-1"></i> The <strong>Administrator</strong> role has full master permissions across all modules by system design.
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3 pt-2 border-top">
                    <h6 class="fw-bold mb-0"><i class="fa-solid fa-key text-warning me-2"></i>Module Permissions Matrix</h6>
                    <div class="btn-group btn-group-sm" id="editPermButtons">
                        <button type="button" class="btn btn-outline-secondary" onclick="toggleAllPerms('edit', true)">Select All</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="toggleAllPerms('edit', false)">Clear All</button>
                    </div>
                </div>

                <?php foreach ($definedPermissions as $groupTitle => $groupPerms): ?>
                    <div class="card mb-3 border bg-light">
                        <div class="card-header bg-white py-2 fw-semibold text-primary">
                            <i class="fa-solid fa-layer-group me-1"></i> <?= htmlspecialchars($groupTitle) ?>
                        </div>
                        <div class="card-body p-3">
                            <div class="row g-2">
                                <?php foreach ($groupPerms as $permKey => $permInfo): ?>
                                    <div class="col-md-6">
                                        <div class="form-check p-2 rounded bg-white border">
                                            <input class="form-check-input edit-perm-cb ms-1" type="checkbox" name="permissions[]" value="<?= $permKey ?>" id="edit_perm_<?= $permKey ?>">
                                            <label class="form-check-label ps-2 user-select-none" for="edit_perm_<?= $permKey ?>">
                                                <span class="fw-semibold d-block text-dark"><?= htmlspecialchars($permInfo['label']) ?></span>
                                                <small class="text-muted d-block" style="font-size: 11px;"><?= htmlspecialchars($permInfo['description']) ?></small>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Save Role Permissions</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAllPerms(mode, state) {
    const selector = mode === 'create' ? '.create-perm-cb' : '.edit-perm-cb';
    document.querySelectorAll(selector).forEach(cb => {
        if (!cb.disabled) cb.checked = state;
    });
}

function openEditRoleModal(role) {
    document.getElementById('editRoleId').value = role.id;
    document.getElementById('editRoleSlug').value = role.slug;
    document.getElementById('editRoleName').value = role.name;
    document.getElementById('editRoleDescription').value = role.description || '';

    const isAdminRole = (role.slug === 'admin');
    const notice = document.getElementById('adminLockedNotice');
    const buttons = document.getElementById('editPermButtons');

    if (isAdminRole) {
        notice.classList.remove('d-none');
        buttons.classList.add('d-none');
    } else {
        notice.classList.add('d-none');
        buttons.classList.remove('d-none');
    }

    // Set permission checkboxes
    document.querySelectorAll('.edit-perm-cb').forEach(cb => {
        if (isAdminRole) {
            cb.checked = true;
            cb.disabled = true;
        } else {
            cb.disabled = false;
            cb.checked = Array.isArray(role.permissions) && role.permissions.includes(cb.value);
        }
    });

    const modal = new bootstrap.Modal(document.getElementById('editRoleModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
