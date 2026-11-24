<?php
// web-admin/public/api/health.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

sendSecurityHeaders();

try {
    $pdo = getDbConnection();
    $formsCount = (int)$pdo->query("SELECT COUNT(*) FROM forms WHERE is_active = 1")->fetchColumn();
    $submissionsCount = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();

    echo json_encode([
        'status' => 'ok',
        'service' => 'FieldPlus Web Admin API',
        'version' => '1.0.0',
        'server_time' => date('c'),
        'active_forms' => $formsCount,
        'total_submissions' => $submissionsCount
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    logSecurityEvent('HEALTH_CHECK_ERROR', $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Service temporarily unavailable'
    ]);
}
