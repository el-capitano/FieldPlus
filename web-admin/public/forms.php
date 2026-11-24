<?php
// web-admin/public/forms.php
$pageTitle = 'Forms Management';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
$currentUser = requirePermission('forms_read', 'You do not have permission to view the forms catalog.');

$pdo = getDbConnection();
$message = '';
$messageType = 'success';
$isAdmin = isAdmin($currentUser);
$canWrite = hasPermission('forms_write', $currentUser);
$canDelete = hasPermission('forms_delete', $currentUser);
$canAccessAll = $isAdmin || hasPermission('forms_all', $currentUser);

// Handle Schema Download directly via GET
if (isset($_GET['download_schema']) && !empty($_GET['id'])) {
    $dlId = trim($_GET['id']);
    $stmtDl = $pdo->prepare("SELECT * FROM forms WHERE id = :id LIMIT 1");
    $stmtDl->execute(['id' => $dlId]);
    $formToDownload = $stmtDl->fetch();

    if ($formToDownload && canUserAccessForm($formToDownload, $currentUser)) {
        $schemaObj = json_decode($formToDownload['schema_json'], true) ?: [];
        $exportPayload = [
            'id' => $formToDownload['id'],
            'title' => $formToDownload['title'],
            'description' => $formToDownload['description'],
            'category' => $formToDownload['category'],
            'version' => (int)$formToDownload['version'],
            'access_type' => $formToDownload['access_type'],
            'schema' => $schemaObj,
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_by' => $currentUser['username']
        ];
        
        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $formToDownload['id']) . '_schema.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($exportPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    } else {
        $message = "Permission Denied: Unable to download form schema.";
        $messageType = "danger";
    }
}

// Current active tab (active vs archived)
$currentTab = ($_GET['tab'] ?? 'active') === 'archived' ? 'archived' : 'active';
$isArchivedFilter = ($currentTab === 'archived') ? 1 : 0;

