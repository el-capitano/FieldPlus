<?php
// web-admin/public/index.php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
$currentUser = requirePermission('dashboard_view', 'You do not have permission to view the Operations Dashboard.');

$pdo = getDbConnection();

$isAdmin = isAdmin($currentUser);
$canAccessAll = $isAdmin || (hasPermission('forms_all', $currentUser) && hasPermission('submissions_all', $currentUser));
$canWriteForms = hasPermission('forms_write', $currentUser);
$canReadSubmissions = hasPermission('submissions_read', $currentUser);

// Get metrics
if ($canAccessAll) {
    $totalForms = (int)$pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
    $activeForms = (int)$pdo->query("SELECT COUNT(*) FROM forms WHERE is_active = 1")->fetchColumn();
    $totalSubmissions = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
    $todaySubmissions = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $gpsSubmissionsCount = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE latitude IS NOT NULL AND longitude IS NOT NULL")->fetchColumn();
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    $gpsPointsStmt = $pdo->query("
        SELECT s.id, s.submission_uuid, s.form_id, s.submitter_name, s.latitude, s.longitude, s.accuracy, s.submitted_at, s.journey_path_json, f.title as form_title
        FROM submissions s
        JOIN forms f ON s.form_id = f.id
        WHERE (s.latitude IS NOT NULL AND s.longitude IS NOT NULL) OR (s.journey_path_json IS NOT NULL AND s.journey_path_json != '')
        ORDER BY s.submitted_at DESC
        LIMIT 100
    ");

    $recentStmt = $pdo->query("
        SELECT s.*, f.title as form_title, f.category as form_category, u.full_name as user_full_name
        FROM submissions s
        JOIN forms f ON s.form_id = f.id
        LEFT JOIN users u ON s.user_id = u.id
        ORDER BY s.id DESC
        LIMIT 8
    ");

    $formsStmt = $pdo->query("SELECT id, title, category, version, is_active, updated_at FROM forms ORDER BY updated_at DESC LIMIT 5");
} else {
    $uid = (int)$currentUser['id'];

    $stmtM = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE created_by = :uid");
    $stmtM->execute(['uid' => $uid]);
    $totalForms = (int)$stmtM->fetchColumn();

    $stmtM = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE created_by = :uid AND is_active = 1");
    $stmtM->execute(['uid' => $uid]);
    $activeForms = (int)$stmtM->fetchColumn();

    $stmtM = $pdo->prepare("SELECT COUNT(*) FROM submissions s JOIN forms f ON s.form_id = f.id WHERE s.user_id = :uid_sub OR f.created_by = :uid_form");
    $stmtM->execute(['uid_sub' => $uid, 'uid_form' => $uid]);
    $totalSubmissions = (int)$stmtM->fetchColumn();

    $stmtM = $pdo->prepare("SELECT COUNT(*) FROM submissions s JOIN forms f ON s.form_id = f.id WHERE (s.user_id = :uid_sub OR f.created_by = :uid_form) AND DATE(s.created_at) = CURDATE()");
    $stmtM->execute(['uid_sub' => $uid, 'uid_form' => $uid]);
    $todaySubmissions = (int)$stmtM->fetchColumn();

    $stmtM = $pdo->prepare("SELECT COUNT(*) FROM submissions s JOIN forms f ON s.form_id = f.id WHERE (s.user_id = :uid_sub OR f.created_by = :uid_form) AND s.latitude IS NOT NULL AND s.longitude IS NOT NULL");
    $stmtM->execute(['uid_sub' => $uid, 'uid_form' => $uid]);
    $gpsSubmissionsCount = (int)$stmtM->fetchColumn();
    $totalUsers = 0;

    $gpsPointsStmt = $pdo->prepare("
        SELECT s.id, s.submission_uuid, s.form_id, s.submitter_name, s.latitude, s.longitude, s.accuracy, s.submitted_at, s.journey_path_json, f.title as form_title
        FROM submissions s
        JOIN forms f ON s.form_id = f.id
        WHERE ((s.latitude IS NOT NULL AND s.longitude IS NOT NULL) OR (s.journey_path_json IS NOT NULL AND s.journey_path_json != '')) AND (s.user_id = :uid_sub OR f.created_by = :uid_form)
        ORDER BY s.submitted_at DESC
        LIMIT 100
    ");
    $gpsPointsStmt->execute(['uid_sub' => $uid, 'uid_form' => $uid]);

    $recentStmt = $pdo->prepare("
        SELECT s.*, f.title as form_title, f.category as form_category, u.full_name as user_full_name
        FROM submissions s
        JOIN forms f ON s.form_id = f.id
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.user_id = :uid_sub OR f.created_by = :uid_form
        ORDER BY s.id DESC
        LIMIT 8
    ");
    $recentStmt->execute(['uid_sub' => $uid, 'uid_form' => $uid]);

    $formsStmt = $pdo->prepare("SELECT id, title, category, version, is_active, updated_at FROM forms WHERE created_by = :uid_created OR is_active = 1 ORDER BY (created_by = :uid_order) DESC, updated_at DESC LIMIT 5");
    $formsStmt->execute(['uid_created' => $uid, 'uid_order' => $uid]);
}

$gpsPoints = $gpsPointsStmt->fetchAll();
$recentSubmissions = $recentStmt->fetchAll();
$formsList = $formsStmt->fetchAll();

// Analytics: Submissions Velocity (Last 7 Days)
$dailyMap = [];
$dailyLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-$i days"));
    $dailyLabels[] = date('D, M j', strtotime("-$i days"));
    $dailyMap[$dateKey] = 0;
}
if ($canAccessAll) {
    $dailyDb = $pdo->query("
        SELECT DATE(submitted_at) as s_date, COUNT(*) as cnt
        FROM submissions
        WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(submitted_at)
    ")->fetchAll();
} else {
    $dailyDbStmt = $pdo->prepare("
        SELECT DATE(s.submitted_at) as s_date, COUNT(*) as cnt
        FROM submissions s
        JOIN forms f ON s.form_id = f.id
        WHERE s.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
          AND (s.user_id = :uid_sub OR f.created_by = :uid_form)
        GROUP BY DATE(s.submitted_at)
    ");
    $dailyDbStmt->execute(['uid_sub' => $uid, 'uid_form' => $uid]);
    $dailyDb = $dailyDbStmt->fetchAll();
}
foreach ($dailyDb as $row) {
    if (isset($dailyMap[$row['s_date']])) {
        $dailyMap[$row['s_date']] = (int)$row['cnt'];
    }
}
$dailyCounts = array_values($dailyMap);

// Analytics: Category Breakdown
$catStats = $pdo->query("
    SELECT COALESCE(NULLIF(f.category, ''), 'General') as cat_name, COUNT(s.id) as cnt
    FROM forms f
    LEFT JOIN submissions s ON s.form_id = f.id
    GROUP BY cat_name
    ORDER BY cnt DESC
")->fetchAll();
$catLabels = [];
$catCounts = [];
foreach ($catStats as $cs) {
    $catLabels[] = $cs['cat_name'];
    $catCounts[] = (int)$cs['cnt'];
}

require_once __DIR__ . '/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col-md-7">
        <h2 class="fw-bold mb-1">Field Visit Operations Dashboard</h2>
        <p class="text-muted mb-0">Monitor online & offline field surveys, dynamic form versions, and surveyor geolocations.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <?php if ($canWriteForms): ?>
            <a href="form_builder.php" class="btn btn-primary me-2">
                <i class="fa-solid fa-plus me-1"></i> New Form Schema
            </a>
        <?php endif; ?>
        <?php if ($canReadSubmissions): ?>
            <a href="submissions.php" class="btn btn-outline-secondary">
                <i class="fa-solid fa-download me-1"></i> View Data & Export
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Metrics Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 stat-card" style="border-left-color: #2563eb;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Active Forms</span>
                    <h3 class="fw-bold mt-1 mb-0"><?= $activeForms ?> <span class="fs-6 fw-normal text-muted">/ <?= $totalForms ?></span></h3>
                </div>
                <div class="bg-primary-subtle text-primary p-3 rounded-circle">
                    <i class="fa-solid fa-file-lines fa-xl"></i>
                </div>
            </div>
            <div class="mt-2 text-muted" style="font-size: 0.8rem;">
                <i class="fa-solid fa-check text-success me-1"></i> Ready for Android Sync
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 stat-card" style="border-left-color: #10b981;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Total Submissions</span>
                    <h3 class="fw-bold mt-1 mb-0"><?= $totalSubmissions ?></h3>
                </div>
                <div class="bg-success-subtle text-success p-3 rounded-circle">
                    <i class="fa-solid fa-cloud-arrow-up fa-xl"></i>
                </div>
            </div>
            <div class="mt-2 text-muted" style="font-size: 0.8rem;">
                <span class="text-success fw-bold">+<?= $todaySubmissions ?></span> synced today
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 stat-card" style="border-left-color: #f59e0b;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">GPS Field Fixes</span>
                    <h3 class="fw-bold mt-1 mb-0"><?= $gpsSubmissionsCount ?></h3>
                </div>
                <div class="bg-warning-subtle text-warning p-3 rounded-circle">
                    <i class="fa-solid fa-location-dot fa-xl"></i>
                </div>
            </div>
            <div class="mt-2 text-muted" style="font-size: 0.8rem;">
                <i class="fa-solid fa-satellite text-primary me-1"></i> Geotagged Inspections
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 stat-card" style="border-left-color: #8b5cf6;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <?php if ($isAdmin): ?>
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Staff Accounts</span>
                        <h3 class="fw-bold mt-1 mb-0"><?= $totalUsers ?></h3>
                    <?php else: ?>
                        <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.75rem;">Account Role</span>
                        <h4 class="fw-bold mt-1 mb-0 text-primary">Field Staff</h4>
                    <?php endif; ?>
                </div>
                <div class="bg-purple-subtle text-primary p-3 rounded-circle">
                    <i class="fa-solid <?= $isAdmin ? 'fa-users-gear' : 'fa-id-badge' ?> fa-xl"></i>
                </div>
            </div>
            <div class="mt-2 text-muted" style="font-size: 0.8rem;">
                <?php if ($isAdmin): ?>
                    <a href="users.php" class="text-decoration-none fw-semibold"><i class="fa-solid fa-arrow-right me-1"></i> Manage Users</a>
                <?php else: ?>
                    <a href="app_connect.php" class="text-decoration-none fw-semibold"><i class="fa-solid fa-qrcode me-1"></i> My Mobile QR Code</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Map & Quick Access -->
<div class="row g-4 mb-4">
    <!-- Field GPS Map -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-3 flex-wrap gap-2">
                <span class="fw-bold"><i class="fa-solid fa-map-location-dot me-2 text-primary"></i>Live Field Visit Map</span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <!-- Base Map Provider Selector (OSM vs Google Maps) -->
                    <div class="btn-group btn-group-sm" role="group" aria-label="Map Base Layer">
                        <button type="button" class="btn btn-outline-secondary" id="btnMap_osm" onclick="switchMapProvider('osm')" title="OpenStreetMap">
                            <i class="fa-solid fa-map me-1"></i> OSM
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnMap_google_roads" onclick="switchMapProvider('google_roads')" title="Google Maps Streets">
                            <i class="fa-brands fa-google text-primary me-1"></i> Google
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnMap_google_hybrid" onclick="switchMapProvider('google_hybrid')" title="Google Maps Satellite Hybrid">
                            <i class="fa-solid fa-satellite text-success me-1"></i> Satellite
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnMap_google_terrain" onclick="switchMapProvider('google_terrain')" title="Google Maps Terrain">
                            <i class="fa-solid fa-mountain text-warning me-1"></i> Terrain
                        </button>
                    </div>

                    <!-- Overlay Mode (Markers vs Heatmap) -->
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary active" id="btnMapMarkers" onclick="switchMapLayer('markers')">
                            <i class="fa-solid fa-location-dot me-1"></i> Markers
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="btnMapHeatmap" onclick="switchMapLayer('heatmap')">
                            <i class="fa-solid fa-fire me-1"></i> Density Heatmap
                        </button>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary"><?= count($gpsPoints) ?> Points</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="fieldMap" style="height: 380px; width: 100%; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;"></div>
            </div>
        </div>
    </div>

    <!-- Mobile Connection & Quick Guide -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header py-3">
                <span class="fw-bold"><i class="fa-solid fa-tower-broadcast me-2 text-success"></i>Mobile Sync Endpoint</span>
            </div>
            <div class="card-body">
                <p class="text-muted small">Configure your Android app Server Settings to synchronize forms and upload offline surveys.</p>
                <div class="bg-light p-2 rounded border mb-3">
                    <small class="text-muted d-block fw-semibold mb-1">Android Emulator Endpoint:</small>
                    <code class="text-primary fw-bold">http://10.0.2.2:8080/api</code>
                </div>
                <div class="bg-light p-2 rounded border mb-3">
                    <small class="text-muted d-block fw-semibold mb-1">LAN / Physical Device Endpoint:</small>
                    <code class="text-primary fw-bold">http://&lt;your-computer-ip&gt;:8080/api</code>
                </div>

                <h6 class="fw-bold mt-3 mb-2 small text-uppercase text-secondary">Supported Survey Controls:</h6>
                <div class="d-flex flex-wrap gap-1">
                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-font text-muted me-1"></i>Text</span>
                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-hashtag text-muted me-1"></i>Numeric</span>
                    <span class="badge bg-light text-dark border"><i class="fa-regular fa-calendar text-muted me-1"></i>Date/Time</span>
                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-location-crosshairs text-danger me-1"></i>GPS Location</span>
                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-camera text-primary me-1"></i>Camera/File</span>
                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-signature text-success me-1"></i>Digital Signature</span>
                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-list-ul text-muted me-1"></i>Dropdown</span>
                    <span class="badge bg-light text-dark border"><i class="fa-regular fa-circle-dot text-muted me-1"></i>Radio</span>
                    <span class="badge bg-light text-dark border"><i class="fa-regular fa-square-check text-muted me-1"></i>Checkboxes</span>
                    <span class="badge bg-light text-dark border"><i class="fa-solid fa-toggle-on text-info me-1"></i>Switch</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Analytics & Velocity Section -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold"><i class="fa-solid fa-chart-line me-2 text-primary"></i>Submissions Velocity (Past 7 Days)</span>
                <span class="badge bg-primary-subtle text-primary">Daily Activity</span>
            </div>
            <div class="card-body">
                <div style="height: 240px; position: relative;">
                    <canvas id="velocityChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Forms by Category</span>
                <span class="badge bg-secondary-subtle text-secondary">Breakdown</span>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <div style="height: 240px; width: 100%; position: relative;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Submissions & Forms Table -->
<div class="row g-4">
    <!-- Recent Submissions -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold"><i class="fa-solid fa-inbox me-2 text-primary"></i>Recent Field Submissions</span>
                <a href="submissions.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>UUID / Time</th>
                            <th>Form</th>
                            <th>Submitter</th>
                            <th>GPS</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSubmissions)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-clipboard-question fa-2x mb-2 d-block text-secondary"></i>
                                    No submissions received yet. Launch the Android app or test submission endpoint.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentSubmissions as $sub): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-truncate" style="max-width: 140px;" title="<?= htmlspecialchars($sub['submission_uuid']) ?>">
                                            <?= htmlspecialchars(substr($sub['submission_uuid'], 0, 12)) ?>...
                                        </div>
                                        <small class="text-muted"><?= htmlspecialchars($sub['submitted_at'] ?? $sub['created_at']) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?= htmlspecialchars($sub['form_title']) ?></div>
                                        <small class="text-muted badge bg-light text-dark border"><?= htmlspecialchars($sub['form_category']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($sub['submitter_name'] ?? 'Anonymous') ?></td>
                                    <td>
                                        <?php if (!empty($sub['latitude']) && !empty($sub['longitude'])): ?>
                                            <span class="badge bg-success-subtle text-success">
                                                <i class="fa-solid fa-location-dot me-1"></i><?= round($sub['latitude'], 4) ?>, <?= round($sub['longitude'], 4) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">No GPS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary text-uppercase"><?= htmlspecialchars($sub['status']) ?></span>
                                    </td>
                                    <td>
                                        <a href="submission_detail.php?uuid=<?= urlencode($sub['submission_uuid']) ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="fa-solid fa-eye"></i>
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

    <!-- Active Forms List -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold"><i class="fa-solid fa-folder-open me-2 text-primary"></i>Available Forms</span>
                <a href="forms.php" class="btn btn-sm btn-outline-primary">Manage</a>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($formsList as $f): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                        <div>
                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($f['title']) ?></h6>
                            <div class="d-flex gap-2 align-items-center">
                                <span class="badge bg-secondary-subtle text-secondary">v<?= $f['version'] ?></span>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($f['category']) ?></span>
                            </div>
                        </div>
                        <div class="btn-group">
                            <a href="form_preview.php?id=<?= urlencode($f['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Preview / Test">
                                <i class="fa-solid fa-play"></i>
                            </a>
                            <a href="form_builder.php?id=<?= urlencode($f['id']) ?>" class="btn btn-sm btn-outline-primary" title="Edit in Builder">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet Heat Plugin -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.heat/0.2.0/leaflet-heat.js"></script>
<script>
var mapInstance = null;
var markersLayerGroup = null;
var heatmapLayerGroup = null;
var baseLayers = {};
var currentBaseKey = localStorage.getItem('fieldplus_map_provider') || 'osm';
var currentBaseLayer = null;
var isInternalSwitch = false;

function getBaseLayers() {
    return {
        'osm': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>'
        }),
        'google_roads': L.tileLayer('https://mt{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: '0123',
            attribution: '&copy; Google Maps'
        }),
        'google_hybrid': L.tileLayer('https://mt{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: '0123',
            attribution: '&copy; Google Maps Satellite'
        }),
        'google_terrain': L.tileLayer('https://mt{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: '0123',
            attribution: '&copy; Google Maps Terrain'
        }),
        'esri_satellite': L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
        })
    };
}

