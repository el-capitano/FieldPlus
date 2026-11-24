<?php
// web-admin/public/submission_detail.php
$pageTitle = 'Field Visit Audit Details';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
$currentUser = requirePermission('submissions_read', 'You do not have permission to view field submission audit details.');

$pdo = getDbConnection();
$uuid = $_GET['uuid'] ?? '';

if (!$uuid) {
    header('Location: submissions.php');
    exit();
}

$isAdmin = isAdmin($currentUser);
$canWrite = hasPermission('submissions_write', $currentUser);
$canAccessAll = $isAdmin || hasPermission('submissions_all', $currentUser);

$stmt = $pdo->prepare("
    SELECT s.*, f.title as form_title, f.category as form_category, f.schema_json, f.created_by as form_created_by,
           u.full_name as user_full_name, u.username as user_username, u.role as user_role
    FROM submissions s
    JOIN forms f ON s.form_id = f.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.submission_uuid = :uuid
    LIMIT 1
");
$stmt->execute(['uuid' => $uuid]);
$sub = $stmt->fetch();

if (!$sub) {
    die("Submission not found.");
}

// Permission check: admin/submissions_all can view all; others can view if collected by them or for their form
if (!$canAccessAll && (int)($sub['user_id'] ?? 0) !== (int)$currentUser['id'] && (int)($sub['form_created_by'] ?? 0) !== (int)$currentUser['id']) {
    header('Location: submissions.php');
    exit();
}

$data = json_decode($sub['data_json'], true) ?: [];
$schema = json_decode($sub['schema_json'], true) ?: [];

// Handle Supervisor Audit Review Update
$reviewMsg = '';
$reviewError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_review') {
    requireCsrfToken();
    if (!$canWrite) {
        $reviewError = 'Permission Denied: You do not have permission to update submission audit status.';
    } else {
        $newStatus = trim($_POST['status'] ?? 'submitted');
        $notes = trim($_POST['review_notes'] ?? '');

        $upd = $pdo->prepare("
            UPDATE submissions 
            SET status = :status, review_notes = :notes, reviewed_at = CURRENT_TIMESTAMP 
            WHERE submission_uuid = :uuid
        ");
        $upd->execute([
            'status' => $newStatus,
            'notes' => $notes,
            'uuid' => $uuid
        ]);
        logSecurityEvent('SUBMISSION_REVIEWED', "Submission '{$uuid}' status updated to '{$newStatus}'", (int)$currentUser['id'], $currentUser['username']);
        $reviewMsg = "Audit verification updated: Status set to " . strtoupper($newStatus);

        // Refresh sub
        $stmt->execute(['uuid' => $uuid]);
        $sub = $stmt->fetch();
    }
}

// Get attachments
$stmtAtt = $pdo->prepare("SELECT * FROM submission_attachments WHERE submission_uuid = :uuid");
$stmtAtt->execute(['uuid' => $uuid]);
$attachments = $stmtAtt->fetchAll();

// Geodesic bearing and distance helper functions
if (!function_exists('fp_calc_bearing')) {
    function fp_calc_bearing($lat1, $lon1, $lat2, $lon2) {
        $lat1Rad = deg2rad((float)$lat1);
        $lat2Rad = deg2rad((float)$lat2);
        $dLonRad = deg2rad((float)($lon2 - $lon1));

        $y = sin($dLonRad) * cos($lat2Rad);
        $x = cos($lat1Rad) * sin($lat2Rad) - sin($lat1Rad) * cos($lat2Rad) * cos($dLonRad);
        $brng = rad2deg(atan2($y, $x));
        return round(fmod(fmod($brng, 360) + 360, 360), 1);
    }
}

if (!function_exists('fp_direction_label')) {
    function fp_direction_label($bearing) {
        $b = fmod(fmod((float)$bearing, 360) + 360, 360);
        if ($b >= 337.5 || $b < 22.5) return "North (N)";
        if ($b >= 22.5 && $b < 67.5) return "North-East (NE)";
        if ($b >= 67.5 && $b < 112.5) return "East (E)";
        if ($b >= 112.5 && $b < 157.5) return "South-East (SE)";
        if ($b >= 157.5 && $b < 202.5) return "South (S)";
        if ($b >= 202.5 && $b < 247.5) return "South-West (SW)";
        if ($b >= 247.5 && $b < 292.5) return "West (W)";
        return "North-West (NW)";
    }
}

if (!function_exists('fp_direction_arrow')) {
    function fp_direction_arrow($bearing) {
        $b = fmod(fmod((float)$bearing, 360) + 360, 360);
        if ($b >= 337.5 || $b < 22.5) return "⬆";
        if ($b >= 22.5 && $b < 67.5) return "↗";
        if ($b >= 67.5 && $b < 112.5) return "➡";
        if ($b >= 112.5 && $b < 157.5) return "↘";
        if ($b >= 157.5 && $b < 202.5) return "⬇";
        if ($b >= 202.5 && $b < 247.5) return "↙";
        if ($b >= 247.5 && $b < 292.5) return "⬅";
        return "↖";
    }
}

if (!function_exists('fp_haversine_dist')) {
    function fp_haversine_dist($lat1, $lon1, $lat2, $lon2) {
        $r = 6371000; // meters
        $dLat = deg2rad((float)($lat2 - $lat1));
        $dLon = deg2rad((float)($lon2 - $lon1));
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        return $r * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}

// Robust Journey Track Extraction & Enrichment
$journeyPoints = [];
if (!empty($sub['journey_path_json'])) {
    $decodedPoints = json_decode($sub['journey_path_json'], true);
    if (is_array($decodedPoints)) {
        $journeyPoints = $decodedPoints;
    }
}

// Fallback search across $data if journey_path_json is null or unpopulated
if (empty($journeyPoints) && !empty($data)) {
    foreach ($data as $fKey => $fVal) {
        $cand = $fVal;
        if (is_string($cand) && (str_starts_with(trim($cand), '{') || str_starts_with(trim($cand), '['))) {
            $dec = json_decode($cand, true);
            if (is_array($dec)) $cand = $dec;
        }
        if (is_array($cand)) {
            if (isset($cand['journey_path']) && is_array($cand['journey_path'])) {
                $journeyPoints = $cand['journey_path'];
            } elseif (isset($cand['points']) && is_array($cand['points'])) {
                $journeyPoints = $cand['points'];
            } elseif (isset($cand[0]['lat']) && isset($cand[0]['lng'])) {
                $journeyPoints = $cand;
            }

            if (!empty($journeyPoints)) {
                if (empty($sub['journey_start_time']) && !empty($cand['start_time'])) {
                    $sub['journey_start_time'] = date('Y-m-d H:i:s', strtotime($cand['start_time']));
                }
                if (empty($sub['journey_end_time']) && !empty($cand['end_time'])) {
                    $sub['journey_end_time'] = date('Y-m-d H:i:s', strtotime($cand['end_time']));
                }
                if (empty($sub['journey_duration_seconds']) && isset($cand['duration_seconds'])) {
                    $sub['journey_duration_seconds'] = (int)$cand['duration_seconds'];
                }
                if (empty($sub['journey_distance_meters']) && isset($cand['distance_meters'])) {
                    $sub['journey_distance_meters'] = (float)$cand['distance_meters'];
                }
                break;
            }
        }
    }
}

// Enrich each waypoint with missing geodesic bearing, direction text, arrow emoji, and cumulative distance
$totalCalculatedMeters = 0.0;
$runningCumulativeDist = 0.0;
if (!empty($journeyPoints)) {
    for ($i = 0; $i < count($journeyPoints); $i++) {
        $p = &$journeyPoints[$i];
        $lat = (float)($p['lat'] ?? 0);
        $lng = (float)($p['lng'] ?? 0);

        if ($i > 0) {
            $prev = $journeyPoints[$i - 1];
            $prevLat = (float)($prev['lat'] ?? 0);
            $prevLng = (float)($prev['lng'] ?? 0);

            $step = fp_haversine_dist($prevLat, $prevLng, $lat, $lng);
            $totalCalculatedMeters += $step;
            $runningCumulativeDist += $step;

            if (!isset($p['distance_from_prev']) || $p['distance_from_prev'] === null) {
                $p['distance_from_prev'] = round($step, 1);
            }
            if (!isset($p['bearing']) || $p['bearing'] === null) {
                $p['bearing'] = fp_calc_bearing($prevLat, $prevLng, $lat, $lng);
            }
        } else {
            if (!isset($p['distance_from_prev'])) {
                $p['distance_from_prev'] = 0.0;
            }
            if (!isset($p['bearing']) || $p['bearing'] === null) {
                if (isset($journeyPoints[1])) {
                    $p['bearing'] = fp_calc_bearing($lat, $lng, (float)$journeyPoints[1]['lat'], (float)$journeyPoints[1]['lng']);
                } else {
                    $p['bearing'] = 0.0;
                }
            }
        }

        $p['cumulative_dist'] = round($runningCumulativeDist, 1);

        $bDeg = fmod(fmod((float)$p['bearing'], 360) + 360, 360);
        if (empty($p['direction'])) {
            $p['direction'] = fp_direction_label($bDeg);
        }
        if (empty($p['arrow'])) {
            $p['arrow'] = fp_direction_arrow($bDeg);
        }
    }
    unset($p);

    // Identify 1-Minute Interval Milestones for Map Display (leaves all points for the detailed telematics log)
    $startTs = !empty($journeyPoints[0]['timestamp']) ? strtotime($journeyPoints[0]['timestamp']) : null;
    $lastMappedTs = $startTs;
    $mapWaypointsCount = 0;

    for ($i = 0; $i < count($journeyPoints); $i++) {
        $p = &$journeyPoints[$i];
        $isStart = ($i === 0);
        $isEnd = ($i === count($journeyPoints) - 1);
        $curTs = !empty($p['timestamp']) ? strtotime($p['timestamp']) : null;

        if ($isStart) {
            $p['is_map_waypoint'] = true;
            $p['minute_mark'] = 0;
            $mapWaypointsCount++;
        } elseif ($isEnd) {
            $p['is_map_waypoint'] = true;
            $minuteMark = ($startTs && $curTs && $curTs >= $startTs) ? round(($curTs - $startTs) / 60) : round((count($journeyPoints) * 2) / 60);
            $p['minute_mark'] = $minuteMark;
            $mapWaypointsCount++;
        } else {
            // Check if at least 58 seconds have elapsed since last mapped waypoint
            $diffSec = ($lastMappedTs && $curTs) ? ($curTs - $lastMappedTs) : null;
            if ($diffSec !== null && $diffSec >= 58) {
                $p['is_map_waypoint'] = true;
                $p['minute_mark'] = ($startTs && $curTs) ? round(($curTs - $startTs) / 60) : round($i);
                $lastMappedTs = $curTs;
                $mapWaypointsCount++;
            } else {
                $p['is_map_waypoint'] = false;
                $p['minute_mark'] = null;
            }
        }
    }
    unset($p);

    // Fallback if timestamps are missing or invalid: sample every minute based on total duration or point count
    if ($mapWaypointsCount <= 2 && count($journeyPoints) > 4) {
        $totalDurSec = (int)($sub['journey_duration_seconds'] ?? 0);
        if ($totalDurSec >= 120) {
            $numMins = floor($totalDurSec / 60);
            $step = max(2, (int)round(count($journeyPoints) / ($numMins + 1)));
            $mapWaypointsCount = 0;
            for ($i = 0; $i < count($journeyPoints); $i++) {
                if ($i === 0 || $i === count($journeyPoints) - 1 || ($i % $step === 0)) {
                    $journeyPoints[$i]['is_map_waypoint'] = true;
                    $journeyPoints[$i]['minute_mark'] = round(($i / count($journeyPoints)) * ($totalDurSec / 60));
                    $mapWaypointsCount++;
                } else {
                    $journeyPoints[$i]['is_map_waypoint'] = false;
                    $journeyPoints[$i]['minute_mark'] = null;
                }
            }
        }
    }

    if (empty($sub['journey_distance_meters']) || (float)$sub['journey_distance_meters'] <= 0) {
        $sub['journey_distance_meters'] = round($totalCalculatedMeters, 1);
    }
    if (empty($sub['journey_start_time']) && !empty($journeyPoints[0]['timestamp'])) {
        $sub['journey_start_time'] = date('Y-m-d H:i:s', strtotime($journeyPoints[0]['timestamp']));
    }
    if (empty($sub['journey_end_time']) && !empty($journeyPoints[count($journeyPoints) - 1]['timestamp'])) {
        $sub['journey_end_time'] = date('Y-m-d H:i:s', strtotime($journeyPoints[count($journeyPoints) - 1]['timestamp']));
    }
    if (empty($sub['journey_duration_seconds']) && !empty($sub['journey_start_time']) && !empty($sub['journey_end_time'])) {
        $sub['journey_duration_seconds'] = max(0, strtotime($sub['journey_end_time']) - strtotime($sub['journey_start_time']));
    }

    $sub['journey_path_json'] = json_encode($journeyPoints, JSON_UNESCAPED_SLASHES);

    if (empty($sub['latitude']) && isset($journeyPoints[0]['lat'])) {
        $sub['latitude'] = $journeyPoints[0]['lat'];
        $sub['longitude'] = $journeyPoints[0]['lng'];
    }

    // Seamlessly backfill to database if it was missing in the table column
    try {
        $updTrack = $pdo->prepare("
            UPDATE submissions 
            SET journey_path_json = :jp,
                journey_start_time = COALESCE(journey_start_time, :jst),
                journey_end_time = COALESCE(journey_end_time, :jet),
                journey_duration_seconds = COALESCE(journey_duration_seconds, :jds),
                journey_distance_meters = COALESCE(journey_distance_meters, :jdm),
                latitude = COALESCE(latitude, :lat),
                longitude = COALESCE(longitude, :lng)
            WHERE submission_uuid = :uuid AND (journey_path_json IS NULL OR journey_path_json = '')
        ");
        $updTrack->execute([
            'jp' => $sub['journey_path_json'],
            'jst' => $sub['journey_start_time'] ?? null,
            'jet' => $sub['journey_end_time'] ?? null,
            'jds' => $sub['journey_duration_seconds'] ?? null,
            'jdm' => $sub['journey_distance_meters'] ?? null,
            'lat' => $sub['latitude'] ?? null,
            'lng' => $sub['longitude'] ?? null,
            'uuid' => $uuid
        ]);
    } catch (\Exception $e) {
        // Silently continue
    }
}

// Compute External Route URLs
$googleRouteUrl = '';
$osmRouteUrl = '';
$hasJourney = !empty($journeyPoints) && count($journeyPoints) >= 2;

if ($hasJourney) {
    $firstPoint = $journeyPoints[0];
    $lastPoint = $journeyPoints[count($journeyPoints) - 1];
    $originStr = $firstPoint['lat'] . ',' . $firstPoint['lng'];
    $destStr = $lastPoint['lat'] . ',' . $lastPoint['lng'];

    $numPts = count($journeyPoints);
    $waypointsSample = [];
    if ($numPts > 2) {
        $maxIntermediate = 8;
        $stepRatio = ($numPts - 2) / min($maxIntermediate, $numPts - 2);
        for ($s = 0; $s < min($maxIntermediate, $numPts - 2); $s++) {
            $sampleIdx = (int)round(1 + $s * $stepRatio);
            if ($sampleIdx >= 1 && $sampleIdx < $numPts - 1) {
                $waypointsSample[] = $journeyPoints[$sampleIdx]['lat'] . ',' . $journeyPoints[$sampleIdx]['lng'];
            }
        }
    }
    $waypointsStr = implode('|', array_unique($waypointsSample));

    $googleRouteUrl = "https://www.google.com/maps/dir/?api=1&origin=" . urlencode($originStr) . "&destination=" . urlencode($destStr);
    if (!empty($waypointsStr)) {
        $googleRouteUrl .= "&waypoints=" . urlencode($waypointsStr);
    }
    $osmRouteUrl = "https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=" . urlencode($firstPoint['lat'] . ',' . $firstPoint['lng'] . ';' . $lastPoint['lat'] . ',' . $lastPoint['lng']);
} elseif (!empty($sub['latitude']) && !empty($sub['longitude'])) {
    $googleRouteUrl = "https://www.google.com/maps?q=" . $sub['latitude'] . "," . $sub['longitude'];
    $osmRouteUrl = "https://www.openstreetmap.org/?mlat=" . $sub['latitude'] . "&mlon=" . $sub['longitude'] . "#map=16/" . $sub['latitude'] . "/" . $sub['longitude'];
}

require_once __DIR__ . '/header.php';
?>

<style>
@media print {
    .navbar, footer, .btn, .no-print, a[href="submissions.php"], .dropdown {
        display: none !important;
    }
    body {
        background-color: #fff !important;
        color: #0f172a !important;
        font-size: 10.5pt;
        line-height: 1.4;
    }
    .print-only-header {
        display: block !important;
        border-bottom: 2px solid #0f172a;
        padding-bottom: 12px;
        margin-bottom: 18px;
    }
    .print-signoff-box {
        display: flex !important;
        justify-content: space-between;
        margin-top: 30px;
        padding-top: 15px;
        border-top: 1px dashed #64748b;
        break-inside: avoid;
    }
    .card {
        border: 1px solid #cbd5e1 !important;
        box-shadow: none !important;
        break-inside: avoid;
        margin-bottom: 14px !important;
    }
    .card-header {
        background-color: #f1f5f9 !important;
        color: #0f172a !important;
        font-weight: bold !important;
        border-bottom: 1px solid #cbd5e1 !important;
    }
    #subMap, #journeyMap {
        height: 220px !important;
        border: 1px solid #cbd5e1 !important;
    }
    .container, .container-fluid {
        max-width: 100% !important;
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
}
.print-only-header, .print-signoff-box {
    display: none;
}

/* Journey Map & Direction Flow Styles */
.route-arrow-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: transform 0.15s ease-in-out, filter 0.15s ease-in-out;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.45));
}
.route-arrow-wrap:hover {
    transform: scale(1.25) !important;
    filter: drop-shadow(0 4px 8px rgba(0,0,0,0.7));
    z-index: 1000 !important;
}
.waypoint-badge-pin {
    background: #0284c7;
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 5px rgba(0,0,0,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
.start-marker-badge {
    background: #16a34a;
    color: #ffffff;
    font-weight: 700;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 12px;
    border: 2px solid #ffffff;
    box-shadow: 0 3px 8px rgba(0,0,0,0.35);
    white-space: nowrap;
    cursor: pointer;
}
.end-marker-badge {
    background: #dc2626;
    color: #ffffff;
    font-weight: 700;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 12px;
    border: 2px solid #ffffff;
    box-shadow: 0 3px 8px rgba(0,0,0,0.35);
    white-space: nowrap;
    cursor: pointer;
}
.timeline-row:hover {
    background-color: #f0fdf4 !important;
}
.timeline-active-row {
    background-color: #e0f2fe !important;
    border-left: 4px solid #0284c7 !important;
}
</style>

<!-- Print Only Official Header -->
<div class="print-only-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold mb-0 text-primary">FIELDPLUS &bull; INSPECTION &amp; AUDIT CERTIFICATE</h3>
            <small class="text-muted">Enterprise Field Operations Verification Sheet</small>
        </div>
        <div class="text-end">
            <div class="fw-bold text-dark">GENERATED: <?= date('Y-m-d H:i:s') ?></div>
            <small class="text-secondary">UUID: <?= htmlspecialchars($sub['submission_uuid']) ?></small>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="submissions.php" class="btn btn-sm btn-outline-secondary mb-2">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Submissions
        </a>
        <h2 class="fw-bold mb-1">
            <i class="fa-solid fa-clipboard-check text-primary me-2"></i>
            <?= htmlspecialchars($sub['form_title']) ?>
        </h2>
        <p class="text-muted mb-0">Field Visit Audit Report &bull; UUID: <code class="text-dark"><?= htmlspecialchars($sub['submission_uuid']) ?></code></p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Print / PDF
        </button>
        <?php
        $st = strtolower($sub['status'] ?? 'submitted');
        $stBadge = match($st) {
            'approved' => 'bg-success-subtle text-success border border-success-subtle',
            'flagged' => 'bg-warning-subtle text-warning border border-warning-subtle',
            'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
            default => 'bg-primary-subtle text-primary border border-primary-subtle',
        };
        ?>
        <span class="badge <?= $stBadge ?> fs-6 align-self-center px-3 py-2">
            <i class="fa-solid fa-certificate me-1"></i> <?= strtoupper($sub['status']) ?>
        </span>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Meta Summary -->
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-header py-3">
                <span class="fw-bold"><i class="fa-solid fa-circle-info text-primary me-2"></i>Visit Metadata</span>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-2">
                        <small class="text-muted d-block">Surveyor / Collector Account:</small>
                        <span class="fw-bold fs-6"><?= htmlspecialchars($sub['user_full_name'] ?: $sub['submitter_name'] ?: 'Field Officer') ?></span>
                        <?php if (!empty($sub['user_username'])): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-1" style="font-size: 11px;">
                                @<?= htmlspecialchars($sub['user_username']) ?> (<?= ucfirst($sub['user_role'] ?? 'staff') ?>)
                            </span>
                        <?php endif; ?>
                    </li>
                    <li class="mb-2">
                        <small class="text-muted d-block">Device Info:</small>
                        <code><?= htmlspecialchars($sub['device_id'] ?: 'Android Device') ?></code>
                    </li>
                    <li class="mb-2">
                        <small class="text-muted d-block">Submitted Timestamp:</small>
                        <span><?= htmlspecialchars($sub['submitted_at'] ?? $sub['created_at']) ?></span>
                    </li>
                    <li class="mb-2">
                        <small class="text-muted d-block">Form ID & Category:</small>
                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($sub['form_id']) ?></span>
                        <span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($sub['form_category']) ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Geolocation & Journey Track Map -->
    <div class="col-md-8">
        <div class="card h-100 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center py-3 flex-wrap gap-2">
                <span class="fw-bold">
                    <?php if (!empty($journeyPoints)): ?>
                        <i class="fa-solid fa-route text-success me-2"></i>Survey Journey Track Map
                        <span class="badge bg-success-subtle text-success border ms-2">
                            <i class="fa-solid fa-clock me-1"></i>1-Min Map Waypoints (<span id="mapPointsCountHeader"><?= $mapWaypointsCount ?></span> on map / <?= count($journeyPoints) ?> in log)
                        </span>
                    <?php else: ?>
                        <i class="fa-solid fa-map-pin text-danger me-2"></i>Field Geolocation Fix
                    <?php endif; ?>
                </span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <!-- Base Layer Switcher -->
                    <div class="btn-group btn-group-sm" role="group" aria-label="SubMap Layer">
                        <button type="button" class="btn btn-outline-secondary" id="btnSubMap_osm" onclick="switchSubMapProvider('osm')" title="OpenStreetMap">
                            <i class="fa-solid fa-map me-1"></i> OSM
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnSubMap_google_roads" onclick="switchSubMapProvider('google_roads')" title="Google Maps Roads">
                            <i class="fa-brands fa-google text-primary me-1"></i> Google
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnSubMap_google_hybrid" onclick="switchSubMapProvider('google_hybrid')" title="Google Maps Satellite Hybrid">
                            <i class="fa-solid fa-satellite text-success me-1"></i> Satellite
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnSubMap_google_terrain" onclick="switchSubMapProvider('google_terrain')" title="Google Maps Terrain">
                            <i class="fa-solid fa-mountain text-warning me-1"></i> Terrain
                        </button>
                    </div>

                    <?php if (!empty($journeyPoints)): ?>
                        <!-- Quick Track Controls -->
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary" onclick="fitSubMapBounds()" title="Fit track in view">
                                <i class="fa-solid fa-expand me-1"></i>Fit
                            </button>
                            <button type="button" class="btn btn-primary active" id="btnToggleArrows" onclick="toggleRouteArrows()" title="Toggle Direction Flow Arrows on Map">
                                <i class="fa-solid fa-location-arrow me-1"></i>Arrows
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="btnToggleWaypoints" onclick="toggleRouteWaypoints()" title="Toggle Numbered 1-Min Milestone Badges on Map">
                                <i class="fa-solid fa-map-pin me-1"></i>Milestones
                            </button>
                            <button type="button" class="btn btn-outline-info" id="btnToggleMapDensity" onclick="toggleMapPointDensity()" title="Toggle between 1-min waypoints only and all points on map">
                                <i class="fa-solid fa-filter me-1"></i><span id="densityBtnLabel">1-Min View</span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($sub['latitude']) && !empty($sub['longitude'])): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> View External
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <?php if ($hasJourney): ?>
                                    <li>
                                        <a class="dropdown-item fw-semibold text-primary" href="<?= $googleRouteUrl ?>" target="_blank">
                                            <i class="fa-brands fa-google text-danger me-2"></i>Google Maps Driving Route
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item fw-semibold text-success" href="<?= $osmRouteUrl ?>" target="_blank">
                                            <i class="fa-solid fa-diamond-turn-right me-2"></i>OpenStreetMap Directions
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item text-secondary small" href="https://www.google.com/maps?q=<?= $sub['latitude'] ?>,<?= $sub['longitude'] ?>" target="_blank">
                                            <i class="fa-solid fa-location-dot me-2"></i>Google Maps (Single Pin)
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li class="dropdown-header text-uppercase small text-muted"><i class="fa-solid fa-download me-1"></i>Export GPS Track</li>
                                    <li>
                                        <button class="dropdown-item" type="button" onclick="exportJourneyFile('gpx')">
                                            <i class="fa-solid fa-file-code text-primary me-2"></i>Download GPX Track (.gpx)
                                        </button>
                                    </li>
                                    <li>
                                        <button class="dropdown-item" type="button" onclick="exportJourneyFile('kml')">
                                            <i class="fa-solid fa-earth-americas text-info me-2"></i>Download KML File (.kml)
                                        </button>
                                    </li>
                                    <li>
                                        <button class="dropdown-item" type="button" onclick="exportJourneyFile('geojson')">
                                            <i class="fa-solid fa-file-lines text-warning me-2"></i>Download GeoJSON (.json)
                                        </button>
                                    </li>
                                <?php else: ?>
                                    <li>
                                        <a class="dropdown-item" href="https://www.google.com/maps?q=<?= $sub['latitude'] ?>,<?= $sub['longitude'] ?>" target="_blank">
                                            <i class="fa-brands fa-google text-danger me-2"></i>Google Maps
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="https://www.openstreetmap.org/?mlat=<?= $sub['latitude'] ?>&mlon=<?= $sub['longitude'] ?>#map=16/<?= $sub['latitude'] ?>/<?= $sub['longitude'] ?>" target="_blank">
                                            <i class="fa-solid fa-map text-success me-2"></i>OpenStreetMap
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="https://earth.google.com/web/search/<?= $sub['latitude'] ?>,<?= $sub['longitude'] ?>" target="_blank">
                                            <i class="fa-solid fa-earth-americas text-primary me-2"></i>Google Earth
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($journeyPoints) || (!empty($sub['latitude']) && !empty($sub['longitude']))): ?>
                    <div id="subMap" style="height: 420px; width: 100%;"></div>
                    <div class="p-3 bg-light d-flex justify-content-around text-center small border-top flex-wrap gap-2">
                        <?php if (!empty($journeyPoints)): 
                            $pts = $journeyPoints;
                            $durSec = $sub['journey_duration_seconds'] ?? 0;
                            $durFormatted = sprintf('%02d:%02d:%02d', ($durSec / 3600), ($durSec / 60 % 60), $durSec % 60);
                            $distKm = !empty($sub['journey_distance_meters']) ? round($sub['journey_distance_meters'] / 1000, 2) : 0;
                            $avgSpeedKmh = ($durSec > 0 && !empty($sub['journey_distance_meters'])) ? round(($sub['journey_distance_meters'] / $durSec) * 3.6, 1) : '--';
                        ?>
                            <div>
                                <span class="text-muted d-block">Map Waypoints</span>
                                <strong><i class="fa-solid fa-clock text-info me-1"></i><?= $mapWaypointsCount ?> (1-min)</strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">Log Fixes</span>
                                <strong><i class="fa-solid fa-location-dot text-primary me-1"></i><?= count($pts) ?> points</strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">Duration</span>
                                <strong><i class="fa-regular fa-clock text-info me-1"></i><?= $durFormatted ?></strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">Distance</span>
                                <strong><i class="fa-solid fa-road text-success me-1"></i><?= $distKm ?> km (<?= round($sub['journey_distance_meters'] ?? 0) ?>m)</strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">Avg Speed</span>
                                <strong><i class="fa-solid fa-gauge-high text-warning me-1"></i><?= $avgSpeedKmh ?> km/h</strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">Start Time</span>
                                <strong><?= $sub['journey_start_time'] ? date('H:i:s', strtotime($sub['journey_start_time'])) : 'N/A' ?></strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">End Time</span>
                                <strong><?= $sub['journey_end_time'] ? date('H:i:s', strtotime($sub['journey_end_time'])) : 'N/A' ?></strong>
                            </div>
                        <?php else: ?>
                            <div>
                                <span class="text-muted d-block">Latitude</span>
                                <strong><?= round($sub['latitude'], 6) ?>°</strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">Longitude</span>
                                <strong><?= round($sub['longitude'], 6) ?>°</strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">Accuracy</span>
                                <strong>&plusmn; <?= round($sub['accuracy'] ?? 0, 1) ?> m</strong>
                            </div>
                            <div>
                                <span class="text-muted d-block">Altitude</span>
                                <strong><?= round($sub['altitude'] ?? 0, 1) ?> m</strong>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-location-slash fa-2x mb-2 text-secondary"></i>
                        <p class="mb-0">No GPS coordinates or journey track recorded for this submission.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($journeyPoints)): ?>
