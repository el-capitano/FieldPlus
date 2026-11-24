<?php
// web-admin/config.php

if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

define('DB_HOST', getenv('DB_HOST') ?: (defined('LOCAL_DB_HOST') ? LOCAL_DB_HOST : '127.0.0.1'));
define('DB_PORT', getenv('DB_PORT') ?: (defined('LOCAL_DB_PORT') ? LOCAL_DB_PORT : '3306'));
define('DB_NAME', getenv('DB_NAME') ?: (defined('LOCAL_DB_NAME') ? LOCAL_DB_NAME : 'fieldplus_db'));
define('DB_USER', getenv('DB_USER') ?: (defined('LOCAL_DB_USER') ? LOCAL_DB_USER : 'root'));
define('DB_PASS', getenv('DB_PASS') ?: (defined('LOCAL_DB_PASS') ? LOCAL_DB_PASS : ''));

define('BASE_DIR', __DIR__);
define('UPLOAD_DIR', __DIR__ . '/public/uploads');
define('UPLOAD_URL', '/uploads');

// Configure timeouts and memory
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '512M');

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}
