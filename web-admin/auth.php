<?php
// web-admin/auth.php
require_once __DIR__ . '/db.php';

// OWASP A02 / A07: Secure session cookies and configuration
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0, // Session cookie expires when browser closes
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true, // Mitigate XSS stealing session cookies
        'samesite' => 'Lax' // Mitigate CSRF across third-party origins
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();
}

/**
 * Send standard HTTP security headers across all web responses (OWASP A05).
 */
function sendSecurityHeaders(): void {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self)');
    
    // Content-Security-Policy (allows CDNs for Bootstrap, FontAwesome, Leaflet, Google Fonts)
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; " .
           "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com https://fonts.googleapis.com; " .
           "font-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://fonts.gstatic.com data:; " .
           "img-src 'self' data: blob: https: https://*.tile.openstreetmap.org https://*.google.com https://*.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com; " .
           "connect-src 'self' *; " .
           "frame-ancestors 'self';";
    header("Content-Security-Policy: {$csp}");
}

// Automatically send security headers
sendSecurityHeaders();

/**
 * Get client IP address accurately with reverse proxy awareness.
 */
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * CSRF Protection Helpers (OWASP A01)
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    $token = htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        $pageTitle = 'Security Violation';
        require_once __DIR__ . '/public/header.php';
        echo '
        <div class="row justify-content-center py-5">
            <div class="col-md-6 text-center">
                <div class="card p-5 border-0 shadow-sm border-danger">
                    <div class="mb-3 text-danger">
                        <i class="fa-solid fa-triangle-exclamation fa-4x"></i>
                    </div>
                    <h3 class="fw-bold mb-2 text-danger">CSRF Security Validation Failed</h3>
                    <p class="text-muted mb-4">Your request was blocked due to an invalid or missing security token (CSRF). This protects your account against unauthorized cross-site requests.</p>
                    <div>
                        <a href="javascript:history.back()" class="btn btn-primary"><i class="fa-solid fa-arrow-left me-1"></i> Go Back and Try Again</a>
                    </div>
                </div>
            </div>
        </div>';
        require_once __DIR__ . '/public/footer.php';
        exit();
    }
}

/**
 * Record Security Audit Event (OWASP A09).
 */
function logSecurityEvent(string $action, string $details = '', ?int $userId = null, ?string $username = null): void {
    try {
        $pdo = getDbConnection();
        $ip = getClientIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        }
        if ($username === null && isset($_SESSION['username'])) {
            $username = $_SESSION['username'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, username, action, details, ip_address, user_agent)
            VALUES (:uid, :uname, :act, :det, :ip, :ua)
        ");
        $stmt->execute([
            'uid' => $userId,
            'uname' => $username,
            'act' => $action,
            'det' => $details,
            'ip' => $ip,
            'ua' => $userAgent
        ]);
    } catch (Exception $e) {
        // Silently continue so security logging never crashes legitimate workflows
    }
}

/**
 * Rate Limiting & Brute Force Lockout (OWASP A04 / A07).
 */
function isLoginRateLimited(string $username): bool {
    try {
        $pdo = getDbConnection();
        $ip = getClientIp();

        // 5 failed attempts in the last 15 minutes
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE (ip_address = :ip OR username = :username)
              AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute(['ip' => $ip, 'username' => $username]);
        $attempts = (int)$stmt->fetchColumn();

        return $attempts >= 5;
    } catch (Exception $e) {
        return false;
    }
}

function recordFailedLogin(string $username): void {
    try {
        $pdo = getDbConnection();
        $ip = getClientIp();
        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (:ip, :username)");
        $stmt->execute(['ip' => $ip, 'username' => $username]);

        logSecurityEvent('LOGIN_FAILED', "Failed login attempt for username '{$username}' from IP {$ip}", null, $username);
    } catch (Exception $e) {
        // fail-safe
    }
}

