<?php
// web-admin/public/submissions.php
$pageTitle = 'Field Submissions Explorer';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
$currentUser = requirePermission('submissions_read', 'You do not have permission to view field submissions.');

$pdo = getDbConnection();

$actionSuccess = '';
$actionError = '';

$isAdmin = isAdmin($currentUser);
$canWrite = hasPermission('submissions_write', $currentUser);
$canDelete = hasPermission('submissions_delete', $currentUser);
$canAccessAll = $isAdmin || hasPermission('submissions_all', $currentUser);
$selectedUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $bulkAction = $_POST['bulk_action'] ?? '';
    $selectedUuids = $_POST['selected_uuids'] ?? [];

    if (!empty($selectedUuids) && is_array($selectedUuids)) {
        $placeholders = implode(',', array_fill(0, count($selectedUuids), '?'));
        $extraPermClause = "";
        $execParams = $selectedUuids;

        if (!$canAccessAll) {
            $extraPermClause = " AND (user_id = ? OR form_id IN (SELECT id FROM forms WHERE created_by = ?))";
            $execParams[] = $currentUser['id'];
            $execParams[] = $currentUser['id'];
        }

        if (strpos($bulkAction, 'status_') === 0 && !$canWrite) {
            $actionError = 'Permission Denied: You do not have permission to update submission review status.';
        } elseif ($bulkAction === 'delete' && !$canDelete) {
            $actionError = 'Permission Denied: You do not have permission to delete submissions.';
        } elseif ($bulkAction === 'status_approved') {
            $stmt = $pdo->prepare("UPDATE submissions SET status = 'approved', review_notes = CONCAT(IFNULL(review_notes,''), '\n[Bulk Approved on ' , NOW(), ']'), reviewed_at = NOW() WHERE submission_uuid IN ($placeholders) $extraPermClause");
            $stmt->execute($execParams);
            logSecurityEvent('SUBMISSIONS_BULK_APPROVED', "Bulk approved " . count($selectedUuids) . " submissions", (int)$currentUser['id'], $currentUser['username']);
            $actionSuccess = 'Submissions marked as Verified / Approved.';
        } elseif ($bulkAction === 'status_flagged') {
            $stmt = $pdo->prepare("UPDATE submissions SET status = 'flagged', review_notes = CONCAT(IFNULL(review_notes,''), '\n[Bulk Flagged on ' , NOW(), ']'), reviewed_at = NOW() WHERE submission_uuid IN ($placeholders) $extraPermClause");
            $stmt->execute($execParams);
            logSecurityEvent('SUBMISSIONS_BULK_FLAGGED', "Bulk flagged " . count($selectedUuids) . " submissions", (int)$currentUser['id'], $currentUser['username']);
            $actionSuccess = 'Submissions flagged for clarification.';
        } elseif ($bulkAction === 'status_rejected') {
            $stmt = $pdo->prepare("UPDATE submissions SET status = 'rejected', review_notes = CONCAT(IFNULL(review_notes,''), '\n[Bulk Rejected on ' , NOW(), ']'), reviewed_at = NOW() WHERE submission_uuid IN ($placeholders) $extraPermClause");
            $stmt->execute($execParams);
            logSecurityEvent('SUBMISSIONS_BULK_REJECTED', "Bulk rejected " . count($selectedUuids) . " submissions", (int)$currentUser['id'], $currentUser['username']);
            $actionSuccess = 'Submissions marked as Rejected.';
        } elseif ($bulkAction === 'status_submitted') {
            $stmt = $pdo->prepare("UPDATE submissions SET status = 'submitted' WHERE submission_uuid IN ($placeholders) $extraPermClause");
            $stmt->execute($execParams);
            $actionSuccess = 'Submissions reset to Submitted.';
        } elseif ($bulkAction === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM submission_attachments WHERE submission_uuid IN ($placeholders)");
            $stmt->execute($selectedUuids);
            $stmt = $pdo->prepare("DELETE FROM submissions WHERE submission_uuid IN ($placeholders) $extraPermClause");
            $stmt->execute($execParams);
            logSecurityEvent('SUBMISSIONS_BULK_DELETED', "Bulk deleted " . count($selectedUuids) . " submissions and media", (int)$currentUser['id'], $currentUser['username']);
            $actionSuccess = 'Submissions deleted permanently.';
        }
    } else {
        $actionError = 'Please select at least one submission to perform this bulk action.';
    }
}

