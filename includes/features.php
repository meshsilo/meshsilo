<?php
/**
 * Feature Toggles
 *
 * Allows administrators to enable/disable optional features.
 * Disabled features hide their UI elements and settings pages.
 */

/**
 * Feature Dependencies
 * Maps features to their required dependencies.
 * If a dependency is disabled, the feature cannot function properly.
 */
function getFeatureDependencies(): array {
    return [
        // Print history requires print queue functionality
        'print_history' => ['print_queue'],
        // Scheduled reports requires activity log for data
        'scheduled_reports' => ['activity_log'],
        // Webhooks often used with API keys
        'webhooks' => [],
        // Model analysis benefits from print history data
        'model_analysis' => [],
    ];
}

/**
 * Get features that depend on a given feature
 */
function getDependentFeatures(string $feature): array {
    $dependencies = getFeatureDependencies();
    $dependents = [];

    foreach ($dependencies as $feat => $deps) {
        if (in_array($feature, $deps)) {
            $dependents[] = $feat;
        }
    }

    return $dependents;
}

/**
 * Check if a feature's dependencies are satisfied
 */
function areFeatureDependenciesMet(string $feature): bool {
    $dependencies = getFeatureDependencies();

    if (!isset($dependencies[$feature])) {
        return true;
    }

    foreach ($dependencies[$feature] as $dep) {
        if (!isFeatureEnabled($dep)) {
            return false;
        }
    }

    return true;
}

/**
 * Get missing dependencies for a feature
 */
function getMissingDependencies(string $feature): array {
    $dependencies = getFeatureDependencies();
    $missing = [];

    if (!isset($dependencies[$feature])) {
        return [];
    }

    foreach ($dependencies[$feature] as $dep) {
        if (!isFeatureEnabled($dep)) {
            $features = getAvailableFeatures();
            $missing[] = $features[$dep]['name'] ?? $dep;
        }
    }

    return $missing;
}

