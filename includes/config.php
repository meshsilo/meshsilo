<?php
// Site Configuration
define('SITE_NAME', 'Silo');
define('SITE_DESCRIPTION', '3D Model Storage');
define('SITE_URL', '/');

// Database Configuration
define('DB_PATH', __DIR__ . '/../db/silo.db');

// Upload Configuration
define('UPLOAD_PATH', __DIR__ . '/../assets/');
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('MODEL_EXTENSIONS', ['stl', '3mf']);
define('ALLOWED_EXTENSIONS', ['stl', '3mf', 'zip']);

// Helper function to get base path for includes
function basePath($path = '') {
    global $baseDir;
    return ($baseDir ?? '') . $path;
}

// Include logging, database, authentication and permissions
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';

// Set up error handling
setupErrorHandler();