<!-- Route Waypoints & Direction Timeline Card -->
<div class="card shadow-sm mb-4 border-info">
    <div class="card-header bg-info-subtle py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <span class="fw-bold text-dark">
                <i class="fa-solid fa-route text-info me-2"></i>Route Waypoint &amp; Direction Log
            </span>
            <span class="badge bg-primary ms-2"><?= count($journeyPoints) ?> Total Logged Fixes</span>
            <span class="badge bg-success ms-1"><?= $mapWaypointsCount ?> on Map (1-Min)</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary active" id="btnFilterLogAll" onclick="filterLogTable('all')">
                    All Log Fixes (<?= count($journeyPoints) ?>)
                </button>
                <button type="button" class="btn btn-outline-primary" id="btnFilterLogMapOnly" onclick="filterLogTable('map_only')">
                    <i class="fa-solid fa-map-pin me-1"></i>1-Min Map Points (<?= $mapWaypointsCount ?>)
                </button>
            </div>
            <small class="text-muted d-none d-md-inline"><i class="fa-solid fa-circle-info me-1"></i>Click any row to focus on map</small>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
            <table class="table table-hover table-sm align-middle mb-0 text-center small" id="waypointsTimelineTable">
                <thead class="table-light sticky-top shadow-sm">
                    <tr>
                        <th style="width: 55px;">#</th>
                        <th>Map Status</th>
                        <th class="text-start">Time Registered</th>
                        <th>Direction Taken</th>
                        <th>Bearing</th>
                        <th>Speed</th>
                        <th>Step Dist</th>
                        <th>Cumulative</th>
                        <th>Acc / Alt</th>
                        <th>Coordinates</th>
                        <th style="width: 85px;">Locate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $cumDist = 0.0;
                    foreach ($journeyPoints as $idx => $pt): 
                        $isStart = ($idx === 0);
                        $isEnd = ($idx === count($journeyPoints) - 1);
                        $isMapPoint = !empty($pt['is_map_waypoint']);
                        $minMark = $pt['minute_mark'] ?? null;
                        $b = (float)($pt['bearing'] ?? 0);
                        $stepD = (float)($pt['distance_from_prev'] ?? 0);
                        $cumDist += $stepD;
                        $speedKmh = isset($pt['speed']) && $pt['speed'] !== null ? round((float)$pt['speed'] * 3.6, 1) . ' km/h' : '--';
                        $altStr = isset($pt['alt']) && $pt['alt'] !== null ? round((float)$pt['alt'], 1) . 'm' : '--';
                        $accStr = isset($pt['accuracy']) && $pt['accuracy'] !== null ? '&plusmn;' . round((float)$pt['accuracy'], 1) . 'm' : '--';
                    ?>
                    <tr class="timeline-row <?= $isMapPoint ? 'is-map-milestone' : 'is-log-only' ?>" id="timelineRow_<?= $idx ?>" style="cursor: pointer;" onclick="focusWaypointOnMap(<?= $idx ?>)">
                        <td>
                            <span class="text-muted fw-semibold">#<?= $idx + 1 ?></span>
                        </td>
                        <td>
                            <?php if ($isStart): ?>
                                <span class="badge bg-success" title="Journey Start Point (Shown on Map)"><i class="fa-solid fa-flag-checkered me-1"></i>Start (0m)</span>
                            <?php elseif ($isEnd): ?>
                                <span class="badge bg-danger" title="Journey End Point (Shown on Map)"><i class="fa-solid fa-circle-stop me-1"></i>End</span>
                            <?php elseif ($isMapPoint): ?>
                                <span class="badge bg-primary text-white" title="1-Minute Waypoint (Shown on Map)"><i class="fa-solid fa-map-pin me-1"></i><?= $minMark !== null ? $minMark . ' min' : 'Milestone' ?></span>
                            <?php else: ?>
                                <span class="badge bg-light text-secondary border" title="Detailed Telematics Log Fix (Saved in log)">Log Fix</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-start">
                            <strong><?= !empty($pt['timestamp']) ? date('H:i:s', strtotime($pt['timestamp'])) : '--' ?></strong>
                            <small class="text-muted d-block" style="font-size: 10px;"><?= !empty($pt['timestamp']) ? date('Y-m-d', strtotime($pt['timestamp'])) : '' ?></small>
                        </td>
                        <td>
                            <span class="badge bg-primary-subtle text-primary border">
                                <span class="d-inline-block" style="transform: rotate(<?= $b ?>deg); font-weight: bold; margin-right: 2px; font-size: 13px;">⬆</span>
                                <?= htmlspecialchars($pt['direction'] ?? '') ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-dark fw-semibold"><?= round($b, 1) ?>°</span>
                        </td>
                        <td>
                            <span class="text-info-emphasis"><?= $speedKmh ?></span>
                        </td>
                        <td>
                            <span class="text-muted"><?= $isStart ? '0 m' : '+' . round($stepD) . ' m' ?></span>
                        </td>
                        <td>
                            <strong><?= $cumDist >= 1000 ? round($cumDist / 1000, 2) . ' km' : round($cumDist) . ' m' ?></strong>
                        </td>
                        <td>
                            <span class="text-muted"><?= $accStr ?> / <?= $altStr ?></span>
                        </td>
                        <td class="font-monospace text-secondary" style="font-size: 11px;">
                            <?= number_format((float)$pt['lat'], 6) ?>, <?= number_format((float)$pt['lng'], 6) ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-xs <?= $isMapPoint ? 'btn-primary' : 'btn-outline-primary' ?> py-0 px-2" style="font-size: 11px;" onclick="event.stopPropagation(); focusWaypointOnMap(<?= $idx ?>);">
                                <i class="fa-solid fa-crosshairs me-1"></i>Focus
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Supervisor Verification & Audit Signoff Card -->
<div class="card shadow-sm mb-4 border-primary">
    <div class="card-header bg-primary-subtle py-3 d-flex justify-content-between align-items-center">
        <span class="fw-bold text-primary">
            <i class="fa-solid fa-stamp me-2"></i>Supervisor Verification &amp; Audit Signoff
        </span>
        <?php if (!empty($sub['reviewed_at'])): ?>
            <small class="text-muted"><i class="fa-regular fa-clock me-1"></i>Last reviewed: <?= htmlspecialchars($sub['reviewed_at']) ?></small>
        <?php endif; ?>
    </div>
    <div class="card-body p-3">
        <?php if ($reviewMsg): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 mb-3" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($reviewMsg) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($reviewError): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 mb-3" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($reviewError) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($canWrite): ?>
            <form method="POST" class="row g-3 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_review">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Audit Decision</label>
                    <select name="status" class="form-select">
                        <option value="submitted" <?= ($sub['status'] === 'submitted') ? 'selected' : '' ?>>⏳ Pending Review (Submitted)</option>
                        <option value="approved" <?= ($sub['status'] === 'approved') ? 'selected' : '' ?>>✅ Verified &amp; Approved</option>
                        <option value="flagged" <?= ($sub['status'] === 'flagged') ? 'selected' : '' ?>>⚠️ Flagged for Clarification</option>
                        <option value="rejected" <?= ($sub['status'] === 'rejected') ? 'selected' : '' ?>>❌ Rejected</option>
                    </select>
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold small">Supervisor Review Notes / Compliance Findings</label>
                    <input type="text" name="review_notes" class="form-control" placeholder="Optional comments, instructions, or findings..." value="<?= htmlspecialchars($sub['review_notes'] ?? '') ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="fa-solid fa-check me-1"></i> Save Audit
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="d-flex align-items-center justify-content-between p-2 bg-light rounded border">
                <div>
                    <span class="text-muted small d-block">Current Review Status:</span>
                    <strong><?= strtoupper($sub['status']) ?></strong>
                </div>
                <div class="text-end">
                    <span class="text-muted small d-block">Supervisor Notes:</span>
                    <span class="text-dark"><?= htmlspecialchars($sub['review_notes'] ?: 'No supervisor review comments entered yet.') ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Survey Answers Grouped by Sections -->