// Define available features with metadata
function getAvailableFeatures(): array {
    return [
        'teams' => [
            'name' => 'Teams',
            'description' => 'Team workspaces for sharing models with groups of users',
            'icon' => 'users',
            'category' => 'Collaboration',
            'default' => true,
        ],
        'api_keys' => [
            'name' => 'API Keys',
            'description' => 'Programmatic access via REST API with key authentication',
            'icon' => 'key',
            'category' => 'Integration',
            'default' => true,
        ],
        'webhooks' => [
            'name' => 'Webhooks',
            'description' => 'Send HTTP notifications when events occur',
            'icon' => 'webhook',
            'category' => 'Integration',
            'default' => true,
        ],
        'retention_policies' => [
            'name' => 'Retention Policies',
            'description' => 'Automated data retention, archiving, and legal holds',
            'icon' => 'archive',
            'category' => 'Compliance',
            'default' => false,
        ],
        'scheduled_reports' => [
            'name' => 'Scheduled Reports',
            'description' => 'Automated report generation and email delivery',
            'icon' => 'file-text',
            'category' => 'Analytics',
            'default' => false,
        ],
        'print_queue' => [
            'name' => 'Print Queue',
            'description' => 'Queue models for printing with priority management',
            'icon' => 'printer',
            'category' => 'Printing',
            'default' => true,
        ],
        'printers' => [
            'name' => 'Printer Profiles',
            'description' => 'Manage printer specifications and bed sizes',
            'icon' => 'settings',
            'category' => 'Printing',
            'default' => true,
        ],
        'print_history' => [
            'name' => 'Print History',
            'description' => 'Track print jobs with filament usage and ratings',
            'icon' => 'history',
            'category' => 'Printing',
            'default' => true,
        ],
        'model_analysis' => [
            'name' => 'Model Analysis',
            'description' => 'Automated printability analysis and warnings',
            'icon' => 'activity',
            'category' => 'Analysis',
            'default' => false,
        ],
        'import_jobs' => [
            'name' => 'Bulk Import',
            'description' => 'Import models from external sources in bulk',
            'icon' => 'download',
            'category' => 'Import/Export',
            'default' => false,
        ],
        'two_factor_auth' => [
            'name' => 'Two-Factor Authentication',
            'description' => 'TOTP-based 2FA with backup codes',
            'icon' => 'shield',
            'category' => 'Security',
            'default' => true,
        ],
        'share_links' => [
            'name' => 'Share Links',
            'description' => 'Create public share links with optional password/expiry',
            'icon' => 'link',
            'category' => 'Sharing',
            'default' => true,
        ],
        'model_ratings' => [
            'name' => 'Model Ratings',
            'description' => 'Allow users to rate and review models',
            'icon' => 'star',
            'category' => 'Community',
            'default' => true,
        ],
        'favorites' => [
            'name' => 'Favorites',
            'description' => 'Allow users to bookmark/favorite models',
            'icon' => 'heart',
            'category' => 'Community',
            'default' => true,
        ],
        'activity_log' => [
            'name' => 'Activity Log',
            'description' => 'Track user actions for audit purposes',
            'icon' => 'list',
            'category' => 'Compliance',
            'default' => true,
        ],
        'attachments' => [
            'name' => 'Model Attachments',
            'description' => 'Attach images and PDFs to models with auto WebP conversion',
            'icon' => 'image',
            'category' => 'Content',
            'default' => true,
        ],
        'tags' => [
            'name' => 'Tags',
            'description' => 'Organize models with color-coded tags',
            'icon' => 'tag',
            'category' => 'Organization',
            'default' => true,
        ],
        'collections' => [
            'name' => 'Collections',
            'description' => 'Group models into themed collections',
            'icon' => 'folder',
            'category' => 'Organization',
            'default' => true,
        ],
        'categories' => [
            'name' => 'Categories',
            'description' => 'Hierarchical category organization for models',
            'icon' => 'layers',
            'category' => 'Organization',
            'default' => true,
        ],
        'version_history' => [
            'name' => 'Version History',
            'description' => 'Track and restore previous versions of models',
            'icon' => 'git',
            'category' => 'Content',
            'default' => true,
        ],
        'external_links' => [
            'name' => 'External Links',
            'description' => 'Add documentation, video, and source links to models',
            'icon' => 'external',
            'category' => 'Content',
            'default' => true,
        ],
        'download_tracking' => [
            'name' => 'Download Tracking',
            'description' => 'Count and display model download statistics',
            'icon' => 'download',
            'category' => 'Analytics',
            'default' => true,
        ],
        'recently_viewed' => [
            'name' => 'Recently Viewed',
            'description' => 'Track and display recently viewed models',
            'icon' => 'eye',
            'category' => 'Analytics',
            'default' => true,
        ],
        'duplicate_detection' => [
            'name' => 'Duplicate Detection',
            'description' => 'Detect and manage duplicate files during upload',
            'icon' => 'copy',
            'category' => 'Storage',
            'default' => true,
        ],
        'mesh_analysis' => [
            'name' => 'Mesh Analysis',
            'description' => 'Analyze STL files for printability issues and repair',
            'icon' => 'cube',
            'category' => 'Analysis',
            'default' => true,
        ],
        'dark_theme' => [
            'name' => 'Dark Theme',
            'description' => 'Allow users to switch between light and dark themes',
            'icon' => 'moon',
            'category' => 'UI',
            'default' => true,
        ],
        'model_notes' => [
            'name' => 'Model Notes',
            'description' => 'Add notes to individual model parts',
            'icon' => 'edit',
            'category' => 'Content',
            'default' => true,
        ],
        'slicer_integration' => [
            'name' => 'Slicer Integration',
            'description' => 'Open models directly in configured slicer applications',
            'icon' => 'sliders',
            'category' => 'Integration',
            'default' => true,
        ],
        'sso' => [
            'name' => 'Single Sign-On',
            'description' => 'External authentication via OIDC, SAML, LDAP/AD, and SCIM user provisioning',
            'icon' => 'unlock',
            'category' => 'Authentication',
            'default' => true,
        ],
        'local_accounts' => [
            'name' => 'Local Accounts',
            'description' => 'Allow users to register and login with local username/password',
            'icon' => 'user',
            'category' => 'Authentication',
            'default' => true,
        ],
    ];
}

