<?php
// Application settings and extension checking functions
function initializeDefaultSettings($db)
{
    $type = $db->getType();

    $defaults = [
        // Core site settings
        'site_name' => 'MeshSilo',
        'site_description' => 'Your 3D Model Library',
        'site_url' => '/',
        'force_site_url' => '0',
        'site_tagline' => '3D Model Library',
        'models_per_page' => '20',
        'allow_registration' => '1',
        'require_approval' => '0',
        'allowed_extensions' => DEFAULT_ALLOWED_EXTENSIONS,
        'auto_convert_stl' => '0',
        'auto_deduplication' => getenv('MESHSILO_DOCKER') === 'true' ? '1' : '0',

        // Feature toggles
        'enable_categories' => '1',
        'enable_collections' => '1',
        'enable_tags' => '1',
        'enable_activity_log' => '1',
        'enable_access_log' => '0',

        // Theme and display
        'default_theme' => 'dark',
        'allow_user_theme' => '1',
        'default_sort' => 'newest',
        'default_view' => 'grid',

        // Maintenance
        'maintenance_mode' => '0',
        'maintenance_admin_bypass' => '1',
        'maintenance_bypass_secret' => '',
        'maintenance_whitelist_ips' => '',
        'maintenance_message' => '',
        'maintenance_title' => 'Maintenance Mode',

        // Activity log
        'activity_log_retention_days' => '90',
        'activity_log_retention' => '90',
        'audit_logging_enabled' => '1',

        // Mail
        'mail_driver' => 'mail',
        'mail_host' => 'localhost',
        'mail_port' => '587',
        'mail_username' => '',
        'mail_password' => '',
        'mail_encryption' => 'tls',
        'mail_from_address' => 'noreply@example.com',
        'mail_from_name' => 'MeshSilo',
        'admin_email' => '',

        // Storage
        'storage_type' => 'local',
        's3_endpoint' => '',
        's3_bucket' => '',
        's3_access_key' => '',
        's3_secret_key' => '',
        's3_region' => 'us-east-1',
        's3_path_style' => '0',
        's3_public_url' => '',

        // Rate limiting
        'rate_limiting' => '1',
        'rate_limit_storage' => 'file',

        // Branding
        'logo_path' => '',
        'favicon_path' => '',
        'brand_primary_color' => '#6366f1',
        'brand_secondary_color' => '#8b5cf6',
        'brand_accent_color' => '#06b6d4',
        'brand_background_color' => '#f9fafb',
        'brand_text_color' => '#111827',
        'custom_css' => '',
        'custom_head_html' => '',
        'custom_footer_html' => '',
        'brand_font_family' => 'Inter, system-ui, sans-serif',
        'brand_border_radius' => '0.5rem',
        'dark_mode_enabled' => '0',
        'brand_dark_background' => '#1f2937',
        'brand_dark_text' => '#f9fafb',

        // Routing and performance
        'seo_redirects' => '1',
        'route_caching' => '0',
        'route_profiling' => '0',

        'currency' => 'USD',

        // File types
        'file_type_config' => '{}',

        // Homepage
        'homepage_config' => '',

        // Signed URLs
        'signed_url_secret' => '',

        // Update checker
        'update_check_enabled' => '1',

        // CORS
        'cors_allowed_origins' => '',
        'cors_allowed_methods' => '',
        'cors_allowed_headers' => '',
        'cors_allow_credentials' => '0',

        // Default group
        'default_group' => '1',

        // Schema tracking
        'schema_version' => '',
        'last_migration' => '',

        // Slicers
        'enabled_slicers' => '',
    ];

    try {
        if ($type === 'mysql') {
            $stmt = $db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (:key, :value)');
        } else {
            $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
        }

        foreach ($defaults as $key => $value) {
            $stmt->execute([':key' => $key, ':value' => $value]);
        }
    } catch (Exception $e) {
        logDebug('Settings initialization skipped', ['error' => $e->getMessage()]);
    }

    // Seed official plugin repository
    try {
        $repoUrl = 'https://raw.githubusercontent.com/meshsilo/meshsilo-plugins/main/registry.json';
        if ($type === 'mysql') {
            $db->prepare('INSERT IGNORE INTO plugin_repositories (name, url, is_official) VALUES (:name, :url, 1)')
                ->execute([':name' => 'MeshSilo Official', ':url' => $repoUrl]);
        } else {
            $db->prepare('INSERT OR IGNORE INTO plugin_repositories (name, url, is_official) VALUES (:name, :url, 1)')
                ->execute([':name' => 'MeshSilo Official', ':url' => $repoUrl]);
        }
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

// Settings cache storage
$GLOBALS['_settings_cache'] = [];

// Get a setting value (with in-memory cache for performance)
function getSetting($key, $default = null)
{
    // Return cached value if available
    if (array_key_exists($key, $GLOBALS['_settings_cache'])) {
        return $GLOBALS['_settings_cache'][$key];
    }

    try {
        $db = getDB();
        $type = $db->getType();
        $keyCol = $type === 'mysql' ? '`key`' : 'key';
        $stmt = $db->prepare("SELECT value FROM settings WHERE $keyCol = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        $value = $row ? $row['value'] : $default;

        // Cache the value (including nulls/defaults)
        $GLOBALS['_settings_cache'][$key] = $value;
        return $value;
    } catch (Exception $e) {
        $GLOBALS['_settings_cache'][$key] = $default;
        return $default;
    }
}

// Set a setting value
function setSetting($key, $value)
{
    try {
        $db = getDB();
        $type = $db->getType();

        if ($type === 'mysql') {
            $stmt = $db->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (:key, :value, NOW()) ON DUPLICATE KEY UPDATE `value` = :value2, updated_at = NOW()');
            $stmt->execute([':key' => $key, ':value' => $value, ':value2' => $value]);
        } else {
            $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)');
            $stmt->execute([':key' => $key, ':value' => $value]);
        }

        // Update cache with new value
        $GLOBALS['_settings_cache'][$key] = $value;

        return true;
    } catch (Exception $e) {
        logException($e, ['action' => 'set_setting', 'key' => $key]);
        return false;
    }
}

// Get all settings
function getAllSettings()
{
    try {
        $db = getDB();
        $type = $db->getType();
        $keyCol = $type === 'mysql' ? '`key`' : 'key';
        $result = $db->query("SELECT $keyCol as setting_key, value FROM settings");
        $settings = [];
        while ($row = $result->fetch()) {
            $settings[$row['setting_key']] = $row['value'];
        }
        return $settings;
    } catch (Exception $e) {
        return [];
    }
}

// Default allowed file extensions — single source of truth for all default lists
const DEFAULT_ALLOWED_EXTENSIONS = 'stl,3mf,obj,ply,amf,gcode,lys,ctb,cbddlp,photon,sl1,pwmo,chitubox,glb,gltf,fbx,dae,blend,step,stp,iges,igs,3ds,dxf,off,x3d,f3d,f3z,scad,skp,3dm,zip';

// Get allowed file extensions (configurable via settings)
function getAllowedExtensions()
{
    $setting = getSetting('allowed_extensions', DEFAULT_ALLOWED_EXTENSIONS);
    $allowedExtensions = array_map('trim', explode(',', $setting));

    if (class_exists('PluginManager')) {
        $allowedExtensions = PluginManager::applyFilter('supported_file_types', $allowedExtensions);
    }

    return $allowedExtensions;
}

// Get model extensions (3D model and slicer file types, not containers or attachments)
function getModelExtensions()
{
    $allowed = getAllowedExtensions();
    $exclude = array_merge(['zip'], defined('ATTACHMENT_EXTENSIONS') ? ATTACHMENT_EXTENSIONS : []);
    return array_values(array_filter($allowed, fn($ext) => !in_array($ext, $exclude)));
}

// Check if an extension is allowed
function isExtensionAllowed($extension)
{
    return in_array(strtolower($extension), getAllowedExtensions());
}

// Check if an extension is a model format (not a container or attachment)
function isModelExtension($extension)
{
    return in_array(strtolower($extension), getModelExtensions());
}

// Check if an extension is an attachment type (image, document, text)
function isAttachmentExtension($extension)
{
    $attachmentExts = defined('ATTACHMENT_EXTENSIONS') ? ATTACHMENT_EXTENSIONS : ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md'];
    return in_array(strtolower($extension), $attachmentExts);
}

// =====================
// Tag Functions
// =====================

// Get all tags (cached for 5 minutes)