function clearFailedLogins(string $username): void {
    try {
        $pdo = getDbConnection();
        $ip = getClientIp();
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = :ip OR username = :username");
        $stmt->execute(['ip' => $ip, 'username' => $username]);
    } catch (Exception $e) {
        // fail-safe
    }
}

/**
 * Enforce Web Admin authentication.
 * Redirects to login.php if session is not authenticated.
 */
function requireAdminAuth(): array {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }

    // Session inactivity timeout (4 hours)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 14400)) {
        logoutUser();
        header('Location: login.php?msg=session_expired');
        exit();
    }
    $_SESSION['last_activity'] = time();

    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role' => $_SESSION['role'],
        'api_token' => $_SESSION['api_token'] ?? ''
    ];
}

/**
 * Check if the user has admin role.
 */
function isAdmin(?array $user = null): bool {
    $role = $user['role'] ?? $_SESSION['role'] ?? '';
    return $role === 'admin';
}

/**
 * Check if the user has field staff role.
 */
function isFieldStaff(?array $user = null): bool {
    $role = $user['role'] ?? $_SESSION['role'] ?? '';
    return in_array($role, ['field_staff', 'surveyor']);
}

/**
 * All available permissions definitions grouped by module.
 */
function getAllDefinedPermissions(): array {
    return [
        'Dashboard & Analytics' => [
            'dashboard_view' => [
                'label' => 'View Dashboard & Analytics',
                'description' => 'Access operational metrics, activity timeline, and GPS map statistics.'
            ]
        ],
        'Form Management' => [
            'forms_read' => [
                'label' => 'Read Forms Catalog',
                'description' => 'View form templates catalog and preview form schemas.'
            ],
            'forms_write' => [
                'label' => 'Create & Edit Forms',
                'description' => 'Create new form templates, edit schema fields in Visual Builder, and duplicate forms.'
            ],
            'forms_delete' => [
                'label' => 'Delete Forms',
                'description' => 'Permanently delete form templates and their associated schemas.'
            ],
            'forms_all' => [
                'label' => 'Access All Forms',
                'description' => 'Bypass ownership filter: view and manage forms created by any user across the organization.'
            ]
        ],
        'Submissions & GPS Audits' => [
            'submissions_read' => [
                'label' => 'Read Submissions & GPS Data',
                'description' => 'Browse submitted audits, review coordinates, inspect photos, signatures, and export CSV/JSON/GeoJSON.'
            ],
            'submissions_write' => [
                'label' => 'Audit & Review Submissions',
                'description' => 'Change submission review status (Approve, Flag, Reject) and write audit review feedback.'
            ],
            'submissions_delete' => [
                'label' => 'Delete Submissions',
                'description' => 'Delete field visit submission records and uploaded media files.'
            ],
            'submissions_all' => [
                'label' => 'Access All Submissions',
                'description' => 'Bypass ownership filter: view and audit submissions collected by any staff member.'
            ]
        ],
        'Mobile App & Data Collection' => [
            'mobile_collect' => [
                'label' => 'Mobile App Data Collection',
                'description' => 'Authenticate via Android QR code, download active forms, and sync field survey records.'
            ]
        ],
        'User & Staff Administration' => [
            'users_manage' => [
                'label' => 'Manage User Accounts',
                'description' => 'Create, edit, toggle active status, and generate mobile pairing codes for staff members.'
            ],
            'roles_manage' => [
                'label' => 'Manage Roles & Permissions',
                'description' => 'Create and customize system roles, and grant/revoke read/write module permissions.'
            ],
            'audit_view' => [
                'label' => 'View Security & Audit Logs',
                'description' => 'Inspect authentication logs, track administrative operations, and monitor rate-limiting lockouts.'
            ]
        ]
    ];
}

/**
 * Get the flat list of all valid permission keys.
 */