$selectedFormId = $_GET['form_id'] ?? '';
$selectedStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$export = $_GET['export'] ?? '';

// Build Query
$sql = "
    SELECT s.*, 
           COALESCE(f.title, s.form_id) as form_title, 
           COALESCE(f.category, 'General') as form_category,
           f.created_by as form_created_by,
           u.full_name as user_full_name,
           u.username as user_username,
           u.role as user_role
    FROM submissions s
    LEFT JOIN forms f ON s.form_id = f.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE 1=1
";
$params = [];

if (!$canAccessAll) {
    $sql .= " AND (s.user_id = :uid_sub OR f.created_by = :uid_form)";
    $params['uid_sub'] = $currentUser['id'];
    $params['uid_form'] = $currentUser['id'];
} elseif ($selectedUserId) {
    $sql .= " AND s.user_id = :uid_selected";
    $params['uid_selected'] = $selectedUserId;
}

if ($selectedFormId) {
    $sql .= " AND s.form_id = :form_id";
    $params['form_id'] = $selectedFormId;
}

if ($selectedStatus) {
    $sql .= " AND s.status = :status";
    $params['status'] = $selectedStatus;
}

if ($search) {
    $sql .= " AND (s.submission_uuid LIKE :search OR s.submitter_name LIKE :search OR s.device_id LIKE :search OR s.data_json LIKE :search)";
    $params['search'] = "%{$search}%";
}

$sql .= " ORDER BY s.submitted_at DESC, s.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll();

