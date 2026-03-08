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
if (!defined('DB_TYPE')) {
    define('DB_TYPE', 'sqlite');
}
if (!defined('DB_PATH')) {
    define('DB_PATH', __DIR__ . '/../storage/db/meshsilo.db');
}

// Upload Configuration (defaults, can be overridden in config.local.php or database)
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', __DIR__ . '/../storage/assets/');
}
// MAX_FILE_SIZE is defined later after database settings are loaded
if (!defined('MAX_UPLOAD_SIZE')) {
    define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB (alias)
}
if (!defined('MODEL_EXTENSIONS')) {
    define('MODEL_EXTENSIONS', ['stl', '3mf', 'obj', 'ply', 'amf', 'gcode', 'glb', 'gltf', 'fbx', 'dae', 'blend', 'step', 'stp', 'iges', 'igs', '3ds', 'dxf', 'off', 'x3d']);
}
if (!defined('ALLOWED_EXTENSIONS')) {
    define('ALLOWED_EXTENSIONS', ['stl', '3mf', 'obj', 'ply', 'amf', 'gcode', 'glb', 'gltf', 'fbx', 'dae', 'blend', 'step', 'stp', 'iges', 'igs', '3ds', 'dxf', 'off', 'x3d', 'zip']);
}

// Helper function to get base path for public assets
function basePath($path = '')
{
    // Always use absolute paths from root for assets
    // This ensures CSS/JS work regardless of the current URL path
    if (preg_match('#^(css|js|images)/#', $path)) {
        return '/public/' . $path;
    }
    return '/' . ltrim($path, '/');
}

// Include logging and database first
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/Debug.php';
Debug::init();
require_once __DIR__ . '/db.php';

// Load site configuration from database (batch load for performance)
// All settings are stored in the database, not in config files
// This ensures settings can be managed via admin panel and can't be overwritten by file changes
if (!defined('SITE_NAME') || !defined('SITE_DESCRIPTION') || !defined('SITE_URL') || !defined('FORCE_SITE_URL')) {
    // Initialize settings cache and load all settings in one query
    $GLOBALS['_settings_cache'] = [];
    try {
        $allSettings = getAllSettings();
        $GLOBALS['_settings_cache'] = $allSettings;
    } catch (Exception $e) {
        $allSettings = [];
    }

    // Define constants from batch-loaded settings
    if (!defined('SITE_NAME')) {
        define('SITE_NAME', $allSettings['site_name'] ?? 'MeshSilo');
    }
    if (!defined('SITE_DESCRIPTION')) {
        define('SITE_DESCRIPTION', $allSettings['site_description'] ?? '3D Model Storage');
    }
    if (!defined('SITE_URL')) {
        $siteUrl = $allSettings['site_url'] ?? '/';
        define('SITE_URL', $siteUrl ?: '/');
    }
    if (!defined('FORCE_SITE_URL')) {
        $forceUrl = $allSettings['force_site_url'] ?? '0';
        define('FORCE_SITE_URL', $forceUrl === '1' || $forceUrl === true);
    }
    // Define MAX_FILE_SIZE from database or use default
    if (!defined('MAX_FILE_SIZE')) {
        $maxFileSize = isset($allSettings['max_file_size']) ? (int)$allSettings['max_file_size'] : 0;
        if ($maxFileSize > 0) {
            define('MAX_FILE_SIZE', $maxFileSize);
        } else {
            define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB default
        }
    }
}

// Include authentication, permissions, mail and licensing
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/Queue.php';
require_once __DIR__ . '/Mail.php';
// Include router and helpers (if not already loaded by front controller)
if (!class_exists('Router')) {
    require_once __DIR__ . '/Router.php';
}
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/features.php';

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

// Load infrastructure components
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/QueryBuilder.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/Search.php';
require_once __DIR__ . '/Asset.php';
require_once __DIR__ . '/HttpCache.php';
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/RateLimiter.php';

// Load plugin system
require_once __DIR__ . '/PluginManager.php';
if (is_dir(dirname(__DIR__) . '/plugins')) {
    $pluginManager = PluginManager::getInstance();
    $pluginManager->discoverPlugins();
    $pluginManager->loadActivePlugins();
}

// Enforce authentication (after plugins load so they can register public routes)
if (php_sapi_name() !== 'cli') {
    enforceAuthentication();
}

// Load route definitions (for URL generation)
// Only load if routes haven't been loaded yet
$router = Router::getInstance();
if (empty($router->getNamedRoutes())) {
    require_once __DIR__ . '/routes.php';
}

// Set up error handling
setupErrorHandler();