function getAllPermissionKeys(): array {
    $keys = [];
    foreach (getAllDefinedPermissions() as $group => $items) {
        foreach ($items as $k => $info) {
            $keys[] = $k;
        }
    }
    return $keys;
}

/**
 * Get permissions assigned to a user/role.
 */
function getUserPermissions(?array $user = null): array {
    static $cache = [];

    $role = $user['role'] ?? $_SESSION['role'] ?? '';
    if (empty($role)) {
        return [];
    }

    // Administrator always has all permissions
    if ($role === 'admin') {
        return getAllPermissionKeys();
    }

    if (isset($cache[$role])) {
        return $cache[$role];
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT permissions_json FROM roles WHERE slug = :slug LIMIT 1");
    $stmt->execute(['slug' => $role]);
    $row = $stmt->fetch();

    if ($row && !empty($row['permissions_json'])) {
        $perms = json_decode($row['permissions_json'], true) ?: [];
    } else {
        // Fallback for default field_staff / surveyor if not in DB
        $perms = [
            'dashboard_view',
            'forms_read',
            'forms_write',
            'submissions_read',
            'mobile_collect'
        ];
    }

    $cache[$role] = $perms;
    return $perms;
}

/**
 * Check if the given user (or session user) has a specific permission.
 */
function hasPermission(string $permission, ?array $user = null): bool {
    $role = $user['role'] ?? $_SESSION['role'] ?? '';
    if ($role === 'admin') {
        return true;
    }
    $perms = getUserPermissions($user);
    return in_array($permission, $perms, true);
}

/**
 * Enforce a specific permission or halt with 403.
 */
function requirePermission(string $permission, string $customMessage = ''): array {
    $user = requireAdminAuth();
    if (!hasPermission($permission, $user)) {
        http_response_code(403);
        $pageTitle = 'Access Denied';
        require_once __DIR__ . '/public/header.php';
        $msg = $customMessage ?: "You do not have the required permission (<code>{$permission}</code>) to access this module.";
        echo '
        <div class="row justify-content-center py-5">
            <div class="col-md-6 text-center">
                <div class="card p-5 border-0 shadow-sm">
                    <div class="mb-3 text-danger">
                        <i class="fa-solid fa-lock fa-4x"></i>
                    </div>
                    <h3 class="fw-bold mb-2">Insufficient Permissions</h3>
                    <p class="text-muted mb-4">' . $msg . '</p>
                    <div>
                        <a href="index.php" class="btn btn-primary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>';
        require_once __DIR__ . '/public/footer.php';
        exit();
    }
    return $user;
}

/**
 * Enforce that the user must be an Admin.
 * Used for admin-only management screens (e.g. User Management).
 */
function requireAdminOnly(): array {
    $user = requireAdminAuth();
    if (!isAdmin($user)) {
        http_response_code(403);
        $pageTitle = 'Access Denied';
        require_once __DIR__ . '/public/header.php';
        echo '
        <div class="row justify-content-center py-5">
            <div class="col-md-6 text-center">
                <div class="card p-5 border-0 shadow-sm">
                    <div class="mb-3 text-danger">
                        <i class="fa-solid fa-shield-halved fa-4x"></i>
                    </div>
                    <h3 class="fw-bold mb-2">Access Denied</h3>
                    <p class="text-muted mb-4">You do not have administrative privileges to access this user management area. Only users with the <strong>Admin</strong> role can perform this operation.</p>
                    <div>
                        <a href="index.php" class="btn btn-primary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>';
        require_once __DIR__ . '/public/footer.php';
        exit();
    }
    return $user;
}

/**
 * Enforce Bearer Token authentication for API endpoints.
 * Returns authenticated user row from database or sends JSON 401 and halts.
 */
function requireApiBearerAuth(): array {
    $pdo = getDbConnection();
    
    // Check Authorization header
    $authHeader = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    }

    $token = null;
    if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    } elseif (!empty($_GET['token'])) {
        $token = trim($_GET['token']);
    } elseif (!empty($_POST['token'])) {
        $token = trim($_POST['token']);
    }

    // If no Bearer token, check if user is already authenticated via Web Admin Session
    if (!$token && isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, username, email, full_name, role, api_token, is_active FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user && $user['is_active']) {
            return $user;
        }
    }

    if (!$token) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'error_code' => 'AUTH_REQUIRED',
            'message' => 'Unauthorized: Missing Bearer token in Authorization header (Format: Bearer <api_token>)'
        ]);
        exit();
    }

    $stmt = $pdo->prepare("SELECT id, username, email, full_name, role, api_token, is_active FROM users WHERE api_token = :token LIMIT 1");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'error_code' => 'INVALID_TOKEN',
            'message' => 'Unauthorized: Invalid or revoked API Bearer token'
        ]);
        exit();
    }

    return $user;
}

