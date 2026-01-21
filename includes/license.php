<?php
/**
 * Silo License Management System
 *
 * Handles license validation, feature gating, and tier management.
 * Uses RSA signatures for offline license validation.
 */

// License tiers
define('LICENSE_TIER_FREE', 'free');
define('LICENSE_TIER_PRO', 'pro');
define('LICENSE_TIER_BUSINESS', 'business');

// Feature flags - these map to specific features that can be gated
define('FEATURE_MULTI_USER', 'multi_user');
define('FEATURE_TAGS', 'tags');
define('FEATURE_FAVORITES', 'favorites');
define('FEATURE_BATCH_OPERATIONS', 'batch_operations');
define('FEATURE_PRINT_QUEUE', 'print_queue');
define('FEATURE_THEMES', 'themes');
define('FEATURE_KEYBOARD_SHORTCUTS', 'keyboard_shortcuts');
define('FEATURE_GCODE_PREVIEW', 'gcode_preview');
define('FEATURE_DIMENSIONS', 'dimensions');
define('FEATURE_VERSION_HISTORY', 'version_history');
define('FEATURE_RELATED_MODELS', 'related_models');
define('FEATURE_ACTIVITY_LOG', 'activity_log');
define('FEATURE_STORAGE_ANALYTICS', 'storage_analytics');
define('FEATURE_DUPLICATE_DETECTION', 'duplicate_detection');
define('FEATURE_API_ACCESS', 'api_access');
define('FEATURE_SSO_OIDC', 'sso_oidc');
define('FEATURE_WEBHOOKS', 'webhooks');
define('FEATURE_CUSTOM_BRANDING', 'custom_branding');
define('FEATURE_PRIORITY_SUPPORT', 'priority_support');
define('FEATURE_BULK_UPLOAD', 'bulk_upload');
define('FEATURE_S3_STORAGE', 's3_storage');
define('FEATURE_UNLIMITED_STORAGE', 'unlimited_storage');

/**
 * Feature definitions by tier
 * Free tier gets basic functionality
 * Pro tier gets power user features
 * Business tier gets everything including enterprise features
 */
function getFeaturesByTier() {
    return [
        LICENSE_TIER_FREE => [
            // Basic features included in free tier
            'max_users' => 1,
            'max_models' => 100,
            'max_storage_gb' => 5,
            'features' => [
                // Core functionality only
            ]
        ],
        LICENSE_TIER_PRO => [
            'max_users' => 5,
            'max_models' => -1, // unlimited
            'max_storage_gb' => 100,
            'features' => [
                FEATURE_MULTI_USER,
                FEATURE_TAGS,
                FEATURE_FAVORITES,
                FEATURE_BATCH_OPERATIONS,
                FEATURE_PRINT_QUEUE,
                FEATURE_THEMES,
                FEATURE_KEYBOARD_SHORTCUTS,
                FEATURE_GCODE_PREVIEW,
                FEATURE_DIMENSIONS,
                FEATURE_VERSION_HISTORY,
                FEATURE_RELATED_MODELS,
                FEATURE_ACTIVITY_LOG,
                FEATURE_STORAGE_ANALYTICS,
                FEATURE_DUPLICATE_DETECTION,
                FEATURE_BULK_UPLOAD,
            ]
        ],
        LICENSE_TIER_BUSINESS => [
            'max_users' => -1, // unlimited
            'max_models' => -1,
            'max_storage_gb' => -1,
            'features' => [
                // All Pro features plus:
                FEATURE_MULTI_USER,
                FEATURE_TAGS,
                FEATURE_FAVORITES,
                FEATURE_BATCH_OPERATIONS,
                FEATURE_PRINT_QUEUE,
                FEATURE_THEMES,
                FEATURE_KEYBOARD_SHORTCUTS,
                FEATURE_GCODE_PREVIEW,
                FEATURE_DIMENSIONS,
                FEATURE_VERSION_HISTORY,
                FEATURE_RELATED_MODELS,
                FEATURE_ACTIVITY_LOG,
                FEATURE_STORAGE_ANALYTICS,
                FEATURE_DUPLICATE_DETECTION,
                FEATURE_BULK_UPLOAD,
                FEATURE_API_ACCESS,
                FEATURE_SSO_OIDC,
                FEATURE_WEBHOOKS,
                FEATURE_CUSTOM_BRANDING,
                FEATURE_PRIORITY_SUPPORT,
                FEATURE_S3_STORAGE,
                FEATURE_UNLIMITED_STORAGE,
            ]
        ]
    ];
}

