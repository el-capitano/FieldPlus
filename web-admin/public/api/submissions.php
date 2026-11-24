<?php
// web-admin/public/api/submissions.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

// Enforce Bearer Token Authentication
$authenticatedUser = requireApiBearerAuth();

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    $isAdmin = ($authenticatedUser['role'] === 'admin');
    $canCollect = $isAdmin || hasPermission('mobile_collect', $authenticatedUser) || hasPermission('submissions_write', $authenticatedUser);
    $canReadSubmissions = $isAdmin || hasPermission('submissions_read', $authenticatedUser);
    $canAccessAllSubmissions = $isAdmin || hasPermission('submissions_all', $authenticatedUser);

    if ($method === 'POST') {
        if (!$canCollect) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Insufficient permissions to submit field survey data.']);
            exit();
        }

        // Handle submission receipt from Mobile App
        $submissionUuid = $_POST['submission_uuid'] ?? null;
        $formId = $_POST['form_id'] ?? null;
        $deviceId = $_POST['device_id'] ?? 'Unknown Android Device';
        $submitterName = $_POST['submitter_name'] ?? null;
        $submittedAt = $_POST['submitted_at'] ?? date('Y-m-d H:i:s');
        $status = $_POST['status'] ?? 'submitted';

        // Check if data was sent via raw JSON payload
        $rawJson = null;
        if (isset($_POST['data'])) {
            $rawJson = $_POST['data'];
        } else {
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input) {
                $submissionUuid = $input['submission_uuid'] ?? $submissionUuid;
                $formId = $input['form_id'] ?? $formId;
                $deviceId = $input['device_id'] ?? $deviceId;
                $submitterName = $input['submitter_name'] ?? $submitterName;
                $submittedAt = $input['submitted_at'] ?? $submittedAt;
                $status = $input['status'] ?? $status;
                $rawJson = isset($input['data']) ? (is_string($input['data']) ? $input['data'] : json_encode($input['data'])) : null;
            }
        }

        if (empty($submissionUuid)) {
            $submissionUuid = 'sub_' . bin2hex(random_bytes(16));
        }

        if (empty($formId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing form_id parameter']);
            exit();
        }

        // Verify that the form exists
        $formCheck = $pdo->prepare("SELECT * FROM forms WHERE id = :id LIMIT 1");
        $formCheck->execute(['id' => $formId]);
        $form = $formCheck->fetch();
        if (!$form) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => "Form with id '{$formId}' not found"]);
            exit();
        }

        if (!empty($form['is_archived'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Form '{$form['title']}' has been archived and is no longer accepting submissions."]);
            exit();
        }

        if (empty($form['is_active'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Form '{$form['title']}' is currently inactive."]);
            exit();
        }

        if (!canUserAccessForm($form, $authenticatedUser)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => "Access denied: You are not authorized to submit responses for this form."]);
            exit();
        }

        $dataArr = [];
        if (!empty($rawJson)) {
            $dataArr = is_string($rawJson) ? json_decode($rawJson, true) : $rawJson;
            if (!is_array($dataArr)) {
                $dataArr = [];
            }
        }

        // Extract GPS coordinates or Journey Path if present anywhere in data array
        $latitude = null;
        $longitude = null;
        $altitude = null;
        $accuracy = null;
        $locationLabel = null;
        $journeyPathJson = null;
        $journeyStartTime = null;
        $journeyEndTime = null;
        $journeyDurationSec = null;
        $journeyDistanceMeters = null;

        // Check if top-level or data fields contain journey tracking info
        if (!empty($_POST['journey_path_json'])) {
            $journeyPathJson = $_POST['journey_path_json'];
        }
        if (!empty($_POST['journey_start_time'])) {
            $journeyStartTime = date('Y-m-d H:i:s', strtotime($_POST['journey_start_time']));
        }
        if (!empty($_POST['journey_end_time'])) {
            $journeyEndTime = date('Y-m-d H:i:s', strtotime($_POST['journey_end_time']));
        }
        if (isset($_POST['journey_duration_seconds'])) {
            $journeyDurationSec = (int)$_POST['journey_duration_seconds'];
        }
        if (isset($_POST['journey_distance_meters'])) {
            $journeyDistanceMeters = (float)$_POST['journey_distance_meters'];
        }

        foreach ($dataArr as $k => $rawVal) {
            $v = $rawVal;
            if (is_string($v) && (str_starts_with(trim($v), '{') || str_starts_with(trim($v), '['))) {
                $decoded = json_decode($v, true);
                if (is_array($decoded)) {
                    $v = $decoded;
                }
            }

            if (is_array($v) && isset($v['latitude']) && isset($v['longitude'])) {
                if ($latitude === null) {
                    $latitude = (float)$v['latitude'];
                    $longitude = (float)$v['longitude'];
                    $altitude = isset($v['altitude']) ? (float)$v['altitude'] : null;
                    $accuracy = isset($v['accuracy']) ? (float)$v['accuracy'] : null;
                    $locationLabel = $v['label'] ?? null;
                }
            }

            // Check if field is journey track (object or array of points)
            if (is_array($v)) {
                $points = null;
                if (isset($v['journey_path']) && is_array($v['journey_path'])) {
                    $points = $v['journey_path'];
                } elseif (isset($v['points']) && is_array($v['points'])) {
                    $points = $v['points'];
                } elseif (isset($v[0]['lat']) && isset($v[0]['lng'])) {
                    $points = $v;
                }

                if (is_array($points) && !empty($points)) {
                    // Enrich missing bearings/directions/arrows if needed
                    $calcDist = 0.0;
                    for ($pi = 0; $pi < count($points); $pi++) {
                        $pCur = &$points[$pi];
                        $curLat = (float)($pCur['lat'] ?? 0);
                        $curLng = (float)($pCur['lng'] ?? 0);
                        if ($pi > 0) {
                            $pPrev = $points[$pi - 1];
                            $prevLat = (float)($pPrev['lat'] ?? 0);
                            $prevLng = (float)($pPrev['lng'] ?? 0);
                            
                            // Geodesic distance in meters
                            $dLat = deg2rad($curLat - $prevLat);
                            $dLon = deg2rad($curLng - $prevLng);
                            $aDist = sin($dLat / 2) * sin($dLat / 2) +
                                     cos(deg2rad($prevLat)) * cos(deg2rad($curLat)) *
                                     sin($dLon / 2) * sin($dLon / 2);
                            $stepDist = 6371000 * (2 * atan2(sqrt($aDist), sqrt(1 - $aDist)));
                            $calcDist += $stepDist;

                            if (!isset($pCur['distance_from_prev']) || $pCur['distance_from_prev'] === null) {
                                $pCur['distance_from_prev'] = round($stepDist, 1);
                            }
                            if (!isset($pCur['bearing']) || $pCur['bearing'] === null) {
                                $yB = sin($dLon) * cos(deg2rad($curLat));
                                $xB = cos(deg2rad($prevLat)) * sin(deg2rad($curLat)) - sin(deg2rad($prevLat)) * cos(deg2rad($curLat)) * cos($dLon);
                                $pCur['bearing'] = round(fmod(rad2deg(atan2($yB, $xB)) + 360, 360), 1);
                            }
                        } else {
                            if (!isset($pCur['distance_from_prev'])) $pCur['distance_from_prev'] = 0.0;
                            if (!isset($pCur['bearing']) || $pCur['bearing'] === null) $pCur['bearing'] = 0.0;
                        }

                        $bDeg = fmod(fmod((float)$pCur['bearing'], 360) + 360, 360);
                        if (empty($pCur['direction'])) {
                            if ($bDeg >= 337.5 || $bDeg < 22.5) $pCur['direction'] = "North (N)";
                            elseif ($bDeg >= 22.5 && $bDeg < 67.5) $pCur['direction'] = "North-East (NE)";
                            elseif ($bDeg >= 67.5 && $bDeg < 112.5) $pCur['direction'] = "East (E)";
                            elseif ($bDeg >= 112.5 && $bDeg < 157.5) $pCur['direction'] = "South-East (SE)";
                            elseif ($bDeg >= 157.5 && $bDeg < 202.5) $pCur['direction'] = "South (S)";
                            elseif ($bDeg >= 202.5 && $bDeg < 247.5) $pCur['direction'] = "South-West (SW)";
                            elseif ($bDeg >= 247.5 && $bDeg < 292.5) $pCur['direction'] = "West (W)";
                            else $pCur['direction'] = "North-West (NW)";
                        }
                        if (empty($pCur['arrow'])) {
                            if ($bDeg >= 337.5 || $bDeg < 22.5) $pCur['arrow'] = "⬆";
                            elseif ($bDeg >= 22.5 && $bDeg < 67.5) $pCur['arrow'] = "↗";
                            elseif ($bDeg >= 67.5 && $bDeg < 112.5) $pCur['arrow'] = "➡";
                            elseif ($bDeg >= 112.5 && $bDeg < 157.5) $pCur['arrow'] = "↘";
                            elseif ($bDeg >= 157.5 && $bDeg < 202.5) $pCur['arrow'] = "⬇";
                            elseif ($bDeg >= 202.5 && $bDeg < 247.5) $pCur['arrow'] = "↙";
                            elseif ($bDeg >= 247.5 && $bDeg < 292.5) $pCur['arrow'] = "⬅";
                            else $pCur['arrow'] = "↖";
                        }
                    }
                    unset($pCur);

                    if (!$journeyPathJson) {
                        $journeyPathJson = json_encode($points, JSON_UNESCAPED_SLASHES);
                    }
                    if (!$latitude && isset($points[0]['lat'])) {
                        $latitude = (float)$points[0]['lat'];
                        $longitude = (float)$points[0]['lng'];
                    }
                    if (isset($v['start_time']) && !$journeyStartTime) {
                        $journeyStartTime = date('Y-m-d H:i:s', strtotime($v['start_time']));
                    } elseif (!$journeyStartTime && !empty($points[0]['timestamp'])) {
                        $journeyStartTime = date('Y-m-d H:i:s', strtotime($points[0]['timestamp']));
                    }
                    if (isset($v['end_time']) && !$journeyEndTime) {
                        $journeyEndTime = date('Y-m-d H:i:s', strtotime($v['end_time']));
                    } elseif (!$journeyEndTime && !empty($points[count($points) - 1]['timestamp'])) {
                        $journeyEndTime = date('Y-m-d H:i:s', strtotime($points[count($points) - 1]['timestamp']));
                    }
                    if (isset($v['duration_seconds']) && $journeyDurationSec === null) {
                        $journeyDurationSec = (int)$v['duration_seconds'];
                    } elseif ($journeyDurationSec === null && $journeyStartTime && $journeyEndTime) {
                        $journeyDurationSec = max(0, strtotime($journeyEndTime) - strtotime($journeyStartTime));
                    }
                    if (isset($v['distance_meters']) && $journeyDistanceMeters === null) {
                        $journeyDistanceMeters = (float)$v['distance_meters'];
                    } elseif ($journeyDistanceMeters === null && $calcDist > 0) {
                        $journeyDistanceMeters = round($calcDist, 2);
                    }
                }
            }
        }

        // Try to identify submitter name from form field if not provided in header
        if (empty($submitterName)) {
            foreach (['inspector_name', 'submitter_name', 'farmer_name', 'user_name', 'customer_name', 'name'] as $candidate) {
                if (!empty($dataArr[$candidate]) && is_string($dataArr[$candidate])) {
                    $submitterName = $dataArr[$candidate];
                    break;
                }
            }
        }

        $userId = $authenticatedUser['id'] ?? null;
        if (empty($submitterName)) {
            $submitterName = $authenticatedUser['full_name'] ?? $authenticatedUser['username'] ?? 'Field Staff';
        }

        $jsonDataString = json_encode($dataArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Begin Transaction
        $pdo->beginTransaction();

        // 1. Insert or update parent submission record FIRST (satisfies foreign keys)
        $stmtSub = $pdo->prepare("
            INSERT INTO submissions
            (submission_uuid, form_id, user_id, device_id, submitter_name, latitude, longitude, altitude, accuracy, location_label,
             journey_path_json, journey_start_time, journey_end_time, journey_duration_seconds, journey_distance_meters,
             data_json, status, submitted_at)
            VALUES (:uuid, :form_id, :user_id, :device_id, :submitter_name, :lat, :lng, :alt, :acc, :loc_label,
                    :j_path, :j_start, :j_end, :j_dur, :j_dist,
                    :data_json, :status, :submitted_at)
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                submitter_name = VALUES(submitter_name),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                altitude = VALUES(altitude),
                accuracy = VALUES(accuracy),
                location_label = VALUES(location_label),
                journey_path_json = VALUES(journey_path_json),
                journey_start_time = VALUES(journey_start_time),
                journey_end_time = VALUES(journey_end_time),
                journey_duration_seconds = VALUES(journey_duration_seconds),
                journey_distance_meters = VALUES(journey_distance_meters),
                data_json = VALUES(data_json),
                status = VALUES(status),
                submitted_at = VALUES(submitted_at)
        ");

        $stmtSub->execute([
            'uuid' => $submissionUuid,
            'form_id' => $formId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'submitter_name' => $submitterName,
            'lat' => $latitude,
            'lng' => $longitude,
            'alt' => $altitude,
            'acc' => $accuracy,
            'loc_label' => $locationLabel,
            'j_path' => $journeyPathJson,
            'j_start' => $journeyStartTime,
            'j_end' => $journeyEndTime,
            'j_dur' => $journeyDurationSec,
            'j_dist' => $journeyDistanceMeters,
            'data_json' => $jsonDataString,
            'status' => $status,
            'submitted_at' => $submittedAt
        ]);

        // 2. Handle uploaded files/photos/videos/signatures with strict OWASP validation
        $savedAttachments = [];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', '3gp', 'mkv', 'avi', 'pdf', 'csv', 'txt'];
        $allowedMimes = [
            'image/jpeg', 'image/pjpeg', 'image/png', 'image/x-png', 'image/webp', 'image/gif',
            'video/mp4', 'video/quicktime', 'video/3gpp', 'video/3gpp2', 'video/x-matroska', 'video/x-msvideo', 'video/webm', 'video/mp4v-es', 'application/mp4',
            'application/pdf', 'text/plain', 'text/csv', 'application/octet-stream'
        ];

        if (!empty($_FILES)) {
            // Helper function to save a single uploaded file
            $saveSingleUpload = function($fieldKey, $fileInfo, $index = null) use (&$savedAttachments, &$dataArr, $submissionUuid, $pdo, $allowedExtensions, $allowedMimes) {
                if ($fileInfo['error'] !== UPLOAD_ERR_OK || empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
                    error_log("Upload skipped for {$fieldKey}: error code " . ($fileInfo['error'] ?? 'unknown'));
                    return;
                }

                // Check file size (max 60 MB)
                if ($fileInfo['size'] > 62914560) {
                    error_log("Upload skipped for {$fieldKey}: file size exceeds 60MB ({$fileInfo['size']} bytes)");
                    return;
                }

                $rawExt = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
                if (empty($rawExt)) {
                    $rawExt = (strpos($fileInfo['type'] ?? '', 'video') !== false) ? 'mp4' : 'jpg';
                }

                // Enforce strict extension whitelist
                if (!in_array($rawExt, $allowedExtensions, true)) {
                    error_log("Upload skipped for {$fieldKey}: extension '{$rawExt}' not allowed");
                    return;
                }

                // Verify real server-side MIME type via finfo
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $realMime = finfo_file($finfo, $fileInfo['tmp_name']);
                finfo_close($finfo);

                $isAllowedMime = in_array(strtolower($realMime), $allowedMimes, true);
                if (!$isAllowedMime && (strpos($realMime, 'image/') === 0 || strpos($realMime, 'video/') === 0)) {
                    $isAllowedMime = true;
                }

                if (!$isAllowedMime) {
                    error_log("Upload skipped for {$fieldKey}: MIME type '{$realMime}' not allowed for extension '{$rawExt}'");
                    return;
                }

                $safeFieldKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $fieldKey);
                $suffix = ($index !== null) ? "_{$index}_" : "_";
                $newFileName = preg_replace('/[^a-zA-Z0-9_-]/', '', $submissionUuid) . '_' . $safeFieldKey . $suffix . bin2hex(random_bytes(8)) . '.' . $rawExt;
                $targetPath = UPLOAD_DIR . '/' . $newFileName;
                $webPath = UPLOAD_URL . '/' . $newFileName;

                if (move_uploaded_file($fileInfo['tmp_name'], $targetPath)) {
                    $fileType = 'file';
                    if (strpos($realMime, 'video') !== false || in_array($rawExt, ['mp4', 'mov', '3gp', 'mkv', 'avi'])) {
                        $fileType = 'video';
                    } elseif (strpos($realMime, 'image') !== false || in_array($rawExt, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $fileType = (strpos($fieldKey, 'signature') !== false) ? 'signature' : 'image';
                    }

                    $stmtAtt = $pdo->prepare("
                        INSERT INTO submission_attachments
                        (submission_uuid, field_id, file_type, file_name, file_path, file_size, mime_type)
                        VALUES (:uuid, :field_id, :file_type, :file_name, :file_path, :file_size, :mime_type)
                    ");
                    $stmtAtt->execute([
                        'uuid' => $submissionUuid,
                        'field_id' => $fieldKey,
                        'file_type' => $fileType,
                        'file_name' => basename($fileInfo['name']),
                        'file_path' => $webPath,
                        'file_size' => $fileInfo['size'],
                        'mime_type' => $realMime
                    ]);

                    $attachmentInfo = [
                        'url' => $webPath,
                        'filename' => basename($fileInfo['name']),
                        'size' => $fileInfo['size'],
                        'type' => $fileType
                    ];

                    if ($index !== null || (isset($dataArr[$fieldKey]) && is_array($dataArr[$fieldKey]) && (isset($dataArr[$fieldKey][0]) || isset($dataArr[$fieldKey]['url'])))) {
                        if (!isset($dataArr[$fieldKey]) || !is_array($dataArr[$fieldKey]) || isset($dataArr[$fieldKey]['url'])) {
                            $existing = (isset($dataArr[$fieldKey]) && isset($dataArr[$fieldKey]['url'])) ? [$dataArr[$fieldKey]] : [];
                            $dataArr[$fieldKey] = $existing;
                        }
                        $dataArr[$fieldKey][] = $attachmentInfo;
                    } else {
                        $dataArr[$fieldKey] = $attachmentInfo;
                    }

                    $savedAttachments[] = [
                        'field_id' => $fieldKey,
                        'url' => $webPath,
                        'type' => $fileType
                    ];
                }
            };

            // Track which fieldKeys had prior attachments cleaned up in this request
            $clearedFieldsInThisRequest = [];

            foreach ($_FILES as $fieldKey => $fileInfo) {
                // Determine base field key if part name is formatted as fieldId_timestamp
                $baseFieldKey = $fieldKey;
                if (preg_match('/^([a-zA-Z0-9_-]+)_[0-9]{10,}$/', $fieldKey, $m)) {
                    $baseFieldKey = $m[1];
                }

                // Remove existing attachments for this fieldKey on re-sync only once per request
                if (!in_array($baseFieldKey, $clearedFieldsInThisRequest, true)) {
                    $pdo->prepare("DELETE FROM submission_attachments WHERE submission_uuid = :uuid AND (field_id = :fid OR field_id LIKE :fid_wild)")
                        ->execute([
                            'uuid' => $submissionUuid,
                            'fid' => $baseFieldKey,
                            'fid_wild' => $baseFieldKey . '_%'
                        ]);
                    $clearedFieldsInThisRequest[] = $baseFieldKey;
                }

                if (is_array($fileInfo['name'])) {
                    // Multi-file array upload (e.g. multiple_photos or fieldKey[])
                    $numFiles = count($fileInfo['name']);
                    for ($i = 0; $i < $numFiles; $i++) {
                        $singleItem = [
                            'name' => $fileInfo['name'][$i],
                            'type' => $fileInfo['type'][$i],
                            'tmp_name' => $fileInfo['tmp_name'][$i],
                            'error' => $fileInfo['error'][$i],
                            'size' => $fileInfo['size'][$i],
                        ];
                        $saveSingleUpload($baseFieldKey, $singleItem, $i);
                    }
                } else {
                    $saveSingleUpload($baseFieldKey, $fileInfo);
                }
            }
        }

        // 3. Handle Base64-encoded signatures or photos embedded in data JSON
        foreach ($dataArr as $k => $v) {
            if (is_string($v) && (strpos($v, 'data:image/') === 0)) {
                preg_match('/data:image\/(.*?);base64,(.*)$/', $v, $matches);
                if (count($matches) === 3) {
                    $rawExt = strtolower($matches[1]);
                    $ext = ($rawExt === 'jpeg' || $rawExt === 'jpg') ? 'jpg' : (($rawExt === 'png') ? 'png' : (($rawExt === 'webp') ? 'webp' : ''));
                    if (empty($ext)) {
                        continue;
                    }
                    $base64Data = base64_decode($matches[2]);
                    if ($base64Data === false || strlen($base64Data) > 10485760) {
                        continue; // Reject invalid or oversized base64
                    }

                    $safeFieldKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $k);
                    $newFileName = preg_replace('/[^a-zA-Z0-9_-]/', '', $submissionUuid) . '_' . $safeFieldKey . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $targetPath = UPLOAD_DIR . '/' . $newFileName;
                    $webPath = UPLOAD_URL . '/' . $newFileName;

                    if (file_put_contents($targetPath, $base64Data)) {
                        $fileType = (strpos($k, 'signature') !== false) ? 'signature' : 'image';
                        $pdo->prepare("DELETE FROM submission_attachments WHERE submission_uuid = :uuid AND field_id = :fid")
                            ->execute(['uuid' => $submissionUuid, 'fid' => $k]);

                        $stmtAtt = $pdo->prepare("
                            INSERT INTO submission_attachments
                            (submission_uuid, field_id, file_type, file_name, file_path, file_size, mime_type)
                            VALUES (:uuid, :field_id, :file_type, :file_name, :file_path, :file_size, :mime_type)
                        ");
                        $stmtAtt->execute([
                            'uuid' => $submissionUuid,
                            'field_id' => $k,
                            'file_type' => $fileType,
                            'file_name' => $safeFieldKey . '.' . $ext,
                            'file_path' => $webPath,
                            'file_size' => strlen($base64Data),
                            'mime_type' => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)
                        ]);

                        $dataArr[$k] = [
                            'url' => $webPath,
                            'filename' => $safeFieldKey . '.' . $ext,
                            'size' => strlen($base64Data),
                            'type' => $fileType
                        ];

                        $savedAttachments[] = [
                            'field_id' => $k,
                            'url' => $webPath,
                            'type' => $fileType
                        ];
                    }
                }
            }
        }

        // 4. If attachments modified data array, update data_json in submissions
        if (!empty($savedAttachments)) {
            $jsonDataString = json_encode($dataArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmtUpdateData = $pdo->prepare("UPDATE submissions SET data_json = :data_json WHERE submission_uuid = :uuid");
            $stmtUpdateData->execute([
                'data_json' => $jsonDataString,
                'uuid' => $submissionUuid
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'submission_uuid' => $submissionUuid,
            'message' => 'Field survey submission synced successfully',
            'attachments_saved' => count($savedAttachments),
            'server_timestamp' => date('c')
        ]);
    } elseif ($method === 'GET') {
        if (!$canReadSubmissions) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Insufficient permissions to view or download field submissions.']);
            exit();
        }

        // Query submissions
        $formId = $_GET['form_id'] ?? null;
        $uuid = $_GET['uuid'] ?? null;

        if ($uuid) {
            $stmt = $pdo->prepare("
                SELECT s.*, f.title as form_title, f.created_by as form_created_by
                FROM submissions s
                JOIN forms f ON s.form_id = f.id
                WHERE s.submission_uuid = :uuid
                LIMIT 1
            ");
            $stmt->execute(['uuid' => $uuid]);
            $sub = $stmt->fetch();

            if (!$sub) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Submission not found']);
                exit();
            }

            if (!$canAccessAllSubmissions && (int)($sub['user_id'] ?? 0) !== (int)$authenticatedUser['id'] && (int)($sub['form_created_by'] ?? 0) !== (int)$authenticatedUser['id']) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Access denied: You do not have permission to access this submission.']);
                exit();
            }

            // Get attachments
            $stmtAtt = $pdo->prepare("SELECT * FROM submission_attachments WHERE submission_uuid = :uuid");
            $stmtAtt->execute(['uuid' => $uuid]);
            $attachments = $stmtAtt->fetchAll();

            $sub['data'] = json_decode($sub['data_json'], true);
            $sub['attachments'] = $attachments;
            unset($sub['data_json']);

            echo json_encode(['status' => 'success', 'data' => $sub]);
            exit();
        }

        $query = "SELECT s.*, f.title as form_title FROM submissions s JOIN forms f ON s.form_id = f.id WHERE 1=1";
        $params = [];

        if (!$canAccessAllSubmissions) {
            $query .= " AND (s.user_id = :uid_sub OR f.created_by = :uid_form)";
            $params['uid_sub'] = $authenticatedUser['id'];
            $params['uid_form'] = $authenticatedUser['id'];
        }

        if ($formId) {
            $query .= " AND s.form_id = :form_id";
            $params['form_id'] = $formId;
        }

        $query .= " ORDER BY s.submitted_at DESC, s.id DESC LIMIT 100";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['data'] = json_decode($r['data_json'], true);
            unset($r['data_json']);
        }

        echo json_encode(['status' => 'success', 'count' => count($rows), 'data' => $rows]);
    } else {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