/**
 * Authenticate username/password and create web session.
 */
function loginUser(string $username, string $password): ?array {
    $pdo = getDbConnection();

    // Check brute-force lockout
    if (isLoginRateLimited($username)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1");
    $stmt->execute(['username' => $username, 'email' => $username]);
    $user = $stmt->fetch();

    if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
        // OWASP A07: Session Fixation Prevention
        session_regenerate_id(true);

        // Update last login
        $pdo->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['id' => $user['id']]);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['api_token'] = $user['api_token'];
        $_SESSION['last_activity'] = time();

        clearFailedLogins($username);
        logSecurityEvent('LOGIN_SUCCESS', "User '{$user['username']}' successfully logged in", (int)$user['id'], $user['username']);

        return $user;
    }

    recordFailedLogin($username);
    return null;
}

/**
 * Log out current web user.
 */
function logoutUser(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['username'])) {
        logSecurityEvent('LOGOUT', "User '{$_SESSION['username']}' logged out", (int)($_SESSION['user_id'] ?? 0), $_SESSION['username']);
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Determine if a user is permitted to use/view/download a form.
 * Returns true if user has admin/forms_all, is creator, or matches access_type criteria (and form is not archived).
 */
function canUserAccessForm(array $form, ?array $user = null): bool {
    if (!$user) {
        $user = requireAdminAuth();
    }
    
    // Super admins and accounts with full forms access can access everything
    if (isAdmin($user) || hasPermission('forms_all', $user)) {
        return true;
    }

    $userId = (int)($user['id'] ?? 0);
    $creatorId = (int)($form['created_by'] ?? 0);

    // Creator always has access to their own form
    if ($userId > 0 && $userId === $creatorId) {
        return true;
    }

    // Archived forms are not accessible for regular use/sync
    if (!empty($form['is_archived'])) {
        return false;
    }

    $accessType = $form['access_type'] ?? 'all';

    if ($accessType === 'all') {
        return true;
    }

    if ($accessType === 'creator_only') {
        return false; // Handled above if user was creator
    }

    if ($accessType === 'specific_users') {
        $assigned = json_decode($form['assigned_users_json'] ?? '[]', true) ?: [];
        $assigned = array_map('intval', $assigned);
        return in_array($userId, $assigned, true);
    }

    if ($accessType === 'specific_roles') {
        $assignedRoles = json_decode($form['assigned_roles_json'] ?? '[]', true) ?: [];
        $userRole = $user['role'] ?? '';
        return in_array($userRole, $assignedRoles, true);
    }

    return true;
}

/**
 * Determine if a user can manage (edit, configure permissions, archive, delete, or clear submissions) a form.
 */
function canUserManageForm(array $form, ?array $user = null): bool {
    if (!$user) {
        $user = requireAdminAuth();
    }

    if (isAdmin($user) || hasPermission('forms_all', $user)) {
        return true;
    }

    $userId = (int)($user['id'] ?? 0);
    $creatorId = (int)($form['created_by'] ?? 0);

    return ($userId > 0 && $userId === $creatorId);
}

