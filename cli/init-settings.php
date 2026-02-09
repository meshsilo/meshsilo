#!/usr/bin/env php
<?php
/**
 * Initialize Database Settings from Environment Variables
 *
 * This script is designed to be run by Docker or other container orchestration
 * tools to initialize database settings from environment variables.
 *
 * All settings are stored in the database, not in config files.
 * This ensures settings can be managed via admin panel and can't be overwritten.
 *
 * Usage: php cli/init-settings.php
 */

// Only run from CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Check if config exists
$configPaths = [
    __DIR__ . '/../storage/db/config.local.php',
    __DIR__ . '/../db/config.local.php',
    __DIR__ . '/../config.local.php'
];

$configFound = false;
foreach ($configPaths as $path) {
    if (file_exists($path)) {
        $configFound = true;
        break;
    }
}

if (!$configFound) {
    echo "No config file found. Run install.php first.\n";
    exit(1);
}

// Load the application
require_once __DIR__ . '/../includes/config.php';

echo "Initializing database settings from environment variables...\n";

// Mapping of environment variables to database settings
$settingsMap = [
    // Site settings
    'MESHSILO_SITE_NAME' => 'site_name',
    'MESHSILO_SITE_DESCRIPTION' => 'site_description',
    'MESHSILO_SITE_URL' => 'site_url',

    // Application settings
    'MESHSILO_ALLOWED_EXTENSIONS' => 'allowed_extensions',
    'MESHSILO_MODELS_PER_PAGE' => 'models_per_page',
    'MESHSILO_ALLOW_REGISTRATION' => 'allow_registration',
    'MESHSILO_REQUIRE_APPROVAL' => 'require_approval',
    'MESHSILO_AUTO_DEDUPLICATION' => 'auto_deduplication',

    // Demo mode
    'MESHSILO_DEMO_MODE' => 'demo_mode',
    'MESHSILO_DEMO_RESET_INTERVAL' => 'demo_reset_interval',
];

$updated = 0;
$skipped = 0;

foreach ($settingsMap as $envVar => $settingKey) {
    $value = getenv($envVar);

    // Skip if environment variable is not set
    if ($value === false) {
        $skipped++;
        continue;
    }

    // Get current value from database
    $currentValue = getSetting($settingKey, null);

    // Only update if value is different or not set
    if ($currentValue !== $value) {
        setSetting($settingKey, $value);
        echo "  Updated: {$settingKey} = {$value}\n";
        $updated++;
    } else {
        $skipped++;
    }
}

// Generate server UUID if not exists
$serverUuid = getSetting('server_uuid', '');
if (empty($serverUuid)) {
    $serverUuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    setSetting('server_uuid', $serverUuid);
    echo "  Generated: server_uuid = {$serverUuid}\n";
    $updated++;
}

echo "\nDone. Updated: {$updated}, Skipped: {$skipped}\n";