/**
 * Human-readable feature names and descriptions
 */
function getFeatureInfo() {
    return [
        FEATURE_MULTI_USER => [
            'name' => 'Multi-User Support',
            'description' => 'Allow multiple users to access and manage the library',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_TAGS => [
            'name' => 'Tags & Organization',
            'description' => 'Tag models for better organization and searchability',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_FAVORITES => [
            'name' => 'Favorites',
            'description' => 'Bookmark your favorite models for quick access',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_BATCH_OPERATIONS => [
            'name' => 'Batch Operations',
            'description' => 'Apply tags, download, or modify multiple models at once',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_PRINT_QUEUE => [
            'name' => 'Print Queue',
            'description' => 'Queue models for printing with priority management',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_THEMES => [
            'name' => 'Themes & Dark Mode',
            'description' => 'Switch between light and dark themes',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_KEYBOARD_SHORTCUTS => [
            'name' => 'Keyboard Shortcuts',
            'description' => 'Navigate quickly with keyboard shortcuts',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_GCODE_PREVIEW => [
            'name' => 'GCODE Preview',
            'description' => 'Visualize GCODE toolpaths and print metadata',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_DIMENSIONS => [
            'name' => 'Model Dimensions',
            'description' => 'Calculate and display model bounding box dimensions',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_VERSION_HISTORY => [
            'name' => 'Version History',
            'description' => 'Track model versions and access previous uploads',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_RELATED_MODELS => [
            'name' => 'Related Models',
            'description' => 'Link related models together',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_ACTIVITY_LOG => [
            'name' => 'Activity Log',
            'description' => 'Track all actions and changes in your library',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_STORAGE_ANALYTICS => [
            'name' => 'Storage Analytics',
            'description' => 'Detailed storage usage breakdown and deduplication stats',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_DUPLICATE_DETECTION => [
            'name' => 'Duplicate Detection',
            'description' => 'Automatically detect duplicate uploads',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_BULK_UPLOAD => [
            'name' => 'Bulk Upload',
            'description' => 'Upload multiple models at once via ZIP',
            'tier' => LICENSE_TIER_PRO
        ],
        FEATURE_API_ACCESS => [
            'name' => 'REST API Access',
            'description' => 'Programmatic access via REST API',
            'tier' => LICENSE_TIER_BUSINESS
        ],
        FEATURE_SSO_OIDC => [
            'name' => 'SSO / OIDC Integration',
            'description' => 'Single sign-on with your identity provider',
            'tier' => LICENSE_TIER_BUSINESS
        ],
        FEATURE_WEBHOOKS => [
            'name' => 'Webhooks',
            'description' => 'Trigger external services on events',
            'tier' => LICENSE_TIER_BUSINESS
        ],
        FEATURE_CUSTOM_BRANDING => [
            'name' => 'Custom Branding',
            'description' => 'Customize logo, colors, and branding',
            'tier' => LICENSE_TIER_BUSINESS
        ],
        FEATURE_PRIORITY_SUPPORT => [
            'name' => 'Priority Support',
            'description' => 'Get priority email and chat support',
            'tier' => LICENSE_TIER_BUSINESS
        ],
        FEATURE_S3_STORAGE => [
            'name' => 'S3 / Object Storage',
            'description' => 'Store files in S3-compatible object storage',
            'tier' => LICENSE_TIER_BUSINESS
        ],
        FEATURE_UNLIMITED_STORAGE => [
            'name' => 'Unlimited Storage',
            'description' => 'No storage limits',
            'tier' => LICENSE_TIER_BUSINESS
        ],
    ];
}

/**
 * Public key for license verification
 * This key is used to verify license signatures
 * The private key is kept secure on the license server
 */
function getLicensePublicKey() {
    // This is a placeholder - generate your own RSA key pair
    // openssl genrsa -out private.pem 2048
    // openssl rsa -in private.pem -pubout -out public.pem
    return getSetting('license_public_key', '');
}

/**
 * Validate a license key
 *
 * License format: base64(json_payload).base64(signature)
 * Payload contains: license_id, email, tier, features, expires_at, max_users, issued_at
 *
 * @param string $licenseKey The license key to validate
 * @return array Validation result with 'valid', 'license', 'error' keys
 */
function validateLicense($licenseKey = null) {
    // Get stored license if not provided
    if ($licenseKey === null) {
        $licenseKey = getSetting('license_key', '');
    }

    // No license = free tier
    if (empty($licenseKey)) {
        return [
            'valid' => true,
            'license' => getFreeLicense(),
            'tier' => LICENSE_TIER_FREE,
            'error' => null
        ];
    }

    // Parse license key
    $parts = explode('.', $licenseKey);
    if (count($parts) !== 2) {
        return [
            'valid' => false,
            'license' => getFreeLicense(),
            'tier' => LICENSE_TIER_FREE,
            'error' => 'Invalid license format'
        ];
    }

    $payloadBase64 = $parts[0];
    $signatureBase64 = $parts[1];

    // Decode payload
    $payloadJson = base64_decode($payloadBase64);
    if ($payloadJson === false) {
        return [
            'valid' => false,
            'license' => getFreeLicense(),
            'tier' => LICENSE_TIER_FREE,
            'error' => 'Invalid license encoding'
        ];
    }

    $payload = json_decode($payloadJson, true);
    if ($payload === null) {
        return [
            'valid' => false,
            'license' => getFreeLicense(),
            'tier' => LICENSE_TIER_FREE,
            'error' => 'Invalid license data'
        ];
    }

    // Verify signature if public key is configured
    $publicKey = getLicensePublicKey();
    if (!empty($publicKey)) {
        $signature = base64_decode($signatureBase64);
        $keyResource = openssl_pkey_get_public($publicKey);

        if ($keyResource === false) {
            return [
                'valid' => false,
                'license' => getFreeLicense(),
                'tier' => LICENSE_TIER_FREE,
                'error' => 'Invalid public key configuration'
            ];
        }

        $verified = openssl_verify($payloadJson, $signature, $keyResource, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            return [
                'valid' => false,
                'license' => getFreeLicense(),
                'tier' => LICENSE_TIER_FREE,
                'error' => 'Invalid license signature'
            ];
        }
    }

    // Check expiration
    if (isset($payload['expires_at']) && $payload['expires_at'] !== null) {
        $expiresAt = strtotime($payload['expires_at']);
        if ($expiresAt !== false && $expiresAt < time()) {
            return [
                'valid' => false,
                'license' => getFreeLicense(),
                'tier' => LICENSE_TIER_FREE,
                'error' => 'License has expired',
                'expired_license' => $payload
            ];
        }
    }

    // License is valid
    return [
        'valid' => true,
        'license' => $payload,
        'tier' => $payload['tier'] ?? LICENSE_TIER_FREE,
        'error' => null
    ];
}

/**
 * Get a default free license structure
 */
function getFreeLicense() {
    $tiers = getFeaturesByTier();
    $freeTier = $tiers[LICENSE_TIER_FREE];

    return [
        'license_id' => 'free',
        'tier' => LICENSE_TIER_FREE,
        'email' => null,
        'max_users' => $freeTier['max_users'],
        'max_models' => $freeTier['max_models'],
        'max_storage_gb' => $freeTier['max_storage_gb'],
        'features' => $freeTier['features'],
        'expires_at' => null,
        'issued_at' => null
    ];
}

/**
 * Get current license info (cached)
 */
function getCurrentLicense() {
    static $license = null;

    if ($license === null) {
        $result = validateLicense();
        $license = $result;
    }

    return $license;
}

/**
 * Get current license tier
 */
function getLicenseTier() {
    $license = getCurrentLicense();
    return $license['tier'] ?? LICENSE_TIER_FREE;
}

/**
 * Check if a specific feature is available
 *
 * @param string $feature Feature constant (e.g., FEATURE_TAGS)
 * @return bool Whether the feature is available
 */
function hasFeature($feature) {
    $license = getCurrentLicense();
    $tier = $license['tier'] ?? LICENSE_TIER_FREE;

    // Check if feature is explicitly granted in license
    if (isset($license['license']['features']) && is_array($license['license']['features'])) {
        if (in_array($feature, $license['license']['features'])) {
            return true;
        }
    }

    // Check tier-based features
    $tiers = getFeaturesByTier();
    if (isset($tiers[$tier]['features'])) {
        return in_array($feature, $tiers[$tier]['features']);
    }

    return false;
}

/**
 * Check if user limit has been reached
 */
function canAddUser() {
    $license = getCurrentLicense();
    $maxUsers = $license['license']['max_users'] ?? 1;

    // -1 means unlimited
    if ($maxUsers === -1) {
        return true;
    }

    $db = getDB();
    $result = $db->query('SELECT COUNT(*) as count FROM users');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $currentUsers = $row['count'] ?? 0;

    return $currentUsers < $maxUsers;
}

/**
 * Check if model limit has been reached
 */
function canAddModel() {
    $license = getCurrentLicense();
    $maxModels = $license['license']['max_models'] ?? 100;

    // -1 means unlimited
    if ($maxModels === -1) {
        return true;
    }

    $db = getDB();
    $result = $db->query('SELECT COUNT(*) as count FROM models WHERE parent_id IS NULL');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $currentModels = $row['count'] ?? 0;

    return $currentModels < $maxModels;
}

/**
 * Check if storage limit has been reached
 *
 * @param int $additionalBytes Additional bytes to check (for uploads)
 */
function canUseStorage($additionalBytes = 0) {
    $license = getCurrentLicense();
    $maxStorageGb = $license['license']['max_storage_gb'] ?? 5;

    // -1 means unlimited
    if ($maxStorageGb === -1) {
        return true;
    }

    $maxStorageBytes = $maxStorageGb * 1024 * 1024 * 1024;

    $db = getDB();
    $result = $db->query('SELECT SUM(file_size) as total FROM models');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $currentStorage = $row['total'] ?? 0;

    return ($currentStorage + $additionalBytes) <= $maxStorageBytes;
}

/**
 * Get usage statistics for license limits
 */
function getLicenseUsage() {
    $license = getCurrentLicense();
    $db = getDB();

    // User count
    $result = $db->query('SELECT COUNT(*) as count FROM users');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $userCount = $row['count'] ?? 0;

    // Model count
    $result = $db->query('SELECT COUNT(*) as count FROM models WHERE parent_id IS NULL');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $modelCount = $row['count'] ?? 0;

    // Storage used
    $result = $db->query('SELECT SUM(file_size) as total FROM models');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $storageUsed = $row['total'] ?? 0;

    $maxUsers = $license['license']['max_users'] ?? 1;
    $maxModels = $license['license']['max_models'] ?? 100;
    $maxStorageGb = $license['license']['max_storage_gb'] ?? 5;

    return [
        'users' => [
            'current' => $userCount,
            'max' => $maxUsers,
            'unlimited' => $maxUsers === -1,
            'percentage' => $maxUsers === -1 ? 0 : round(($userCount / $maxUsers) * 100)
        ],
        'models' => [
            'current' => $modelCount,
            'max' => $maxModels,
            'unlimited' => $maxModels === -1,
            'percentage' => $maxModels === -1 ? 0 : round(($modelCount / $maxModels) * 100)
        ],
        'storage' => [
            'current' => $storageUsed,
            'current_formatted' => formatFileSize($storageUsed),
            'max' => $maxStorageGb === -1 ? -1 : $maxStorageGb * 1024 * 1024 * 1024,
            'max_formatted' => $maxStorageGb === -1 ? 'Unlimited' : $maxStorageGb . ' GB',
            'unlimited' => $maxStorageGb === -1,
            'percentage' => $maxStorageGb === -1 ? 0 : round(($storageUsed / ($maxStorageGb * 1024 * 1024 * 1024)) * 100)
        ]
    ];
}

/**
 * Get tier display name
 */
function getTierName($tier) {
    $names = [
        LICENSE_TIER_FREE => 'Community',
        LICENSE_TIER_PRO => 'Pro',
        LICENSE_TIER_BUSINESS => 'Business'
    ];
    return $names[$tier] ?? 'Unknown';
}

/**
 * Get tier badge color
 */
function getTierColor($tier) {
    $colors = [
        LICENSE_TIER_FREE => '#6b7280',     // gray
        LICENSE_TIER_PRO => '#3b82f6',      // blue
        LICENSE_TIER_BUSINESS => '#8b5cf6'  // purple
    ];
    return $colors[$tier] ?? '#6b7280';
}

/**
 * Get minimum tier required for a feature
 */
function getFeatureTier($feature) {
    $info = getFeatureInfo();
    return $info[$feature]['tier'] ?? LICENSE_TIER_BUSINESS;
}

/**
 * Check if current tier can access a feature and return upgrade info if not
 */
function checkFeatureAccess($feature) {
    if (hasFeature($feature)) {
        return ['allowed' => true];
    }

    $info = getFeatureInfo();
    $featureInfo = $info[$feature] ?? null;

    return [
        'allowed' => false,
        'feature' => $feature,
        'feature_name' => $featureInfo['name'] ?? $feature,
        'required_tier' => $featureInfo['tier'] ?? LICENSE_TIER_PRO,
        'required_tier_name' => getTierName($featureInfo['tier'] ?? LICENSE_TIER_PRO),
        'current_tier' => getLicenseTier(),
        'current_tier_name' => getTierName(getLicenseTier())
    ];
}

/**
 * Require a feature, showing upgrade prompt if not available
 * Use this in page controllers to gate features
 */
function requireFeature($feature) {
    $access = checkFeatureAccess($feature);

    if (!$access['allowed']) {
        // Store upgrade info for display
        $_SESSION['upgrade_prompt'] = $access;
        return false;
    }

    return true;
}

/**
 * Save license key to settings
 */
function saveLicenseKey($licenseKey) {
    $db = getDB();

    // Validate first
    $result = validateLicense($licenseKey);

    if (!$result['valid']) {
        return [
            'success' => false,
            'error' => $result['error']
        ];
    }

    // Save to settings
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('license_key', :value)");
    $stmt->bindValue(':value', $licenseKey, SQLITE3_TEXT);
    $stmt->execute();

    // Log the activation
    if (function_exists('logActivity') && isLoggedIn()) {
        $user = getCurrentUser();
        logActivity($user['id'], 'license_activated', 'system', null, getTierName($result['tier']));
    }

    return [
        'success' => true,
        'license' => $result['license'],
        'tier' => $result['tier']
    ];
}

/**
 * Remove license key (revert to free)
 */
function removeLicenseKey() {
    $db = getDB();
    $db->exec("DELETE FROM settings WHERE key = 'license_key'");

    if (function_exists('logActivity') && isLoggedIn()) {
        $user = getCurrentUser();
        logActivity($user['id'], 'license_removed', 'system', null, null);
    }

    return true;
}

/**
 * Format file size helper (if not already defined)
 */
if (!function_exists('formatFileSize')) {
    function formatFileSize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}