function updateMapButtons(activeKey) {
    ['osm', 'google_roads', 'google_hybrid', 'google_terrain'].forEach(function(k) {
        var el = document.getElementById('btnMap_' + k);
        if (el) {
            if (k === activeKey || (activeKey === 'esri_satellite' && k === 'google_hybrid')) {
                el.classList.add('active', 'btn-primary');
                el.classList.remove('btn-outline-secondary');
            } else {
                el.classList.remove('active', 'btn-primary');
                el.classList.add('btn-outline-secondary');
            }
        }
    });
}

function switchMapProvider(providerKey) {
    if (!mapInstance || !baseLayers[providerKey]) return;
    
    isInternalSwitch = true;
    try {
        for (var k in baseLayers) {
            if (k !== providerKey && mapInstance.hasLayer(baseLayers[k])) {
                mapInstance.removeLayer(baseLayers[k]);
            }
        }
        if (!mapInstance.hasLayer(baseLayers[providerKey])) {
            mapInstance.addLayer(baseLayers[providerKey]);
        }
        currentBaseLayer = baseLayers[providerKey];
        currentBaseKey = providerKey;
        localStorage.setItem('fieldplus_map_provider', providerKey);
    } finally {
        isInternalSwitch = false;
    }
    
    updateMapButtons(providerKey);
}