// Handle CSV / JSON Export
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=field_submissions_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['UUID', 'Form ID', 'Form Title', 'Submitter', 'Device', 'Latitude', 'Longitude', 'Accuracy (m)', 'Altitude (m)', 'Status', 'Submitted At', 'Answers Data JSON']);

    foreach ($submissions as $sub) {
        fputcsv($output, [
            $sub['submission_uuid'],
            $sub['form_id'],
            $sub['form_title'],
            $sub['submitter_name'],
            $sub['device_id'],
            $sub['latitude'],
            $sub['longitude'],
            $sub['accuracy'],
            $sub['altitude'],
            $sub['status'],
            $sub['submitted_at'],
            $sub['data_json']
        ]);
    }
    fclose($output);
    exit();
} elseif ($export === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=field_submissions_' . date('Ymd_His') . '.json');
    foreach ($submissions as &$sub) {
        $sub['data'] = json_decode($sub['data_json'], true);
        unset($sub['data_json']);
    }
    echo json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
} elseif ($export === 'geojson') {
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Content-Disposition: attachment; filename=field_submissions_' . date('Ymd_His') . '.geojson');
    $features = [];
    foreach ($submissions as $sub) {
        $lat = !empty($sub['latitude']) ? (float)$sub['latitude'] : null;
        $lng = !empty($sub['longitude']) ? (float)$sub['longitude'] : null;
        $geometry = null;
        if ($lat !== null && $lng !== null) {
            $geometry = [
                'type' => 'Point',
                'coordinates' => [$lng, $lat, !empty($sub['altitude']) ? (float)$sub['altitude'] : 0.0]
            ];
        }
        $properties = [
            'uuid' => $sub['submission_uuid'],
            'form_id' => $sub['form_id'],
            'form_title' => $sub['form_title'],
            'submitter_name' => $sub['submitter_name'],
            'device_id' => $sub['device_id'],
            'accuracy_m' => !empty($sub['accuracy']) ? (float)$sub['accuracy'] : null,
            'status' => $sub['status'],
            'submitted_at' => $sub['submitted_at'],
            'answers' => json_decode($sub['data_json'], true)
        ];
        $features[] = [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => $properties
        ];
    }
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features
    ];
    echo json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
} elseif ($export === 'kml') {
    header('Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8');
    header('Content-Disposition: attachment; filename=field_submissions_' . date('Ymd_His') . '.kml');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n";
    echo '  <Document>' . "\n";
    echo '    <name>FieldPlus Audits (' . date('Y-m-d') . ')</name>' . "\n";
    echo '    <description>Field inspection locations captured via FieldPlus Android</description>' . "\n";
    echo '    <Style id="auditMarker"><IconStyle><Icon><href>http://maps.google.com/mapfiles/ms/icons/blue-dot.png</href></Icon></IconStyle></Style>' . "\n";
    foreach ($submissions as $sub) {
        $lat = !empty($sub['latitude']) ? (float)$sub['latitude'] : null;
        $lng = !empty($sub['longitude']) ? (float)$sub['longitude'] : null;
        if ($lat !== null && $lng !== null) {
            $alt = !empty($sub['altitude']) ? (float)$sub['altitude'] : 0.0;
            $title = htmlspecialchars($sub['form_title'] ?? 'Inspection');
            $desc = "Submitter: " . htmlspecialchars($sub['submitter_name'] ?? 'N/A') . "\nStatus: " . htmlspecialchars($sub['status']) . "\nTime: " . htmlspecialchars($sub['submitted_at']);
            echo '    <Placemark>' . "\n";
            echo '      <name>' . $title . '</name>' . "\n";
            echo '      <description><![CDATA[' . nl2br($desc) . ']]></description>' . "\n";
            echo '      <styleUrl>#auditMarker</styleUrl>' . "\n";
            echo '      <Point><coordinates>' . $lng . ',' . $lat . ',' . $alt . '</coordinates></Point>' . "\n";
            echo '    </Placemark>' . "\n";
        }
    }
    echo '  </Document>' . "\n";
    echo '</kml>' . "\n";
    exit();
}

// Get forms for filter dropdown
$formsList = $pdo->query("SELECT id, title FROM forms ORDER BY title ASC")->fetchAll();
$usersList = $canAccessAll ? $pdo->query("SELECT id, full_name, username, role FROM users ORDER BY full_name ASC")->fetchAll() : [];

require_once __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold mb-1">
            <i class="fa-solid fa-inbox text-primary me-2"></i>Field Visit Submissions Explorer
        </h2>
        <p class="text-muted mb-0">Browse submitted audits, review captured GPS coordinates, inspect photos and digital signatures.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="?form_id=<?= urlencode($selectedFormId) ?>&user_id=<?= urlencode($selectedUserId ?? '') ?>&status=<?= urlencode($selectedStatus) ?>&search=<?= urlencode($search) ?>&export=csv" class="btn btn-outline-success">
            <i class="fa-solid fa-file-csv me-1"></i> Export CSV
        </a>
        <a href="?form_id=<?= urlencode($selectedFormId) ?>&user_id=<?= urlencode($selectedUserId ?? '') ?>&status=<?= urlencode($selectedStatus) ?>&search=<?= urlencode($search) ?>&export=json" class="btn btn-outline-secondary">
            <i class="fa-solid fa-file-code me-1"></i> Export JSON
        </a>
        <a href="?form_id=<?= urlencode($selectedFormId) ?>&user_id=<?= urlencode($selectedUserId ?? '') ?>&status=<?= urlencode($selectedStatus) ?>&search=<?= urlencode($search) ?>&export=geojson" class="btn btn-outline-primary" title="Export as standard GeoJSON for QGIS, ArcGIS, or Mapbox">
            <i class="fa-solid fa-earth-americas me-1"></i> GeoJSON
        </a>
        <a href="?form_id=<?= urlencode($selectedFormId) ?>&user_id=<?= urlencode($selectedUserId ?? '') ?>&status=<?= urlencode($selectedStatus) ?>&search=<?= urlencode($search) ?>&export=kml" class="btn btn-outline-info" title="Export as KML for Google Earth">
            <i class="fa-solid fa-globe me-1"></i> Google Earth (KML)
        </a>
    </div>
