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
function getFeatureDependencies(): array
{
    return [];
}

/**
 * Get features that depend on a given feature
 */
function getDependentFeatures(string $feature): array
{
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
function areFeatureDependenciesMet(string $feature): bool
{
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
function getMissingDependencies(string $feature): array
{
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

/**
 * Available features cache (per-request)
 */
$_availableFeaturesCache = null;

// Define available features with metadata
function getAvailableFeatures(): array
{
    global $_availableFeaturesCache;
    if ($_availableFeaturesCache !== null) {
        return $_availableFeaturesCache;
    }

    $features = [
        'api_keys' => [
            'name' => 'API Keys',
            'description' => 'Programmatic access via REST API with key authentication',
            'icon' => 'key',
            'category' => 'Integration',
            'default' => true,
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
        'local_accounts' => [
            'name' => 'Local Accounts',
            'description' => 'Allow users to register and login with local username/password',
            'icon' => 'user',
            'category' => 'Authentication',
            'default' => true,
        ],
    ];

    if (class_exists('PluginManager')) {
        $features = PluginManager::applyFilter('available_features', $features);
    }
    $_availableFeaturesCache = $features;
    return $_availableFeaturesCache;
}

/**
 * Feature state cache (per-request)
 */
$_featureStateCache = null;

/**
 * Load all feature states into cache
 */
function loadFeatureStates(): array
{
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
function clearFeatureStateCache(): void
{
    global $_featureStateCache, $_availableFeaturesCache;
    $_featureStateCache = null;
    $_availableFeaturesCache = null;
}

/**
 * Check if a feature is enabled
 */
function isFeatureEnabled(string $feature): bool
{
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
function enableFeature(string $feature): bool
{
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
function disableFeature(string $feature): bool
{
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
function getEnabledFeatures(): array
{
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
function getFeaturesByCategory(): array
{
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
function requireFeature(string $feature): void
{
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
function getFeaturePresets(): array
{
    return [
        'minimal' => [
            'name' => 'Minimal',
            'description' => 'Core features only - uploading, browsing, and basic organization',
            'features' => [
                'api_keys' => false,
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
                'dark_theme' => true,
                'model_notes' => false,
                'local_accounts' => true,
            ],
        ],
        'standard' => [
            'name' => 'Standard',
            'description' => 'Recommended for most users - includes collaboration and printing features',
            'features' => [
                'api_keys' => false,
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
                'dark_theme' => true,
                'model_notes' => true,
                'local_accounts' => true,
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'All features enabled - full integration, compliance, and analytics',
            'features' => [
                'api_keys' => true,
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
                'dark_theme' => true,
                'model_notes' => true,
                'local_accounts' => true,
            ],
        ],
    ];
}

/**
 * Apply a feature preset
 */
function applyFeaturePreset(string $presetName): bool
{
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
function getFeatureUsageStats(): array
{
    $db = getDB();

    // Map feature keys to their table queries
    $tableQueries = [
        'api_keys' => 'api_keys',
        'favorites' => 'favorites',
        'model_ratings' => 'model_ratings',
        'share_links' => 'share_links',
        'activity_log' => 'activity_log',
        'attachments' => 'model_attachments',
        'tags' => 'tags',
        'collections' => 'collections',
        'categories' => 'categories',
        'recently_viewed' => 'recently_viewed',
        'external_links' => 'model_links',
    ];

    // Initialize all stats to 0
    $stats = array_fill_keys(array_keys($tableQueries), 0);
    $stats['two_factor_auth'] = 0;

    // Build a single UNION ALL query for all table counts
    $unionParts = [];
    foreach ($tableQueries as $key => $table) {
        if (tableExists($db, $table)) {
            $unionParts[] = "SELECT '$key' as feature, COUNT(*) as cnt FROM $table";
        }
    }

    // 2FA uses a WHERE clause, add separately
    if (tableExists($db, 'users') && columnExists($db, 'users', 'two_factor_secret')) {
        $unionParts[] = "SELECT 'two_factor_auth' as feature, COUNT(*) as cnt FROM users WHERE two_factor_secret IS NOT NULL";
    }

    if (!empty($unionParts)) {
        try {
            $sql = implode(' UNION ALL ', $unionParts);
            $result = $db->query($sql);
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $stats[$row['feature']] = (int)$row['cnt'];
            }
        } catch (Exception $e) {
            // Individual table might not exist yet; stats stay at 0
        }
    }

    return $stats;
}

/**
 * Get icon HTML for a feature icon name
 */
function getFeatureIcon(string $icon): string
{
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