<div class="card shadow-sm mb-4">
    <div class="card-header py-3">
        <span class="fw-bold"><i class="fa-solid fa-list-check text-primary me-2"></i>Recorded Survey Responses</span>
    </div>
    <div class="card-body p-4">
        <?php foreach ($schema['sections'] ?? [] as $section): ?>
            <div class="mb-4">
                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
                    <i class="fa-regular fa-folder-open text-primary me-2"></i>
                    <?= htmlspecialchars($section['title']) ?>
                </h5>
                <div class="row g-3">
                    <?php foreach ($section['fields'] ?? [] as $fld): 
                        $fId = $fld['id'];
                        $ans = $data[$fId] ?? null;
                    ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small fw-semibold mb-1 d-block">
                                <?= htmlspecialchars($fld['label']) ?>
                            </label>

                            <?php if ($ans === null || $ans === ''): ?>
                                <span class="text-muted fst-italic">Not specified</span>

                            <?php elseif ($fld['type'] === 'gps'): ?>
                                <?php if (is_array($ans)): ?>
                                    <div class="border rounded p-2 bg-light font-monospace small">
                                        <i class="fa-solid fa-location-crosshairs text-danger me-1"></i>
                                        Lat: <?= $ans['latitude'] ?? 'N/A' ?>, Lng: <?= $ans['longitude'] ?? 'N/A' ?>
                                        <div class="text-muted mt-1" style="font-size: 11px;">
                                            Accuracy: &plusmn;<?= $ans['accuracy'] ?? 'N/A' ?>m | Altitude: <?= $ans['altitude'] ?? 'N/A' ?>m
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span><?= htmlspecialchars((string)$ans) ?></span>
                                <?php endif; ?>

                            <?php elseif ($fld['type'] === 'switch'): ?>
                                <?php if ($ans): ?>
                                    <span class="badge bg-success-subtle text-success fs-6"><i class="fa-solid fa-check me-1"></i> Yes / True</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary fs-6"><i class="fa-solid fa-xmark me-1"></i> No / False</span>
                                <?php endif; ?>

                            <?php elseif ($fld['type'] === 'checkbox'): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php if (is_array($ans)): ?>
                                        <?php foreach ($ans as $opt): ?>
                                            <span class="badge bg-primary-subtle text-primary"><?= htmlspecialchars($opt) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary"><?= htmlspecialchars($ans) ?></span>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($fld['type'] === 'file'): ?>
                                <?php 
                                    $fileUrl = is_array($ans) ? ($ans['url'] ?? '') : (is_string($ans) && (strpos($ans, 'http') === 0 || strpos($ans, '/uploads') === 0) ? $ans : '');
                                    if (!$fileUrl) {
                                        foreach ($attachments as $att) {
                                            if ($att['field_id'] === $fId || strpos($att['field_id'], $fId) === 0) {
                                                $fileUrl = $att['file_path'];
                                                break;
                                            }
                                        }
                                    }
                                    if ($fileUrl): 
                                ?>
                                    <div class="border rounded p-2 bg-light">
                                        <a href="<?= htmlspecialchars($fileUrl) ?>" target="_blank" class="d-block mb-2">
                                            <img src="<?= htmlspecialchars($fileUrl) ?>" alt="Evidence Photo" class="img-fluid rounded border" style="max-height: 200px;">
                                        </a>
                                        <a href="<?= htmlspecialchars($fileUrl) ?>" download class="btn btn-sm btn-outline-secondary">
                                            <i class="fa-solid fa-download me-1"></i> Download Photo
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No file attached</span>
                                <?php endif; ?>

                            <?php elseif ($fld['type'] === 'multiple_photos'): ?>
                                <?php
                                    $photos = [];
                                    if (is_array($ans)) {
                                        if (isset($ans['url'])) {
                                            $photos[] = $ans['url'];
                                        } else {
                                            foreach ($ans as $p) {
                                                if (is_array($p) && isset($p['url'])) $photos[] = $p['url'];
                                                elseif (is_string($p) && (strpos($p, 'http') === 0 || strpos($p, '/uploads') === 0)) $photos[] = $p;
                                            }
                                        }
                                    }
                                    // Also check attachments list for matching fieldId
                                    if (empty($photos)) {
                                        foreach ($attachments as $att) {
                                            if ($att['field_id'] === $fId || strpos($att['field_id'], $fId) === 0) {
                                                $photos[] = $att['file_path'];
                                            }
                                        }
                                    }
                                ?>
                                <?php if (!empty($photos)): ?>
                                    <div class="border rounded p-2 bg-light">
                                        <div class="d-flex flex-wrap gap-2 mb-2">
                                            <?php foreach ($photos as $pUrl): ?>
                                                <a href="<?= htmlspecialchars($pUrl) ?>" target="_blank" class="d-inline-block border rounded overflow-hidden shadow-sm">
                                                    <img src="<?= htmlspecialchars($pUrl) ?>" alt="Photo" style="width: 110px; height: 110px; object-fit: cover;">
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                        <small class="text-muted"><i class="fa-solid fa-camera me-1"></i><?= count($photos) ?> photos captured for this item</small>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No photos attached</span>
                                <?php endif; ?>

                            <?php elseif ($fld['type'] === 'video'): ?>
                                <?php
                                    $videoUrl = is_array($ans) ? ($ans['url'] ?? '') : (is_string($ans) && (strpos($ans, 'http') === 0 || strpos($ans, '/uploads') === 0) ? $ans : '');
                                    if (!$videoUrl) {
                                        foreach ($attachments as $att) {
                                            if (($att['field_id'] === $fId || strpos($att['field_id'], $fId) === 0) && $att['file_type'] === 'video') {
                                                $videoUrl = $att['file_path'];
                                                break;
                                            }
                                        }
                                    }
                                ?>
                                <?php if ($videoUrl): ?>
                                    <div class="border rounded p-2 bg-light">
                                        <video controls class="w-100 rounded border mb-2" style="max-height: 280px; background: #000;">
                                            <source src="<?= htmlspecialchars($videoUrl) ?>">
                                            Your browser does not support HTML5 video playback.
                                        </video>
                                        <a href="<?= htmlspecialchars($videoUrl) ?>" download class="btn btn-sm btn-outline-danger">
                                            <i class="fa-solid fa-download me-1"></i> Download Video Clip
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No video recorded</span>
                                <?php endif; ?>

                            <?php elseif ($fld['type'] === 'journey_gps'): ?>
                                <div class="border rounded p-3 bg-light border-success-subtle">
                                    <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                                        <strong class="text-success"><i class="fa-solid fa-route me-1"></i>Continuous GPS Track Recorded</strong>
                                        <span class="badge bg-success-subtle text-success border"><i class="fa-solid fa-location-arrow me-1"></i><?= count($journeyPoints) ?> waypoints</span>
                                    </div>
                                    <div class="small text-muted mb-2">
                                        Total Distance: <strong><?= !empty($sub['journey_distance_meters']) ? round($sub['journey_distance_meters'] / 1000, 2) . ' km' : 'N/A' ?></strong> &bull;
                                        Duration: <strong><?= !empty($sub['journey_duration_seconds']) ? sprintf('%02d:%02d:%02d', ($sub['journey_duration_seconds'] / 3600), ($sub['journey_duration_seconds'] / 60 % 60), $sub['journey_duration_seconds'] % 60) : 'N/A' ?></strong>
                                    </div>
                                    <a href="#subMap" onclick="fitSubMapBounds(); document.getElementById('subMap').scrollIntoView({behavior:'smooth'});" class="btn btn-sm btn-outline-success py-0 px-2">
                                        <i class="fa-solid fa-map-location-dot me-1"></i>View Route &amp; Direction Timeline On Map
                                    </a>
                                </div>

                            <?php elseif ($fld['type'] === 'signature'): ?>
                                <?php 
                                    $sigUrl = is_array($ans) ? ($ans['url'] ?? '') : (is_string($ans) && (strpos($ans, 'http') === 0 || strpos($ans, '/uploads') === 0) ? $ans : '');
                                    if (!$sigUrl) {
                                        foreach ($attachments as $att) {
                                            if (($att['field_id'] === $fId || strpos($att['field_id'], $fId) === 0) && $att['file_type'] === 'signature') {
                                                $sigUrl = $att['file_path'];
                                                break;
                                            }
                                        }
                                    }
                                    if ($sigUrl): 
                                ?>
                                    <div class="border rounded p-2 bg-white" style="max-width: 350px;">
                                        <img src="<?= htmlspecialchars($sigUrl) ?>" alt="Signature" class="img-fluid border rounded" style="max-height: 100px; background:#fff;">
                                        <small class="text-success d-block mt-1"><i class="fa-solid fa-certificate me-1"></i> Digitally Signed on Device</small>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No signature captured</span>
                                <?php endif; ?>

                            <?php else: ?>
                                <div class="p-2 bg-light border rounded text-dark">
                                    <?= nl2br(htmlspecialchars(is_array($ans) ? json_encode($ans) : (string)$ans)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($attachments)): ?>
