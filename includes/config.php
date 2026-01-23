<?php
// Silo Version
define('SILO_VERSION', '1.0.0');

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
require_once __DIR__ . '/saml.php';
require_once __DIR__ . '/ldap.php';
require_once __DIR__ . '/license.php';

// Include router and helpers (if not already loaded by front controller)
if (!class_exists('Router')) {
    require_once __DIR__ . '/Router.php';
}
require_once __DIR__ . '/helpers.php';

// Load middleware interface before classes that implement it
if (!interface_exists('MiddlewareInterface')) {
    require_once __DIR__ . '/middleware/MiddlewareInterface.php';
}
require_once __DIR__ . '/SignedUrl.php';
require_once __DIR__ . '/Events.php';
require_once __DIR__ . '/TwoFactor.php';
require_once __DIR__ . '/Integrity.php';
require_once __DIR__ . '/Scheduler.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/RetentionManager.php';
require_once __DIR__ . '/Analytics.php';

// Load infrastructure components
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/QueryBuilder.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/Asset.php';
require_once __DIR__ . '/HttpCache.php';
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/Queue.php';
// require_once __DIR__ . '/RateLimiter.php';  // Temporarily disabled for debugging
// require_once __DIR__ . '/UpdateChecker.php'; // Temporarily disabled for debugging

// Load route definitions (for URL generation)
// Only load if routes haven't been loaded yet
$router = Router::getInstance();
if (empty($router->getNamedRoutes())) {
    require_once __DIR__ . '/routes.php';
}

// Set up error handling
setupErrorHandler();