// Handle Actions (toggle status, archive, unarchive, clear_submissions, delete, duplicate, update_permissions)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    $formId = $_POST['form_id'] ?? '';

    // Verify ownership / manage permissions if not superadmin / forms_all
    $formRow = null;
    if ($formId) {
        $chk = $pdo->prepare("SELECT * FROM forms WHERE id = :id");
        $chk->execute(['id' => $formId]);
        $formRow = $chk->fetch();

        if (!$formRow) {
            $message = "Form not found.";
            $messageType = "danger";
            $action = '';
        } elseif (!canUserManageForm($formRow, $currentUser)) {
            $message = "Permission Denied: You can only manage forms created by your account.";
            $messageType = "danger";
            $action = '';
        }
    }

    if ($action === 'toggle_status' && $formId) {
        if (!$canWrite) {
            $message = "Permission Denied: You do not have permission to modify form status.";
            $messageType = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE forms SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
            $stmt->execute(['id' => $formId]);
            logSecurityEvent('FORM_STATUS_TOGGLED', "Toggled active status for form ID '{$formId}'", (int)$currentUser['id'], $currentUser['username']);
            $message = "Form active status updated.";
        }
    } elseif ($action === 'archive' && $formId) {
        if (!$canWrite) {
            $message = "Permission Denied: You do not have permission to archive forms.";
            $messageType = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE forms SET is_archived = 1, archived_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $formId]);
            logSecurityEvent('FORM_ARCHIVED', "Archived form '{$formRow['title']}' ({$formId})", (int)$currentUser['id'], $currentUser['username']);
            $message = "Form '{$formRow['title']}' moved to archive. It is now hidden from mobile sync.";
        }
    } elseif ($action === 'unarchive' && $formId) {
        if (!$canWrite) {
            $message = "Permission Denied: You do not have permission to restore forms.";
            $messageType = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE forms SET is_archived = 0, archived_at = NULL WHERE id = :id");
            $stmt->execute(['id' => $formId]);
            logSecurityEvent('FORM_RESTORED', "Restored form '{$formRow['title']}' ({$formId}) from archive", (int)$currentUser['id'], $currentUser['username']);
            $message = "Form '{$formRow['title']}' restored from archive to active forms.";
        }
    } elseif ($action === 'clear_submissions' && $formId) {
        if (!$canDelete) {
            $message = "Permission Denied: You do not have permission to clear form submissions.";
            $messageType = "danger";
        } else {
            try {
                $pdo->beginTransaction();
                // Delete attachments first
                $stmtDelAtt = $pdo->prepare("
                    DELETE FROM submission_attachments 
                    WHERE submission_uuid IN (SELECT submission_uuid FROM submissions WHERE form_id = :id)
                ");
                $stmtDelAtt->execute(['id' => $formId]);

                // Delete submissions
                $stmtDelSub = $pdo->prepare("DELETE FROM submissions WHERE form_id = :id");
                $stmtDelSub->execute(['id' => $formId]);
                $countCleared = $stmtDelSub->rowCount();

                $pdo->commit();
                logSecurityEvent('FORM_SUBMISSIONS_CLEARED', "Cleared {$countCleared} submissions for form '{$formRow['title']}' ({$formId})", (int)$currentUser['id'], $currentUser['username']);
                $message = "Cleared {$countCleared} submissions and their associated attachments for '{$formRow['title']}'. The form template remains intact.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error clearing submissions: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    } elseif ($action === 'delete' && $formId) {
        if (!$canDelete) {
            $message = "Permission Denied: You do not have permission to delete forms.";
            $messageType = "danger";
        } else {
            try {
                $pdo->beginTransaction();
                // Cascade delete attachments and submissions
                $stmtDelAtt = $pdo->prepare("
                    DELETE FROM submission_attachments 
                    WHERE submission_uuid IN (SELECT submission_uuid FROM submissions WHERE form_id = :id)
                ");
                $stmtDelAtt->execute(['id' => $formId]);

                $stmtDelSub = $pdo->prepare("DELETE FROM submissions WHERE form_id = :id");
                $stmtDelSub->execute(['id' => $formId]);

                $stmt = $pdo->prepare("DELETE FROM forms WHERE id = :id");
                $stmt->execute(['id' => $formId]);
                $pdo->commit();
                logSecurityEvent('FORM_DELETED', "Permanently deleted form template '{$formRow['title']}' ({$formId}) and its data", (int)$currentUser['id'], $currentUser['username']);
                $message = "Form '{$formRow['title']}' and all its submissions permanently deleted.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error deleting form: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    } elseif ($action === 'update_permissions' && $formId) {
        if (!$canWrite) {
            $message = "Permission Denied: You do not have permission to change form permissions.";
            $messageType = "danger";
        } else {
            $accessType = $_POST['access_type'] ?? 'all';
            if (!in_array($accessType, ['all', 'creator_only', 'specific_users', 'specific_roles'])) {
                $accessType = 'all';
            }

            $assignedUsers = isset($_POST['assigned_users']) && is_array($_POST['assigned_users'])
                ? array_map('intval', $_POST['assigned_users'])
                : [];
            $assignedRoles = isset($_POST['assigned_roles']) && is_array($_POST['assigned_roles'])
                ? array_values(array_filter($_POST['assigned_roles'], 'is_string'))
                : [];

            $usersJson = (!empty($assignedUsers) && $accessType === 'specific_users') ? json_encode($assignedUsers) : null;
            $rolesJson = (!empty($assignedRoles) && $accessType === 'specific_roles') ? json_encode($assignedRoles) : null;

            $stmtPerm = $pdo->prepare("
                UPDATE forms 
                SET access_type = :access_type,
                    assigned_users_json = :users,
                    assigned_roles_json = :roles
                WHERE id = :id
            ");
            $stmtPerm->execute([
                'access_type' => $accessType,
                'users' => $usersJson,
                'roles' => $rolesJson,
                'id' => $formId
            ]);
            logSecurityEvent('FORM_PERMISSIONS_CHANGED', "Updated access permissions to '{$accessType}' for form '{$formRow['title']}' ({$formId})", (int)$currentUser['id'], $currentUser['username']);
            $message = "Form access permissions updated for '{$formRow['title']}'.";
        }
    } elseif ($action === 'duplicate' && $formId) {
        if (!$canWrite) {
            $message = "Permission Denied: You do not have permission to create or duplicate forms.";
            $messageType = "danger";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = :id");
            $stmt->execute(['id' => $formId]);
            $orig = $stmt->fetch();
            if ($orig) {
                $newId = 'form_' . substr(bin2hex(random_bytes(4)), 0, 8);
                $newTitle = $orig['title'] . ' (Copy)';
                $schemaArr = json_decode($orig['schema_json'], true);
                $schemaArr['id'] = $newId;
                $schemaArr['title'] = $newTitle;
                $newSchemaJson = json_encode($schemaArr, JSON_UNESCAPED_SLASHES);

                $stmtDup = $pdo->prepare("
                    INSERT INTO forms (id, title, description, category, version, is_active, is_archived, created_by, access_type, assigned_users_json, assigned_roles_json, schema_json)
                    VALUES (:id, :title, :desc, :cat, 1, 1, 0, :created_by, :access_type, :users, :roles, :schema)
                ");
                $stmtDup->execute([
                    'id' => $newId,
                    'title' => $newTitle,
                    'desc' => $orig['description'],
                    'cat' => $orig['category'],
                    'created_by' => $currentUser['id'],
                    'access_type' => $orig['access_type'] ?? 'all',
                    'users' => $orig['assigned_users_json'] ?? null,
                    'roles' => $orig['assigned_roles_json'] ?? null,
                    'schema' => $newSchemaJson
                ]);
                logSecurityEvent('FORM_DUPLICATED', "Duplicated form '{$orig['title']}' into new form '{$newTitle}' ({$newId})", (int)$currentUser['id'], $currentUser['username']);
                $message = "Form duplicated successfully as '{$newTitle}'.";
            }
        }
    }
}

// Counts for Tabs
$countActiveStmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE is_archived = 0 " . ($canAccessAll ? "" : "AND (created_by = :uid OR access_type = 'all')"));
if ($canAccessAll) {
    $countActiveStmt->execute();
} else {
    $countActiveStmt->execute(['uid' => $currentUser['id']]);
}
$countActive = (int)$countActiveStmt->fetchColumn();

$countArchivedStmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE is_archived = 1 " . ($canAccessAll ? "" : "AND created_by = :uid"));
if ($canAccessAll) {
    $countArchivedStmt->execute();
} else {
    $countArchivedStmt->execute(['uid' => $currentUser['id']]);
}
$countArchived = (int)$countArchivedStmt->fetchColumn();

// Creator filter for users with all-forms scope
$creatorFilter = isset($_GET['creator_id']) && is_numeric($_GET['creator_id']) ? (int)$_GET['creator_id'] : null;

// Fetch forms based on role, tab & scope
if ($canAccessAll) {
    $sql = "
        SELECT f.*, u.full_name AS creator_name, u.username AS creator_username, u.role AS creator_role,
               (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id) AS submissions_count
        FROM forms f
        LEFT JOIN users u ON f.created_by = u.id
        WHERE f.is_archived = :is_archived
    ";
    $params = ['is_archived' => $isArchivedFilter];
    if ($creatorFilter) {
        $sql .= " AND f.created_by = :cid";
        $params['cid'] = $creatorFilter;
    }
    $sql .= " ORDER BY f.updated_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} else {
    // Staff sees their own forms + shared templates that match access permissions
    $sql = "
        SELECT f.*, u.full_name AS creator_name, u.username AS creator_username, u.role AS creator_role,
               (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id) AS submissions_count
        FROM forms f
        LEFT JOIN users u ON f.created_by = u.id
        WHERE f.is_archived = :is_archived
          AND (
            f.created_by = :uid 
            OR (f.is_archived = 0 AND f.is_active = 1 AND (
                f.access_type = 'all'
                OR (f.access_type = 'specific_users' AND JSON_CONTAINS(f.assigned_users_json, :uid_str))
                OR (f.access_type = 'specific_roles' AND JSON_CONTAINS(f.assigned_roles_json, :role_str))
            ))
          )
        ORDER BY (f.created_by = :uid_order) DESC, f.updated_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'is_archived' => $isArchivedFilter,
        'uid' => $currentUser['id'],
        'uid_str' => json_encode((int)$currentUser['id']),
        'role_str' => json_encode($currentUser['role']),
        'uid_order' => $currentUser['id']
    ]);
}
$forms = $stmt->fetchAll();

// Fetch creators for filter dropdown
$creators = [];
if ($canAccessAll) {
    $creators = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC")->fetchAll();
}

// Fetch all system users and roles for Permissions Modal
$allUsers = $pdo->query("SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();
$allRoles = $pdo->query("SELECT id, slug, name, description FROM roles ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1">
            <i class="fa-solid fa-clipboard-list text-primary me-2"></i>Forms Management
        </h2>
        <p class="text-muted mb-0">
            Control form templates, assign surveyor access permissions, download schemas, or manage archived surveys.
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($isAdmin && !empty($creators)): ?>
            <form method="GET" class="d-flex align-items-center gap-1 me-2">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($currentTab) ?>">
                <select name="creator_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Creators (<?= count($forms) ?>)</option>
                    <?php foreach ($creators as $cr): ?>
                        <option value="<?= $cr['id'] ?>" <?= ($creatorFilter === (int)$cr['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cr['full_name']) ?> (<?= ucfirst($cr['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($creatorFilter): ?>
                    <a href="forms.php?tab=<?= urlencode($currentTab) ?>" class="btn btn-sm btn-outline-secondary" title="Clear filter"><i class="fa-solid fa-xmark"></i></a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <?php if ($canWrite): ?>
            <a href="form_builder.php" class="btn btn-primary">
                <i class="fa-solid fa-plus me-1"></i> Create New Form
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm" role="alert">
        <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Tabs: Active Forms vs Archived Forms -->
<ul class="nav nav-pills mb-3 gap-2">
    <li class="nav-item">
        <a class="nav-link <?= $currentTab === 'active' ? 'active' : 'bg-white border' ?>" href="forms.php?tab=active<?= $creatorFilter ? '&creator_id='.$creatorFilter : '' ?>">
            <i class="fa-solid fa-clipboard-check me-2"></i>Active Forms
            <span class="badge <?= $currentTab === 'active' ? 'bg-white text-primary' : 'bg-secondary' ?> ms-2"><?= $countActive ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $currentTab === 'archived' ? 'active bg-secondary' : 'bg-white border' ?>" href="forms.php?tab=archived<?= $creatorFilter ? '&creator_id='.$creatorFilter : '' ?>">
            <i class="fa-solid fa-box-archive me-2"></i>Archived Forms
            <span class="badge <?= $currentTab === 'archived' ? 'bg-white text-secondary' : 'bg-secondary' ?> ms-2"><?= $countArchived ?></span>
        </a>
    </li>
</ul>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Form Title &amp; Description</th>
                        <th>Category &amp; ID</th>
                        <th>Creator</th>
                        <th>Access &amp; Permissions</th>
                        <th>Submissions</th>
                        <th>Version</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($forms)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fa-solid <?= $currentTab === 'archived' ? 'fa-box-archive' : 'fa-folder-open' ?> fa-3x mb-3 text-secondary"></i>
                                <p class="mb-2">No <?= $currentTab === 'archived' ? 'archived' : 'active' ?> forms found.</p>
                                <?php if ($currentTab === 'active'): ?>
                                    <a href="form_builder.php" class="btn btn-sm btn-primary">Create Your First Form</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($forms as $f): 
                            $schema = json_decode($f['schema_json'], true) ?: [];
                            $sectionCount = count($schema['sections'] ?? []);
                            $fieldCount = 0;
                            if (!empty($schema['sections'])) {
                                foreach ($schema['sections'] as $sec) {
                                    $fieldCount += count($sec['fields'] ?? []);
                                }
                            }

                            $isMyForm = ((int)($f['created_by'] ?? 0) === (int)$currentUser['id']);
                            $canManageThisForm = canUserManageForm($f, $currentUser) && $canWrite;
                            $canDeleteThisForm = canUserManageForm($f, $currentUser) && $canDelete;

                            $accessType = $f['access_type'] ?? 'all';
                            $assignedUsers = json_decode($f['assigned_users_json'] ?? '[]', true) ?: [];
                            $assignedRoles = json_decode($f['assigned_roles_json'] ?? '[]', true) ?: [];
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold fs-6 text-primary mb-1"><?= htmlspecialchars($f['title']) ?></div>
                                    <small class="text-muted d-block text-truncate" style="max-width: 280px;">
                                        <?= htmlspecialchars($f['description'] ?: 'No description provided') ?>
                                    </small>
                                    <small class="text-secondary"><?= $fieldCount ?> fields in <?= $sectionCount ?> sections</small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border mb-1"><?= htmlspecialchars($f['category']) ?></span>
                                    <code class="d-block small text-muted"><?= htmlspecialchars($f['id']) ?></code>
                                </td>
                                <td>
                                    <?php if ($isMyForm): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                            <i class="fa-solid fa-user-check me-1"></i> You
                                        </span>
                                    <?php elseif (!empty($f['creator_name'])): ?>
                                        <div class="small fw-semibold text-dark"><?= htmlspecialchars($f['creator_name']) ?></div>
                                        <span class="badge bg-secondary-subtle text-secondary" style="font-size: 10px;">
                                            <?= ucfirst(htmlspecialchars($f['creator_role'] ?? 'staff')) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">System</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($accessType === 'all'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" title="Anyone with field collection permission can use this form">
                                            <i class="fa-solid fa-earth-americas me-1"></i> All Users
                                        </span>
                                    <?php elseif ($accessType === 'creator_only'): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1" title="Only the creator can access and collect on this form">
                                            <i class="fa-solid fa-lock me-1"></i> Creator Only
                                        </span>
                                    <?php elseif ($accessType === 'specific_users'): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1" title="Restricted to <?= count($assignedUsers) ?> specific user(s)">
                                            <i class="fa-solid fa-users me-1"></i> <?= count($assignedUsers) ?> User(s)
                                        </span>
                                    <?php elseif ($accessType === 'specific_roles'): ?>
                                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1" style="background-color: #f3e8ff; color: #7e22ce;" title="Restricted to roles: <?= htmlspecialchars(implode(', ', $assignedRoles)) ?>">
                                            <i class="fa-solid fa-id-badge me-1"></i> <?= count($assignedRoles) ?> Role(s)
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($canManageThisForm): ?>
                                        <button type="button" class="btn btn-sm btn-link p-0 text-primary ms-1" 
                                                title="Configure Form Access Permissions"
                                                onclick="openPermissionsModal(<?= htmlspecialchars(json_encode([
                                                    'id' => $f['id'],
                                                    'title' => $f['title'],
                                                    'access_type' => $accessType,
                                                    'assigned_users' => $assignedUsers,
                                                    'assigned_roles' => $assignedRoles
                                                ])) ?>)">
                                            <i class="fa-solid fa-sliders"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="submissions.php?form_id=<?= urlencode($f['id']) ?>" class="badge text-decoration-none <?= $f['submissions_count'] > 0 ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-muted border' ?> px-2 py-1">
                                        <i class="fa-solid fa-inbox me-1"></i> <?= (int)$f['submissions_count'] ?> records
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1">v<?= $f['version'] ?></span>
                                </td>
                                <td>
                                    <?php if ($f['is_archived']): ?>
                                        <span class="badge bg-secondary text-white rounded-pill px-3 py-1">
                                            <i class="fa-solid fa-box-archive me-1"></i> Archived
                                        </span>
                                    <?php elseif ($canManageThisForm): ?>
                                        <form method="POST" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="form_id" value="<?= htmlspecialchars($f['id']) ?>">
                                            <?php if ($f['is_active']): ?>
                                                <button type="submit" class="btn btn-sm btn-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1" title="Click to Deactivate">
                                                    <i class="fa-solid fa-circle-check me-1"></i> Active
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-sm btn-secondary-subtle text-secondary border border-secondary rounded-pill px-3 py-1" title="Click to Activate">
                                                    <i class="fa-solid fa-circle-xmark me-1"></i> Inactive
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">
                                            <i class="fa-solid fa-circle-check me-1"></i> Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group">
                                        <a href="form_preview.php?id=<?= urlencode($f['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Preview / Test Form">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>

                                        <?php if ($canManageThisForm): ?>
                                            <a href="form_builder.php?id=<?= urlencode($f['id']) ?>" class="btn btn-sm btn-outline-primary" title="Edit in Visual Builder">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </a>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <!-- Download Form Schema -->
                                            <li>
                                                <a class="dropdown-item" href="forms.php?download_schema=1&id=<?= urlencode($f['id']) ?>">
                                                    <i class="fa-solid fa-download me-2 text-primary"></i> Download Form Schema (JSON)
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="submissions.php?form_id=<?= urlencode($f['id']) ?>">
                                                    <i class="fa-solid fa-inbox me-2 text-success"></i> View Submissions (<?= (int)$f['submissions_count'] ?>)
                                                </a>
                                            </li>

                                            <?php if ($canManageThisForm): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <!-- Configure Access Permissions -->
                                                <li>
                                                    <button type="button" class="dropdown-item" onclick="openPermissionsModal(<?= htmlspecialchars(json_encode([
                                                        'id' => $f['id'],
                                                        'title' => $f['title'],
                                                        'access_type' => $accessType,
                                                        'assigned_users' => $assignedUsers,
                                                        'assigned_roles' => $assignedRoles
                                                    ])) ?>)">
                                                        <i class="fa-solid fa-user-lock me-2 text-info"></i> Manage Permissions &amp; Access
                                                    </button>
                                                </li>

                                                <!-- Move to Archive or Restore -->
                                                <?php if ($f['is_archived']): ?>
                                                    <li>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Restore this form to active forms list?');">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="action" value="unarchive">
                                                            <input type="hidden" name="form_id" value="<?= htmlspecialchars($f['id']) ?>">
                                                            <button type="submit" class="dropdown-item text-primary">
                                                                <i class="fa-solid fa-box-open me-2"></i> Restore / Unarchive Form
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Archive this form? It will be preserved but hidden from mobile synchronization.');">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="action" value="archive">
                                                            <input type="hidden" name="form_id" value="<?= htmlspecialchars($f['id']) ?>">
                                                            <button type="submit" class="dropdown-item text-secondary">
                                                                <i class="fa-solid fa-box-archive me-2"></i> Move to Archive
                                                            </button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>

                                                <!-- Clear Submissions -->
                                                <?php if ($canDeleteThisForm): ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item text-warning" onclick="openClearSubmissionsModal('<?= htmlspecialchars($f['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($f['title']), ENT_QUOTES) ?>', <?= (int)$f['submissions_count'] ?>)">
                                                            <i class="fa-solid fa-broom me-2"></i> Clear Submissions
                                                        </button>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($canWrite): ?>
                                                <li>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Duplicate this form?');">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="duplicate">
                                                        <input type="hidden" name="form_id" value="<?= htmlspecialchars($f['id']) ?>">
                                                        <button type="submit" class="dropdown-item">
                                                            <i class="fa-regular fa-copy me-2 text-secondary"></i> Duplicate as My Form
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>

                                            <?php if ($canDeleteThisForm): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this form template and all related data? This cannot be undone.');">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="form_id" value="<?= htmlspecialchars($f['id']) ?>">
                                                        <button type="submit" class="dropdown-item text-danger">
                                                            <i class="fa-solid fa-trash me-2"></i> Delete Form
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Form Permissions Modal -->
<div class="modal fade" id="permissionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content shadow">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="form_id" id="perm_form_id">

            <div class="modal-header border-bottom bg-light">
                <div>
                    <h5 class="modal-title fw-bold">
                        <i class="fa-solid fa-user-lock text-primary me-2"></i>Configure Form Permissions
                    </h5>
                    <small class="text-muted" id="perm_form_title"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">
                <p class="text-muted mb-3">
                    Specify who is authorized to view, download to mobile devices, and submit field surveys for this form:
                </p>

                <!-- Access Type Radio Options -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card h-100 p-3 border cursor-pointer perm-option-card" onclick="selectAccessType('all')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="access_type" id="access_all" value="all" onchange="toggleAccessSubpanels()">
                                <label class="form-check-label fw-bold text-dark" for="access_all">
                                    <i class="fa-solid fa-earth-americas text-success me-1"></i> All Users &amp; Surveyors
                                </label>
                            </div>
                            <small class="text-muted ps-4 d-block mt-1">Available to every active field staff member and surveyor.</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 p-3 border cursor-pointer perm-option-card" onclick="selectAccessType('creator_only')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="access_type" id="access_creator_only" value="creator_only" onchange="toggleAccessSubpanels()">
                                <label class="form-check-label fw-bold text-dark" for="access_creator_only">
                                    <i class="fa-solid fa-lock text-warning me-1"></i> Creator Only (Private)
                                </label>
                            </div>
                            <small class="text-muted ps-4 d-block mt-1">Only the creator (and system superadmins) can view and collect data.</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 p-3 border cursor-pointer perm-option-card" onclick="selectAccessType('specific_users')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="access_type" id="access_specific_users" value="specific_users" onchange="toggleAccessSubpanels()">
                                <label class="form-check-label fw-bold text-dark" for="access_specific_users">
                                    <i class="fa-solid fa-users text-info me-1"></i> Specific Assigned Users
                                </label>
                            </div>
                            <small class="text-muted ps-4 d-block mt-1">Grant access to individual selected staff accounts.</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 p-3 border cursor-pointer perm-option-card" onclick="selectAccessType('specific_roles')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="access_type" id="access_specific_roles" value="specific_roles" onchange="toggleAccessSubpanels()">
                                <label class="form-check-label fw-bold text-dark" for="access_specific_roles">
                                    <i class="fa-solid fa-id-badge text-primary me-1"></i> Specific Roles / Groups
                                </label>
                            </div>
                            <small class="text-muted ps-4 d-block mt-1">Grant access to all staff members belonging to chosen roles.</small>
                        </div>
                    </div>
                </div>

                <!-- Subpanel: Specific Users Selection -->
                <div id="panel_specific_users" class="border rounded p-3 bg-light mb-3 d-none">
                    <h6 class="fw-bold mb-2"><i class="fa-solid fa-user-check text-info me-2"></i>Select Authorized Users:</h6>
                    <div class="row g-2" style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($allUsers as $u): ?>
                            <div class="col-md-6">
                                <div class="form-check bg-white p-2 rounded border">
                                    <input class="form-check-input perm-user-check ms-0 me-2" type="checkbox" name="assigned_users[]" value="<?= $u['id'] ?>" id="perm_user_<?= $u['id'] ?>">
                                    <label class="form-check-label small" for="perm_user_<?= $u['id'] ?>">
                                        <strong><?= htmlspecialchars($u['full_name']) ?></strong> (<?= htmlspecialchars($u['username']) ?>)
                                        <span class="badge bg-secondary-subtle text-secondary" style="font-size: 10px;"><?= ucfirst($u['role']) ?></span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Subpanel: Specific Roles Selection -->
                <div id="panel_specific_roles" class="border rounded p-3 bg-light mb-3 d-none">
                    <h6 class="fw-bold mb-2"><i class="fa-solid fa-shield-halved text-primary me-2"></i>Select Authorized Roles / Groups:</h6>
                    <div class="row g-2">
                        <?php foreach ($allRoles as $r): ?>
                            <div class="col-md-6">
                                <div class="form-check bg-white p-2 rounded border">
                                    <input class="form-check-input perm-role-check ms-0 me-2" type="checkbox" name="assigned_roles[]" value="<?= htmlspecialchars($r['slug']) ?>" id="perm_role_<?= htmlspecialchars($r['slug']) ?>">
                                    <label class="form-check-label small" for="perm_role_<?= htmlspecialchars($r['slug']) ?>">
                                        <strong><?= htmlspecialchars($r['name']) ?></strong>
                                        <code class="small text-muted d-block"><?= htmlspecialchars($r['slug']) ?></code>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Permissions
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Clear Submissions Confirmation Modal -->
<div class="modal fade" id="clearSubmissionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content shadow border-warning">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="clear_submissions">
            <input type="hidden" name="form_id" id="clear_form_id">

            <div class="modal-header border-bottom bg-warning-subtle text-warning-emphasis">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Clear Form Submissions
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">
                <p>Are you sure you want to clear submissions for form: <strong id="clear_form_title" class="text-primary"></strong>?</p>
                <div class="alert alert-warning py-2 mb-3">
                    <i class="fa-solid fa-info-circle me-1"></i>
                    This will permanently delete <strong id="clear_form_count">0</strong> submission records and all uploaded photos / signatures for this form.
                </div>
                <p class="text-muted small mb-0">
                    The blank form schema and builder fields will remain intact. The submission count will be reset to 0.
                </p>
            </div>

            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning fw-semibold">
                    <i class="fa-solid fa-broom me-1"></i> Yes, Clear Submissions
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function selectAccessType(type) {
    const radio = document.getElementById('access_' + type);
    if (radio) {
        radio.checked = true;
        toggleAccessSubpanels();
    }
}

function toggleAccessSubpanels() {
    const selected = document.querySelector('input[name="access_type"]:checked')?.value || 'all';
    const usersPanel = document.getElementById('panel_specific_users');
    const rolesPanel = document.getElementById('panel_specific_roles');

    if (selected === 'specific_users') {
        usersPanel.classList.remove('d-none');
    } else {
        usersPanel.classList.add('d-none');
    }

    if (selected === 'specific_roles') {
        rolesPanel.classList.remove('d-none');
    } else {
        rolesPanel.classList.add('d-none');
    }
}

function openPermissionsModal(data) {
    document.getElementById('perm_form_id').value = data.id;
    document.getElementById('perm_form_title').innerText = data.title + ' (' + data.id + ')';

    const accessType = data.access_type || 'all';
    const radio = document.getElementById('access_' + accessType);
    if (radio) {
        radio.checked = true;
    }

    // Reset user checkboxes
    document.querySelectorAll('.perm-user-check').forEach(cb => {
        const uid = parseInt(cb.value);
        cb.checked = (data.assigned_users || []).includes(uid);
    });

    // Reset role checkboxes
    document.querySelectorAll('.perm-role-check').forEach(cb => {
        cb.checked = (data.assigned_roles || []).includes(cb.value);
    });

    toggleAccessSubpanels();
    new bootstrap.Modal(document.getElementById('permissionsModal')).show();
}

function openClearSubmissionsModal(id, title, count) {
    document.getElementById('clear_form_id').value = id;
    document.getElementById('clear_form_title').innerText = title;
    document.getElementById('clear_form_count').innerText = count;
    new bootstrap.Modal(document.getElementById('clear_submissionsModal')).show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