function switchMapLayer(mode) {
    if (!mapInstance) return;
    const btnMarkers = document.getElementById('btnMapMarkers');
    const btnHeatmap = document.getElementById('btnMapHeatmap');

    if (mode === 'heatmap') {
        if (markersLayerGroup) mapInstance.removeLayer(markersLayerGroup);
        if (heatmapLayerGroup) mapInstance.addLayer(heatmapLayerGroup);
        btnMarkers?.classList.remove('active');
        btnHeatmap?.classList.add('active');
    } else {
        if (heatmapLayerGroup) mapInstance.removeLayer(heatmapLayerGroup);
        if (markersLayerGroup) mapInstance.addLayer(markersLayerGroup);
        btnHeatmap?.classList.remove('active');
        btnMarkers?.classList.add('active');
    }
}

document.addEventListener("DOMContentLoaded", function() {
    var gpsPoints = <?= json_encode($gpsPoints) ?>;
    
    // Default center (e.g. Riyadh or Middle East or First Point)
    var defaultLat = 24.7136;
    var defaultLng = 46.6753;
    var zoomLevel = 6;

    if (gpsPoints.length > 0) {
        defaultLat = parseFloat(gpsPoints[0].latitude);
        defaultLng = parseFloat(gpsPoints[0].longitude);
        zoomLevel = 11;
    }

    mapInstance = L.map('fieldMap').setView([defaultLat, defaultLng], zoomLevel);

    // Initialize base layers and apply active layer
    baseLayers = getBaseLayers();
    if (!baseLayers[currentBaseKey]) {
        currentBaseKey = 'osm';
    }
    switchMapProvider(currentBaseKey);

    // Leaflet Top-Right Layer Switcher
    var baseControls = {
        "OpenStreetMap": baseLayers['osm'],
        "Google Roads": baseLayers['google_roads'],
        "Google Satellite": baseLayers['google_hybrid'],
        "Google Terrain": baseLayers['google_terrain'],
        "ESRI Satellite (Fallback)": baseLayers['esri_satellite']
    };
    var layerControl = L.control.layers(baseControls, null, { position: 'topright' }).addTo(mapInstance);

    // Invalidate map size after rendering to ensure proper tile display
    setTimeout(function() {
        if (mapInstance) {
            mapInstance.invalidateSize();
        }
    }, 200);

    // Sync button state if changed from Leaflet control
    mapInstance.on('baselayerchange', function(e) {
        if (isInternalSwitch) return;
        for (var key in baseLayers) {
            if (baseLayers[key] === e.layer) {
                currentBaseKey = key;
                currentBaseLayer = e.layer;
                localStorage.setItem('fieldplus_map_provider', key);
                updateMapButtons(key);
                break;
            }
        }
    });

    markersLayerGroup = L.layerGroup().addTo(mapInstance);

    var heatData = [];
    if (gpsPoints.length > 0) {
        var group = [];
        gpsPoints.forEach(function(pt) {
            var lat = parseFloat(pt.latitude);
            var lng = parseFloat(pt.longitude);
            if (!isNaN(lat) && !isNaN(lng)) {
                var marker = L.marker([lat, lng]);
                var popupContent = '<strong>' + (pt.form_title || 'Survey') + '</strong><br>' +
                                   'Submitter: ' + (pt.submitter_name || 'N/A') + '<br>' +
                                   'Coords: ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '<br>' +
                                   '<div class="mt-2 d-flex gap-1 flex-wrap">' +
                                   '<a href="submission_detail.php?uuid=' + pt.submission_uuid + '" class="btn btn-sm btn-primary text-white py-0 px-2" style="font-size:11px;">View Details</a>' +
                                   '<a href="https://www.google.com/maps?q=' + lat + ',' + lng + '" target="_blank" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;" title="Open in Google Maps"><i class="fa-brands fa-google me-1"></i>Google Maps</a>' +
                                   '<a href="https://www.openstreetmap.org/?mlat=' + lat + '&mlon=' + lng + '#map=16/' + lat + '/' + lng + '" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;" title="Open in OpenStreetMap"><i class="fa-solid fa-map me-1"></i>OSM</a>' +
                                   '</div>';
                marker.bindPopup(popupContent);
                markersLayerGroup.addLayer(marker);
                group.push([lat, lng]);
                heatData.push([lat, lng, 1.0]);
            }

            // Also ingest high-density points from Journey GPS tracks
            if (pt.journey_path_json) {
                try {
                    var journeyPts = typeof pt.journey_path_json === 'string' ? JSON.parse(pt.journey_path_json) : pt.journey_path_json;
                    if (Array.isArray(journeyPts)) {
                        journeyPts.forEach(function(jpt) {
                            var jLat = parseFloat(jpt.lat);
                            var jLng = parseFloat(jpt.lng);
                            if (!isNaN(jLat) && !isNaN(jLng)) {
                                heatData.push([jLat, jLng, 0.9]);
                                group.push([jLat, jLng]);
                            }
                        });
                    }
                } catch(e) {
                    console.warn("Could not parse journey_path_json for heatData", e);
                }
            }
        });

        if (group.length > 1) {
            mapInstance.fitBounds(group, { padding: [30, 30] });
        }
    }

    if (typeof L.heatLayer === 'function' && heatData.length > 0) {
        heatmapLayerGroup = L.heatLayer(heatData, {
            radius: 35,
            blur: 20,
            maxZoom: 18,
            max: 1.0,
            minOpacity: 0.35,
            gradient: { 0.2: '#00f', 0.4: '#0ff', 0.6: '#0f0', 0.8: '#ff0', 1.0: '#f00' }
        });
    }
});
</script>

<!-- Chart.js for Analytics -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Submissions Velocity Chart
    const velocityCtx = document.getElementById('velocityChart')?.getContext('2d');
    if (velocityCtx) {
        new Chart(velocityCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($dailyLabels) ?>,
                datasets: [{
                    label: 'Submissions',
                    data: <?= json_encode($dailyCounts) ?>,
                    backgroundColor: 'rgba(37, 99, 235, 0.75)',
                    borderColor: '#2563eb',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0 }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // 2. Category Distribution Doughnut Chart
    const catCtx = document.getElementById('categoryChart')?.getContext('2d');
    if (catCtx) {
        new Chart(catCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($catLabels) ?>,
                datasets: [{
                    data: <?= json_encode($catCounts) ?>,
                    backgroundColor: [
                        '#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#64748b'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    }
                },
                cutout: '65%'
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