<!-- All Uploaded Evidence & Media Gallery -->
<div class="card shadow-sm mb-4">
    <div class="card-header py-3 bg-light d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fa-solid fa-photo-film text-primary me-2"></i>All Uploaded Evidence &amp; Media Attachments (<?= count($attachments) ?>)</span>
    </div>
    <div class="card-body p-3">
        <div class="row g-3">
            <?php foreach ($attachments as $att): 
                $fPath = $att['file_path'];
                $fType = $att['file_type'];
                $fName = htmlspecialchars($att['file_name']);
                $fSizeKb = round(($att['file_size'] ?? 0) / 1024, 1);
            ?>
                <div class="col-md-3 col-sm-6">
                    <div class="border rounded p-2 text-center h-100 bg-white shadow-sm d-flex flex-column justify-content-between">
                        <div>
                            <?php if ($fType === 'video'): ?>
                                <video controls class="w-100 rounded mb-2" style="max-height: 140px; background: #000;">
                                    <source src="<?= htmlspecialchars($fPath) ?>">
                                </video>
                                <span class="badge bg-danger mb-1"><i class="fa-solid fa-video me-1"></i> Video Clip</span>
                            <?php elseif ($fType === 'signature'): ?>
                                <a href="<?= htmlspecialchars($fPath) ?>" target="_blank" class="d-block mb-2">
                                    <img src="<?= htmlspecialchars($fPath) ?>" alt="Signature" class="img-fluid rounded border" style="max-height: 100px;">
                                </a>
                                <span class="badge bg-success mb-1"><i class="fa-solid fa-signature me-1"></i> Signature</span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($fPath) ?>" target="_blank" class="d-block mb-2">
                                    <img src="<?= htmlspecialchars($fPath) ?>" alt="<?= $fName ?>" class="img-fluid rounded border" style="max-height: 120px; object-fit: cover;">
                                </a>
                                <span class="badge bg-primary mb-1"><i class="fa-solid fa-camera me-1"></i> Photo</span>
                            <?php endif; ?>
                            <div class="small fw-semibold text-truncate" title="<?= $fName ?>"><?= $fName ?></div>
                            <small class="text-muted d-block"><?= $fSizeKb ?> KB &bull; <?= htmlspecialchars($att['field_id']) ?></small>
                        </div>
                        <div class="mt-2 pt-2 border-top">
                            <a href="<?= htmlspecialchars($fPath) ?>" download class="btn btn-sm btn-outline-secondary w-100">
                                <i class="fa-solid fa-download me-1"></i> Download
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Print Only Official Signoff & Certification Box -->
<div class="print-signoff-box">
    <div style="width: 45%;">
        <div class="small fw-bold text-uppercase text-muted mb-4">Inspecting Field Officer / Collector:</div>
        <div class="border-bottom pb-1 mb-1">
            <strong><?= htmlspecialchars($sub['user_full_name'] ?: $sub['submitter_name'] ?: 'Field Officer') ?></strong>
        </div>
        <small class="text-muted d-block">Signature: __________________________</small>
        <small class="text-muted d-block mt-1">Date: <?= htmlspecialchars(substr($sub['submitted_at'] ?? date('Y-m-d'), 0, 10)) ?></small>
    </div>
    <div style="width: 45%;">
        <div class="small fw-bold text-uppercase text-muted mb-4">Auditing Supervisor / Quality Assurance:</div>
        <div class="border-bottom pb-1 mb-1">
            <strong><?= htmlspecialchars($currentUser['full_name'] ?? 'Supervisor') ?></strong>
        </div>
        <small class="text-muted d-block">Signature: __________________________</small>
        <small class="text-muted d-block mt-1">Status: <?= strtoupper($sub['status']) ?> &bull; Reviewed: <?= date('Y-m-d') ?></small>
    </div>
