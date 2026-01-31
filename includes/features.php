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
        // Smart collections uses activity patterns
        'smart_collections' => [],
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
        'smart_collections' => [
            'name' => 'Smart Collections',
            'description' => 'Auto-updating collections based on filter rules',
            'icon' => 'filter',
            'category' => 'Organization',
            'default' => false,
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
    ];
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

    // Get from settings, fall back to default
    $default = $features[$feature]['default'] ? '1' : '0';
    return getSetting('feature_' . $feature, $default) === '1';
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
 * Get icon HTML for a feature icon name
 */
function getFeatureIcon(string $icon): string {
    $icons = [
        'users' => '&#128101;',      // Team
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
    ];

    return $icons[$icon] ?? '&#9881;';
}