/**
 * Feature state cache (per-request)
 */
$_featureStateCache = null;

/**
 * Load all feature states into cache
 */
function loadFeatureStates(): array {
    global $_featureStateCache;

    if ($_featureStateCache !== null) {
        return $_featureStateCache;
    }

    $features = getAvailableFeatures();
    $_featureStateCache = [];

    foreach ($features as $key => $meta) {
        $default = $meta['default'] ? '1' : '0';
        $_featureStateCache[$key] = getSetting('feature_' . $key, $default) === '1';
    }

    return $_featureStateCache;
}

/**
 * Clear the feature state cache (call after enabling/disabling)
 */
function clearFeatureStateCache(): void {
    global $_featureStateCache;
    $_featureStateCache = null;
}

/**
 * Check if a feature is enabled
 */
function isFeatureEnabled(string $feature): bool {
    $features = getAvailableFeatures();

    // Unknown feature = enabled (don't break things)
    if (!isset($features[$feature])) {
        return true;
    }

    // Use cached state
    $states = loadFeatureStates();
    return $states[$feature] ?? true;
}

/**
 * Enable a feature
 */
function enableFeature(string $feature): bool {
    $features = getAvailableFeatures();
    if (!isset($features[$feature])) {
        return false;
    }
    setSetting('feature_' . $feature, '1');
    clearFeatureStateCache();
    return true;
}

/**
 * Disable a feature
 */
function disableFeature(string $feature): bool {
    $features = getAvailableFeatures();
    if (!isset($features[$feature])) {
        return false;
    }
    setSetting('feature_' . $feature, '0');
    clearFeatureStateCache();
    return true;
}

/**
 * Get all enabled features
 */
function getEnabledFeatures(): array {
    $features = getAvailableFeatures();
    $enabled = [];

    foreach ($features as $key => $meta) {
        if (isFeatureEnabled($key)) {
            $enabled[$key] = $meta;
        }
    }

    return $enabled;
}

/**
 * Get features grouped by category
 */
function getFeaturesByCategory(): array {
    $features = getAvailableFeatures();
    $grouped = [];

    foreach ($features as $key => $meta) {
        $category = $meta['category'] ?? 'Other';
        if (!isset($grouped[$category])) {
            $grouped[$category] = [];
        }
        $meta['key'] = $key;
        $meta['enabled'] = isFeatureEnabled($key);
        $grouped[$category][] = $meta;
    }

    // Sort categories
    ksort($grouped);

    return $grouped;
}

/**
 * Require a feature to be enabled (redirect if not)
 */
function requireFeature(string $feature): void {
    if (!isFeatureEnabled($feature)) {
        // Redirect to home with message
        $_SESSION['flash_error'] = 'This feature is not enabled.';
        header('Location: ' . route('home'));
        exit;
    }
}

/**
 * Get feature presets
 * Returns predefined feature configurations for different use cases
 */
