<?php
// web-admin/public/header.php
if (function_exists('sendSecurityHeaders')) {
    sendSecurityHeaders();
}
if (!isset($pageTitle)) {
    $pageTitle = 'FieldPlus Web Admin';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (function_exists('generateCsrfToken')): ?>
        <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <title><?= htmlspecialchars($pageTitle) ?> | FieldPlus</title>
    <!-- Bootstrap 5 CSS & JS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- Leaflet CSS & JS for Maps (Loaded in <head> so L is immediately available) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand-badge {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 3px 7px;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.2);
        }
        .card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #e2e8f0;
            border-top-left-radius: 12px !important;
            border-top-right-radius: 12px !important;
            font-weight: 600;
        }
        .stat-card {
            border-left: 4px solid #2563eb;
        }
        .badge-pill {
            border-radius: 20px;
            padding: 4px 10px;
            font-weight: 500;
        }
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 8px 16px;
        }
        .table thead th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: none;
        }
        .nav-link {
            font-weight: 500;
            color: rgba(255, 255, 255, 0.85);
            padding: 8px 14px;
            border-radius: 6px;
        }
        .nav-link:hover, .nav-link.active {
            color: #fff;
            background: rgba(255, 255, 255, 0.15);
        }
        /* Custom visible scrollbars for scrollable modals */
        .modal-dialog-scrollable .modal-body {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }
        .modal-dialog-scrollable .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        .modal-dialog-scrollable .modal-body::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        .modal-dialog-scrollable .modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .modal-dialog-scrollable .modal-body::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm mb-4">
    <div class="container-fluid px-4">
        <a class="navbar-brand text-white" href="index.php">
            <i class="fa-solid fa-clipboard-check fa-lg text-warning"></i>
            <span>FieldPlus</span>
            <span class="brand-badge text-white">Survey & Audit</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : '' ?>" href="index.php">
                        <i class="fa-solid fa-chart-pie me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['forms.php', 'form_builder.php', 'form_preview.php']) ? 'active' : '' ?>" href="forms.php">
                        <i class="fa-solid fa-list-check me-1"></i> Form Management
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'form_builder.php') ? 'active' : '' ?>" href="form_builder.php">
                        <i class="fa-solid fa-hammer me-1"></i> Form Builder
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['submissions.php', 'submission_detail.php']) ? 'active' : '' ?>" href="submissions.php">
                        <i class="fa-solid fa-inbox me-1"></i> Submissions & GPS
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (basename($_SERVER['PHP_SELF']) == 'app_connect.php') ? 'active' : '' ?> text-warning fw-semibold" href="app_connect.php">
                        <i class="fa-solid fa-qrcode me-1"></i> Mobile App QR Connect
                    </a>
                </li>
                <?php 
                    $canManageUsers = function_exists('hasPermission') && (isAdmin() || hasPermission('users_manage'));
                    $canManageRoles = function_exists('hasPermission') && (isAdmin() || hasPermission('roles_manage'));
                    $canViewAudit = function_exists('hasPermission') && (isAdmin() || hasPermission('audit_view'));
                ?>
                <?php if ($canManageUsers || $canManageRoles || $canViewAudit): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['users.php', 'roles.php', 'audit_logs.php']) ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fa-solid fa-users-gear me-1"></i> Access & Security
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark shadow">
                            <?php if ($canManageUsers): ?>
                                <li>
                                    <a class="dropdown-item <?= (basename($_SERVER['PHP_SELF']) == 'users.php') ? 'active' : '' ?>" href="users.php">
                                        <i class="fa-solid fa-users me-2"></i> User Management
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($canManageRoles): ?>
                                <li>
                                    <a class="dropdown-item <?= (basename($_SERVER['PHP_SELF']) == 'roles.php') ? 'active' : '' ?>" href="roles.php">
                                        <i class="fa-solid fa-shield-halved me-2"></i> Roles &amp; Permissions
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($canViewAudit): ?>
                                <li>
                                    <a class="dropdown-item <?= (basename($_SERVER['PHP_SELF']) == 'audit_logs.php') ? 'active' : '' ?>" href="audit_logs.php">
                                        <i class="fa-solid fa-clock-rotate-left me-2 text-info"></i> Security &amp; Audit Logs
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                    <i class="fa-solid fa-wifi me-1"></i> API Online
                </span>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php 
                        $uRole = $_SESSION['role'] ?? 'field_staff';
                        if ($uRole === 'admin') {
                            $roleName = 'Administrator';
                            $roleBadgeColor = 'bg-danger';
                        } elseif ($uRole === 'supervisor') {
                            $roleName = 'Supervisor';
                            $roleBadgeColor = 'bg-warning text-dark';
                        } elseif ($uRole === 'viewer') {
                            $roleName = 'Viewer';
                            $roleBadgeColor = 'bg-secondary';
                        } else {
                            $roleName = ucwords(str_replace('_', ' ', $uRole));
                            $roleBadgeColor = 'bg-info text-dark';
                        }
                    ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-light dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                            <i class="fa-solid fa-circle-user fa-lg"></i>
                            <span><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></span>
                            <span class="badge <?= $roleBadgeColor ?> py-1 px-2" style="font-size: 0.68rem;"><?= $roleName ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><h6 class="dropdown-header">Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong></h6></li>
                            <li><span class="dropdown-item-text small text-muted">Role: <span class="badge <?= $roleBadgeColor ?>"><?= $roleName ?></span></span></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if ($canManageUsers): ?>
                                <li>
                                    <a class="dropdown-item" href="users.php">
                                        <i class="fa-solid fa-users me-2 text-primary"></i> User Management
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($canManageRoles): ?>
                                <li>
                                    <a class="dropdown-item" href="roles.php">
                                        <i class="fa-solid fa-shield-halved me-2 text-warning"></i> Roles &amp; Permissions
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($canViewAudit): ?>
                                <li>
                                    <a class="dropdown-item" href="audit_logs.php">
                                        <i class="fa-solid fa-clock-rotate-left me-2 text-info"></i> Security &amp; Audit Logs
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li>
                                <a class="dropdown-item" href="app_connect.php">
                                    <i class="fa-solid fa-qrcode me-2 text-warning"></i> Mobile App QR Connect
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="logout.php">
                                    <i class="fa-solid fa-right-from-bracket me-2"></i> Log Out
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">
