<?php
// web-admin/public/api/forms.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
$method = $_SERVER['REQUEST_METHOD'];

try {
    $isAdmin = ($authenticatedUser['role'] === 'admin');
    $canReadForms = $isAdmin || hasPermission('forms_read', $authenticatedUser) || hasPermission('mobile_collect', $authenticatedUser);
    $canWriteForms = $isAdmin || hasPermission('forms_write', $authenticatedUser);
    $canDeleteForms = $isAdmin || hasPermission('forms_delete', $authenticatedUser);
    $canAccessAllForms = $isAdmin || hasPermission('forms_all', $authenticatedUser);

    if ($method === 'GET') {
        if (!$canReadForms) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Insufficient permissions to view or download forms.']);
            exit();
        }

        $formId = $_GET['id'] ?? null;

        if ($formId) {
            // Return specific form schema
            $stmt = $pdo->prepare("
                SELECT f.*, u.role as creator_role, u.full_name as creator_name
                FROM forms f
                LEFT JOIN users u ON f.created_by = u.id
                WHERE f.id = :id LIMIT 1
            ");
            $stmt->execute(['id' => $formId]);
            $form = $stmt->fetch();

            if (!$form) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Form not found']);
                exit();
            }

            // Permissions enforcement
            if (!canUserAccessForm($form, $authenticatedUser)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Access denied: You do not have permission to access or download this form.']);
                exit();
            }

            $schema = json_decode($form['schema_json'], true) ?: [];
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'id' => $form['id'],
                    'title' => $form['title'],
                    'description' => $form['description'],
                    'category' => $form['category'],
                    'version' => (int)$form['version'],
                    'is_active' => (bool)$form['is_active'],
                    'is_archived' => (bool)$form['is_archived'],
                    'access_type' => $form['access_type'],
                    'created_by' => (int)$form['created_by'],
                    'creator_name' => $form['creator_name'],
                    'created_at' => $form['created_at'],
                    'updated_at' => $form['updated_at'],
                    'schema' => $schema
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            // List forms (used by mobile sync to download available blank forms)
            $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] == '1' && $canAccessAllForms;
            $onlyActive = !isset($_GET['include_inactive']) || $_GET['include_inactive'] != '1';

            $sql = "
                SELECT f.*, u.full_name as creator_name, u.role as creator_role
                FROM forms f
                LEFT JOIN users u ON f.created_by = u.id
                WHERE 1=1
            ";

            if (!$includeArchived) {
                $sql .= " AND f.is_archived = 0";
            }
            if ($onlyActive) {
                $sql .= " AND f.is_active = 1";
            }
            $sql .= " ORDER BY f.updated_at DESC";

            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();

            $formsList = [];
            foreach ($rows as $row) {
                // Check granular permission per form
                if (!canUserAccessForm($row, $authenticatedUser)) {
                    continue;
                }

                $schema = json_decode($row['schema_json'], true) ?: [];
                $fieldCount = 0;
                if (!empty($schema['sections'])) {
                    foreach ($schema['sections'] as $sec) {
                        $fieldCount += count($sec['fields'] ?? []);
                    }
                }

                $formsList[] = [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'category' => $row['category'],
                    'version' => (int)$row['version'],
                    'is_active' => (bool)$row['is_active'],
                    'is_archived' => (bool)$row['is_archived'],
                    'access_type' => $row['access_type'],
                    'created_by' => (int)$row['created_by'],
                    'creator_name' => $row['creator_name'],
                    'field_count' => $fieldCount,
                    'updated_at' => $row['updated_at'],
                    'schema' => $schema // Embed full schema so mobile can download all forms in one sync call
                ];
            }

            echo json_encode([
                'status' => 'success',
                'count' => count($formsList),
                'data' => $formsList
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
    } elseif ($method === 'POST') {
        // Create or update a form schema
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);

        if (!$payload && !empty($_POST)) {
            $payload = $_POST;
            if (isset($payload['schema']) && is_string($payload['schema'])) {
                $payload['schema'] = json_decode($payload['schema'], true);
            }
        }

        if (empty($payload['id']) || empty($payload['title']) || empty($payload['schema'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields: id, title, schema']);
            exit();
        }

        $id = trim($payload['id']);
        $title = trim($payload['title']);
        $description = $payload['description'] ?? '';
        $category = $payload['category'] ?? 'General';
        $version = isset($payload['version']) ? (int)$payload['version'] : 1;
        $isActive = isset($payload['is_active']) ? (int)$payload['is_active'] : 1;
        $accessType = $payload['access_type'] ?? 'all';
        $assignedUsers = isset($payload['assigned_users']) ? (is_string($payload['assigned_users']) ? $payload['assigned_users'] : json_encode($payload['assigned_users'])) : null;
        $assignedRoles = isset($payload['assigned_roles']) ? (is_string($payload['assigned_roles']) ? $payload['assigned_roles'] : json_encode($payload['assigned_roles'])) : null;
        $schemaJson = is_string($payload['schema']) ? $payload['schema'] : json_encode($payload['schema'], JSON_UNESCAPED_SLASHES);

        if (!$canWriteForms) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: You do not have permission to create or update form templates.']);
            exit();
        }

        // Check ownership on existing form
        $chkStmt = $pdo->prepare("SELECT * FROM forms WHERE id = :id LIMIT 1");
        $chkStmt->execute(['id' => $id]);
        $existing = $chkStmt->fetch();

        $createdBy = $existing ? (int)$existing['created_by'] : (int)$authenticatedUser['id'];

        if ($existing && !canUserManageForm($existing, $authenticatedUser)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: You cannot edit forms created by another user.']);
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO forms (id, title, description, category, version, is_active, is_archived, created_by, access_type, assigned_users_json, assigned_roles_json, schema_json)
            VALUES (:id, :title, :description, :category, :version, :is_active, 0, :created_by, :access_type, :assigned_users, :assigned_roles, :schema_json)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                category = VALUES(category),
                version = version + 1,
                is_active = VALUES(is_active),
                access_type = VALUES(access_type),
                assigned_users_json = VALUES(assigned_users_json),
                assigned_roles_json = VALUES(assigned_roles_json),
                schema_json = VALUES(schema_json),
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'version' => $version,
            'is_active' => $isActive,
            'created_by' => $createdBy,
            'access_type' => $accessType,
            'assigned_users' => $assignedUsers,
            'assigned_roles' => $assignedRoles,
            'schema_json' => $schemaJson
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Form saved successfully',
            'form_id' => $id
        ]);
    } elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing form id parameter']);
            exit();
        }

        $chkStmt = $pdo->prepare("SELECT * FROM forms WHERE id = :id LIMIT 1");
        $chkStmt->execute(['id' => $id]);
        $existing = $chkStmt->fetch();

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Form not found']);
            exit();
        }

        if (!$canDeleteForms || !canUserManageForm($existing, $authenticatedUser)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: You cannot delete this form.']);
            exit();
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM submission_attachments WHERE submission_uuid IN (SELECT submission_uuid FROM submissions WHERE form_id = :id)")->execute(['id' => $id]);
        $pdo->prepare("DELETE FROM submissions WHERE form_id = :id")->execute(['id' => $id]);
        $pdo->prepare("DELETE FROM forms WHERE id = :id")->execute(['id' => $id]);
        $pdo->commit();

        echo json_encode(['status' => 'success', 'message' => 'Form deleted successfully']);
    } else {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