function getFeaturePresets(): array {
    return [
        'minimal' => [
            'name' => 'Minimal',
            'description' => 'Core features only - uploading, browsing, and basic organization',
            'features' => [
                'teams' => false,
                'api_keys' => false,
                'webhooks' => false,
                'retention_policies' => false,
                'scheduled_reports' => false,
                'print_queue' => false,
                'printers' => false,
                'print_history' => false,
                'model_analysis' => false,
                'import_jobs' => false,
                'two_factor_auth' => false,
                'share_links' => true,
                'model_ratings' => false,
                'favorites' => true,
                'activity_log' => false,
                'attachments' => false,
                'tags' => true,
                'collections' => false,
                'categories' => true,
                'version_history' => false,
                'external_links' => false,
                'download_tracking' => false,
                'recently_viewed' => false,
                'duplicate_detection' => false,
                'mesh_analysis' => false,
                'dark_theme' => true,
                'model_notes' => false,
                'slicer_integration' => false,
                'sso' => false,
                'local_accounts' => true,
            ],
        ],
        'standard' => [
            'name' => 'Standard',
            'description' => 'Recommended for most users - includes collaboration and printing features',
            'features' => [
                'teams' => true,
                'api_keys' => false,
                'webhooks' => false,
                'retention_policies' => false,
                'scheduled_reports' => false,
                'print_queue' => true,
                'printers' => true,
                'print_history' => true,
                'model_analysis' => false,
                'import_jobs' => false,
                'two_factor_auth' => true,
                'share_links' => true,
                'model_ratings' => true,
                'favorites' => true,
                'activity_log' => true,
                'attachments' => true,
                'tags' => true,
                'collections' => true,
                'categories' => true,
                'version_history' => false,
                'external_links' => true,
                'download_tracking' => true,
                'recently_viewed' => true,
                'duplicate_detection' => true,
                'mesh_analysis' => true,
                'dark_theme' => true,
                'model_notes' => true,
                'slicer_integration' => true,
                'sso' => true,
                'local_accounts' => true,
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'All features enabled - full integration, compliance, and analytics',
            'features' => [
                'teams' => true,
                'api_keys' => true,
                'webhooks' => true,
                'retention_policies' => true,
                'scheduled_reports' => true,
                'print_queue' => true,
                'printers' => true,
                'print_history' => true,
                'model_analysis' => true,
                'import_jobs' => true,
                'two_factor_auth' => true,
                'share_links' => true,
                'model_ratings' => true,
                'favorites' => true,
                'activity_log' => true,
                'attachments' => true,
                'tags' => true,
                'collections' => true,
                'categories' => true,
                'version_history' => true,
                'external_links' => true,
                'download_tracking' => true,
                'recently_viewed' => true,
                'duplicate_detection' => true,
                'mesh_analysis' => true,
                'dark_theme' => true,
                'model_notes' => true,
                'slicer_integration' => true,
                'sso' => true,
                'local_accounts' => true,
            ],
        ],
    ];
}

/**
 * Apply a feature preset
 */
function applyFeaturePreset(string $presetName): bool {
    $presets = getFeaturePresets();
    if (!isset($presets[$presetName])) {
        return false;
    }

    $preset = $presets[$presetName];
    foreach ($preset['features'] as $feature => $enabled) {
        if ($enabled) {
            enableFeature($feature);
        } else {
            disableFeature($feature);
        }
    }

    return true;
}

/**
 * Get usage statistics for features
 * Returns counts of data associated with each feature
 */
function getFeatureUsageStats(): array {
    $db = getDB();
    $stats = [];

    // API Keys count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM api_keys");
        $stats['api_keys'] = (int)$result;
    } catch (Exception $e) {
        $stats['api_keys'] = 0;
    }

    // Webhooks count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM webhooks");
        $stats['webhooks'] = (int)$result;
    } catch (Exception $e) {
        $stats['webhooks'] = 0;
    }

    // Teams count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM teams");
        $stats['teams'] = (int)$result;
    } catch (Exception $e) {
        $stats['teams'] = 0;
    }

    // Favorites count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM favorites");
        $stats['favorites'] = (int)$result;
    } catch (Exception $e) {
        $stats['favorites'] = 0;
    }

    // Model ratings count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM model_ratings");
        $stats['model_ratings'] = (int)$result;
    } catch (Exception $e) {
        $stats['model_ratings'] = 0;
    }

    // Share links count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM share_links");
        $stats['share_links'] = (int)$result;
    } catch (Exception $e) {
        $stats['share_links'] = 0;
    }

    // Print queue count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM print_queue");
        $stats['print_queue'] = (int)$result;
    } catch (Exception $e) {
        $stats['print_queue'] = 0;
    }

    // Print history count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM print_history");
        $stats['print_history'] = (int)$result;
    } catch (Exception $e) {
        $stats['print_history'] = 0;
    }

    // Printers count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM printers");
        $stats['printers'] = (int)$result;
    } catch (Exception $e) {
        $stats['printers'] = 0;
    }

    // Activity log count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM activity_log");
        $stats['activity_log'] = (int)$result;
    } catch (Exception $e) {
        $stats['activity_log'] = 0;
    }

    // 2FA users count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM users WHERE two_factor_secret IS NOT NULL");
        $stats['two_factor_auth'] = (int)$result;
    } catch (Exception $e) {
        $stats['two_factor_auth'] = 0;
    }

    // Attachments count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM model_attachments");
        $stats['attachments'] = (int)$result;
    } catch (Exception $e) {
        $stats['attachments'] = 0;
    }

    // Tags count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM tags");
        $stats['tags'] = (int)$result;
    } catch (Exception $e) {
        $stats['tags'] = 0;
    }

    // Collections count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM collections");
        $stats['collections'] = (int)$result;
    } catch (Exception $e) {
        $stats['collections'] = 0;
    }

    // Categories count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM categories");
        $stats['categories'] = (int)$result;
    } catch (Exception $e) {
        $stats['categories'] = 0;
    }

    // Recently viewed count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM recently_viewed");
        $stats['recently_viewed'] = (int)$result;
    } catch (Exception $e) {
        $stats['recently_viewed'] = 0;
    }

    // Model links (external links) count
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM model_links");
        $stats['external_links'] = (int)$result;
    } catch (Exception $e) {
        $stats['external_links'] = 0;
    }

    // SSO users count (OIDC, SAML, or LDAP)
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM users WHERE oidc_sub IS NOT NULL OR saml_name_id IS NOT NULL OR ldap_dn IS NOT NULL");
        $stats['sso'] = (int)$result;
    } catch (Exception $e) {
        $stats['sso'] = 0;
    }

    // Local accounts count (users without external auth)
    try {
        $result = $db->querySingle("SELECT COUNT(*) FROM users WHERE oidc_sub IS NULL AND saml_name_id IS NULL AND ldap_dn IS NULL");
        $stats['local_accounts'] = (int)$result;
    } catch (Exception $e) {
        $stats['local_accounts'] = 0;
    }

    return $stats;
}

