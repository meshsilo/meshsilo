<?php
// MeshSilo Version
define('MESHSILO_VERSION', '1.0.0');

// Load local configuration if exists
// Check persistent location first (storage/db/config.local.php for Docker)
// Fall back to old locations for backwards compatibility
// config.local.php should ONLY contain database connection settings
if (file_exists(__DIR__ . '/../storage/db/config.local.php')) {
    require_once __DIR__ . '/../storage/db/config.local.php';
} elseif (file_exists(__DIR__ . '/../db/config.local.php')) {
    require_once __DIR__ . '/../db/config.local.php';
} elseif (file_exists(__DIR__ . '/../config.local.php')) {
    require_once __DIR__ . '/../config.local.php';
}

// Database Configuration (defaults to SQLite)
if (!defined('DB_TYPE')) define('DB_TYPE', 'sqlite');
if (!defined('DB_PATH')) define('DB_PATH', __DIR__ . '/../storage/db/meshsilo.db');

// Upload Configuration (defaults, can be overridden in config.local.php)
if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', __DIR__ . '/../storage/assets/');
if (!defined('MAX_FILE_SIZE')) define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
if (!defined('MAX_UPLOAD_SIZE')) define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB (alias)
if (!defined('MODEL_EXTENSIONS')) define('MODEL_EXTENSIONS', ['stl', '3mf', 'obj', 'ply', 'amf', 'gcode', 'glb', 'gltf', 'fbx', 'dae', 'blend', 'step', 'stp', 'iges', 'igs', '3ds', 'dxf', 'off', 'x3d']);
if (!defined('ALLOWED_EXTENSIONS')) define('ALLOWED_EXTENSIONS', ['stl', '3mf', 'obj', 'ply', 'amf', 'gcode', 'glb', 'gltf', 'fbx', 'dae', 'blend', 'step', 'stp', 'iges', 'igs', '3ds', 'dxf', 'off', 'x3d', 'zip']);

// Helper function to get base path for public assets
function basePath($path = '') {
    // Always use absolute paths from root for assets
    // This ensures CSS/JS work regardless of the current URL path
    if (preg_match('#^(css|js|images)/#', $path)) {
        return '/public/' . $path;
    }
    return '/' . ltrim($path, '/');
}

// Include logging and database first
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db.php';

// Load site configuration from database
// All settings are stored in the database, not in config files
// This ensures settings can be managed via admin panel and can't be overwritten by file changes
if (!defined('SITE_NAME')) {
    $dbSiteName = null;
    try {
        $dbSiteName = getSetting('site_name', 'MeshSilo');
    } catch (Exception $e) {
        $dbSiteName = 'MeshSilo';
    }
    define('SITE_NAME', $dbSiteName);
}
if (!defined('SITE_DESCRIPTION')) {
    $dbSiteDesc = null;
    try {
        $dbSiteDesc = getSetting('site_description', '3D Model Storage');
    } catch (Exception $e) {
        $dbSiteDesc = '3D Model Storage';
    }
    define('SITE_DESCRIPTION', $dbSiteDesc);
}
if (!defined('SITE_URL')) {
    $dbSiteUrl = null;
    try {
        $dbSiteUrl = getSetting('site_url', '/');
    } catch (Exception $e) {
        $dbSiteUrl = '/';
    }
    define('SITE_URL', $dbSiteUrl ?: '/');
}
if (!defined('FORCE_SITE_URL')) {
    $dbForceUrl = null;
    try {
        $dbForceUrl = getSetting('force_site_url', '0');
    } catch (Exception $e) {
        $dbForceUrl = '0';
    }
    define('FORCE_SITE_URL', $dbForceUrl === '1' || $dbForceUrl === true);
}

// Include authentication, permissions, OIDC, mail and licensing
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/oidc.php';
require_once __DIR__ . '/saml.php';
require_once __DIR__ . '/ldap.php';
require_once __DIR__ . '/Queue.php';
require_once __DIR__ . '/Mail.php';
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
require_once __DIR__ . '/DemoMode.php';

// Load infrastructure components
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/QueryBuilder.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/Asset.php';
require_once __DIR__ . '/HttpCache.php';
require_once __DIR__ . '/ErrorHandler.php';

// Load route definitions (for URL generation)
// Only load if routes haven't been loaded yet
$router = Router::getInstance();
if (empty($router->getNamedRoutes())) {
    require_once __DIR__ . '/routes.php';
}

// Set up error handling
setupErrorHandler();
