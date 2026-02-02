<?php
/**
 * Demo Mode Reset CLI Script
 *
 * Resets the Silo instance to demo mode with sample models.
 *
 * NOTE: This task runs automatically via the unified cron when demo mode is enabled.
 * For scheduled execution, use: php cli/cron.php (runs demo:reset task hourly)
 *
 * Usage:
 *   php cli/demo-reset.php              # Run reset
 *   php cli/demo-reset.php --force      # Skip confirmation
 *   php cli/demo-reset.php --dry-run    # Show what would happen
 */

// Ensure running from CLI
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Change to project root
chdir(dirname(__DIR__));

// Parse arguments
$args = getopt('', ['force', 'dry-run', 'help']);
$force = isset($args['force']);
$dryRun = isset($args['dry-run']);
$help = isset($args['help']);

if ($help) {
    echo <<<HELP
Silo Demo Mode Reset

Resets the Silo instance to demo mode with sample models from NASA.
WARNING: This will delete ALL existing models and user data!

Usage:
  php cli/demo-reset.php [options]

Options:
  --force     Skip confirmation prompt
  --dry-run   Show what would happen without making changes
  --help      Show this help message

Examples:
  php cli/demo-reset.php              # Interactive reset
  php cli/demo-reset.php --force      # Automated reset (for cron)
  php cli/demo-reset.php --dry-run    # Preview changes

Cron Setup (reset every hour):
  0 * * * * php /path/to/silo/cli/demo-reset.php --force >> /var/log/silo-demo-reset.log 2>&1

HELP;
    exit(0);
}

// Load configuration
if (!file_exists('includes/config.php')) {
    echo "Error: Silo is not installed. Run install.php first.\n";
    exit(1);
}

require_once 'includes/config.php';
require_once 'includes/DemoMode.php';

// Check if demo mode is enabled (can only be set during installation)
if (getSetting('demo_mode', '0') !== '1') {
    echo "Error: Demo mode is not enabled.\n";
    echo "Demo mode can only be enabled during initial setup (install.php).\n";
    exit(1);
}

$demoMode = new DemoMode();

echo "=== Silo Demo Mode Reset ===\n\n";

if ($dryRun) {
    echo "[DRY RUN] No changes will be made.\n\n";
}

// Show current stats
try {
    $db = getDB();
    $modelCount = $db->querySingle('SELECT COUNT(*) FROM models WHERE parent_id IS NULL');
    $partCount = $db->querySingle('SELECT COUNT(*) FROM models WHERE parent_id IS NOT NULL');
    $userCount = $db->querySingle('SELECT COUNT(*) FROM users');

    echo "Current database state:\n";
    echo "  - Models: $modelCount\n";
    echo "  - Parts: $partCount\n";
    echo "  - Users: $userCount\n\n";
} catch (Exception $e) {
    echo "Warning: Could not read current stats: " . $e->getMessage() . "\n\n";
}

// Show what will be done
$demoUser = getenv('DEMO_USER') ?: 'demo';
$demoAdmin = getenv('DEMO_ADMIN_USER') ?: 'demoadmin';
echo "This will:\n";
echo "  1. Delete ALL existing models and parts\n";
echo "  2. Delete ALL user accounts\n";
echo "  3. Clear favorites, tags, activity logs\n";
echo "  4. Download sample models from NASA\n";
echo "  5. Create demo user ({$demoUser})\n";
echo "  6. Create demo admin ({$demoAdmin})\n";
echo "\nNote: Set DEMO_PASSWORD and DEMO_ADMIN_PASSWORD env vars to specify credentials.\n";
echo "      If not set, random secure passwords will be generated and displayed.\n\n";

if ($dryRun) {
    echo "[DRY RUN] Would reset to demo mode with " . count($demoMode->getSampleModels()) . " sample models.\n";
    exit(0);
}

// Confirm unless --force
if (!$force) {
    echo "Are you sure you want to proceed? Type 'yes' to confirm: ";
    $confirmation = trim(fgets(STDIN));
    if ($confirmation !== 'yes') {
        echo "Aborted.\n";
        exit(1);
    }
    echo "\n";
}

// Run the reset
echo "Starting demo reset...\n\n";

$startTime = microtime(true);

try {
    $result = $demoMode->resetToDemo();

    $elapsed = round(microtime(true) - $startTime, 2);

    if ($result['success']) {
        echo "\n✓ Demo reset completed successfully in {$elapsed}s\n\n";
        echo "Summary:\n";
        echo "  - Models created: " . ($result['models_created'] ?? 0) . "\n";
        foreach ($result['messages'] ?? [] as $msg) {
            if (strpos($msg, 'Created demo') !== false) {
                echo "  - $msg\n";
            }
        }

        if (!empty($result['errors'])) {
            echo "\nWarnings:\n";
            foreach ($result['errors'] as $error) {
                echo "  - $error\n";
            }
        }

        exit(0);
    } else {
        echo "\n✗ Demo reset failed\n";
        if (!empty($result['error'])) {
            echo "Error: " . $result['error'] . "\n";
        }
        if (!empty($result['errors'])) {
            echo "Errors:\n";
            foreach ($result['errors'] as $error) {
                echo "  - $error\n";
            }
        }
        exit(1);
    }
} catch (Exception $e) {
    echo "\n✗ Demo reset failed with exception:\n";
    echo $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