</div>

<script>
var subMapInstance = null;
var subBaseLayers = {};
var currentSubBaseKey = localStorage.getItem('fieldplus_map_provider') || 'osm';
var currentSubBaseLayer = null;
var isSubInternalSwitch = false;

var journeyPath = <?= !empty($sub['journey_path_json']) ? $sub['journey_path_json'] : 'null' ?>;
var singleLat = <?= !empty($sub['latitude']) ? (float)$sub['latitude'] : 'null' ?>;
var singleLng = <?= !empty($sub['longitude']) ? (float)$sub['longitude'] : 'null' ?>;

var arrowLayerGroup = L.layerGroup();
var waypointsLayerGroup = L.layerGroup();
var waypointMarkers = [];
var mainPolyline = null;
var casingPolyline = null;
var showArrowsState = true;
var showWaypointsState = false;

function getSubBaseLayers() {
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

function updateSubMapButtons(activeKey) {
    ['osm', 'google_roads', 'google_hybrid', 'google_terrain'].forEach(function(k) {
        var el = document.getElementById('btnSubMap_' + k);
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

function switchSubMapProvider(providerKey) {
    if (!subMapInstance || !subBaseLayers[providerKey]) return;
    
    isSubInternalSwitch = true;
    try {
        for (var k in subBaseLayers) {
            if (k !== providerKey && subMapInstance.hasLayer(subBaseLayers[k])) {
                subMapInstance.removeLayer(subBaseLayers[k]);
            }
        }
        if (!subMapInstance.hasLayer(subBaseLayers[providerKey])) {
            subMapInstance.addLayer(subBaseLayers[providerKey]);
        }
        currentSubBaseLayer = subBaseLayers[providerKey];
        currentSubBaseKey = providerKey;
        localStorage.setItem('fieldplus_map_provider', providerKey);
    } finally {
        isSubInternalSwitch = false;
    }

    updateSubMapButtons(providerKey);
}

function fitSubMapBounds() {
    if (mainPolyline && subMapInstance) {
        subMapInstance.fitBounds(mainPolyline.getBounds(), { padding: [40, 40] });
    }
}

function toggleRouteArrows() {
    if (!subMapInstance) return;
    showArrowsState = !showArrowsState;
    if (showArrowsState) {
        subMapInstance.addLayer(arrowLayerGroup);
    } else {
        subMapInstance.removeLayer(arrowLayerGroup);
    }
    var btn = document.getElementById('btnToggleArrows');
    if (btn) {
        btn.classList.toggle('active', showArrowsState);
        btn.classList.toggle('btn-primary', showArrowsState);
        btn.classList.toggle('btn-outline-primary', !showArrowsState);
    }
}

function toggleRouteWaypoints() {
    if (!subMapInstance) return;
    showWaypointsState = !showWaypointsState;
    if (showWaypointsState) {
        subMapInstance.addLayer(waypointsLayerGroup);
    } else {
        subMapInstance.removeLayer(waypointsLayerGroup);
    }
    var btn = document.getElementById('btnToggleWaypoints');
    if (btn) {
        btn.classList.toggle('active', showWaypointsState);
        btn.classList.toggle('btn-primary', showWaypointsState);
        btn.classList.toggle('btn-outline-secondary', !showWaypointsState);
    }
}

var is1MinOnlyMapMode = true;
var transientFocusMarker = null;

function buildWaypointPopupHtml(pt, idx) {
    var isStart = (idx === 0);
    var isEnd = (idx === journeyPath.length - 1);
    var isMapPoint = !!pt.is_map_waypoint;
    var minMark = pt.minute_mark != null ? pt.minute_mark : '';
    var prev = idx > 0 ? journeyPath[idx - 1] : null;
    var b = pt.bearing != null ? Number(pt.bearing) : (prev ? getBearingJs(prev.lat, prev.lng, pt.lat, pt.lng) : 0);
    var arrowEmoji = pt.arrow || '⬆';
    var dirText = pt.direction || (Math.round(b) + '°');
    var distFromPrev = pt.distance_from_prev != null ? Math.round(pt.distance_from_prev) : 0;
    var speedText = pt.speed != null ? (Math.round(Number(pt.speed) * 3.6 * 10) / 10) + ' km/h' : '--';
    var altText = pt.alt != null ? Math.round(pt.alt) + 'm' : '--';
    var accText = pt.accuracy != null ? Math.round(pt.accuracy) + 'm' : '--';

    var titlePrefix = isStart ? '🏁 Start Point (#1)' : (isEnd ? '🛑 End Point (#' + (idx + 1) + ')' : (isMapPoint ? '📍 1-Min Waypoint #' + (idx + 1) + (minMark !== '' ? ' (' + minMark + ' min)' : '') : '📝 Telematics Log Fix #' + (idx + 1)));

    return '<div style="font-size:13px; line-height:1.5; min-width:230px;">' +
           '  <div class="d-flex align-items-center justify-content-between mb-2 pb-1 border-bottom">' +
           '    <strong style="color:#0284C7; font-size:14px;"><i class="fa-solid fa-location-dot me-1"></i>' + titlePrefix + '</strong>' +
           '    <span class="badge bg-primary text-white">' + arrowEmoji + ' ' + Math.round(b) + '°</span>' +
           '  </div>' +
           '  <div class="mb-1"><b>🕒 Registered:</b> ' + (pt.timestamp || 'N/A') + '</div>' +
           '  <div class="mb-1"><b>🧭 Direction Taken:</b> <strong>' + arrowEmoji + ' ' + dirText + ' (' + Math.round(b) + '°)</strong></div>' +
           '  <div class="mb-1"><b>📏 Leg Distance:</b> +' + distFromPrev + ' m</div>' +
           '  <div class="mb-1"><b>⚡ Speed:</b> ' + speedText + '</div>' +
           '  <div class="mb-1"><b>⛰️ Altitude:</b> ' + altText + ' &bull; <b>🎯 Acc:</b> &plusmn;' + accText + '</div>' +
           '  <div class="mt-2 pt-1 border-top text-muted font-monospace" style="font-size:11px;">' +
           '    <i class="fa-solid fa-crosshairs me-1"></i>' + Number(pt.lat).toFixed(6) + ', ' + Number(pt.lng).toFixed(6) +
           '  </div>' +
           '</div>';
}

function buildMapMarkers(oneMinOnly) {
    arrowLayerGroup.clearLayers();
    waypointsLayerGroup.clearLayers();
    waypointMarkers = [];

    if (!journeyPath || !Array.isArray(journeyPath) || journeyPath.length === 0) return;

    for (var i = 0; i < journeyPath.length; i++) {
        (function(idx) {
            var pt = journeyPath[idx];
            var isStart = (idx === 0);
            var isEnd = (idx === journeyPath.length - 1);
            var isMapWaypoint = !!pt.is_map_waypoint;

            // In 1-minute mode (default), display ONLY 1-min interval waypoints (plus Start and End) on the map!
            if (oneMinOnly && !isStart && !isEnd && !isMapWaypoint) {
                return; // Leave for the log only!
            }

            var prev = idx > 0 ? journeyPath[idx - 1] : null;
            var b = pt.bearing != null ? Number(pt.bearing) : (prev ? getBearingJs(prev.lat, prev.lng, pt.lat, pt.lng) : 0);
            var arrowEmoji = pt.arrow || '⬆';
            var dirText = pt.direction || (Math.round(b) + '°');
            var minMark = pt.minute_mark != null ? pt.minute_mark : '';
            var popupHtml = buildWaypointPopupHtml(pt, idx);

            // Milestone Pin
            var pinLabel = isStart ? '🏁' : (isEnd ? '🛑' : (minMark !== '' ? minMark + 'm' : (idx + 1)));
            var waypointPinIcon = L.divIcon({
                className: 'custom-div-icon',
                html: '<div class="waypoint-badge-pin" style="min-width:24px; padding:0 3px;" title="' + (isStart ? 'Start' : (isEnd ? 'End' : (minMark !== '' ? minMark + ' min milestone' : 'Waypoint #' + (idx + 1)))) + '">' + pinLabel + '</div>',
                iconSize: [26, 26],
                iconAnchor: [13, 13]
            });
            var wpPinMarker = L.marker([pt.lat, pt.lng], { icon: waypointPinIcon })
                .bindPopup(popupHtml)
                .on('click', function() { highlightTimelineRow(idx); });
            waypointsLayerGroup.addLayer(wpPinMarker);

            if (isStart) {
                var startIcon = L.divIcon({
                    className: 'custom-div-icon',
                    html: '<div class="start-marker-badge">🏁 Start: ' + (pt.timestamp ? pt.timestamp.substr(11, 8) : 'Point 1') + '</div>',
                    iconAnchor: [40, 14]
                });
                var startMarker = L.marker([pt.lat, pt.lng], { icon: startIcon, zIndexOffset: 2000 })
                    .addTo(subMapInstance)
                    .bindPopup(popupHtml)
                    .on('click', function() { highlightTimelineRow(idx); });
                waypointMarkers[idx] = startMarker;
            } else if (isEnd && journeyPath.length > 1) {
                var endIcon = L.divIcon({
                    className: 'custom-div-icon',
                    html: '<div class="end-marker-badge">🛑 End: ' + (pt.timestamp ? pt.timestamp.substr(11, 8) : 'Finish') + '</div>',
                    iconAnchor: [40, 14]
                });
                var endMarker = L.marker([pt.lat, pt.lng], { icon: endIcon, zIndexOffset: 2000 })
                    .addTo(subMapInstance)
                    .bindPopup(popupHtml)
                    .on('click', function() { highlightTimelineRow(idx); });
                waypointMarkers[idx] = endMarker;
            } else {
                waypointMarkers[idx] = wpPinMarker;
            }

            // Directional SVG Arrow for 1-minute milestones
            if (idx > 0) {
                var arrowHtml = 
                    '<div class="route-arrow-wrap" style="transform: rotate(' + b + 'deg); width:32px; height:32px;" title="1-Min Waypoint #' + (idx + 1) + (minMark !== '' ? ' (' + minMark + ' min)' : '') + ': ' + arrowEmoji + ' ' + dirText + ' (' + Math.round(b) + '°)">' +
                    '  <svg width="26" height="26" viewBox="0 0 24 24">' +
                    '    <path d="M12 2L4.5 20.29l.71.71L12 18l6.79 3 .71-.71z" fill="#0284C7" stroke="#FFFFFF" stroke-width="2"/>' +
                    '  </svg>' +
                    '</div>';

                var arrowIcon = L.divIcon({
                    className: 'waypoint-arrow-icon',
                    html: arrowHtml,
                    iconSize: [32, 32],
                    iconAnchor: [16, 16]
                });
                var arrowMarker = L.marker([pt.lat, pt.lng], { icon: arrowIcon, zIndexOffset: 1000 })
                    .bindPopup(popupHtml)
                    .on('click', function() { highlightTimelineRow(idx); });
                arrowLayerGroup.addLayer(arrowMarker);

                if (!isEnd) {
                    waypointMarkers[idx] = arrowMarker;
                }
            }
        })(i);
    }
}

function toggleMapPointDensity() {
    is1MinOnlyMapMode = !is1MinOnlyMapMode;
    buildMapMarkers(is1MinOnlyMapMode);
    
    if (showArrowsState && !subMapInstance.hasLayer(arrowLayerGroup)) {
        subMapInstance.addLayer(arrowLayerGroup);
    }
    if (showWaypointsState && !subMapInstance.hasLayer(waypointsLayerGroup)) {
        subMapInstance.addLayer(waypointsLayerGroup);
    }

    var btn = document.getElementById('btnToggleMapDensity');
    var lbl = document.getElementById('densityBtnLabel');
    if (btn && lbl) {
        if (is1MinOnlyMapMode) {
            lbl.textContent = '1-Min View';
            btn.classList.add('btn-outline-info');
            btn.classList.remove('btn-info', 'text-white');
        } else {
            lbl.textContent = 'All Points View';
            btn.classList.remove('btn-outline-info');
            btn.classList.add('btn-info', 'text-white');
        }
    }
}

function filterLogTable(mode) {
    var rows = document.querySelectorAll('#waypointsTimelineTable tbody tr');
    rows.forEach(function(r) {
        if (mode === 'map_only') {
            if (r.classList.contains('is-map-milestone')) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        } else {
            r.style.display = '';
        }
    });
    var btnAll = document.getElementById('btnFilterLogAll');
    var btnMap = document.getElementById('btnFilterLogMapOnly');
    if (btnAll && btnMap) {
        btnAll.classList.toggle('active', mode === 'all');
        btnMap.classList.toggle('active', mode === 'map_only');
    }
}

function focusWaypointOnMap(index) {
    if (!subMapInstance || !journeyPath || !journeyPath[index]) return;
    var pt = journeyPath[index];
    var latLng = [pt.lat, pt.lng];
    
    // Smoothly pan and zoom to point
    subMapInstance.setView(latLng, 17, { animate: true });

    if (waypointMarkers[index]) {
        waypointMarkers[index].openPopup();
    } else {
        // Transient focus pin for log fixes that are not pinned on the map
        if (transientFocusMarker) {
            subMapInstance.removeLayer(transientFocusMarker);
        }
        var popupHtml = buildWaypointPopupHtml(pt, index);
        var transIcon = L.divIcon({
            className: 'custom-div-icon',
            html: '<div style="background:#f59e0b; color:white; font-size:11px; font-weight:bold; border-radius:12px; padding:3px 7px; border:2px solid white; box-shadow:0 3px 8px rgba(0,0,0,0.4); white-space:nowrap;">📍 Log Fix #' + (index + 1) + '</div>',
            iconAnchor: [38, 14]
        });
        transientFocusMarker = L.marker(latLng, { icon: transIcon, zIndexOffset: 3000 })
            .addTo(subMapInstance)
            .bindPopup(popupHtml)
            .openPopup();
    }

    // Highlight timeline row
    document.querySelectorAll('.timeline-row').forEach(function(row) {
        row.classList.remove('timeline-active-row');
    });
    var activeRow = document.getElementById('timelineRow_' + index);
    if (activeRow) {
        activeRow.classList.add('timeline-active-row');
        activeRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Smooth scroll up to map if map container is out of view
    var mapEl = document.getElementById('subMap');
    if (mapEl) {
        var rect = mapEl.getBoundingClientRect();
        if (rect.top < 0 || rect.bottom > window.innerHeight) {
            mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function exportJourneyFile(format) {
    if (!journeyPath || !Array.isArray(journeyPath) || journeyPath.length === 0) {
        alert("No journey path coordinates available for export.");
        return;
    }
    var uuid = <?= json_encode($sub['submission_uuid']) ?>;
    var title = <?= json_encode($sub['form_title']) ?>;
    var filename = "FieldPlus_" + uuid + "." + (format === 'geojson' ? 'json' : format);
    var content = "";
    var mimeType = "text/plain";

    if (format === 'gpx') {
        mimeType = "application/gpx+xml";
        content = '<?xml version="1.0" encoding="UTF-8"?>\n' +
            '<gpx version="1.1" creator="FieldPlus Enterprise" xmlns="http://www.topografix.com/GPX/1/1">\n' +
            '  <metadata><name>' + escapeXml(title) + ' - Route</name></metadata>\n' +
            '  <trk>\n    <name>' + escapeXml(title) + '</name>\n    <trkseg>\n';
        journeyPath.forEach(function(pt) {
            content += '      <trkpt lat="' + pt.lat + '" lon="' + pt.lng + '">\n';
            if (pt.alt != null) content += '        <ele>' + pt.alt + '</ele>\n';
            if (pt.timestamp) content += '        <time>' + pt.timestamp + '</time>\n';
            if (pt.speed != null) content += '        <speed>' + pt.speed + '</speed>\n';
            content += '      </trkpt>\n';
        });
        content += '    </trkseg>\n  </trk>\n</gpx>';
    } else if (format === 'kml') {
        mimeType = "application/vnd.google-earth.kml+xml";
        content = '<?xml version="1.0" encoding="UTF-8"?>\n' +
            '<kml xmlns="http://www.opengis.net/kml/2.2">\n' +
            '  <Document>\n    <name>' + escapeXml(title) + '</name>\n' +
            '    <Style id="routeStyle">\n      <LineStyle><color>ffeb8402</color><width>5</width></LineStyle>\n    </Style>\n' +
            '    <Placemark>\n      <name>Survey Route</name>\n      <styleUrl>#routeStyle</styleUrl>\n      <LineString>\n        <coordinates>\n';
        journeyPath.forEach(function(pt) {
            content += '          ' + pt.lng + ',' + pt.lat + ',' + (pt.alt || 0) + '\n';
        });
        content += '        </coordinates>\n      </LineString>\n    </Placemark>\n';
        if (journeyPath.length > 0) {
            var sPt = journeyPath[0];
            content += '    <Placemark><name>Start</name><Point><coordinates>' + sPt.lng + ',' + sPt.lat + '</coordinates></Point></Placemark>\n';
            var ePt = journeyPath[journeyPath.length - 1];
            content += '    <Placemark><name>End</name><Point><coordinates>' + ePt.lng + ',' + ePt.lat + '</coordinates></Point></Placemark>\n';
        }
        content += '  </Document>\n</kml>';
    } else if (format === 'geojson') {
        mimeType = "application/geo+json";
        var coordinates = journeyPath.map(function(pt) { return [pt.lng, pt.lat, pt.alt || 0]; });
        var geojsonObj = {
            "type": "FeatureCollection",
            "features": [
                {
                    "type": "Feature",
                    "geometry": { "type": "LineString", "coordinates": coordinates },
                    "properties": {
                        "uuid": uuid,
                        "title": title,
                        "points_count": journeyPath.length,
                        "distance_meters": <?= (float)($sub['journey_distance_meters'] ?? 0) ?>,
                        "duration_seconds": <?= (int)($sub['journey_duration_seconds'] ?? 0) ?>
                    }
                }
            ]
        };
        journeyPath.forEach(function(pt, idx) {
            geojsonObj.features.push({
                "type": "Feature",
                "geometry": { "type": "Point", "coordinates": [pt.lng, pt.lat, pt.alt || 0] },
                "properties": {
                    "index": idx + 1,
                    "timestamp": pt.timestamp,
                    "bearing": pt.bearing,
                    "direction": pt.direction,
                    "arrow": pt.arrow,
                    "speed": pt.speed
                }
            });
        });
        content = JSON.stringify(geojsonObj, null, 2);
    }

    var blob = new Blob([content], { type: mimeType });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function escapeXml(str) {
    if (!str) return '';
    return String(str).replace(/[<>&'"]/g, function (c) {
        switch (c) {
            case '<': return '&lt;';
            case '>': return '&gt;';
            case '&': return '&amp;';
            case '\'': return '&apos;';
            case '"': return '&quot;';
        }
    });
}

function getBearingJs(lat1, lon1, lat2, lon2) {
    var y = Math.sin((lon2 - lon1) * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180);
    var x = Math.cos(lat1 * Math.PI / 180) * Math.sin(lat2 * Math.PI / 180) -
            Math.sin(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.cos((lon2 - lon1) * Math.PI / 180);
    return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

document.addEventListener("DOMContentLoaded", function() {
    var mapElement = document.getElementById('subMap');
    if (!mapElement) return;

    var initCenter = [24.7136, 46.6753];
    var initZoom = 15;

    if (journeyPath && Array.isArray(journeyPath) && journeyPath.length > 0) {
        initCenter = [journeyPath[0].lat, journeyPath[0].lng];
    } else if (singleLat !== null && singleLng !== null) {
        initCenter = [singleLat, singleLng];
    }

    subMapInstance = L.map('subMap').setView(initCenter, initZoom);

    // Initialize base layers and apply active layer
    subBaseLayers = getSubBaseLayers();
    if (!subBaseLayers[currentSubBaseKey]) {
        currentSubBaseKey = 'osm';
    }
    switchSubMapProvider(currentSubBaseKey);

    // Layer Switcher in Top Right
    var baseControls = {
        "OpenStreetMap": subBaseLayers['osm'],
        "Google Roads": subBaseLayers['google_roads'],
        "Google Satellite": subBaseLayers['google_hybrid'],
        "Google Terrain": subBaseLayers['google_terrain'],
        "ESRI Satellite (Fallback)": subBaseLayers['esri_satellite']
    };
    L.control.layers(baseControls, null, { position: 'topright' }).addTo(subMapInstance);

    // Invalidate map size after rendering to ensure proper tile display
    setTimeout(function() {
        if (subMapInstance) {
            subMapInstance.invalidateSize();
        }
    }, 200);

    subMapInstance.on('baselayerchange', function(e) {
        if (isSubInternalSwitch) return;
        for (var key in subBaseLayers) {
            if (subBaseLayers[key] === e.layer) {
                currentSubBaseKey = key;
                currentSubBaseLayer = e.layer;
                localStorage.setItem('fieldplus_map_provider', key);
                updateSubMapButtons(key);
                break;
            }
        }
    });

    if (journeyPath && Array.isArray(journeyPath) && journeyPath.length > 0) {
        var latlngs = journeyPath.map(function(pt) {
            return [pt.lat, pt.lng];
        });

        // 1. Double Polyline: High-contrast white casing + deep blue inner route (draws smooth, accurate complete geometry)
        casingPolyline = L.polyline(latlngs, {
            color: '#FFFFFF',
            weight: 8,
            opacity: 0.95,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(subMapInstance);

        mainPolyline = L.polyline(latlngs, {
            color: '#0284C7',
            weight: 5,
            opacity: 0.95,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(subMapInstance);

        // 2. Build map markers: default is1MinOnlyMapMode = true (1-minute waypoints only on map, rest for log)
        buildMapMarkers(is1MinOnlyMapMode);

        // Add Direction Arrows layer to map by default
        arrowLayerGroup.addTo(subMapInstance);

        // Fit map bounds to polyline
        subMapInstance.fitBounds(mainPolyline.getBounds(), { padding: [40, 40] });

    } else if (singleLat !== null && singleLng !== null) {
        var marker = L.marker([singleLat, singleLng]).addTo(subMapInstance);
        var popupHtml = '<strong><?= addslashes($sub['form_title']) ?></strong><br>' +
                        'Submitter: <?= addslashes($sub['submitter_name'] ?? 'N/A') ?><br>' +
                        'Coords: ' + singleLat.toFixed(5) + ', ' + singleLng.toFixed(5) + '<br>' +
                        '<div class="mt-2 d-flex gap-1">' +
                        '<a href="https://www.google.com/maps?q=' + singleLat + ',' + singleLng + '" target="_blank" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;"><i class="fa-brands fa-google me-1"></i>Google</a>' +
                        '<a href="https://www.openstreetmap.org/?mlat=' + singleLat + '&mlon=' + singleLng + '#map=16/' + singleLat + '/' + singleLng + '" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:11px;"><i class="fa-solid fa-map me-1"></i>OSM</a>' +
                        '</div>';
        marker.bindPopup(popupHtml).openPopup();
    }
});

function highlightTimelineRow(index) {
    document.querySelectorAll('.timeline-row').forEach(function(row) {
        row.classList.remove('timeline-active-row');
    });
    var activeRow = document.getElementById('timelineRow_' + index);
    if (activeRow) {
        activeRow.classList.add('timeline-active-row');
        activeRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
