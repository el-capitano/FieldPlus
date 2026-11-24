<?php
// web-admin/public/login.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$error = null;
$notice = null;

if (isset($_GET['msg']) && $_GET['msg'] === 'session_expired') {
    $notice = 'Your session has timed out due to inactivity. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $error = 'Security validation failed (invalid CSRF token). Please try submitting again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password)) {
            if (isLoginRateLimited($username)) {
                $error = 'Too many failed login attempts. Account access is temporarily locked for 15 minutes for security.';
            } else {
                $user = loginUser($username, $password);
                if ($user) {
                    header('Location: index.php');
                    exit();
                } else {
                    $error = 'Invalid username/email or password.';
                }
            }
        } else {
            $error = 'Please enter both username and password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | FieldPlus Web Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }
        .login-header {
            background: #1e3a8a;
            color: #ffffff;
            padding: 30px 24px;
            text-align: center;
        }
        .login-body {
            padding: 30px 28px;
        }
        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.15);
        }
        .btn-primary {
            background-color: #2563eb;
            border-color: #2563eb;
            border-radius: 8px;
            font-weight: 600;
            padding: 10px 16px;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <div class="d-inline-flex align-items-center justify-content-center bg-white text-primary rounded-circle mb-3" style="width: 56px; height: 56px;">
            <i class="fa-solid fa-shield-halved fa-2xl text-primary"></i>
        </div>
        <h4 class="fw-bold mb-1">FieldPlus Admin</h4>
        <p class="text-white-50 small mb-0">Authorized Survey &amp; Audit Portal</p>
    </div>

    <div class="login-body">
        <?php if ($notice): ?>
            <div class="alert alert-warning alert-dismissible fade show py-2 px-3 small" role="alert">
                <i class="fa-solid fa-clock-rotate-left me-1"></i> <?= htmlspecialchars($notice) ?>
                <button type="button" class="btn-close py-2 px-3" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 px-3 small" role="alert">
                <i class="fa-solid fa-circle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close py-2 px-3" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <?= csrfField() ?>
            <div class="mb-3">
                <label for="username" class="form-label small fw-semibold text-secondary">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-user"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0" id="username" name="username" placeholder="admin" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" class="form-control border-start-0 ps-0" id="password" name="password" placeholder="••••••••" required>
                </div>
            </div>

            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-right-to-bracket me-2"></i> Sign In to Dashboard
                </button>
            </div>
        </form>

        <div class="text-center mt-3 pt-2 border-top border-light-subtle">
            <a href="privacy.html" target="_blank" class="text-decoration-none text-muted small">
                <i class="fa-solid fa-shield-halved me-1"></i> Privacy Policy
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