</div>

<!-- Filters Card -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="<?= $canAccessAll ? 'col-md-3' : 'col-md-4' ?>">
                <label class="form-label fw-semibold small">Filter by Form Template</label>
                <select name="form_id" class="form-select" onchange="this.form.submit()">
                    <option value="">All Forms (<?= count($formsList) ?> templates)</option>
                    <?php foreach ($formsList as $f): ?>
                        <option value="<?= htmlspecialchars($f['id']) ?>" <?= $selectedFormId === $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($canAccessAll): ?>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Filter by Staff Collector</label>
                    <select name="user_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Collectors (<?= count($usersList) ?> users)</option>
                        <?php foreach ($usersList as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ($selectedUserId === (int)$u['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?> (@<?= htmlspecialchars($u['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="<?= $canAccessAll ? 'col-md-2' : 'col-md-3' ?>">
                <label class="form-label fw-semibold small">Audit Review Status</label>
                <select name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="submitted" <?= $selectedStatus === 'submitted' ? 'selected' : '' ?>>Submitted (Pending Review)</option>
                    <option value="approved" <?= $selectedStatus === 'approved' ? 'selected' : '' ?>>Verified / Approved</option>
                    <option value="flagged" <?= $selectedStatus === 'flagged' ? 'selected' : '' ?>>Flagged for Clarification</option>
                    <option value="rejected" <?= $selectedStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div class="<?= $canAccessAll ? 'col-md-3' : 'col-md-3' ?>">
                <label class="form-label fw-semibold small">Search Keywords</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search submitter, UUID, device..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="<?= $canAccessAll ? 'col-md-1' : 'col-md-2' ?> d-grid">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </form>
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

<!-- Submissions Table Card -->
<form method="POST" id="bulkActionsForm" onsubmit="return confirmBulkAction();">
    <?= csrfField() ?>
    <div class="card shadow-sm">
        <!-- Bulk Actions Header Toolbar -->
        <div class="bg-light p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <input type="checkbox" id="selectAllCheckbox" class="form-check-input ms-1" onchange="toggleSelectAll(this)">
                <label for="selectAllCheckbox" class="form-check-label small fw-semibold text-secondary user-select-none">Select All Visible</label>
                <span class="badge bg-secondary ms-2" id="selectionCountBadge">0 selected</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <select name="bulk_action" id="bulkActionSelect" class="form-select form-select-sm" style="width: auto;">
                    <option value="">-- Choose Bulk Action --</option>
                    <?php if ($canWrite): ?>
                        <option value="status_approved">Mark as Verified / Approved</option>
                        <option value="status_flagged">Flag for Clarification</option>
                        <option value="status_rejected">Mark as Rejected</option>
                        <option value="status_submitted">Reset to Submitted</option>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                        <option value="delete">🗑️ Delete Selected Permanently</option>
                    <?php endif; ?>
                </select>
                <button type="submit" id="btnApplyBulk" class="btn btn-sm btn-primary" disabled>
                    <i class="fa-solid fa-check-double me-1"></i> Apply
                </button>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 40px;" class="ps-3"></th>
                            <th>Submission UUID & Date</th>
                            <th>Form Name</th>
                            <th>Surveyor</th>
                            <th>Device</th>
                            <th>GPS Geotag</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($submissions)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-inbox fa-3x mb-3 text-secondary"></i>
                                    <p class="mb-0">No field submissions match your search criteria.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($submissions as $sub): ?>
                                <tr>
                                    <td class="ps-3">
                                        <input type="checkbox" name="selected_uuids[]" value="<?= htmlspecialchars($sub['submission_uuid']) ?>" class="form-check-input row-select" onchange="updateSelectionState()">
                                    </td>
                                    <td>
                                        <div class="fw-bold font-monospace text-primary small">
                                            <?= htmlspecialchars(substr($sub['submission_uuid'], 0, 16)) ?>...
                                        </div>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($sub['submitted_at'] ?? $sub['created_at']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($sub['form_title']) ?></div>
                                        <small class="badge bg-light text-dark border"><?= htmlspecialchars($sub['form_category']) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-medium text-dark d-flex align-items-center gap-1">
                                            <?= htmlspecialchars($sub['user_full_name'] ?: $sub['submitter_name'] ?: 'Field Staff') ?>
                                            <?php if ((int)($sub['user_id'] ?? 0) === (int)$currentUser['id']): ?>
                                                <span class="badge bg-primary-subtle text-primary border" style="font-size: 0.65rem;">You</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($sub['user_username'])): ?>
                                            <small class="text-muted d-block" style="font-size: 11px;">
                                                <i class="fa-solid fa-at text-muted"></i><?= htmlspecialchars($sub['user_username']) ?>
                                                <span class="badge bg-light text-secondary border py-0"><?= ucfirst(htmlspecialchars($sub['user_role'] ?? 'staff')) ?></span>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-secondary text-truncate d-block" style="max-width: 140px;">
                                            <?= htmlspecialchars($sub['device_id'] ?: 'Android') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if (!empty($sub['latitude']) && !empty($sub['longitude'])): ?>
                                            <a href="https://www.google.com/maps?q=<?= $sub['latitude'] ?>,<?= $sub['longitude'] ?>" target="_blank" class="badge bg-success-subtle text-success text-decoration-none" title="Open in Google Maps">
                                                <i class="fa-solid fa-location-dot me-1"></i><?= round($sub['latitude'], 4) ?>, <?= round($sub['longitude'], 4) ?>
                                            </a>
                                            <?php if (!empty($sub['accuracy'])): ?>
                                                <small class="text-muted d-block" style="font-size: 11px;">&plusmn; <?= round($sub['accuracy'], 1) ?>m</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $st = strtolower($sub['status'] ?? 'submitted');
                                        $stBadge = match($st) {
                                            'approved' => 'bg-success-subtle text-success border border-success-subtle',
                                            'flagged' => 'bg-warning-subtle text-warning border border-warning-subtle',
                                            'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                                            default => 'bg-primary-subtle text-primary border border-primary-subtle',
                                        };
                                        ?>
                                        <span class="badge <?= $stBadge ?> text-uppercase"><?= htmlspecialchars($sub['status']) ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="submission_detail.php?uuid=<?= urlencode($sub['submission_uuid']) ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fa-solid fa-file-lines me-1"></i> Review Audit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>

<script>
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.row-select');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateSelectionState();
}

function updateSelectionState() {
    const checkboxes = document.querySelectorAll('.row-select');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    const count = checked.length;
    const badge = document.getElementById('selectionCountBadge');
    const btn = document.getElementById('btnApplyBulk');
    const master = document.getElementById('selectAllCheckbox');

    if (badge) badge.textContent = `${count} selected`;
    if (btn) btn.disabled = (count === 0);

    if (master) {
        if (count === 0) {
            master.checked = false;
            master.indeterminate = false;
        } else if (count === checkboxes.length) {
            master.checked = true;
            master.indeterminate = false;
        } else {
            master.checked = false;
            master.indeterminate = true;
        }
    }
}

function confirmBulkAction() {
    const select = document.getElementById('bulkActionSelect');
    if (!select || !select.value) {
        alert('Please choose an action from the dropdown.');
        return false;
    }
    const checkboxes = document.querySelectorAll('.row-select:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one submission.');
        return false;
    }
    if (select.value === 'delete') {
        return confirm(`Are you sure you want to permanently delete ${checkboxes.length} submissions and all associated attachments? This action cannot be undone.`);
    }
    return true;
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