/**
 * Get icon HTML for a feature icon name
 */
function getFeatureIcon(string $icon): string {
    $icons = [
        'users' => '&#128101;',       // Team
        'key' => '&#128273;',         // Key
        'webhook' => '&#128268;',     // Link
        'archive' => '&#128451;',     // Archive box
        'file-text' => '&#128196;',   // Document
        'printer' => '&#128424;',     // Printer
        'settings' => '&#9881;',      // Gear
        'history' => '&#128337;',     // Clock
        'filter' => '&#128269;',      // Filter/magnifier
        'activity' => '&#128200;',    // Chart
        'download' => '&#11015;',     // Download arrow
        'shield' => '&#128737;',      // Shield
        'link' => '&#128279;',        // Link
        'star' => '&#11088;',         // Star
        'heart' => '&#9829;',         // Heart
        'list' => '&#128203;',        // Clipboard
        'image' => '&#128444;',       // Image
        'tag' => '&#127991;',         // Tag/label
        'folder' => '&#128193;',      // Folder
        'layers' => '&#9776;',        // Layers/hamburger
        'git' => '&#128260;',         // Git branch
        'external' => '&#128279;',    // External link
        'eye' => '&#128065;',         // Eye
        'copy' => '&#128203;',        // Copy/clipboard
        'cube' => '&#9645;',          // Cube
        'moon' => '&#127769;',        // Moon
        'edit' => '&#9998;',          // Pencil
        'sliders' => '&#9881;',       // Sliders/gear
        'unlock' => '&#128275;',      // Unlock/SSO
        'server' => '&#128421;',      // Server/LDAP
        'user' => '&#128100;',        // User/local account
    ];

    return $icons[$icon] ?? '&#9881;';
}
