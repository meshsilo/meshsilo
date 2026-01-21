<?php
// Load local configuration if exists
if (file_exists(__DIR__ . '/../config.local.php')) {
    require_once __DIR__ . '/../config.local.php';
}

// Site Configuration (defaults, can be overridden in config.local.php)
if (!defined('SITE_NAME')) define('SITE_NAME', 'Silo');
if (!defined('SITE_DESCRIPTION')) define('SITE_DESCRIPTION', '3D Model Storage');
if (!defined('SITE_URL')) define('SITE_URL', '/');
if (!defined('FORCE_SITE_URL')) define('FORCE_SITE_URL', false);

// Database Configuration (defaults to SQLite)
if (!defined('DB_TYPE')) define('DB_TYPE', 'sqlite');
if (!defined('DB_PATH')) define('DB_PATH', __DIR__ . '/../db/silo.db');

// Upload Configuration
define('UPLOAD_PATH', __DIR__ . '/../assets/');
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('MODEL_EXTENSIONS', ['stl', '3mf', 'gcode']);
define('ALLOWED_EXTENSIONS', ['stl', '3mf', 'gcode', 'zip']);

// Helper function to get base path for includes
function basePath($path = '') {
    global $baseDir;
    return ($baseDir ?? '') . $path;
}

// Include logging, database, authentication, permissions, OIDC and licensing
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/oidc.php';
require_once __DIR__ . '/license.php';

// Set up error handling
setupErrorHandler();
