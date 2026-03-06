#!/usr/bin/env php
<?php
/**
 * MeshSilo Upgrade Script
 *
 * Comprehensive upgrade script for migrating from pre-v1 versions to v1.0.0.
 * This script wraps the migration system with additional validation,
 * backup verification, and post-upgrade checks.
 *
 * Usage:
 *   php cli/upgrade.php              # Interactive upgrade
 *   php cli/upgrade.php --yes        # Non-interactive (auto-confirm)
 *   php cli/upgrade.php --dry-run    # Preview without changes
 *   php cli/upgrade.php --validate   # Validate upgrade was successful
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Change to project root
chdir(__DIR__ . '/..');

// ANSI color codes
define('COLOR_RED', "\033[31m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_RESET', "\033[0m");
define('COLOR_BOLD', "\033[1m");

// Parse arguments
$options = getopt('', ['yes', 'dry-run', 'validate', 'help', 'skip-backup']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$autoConfirm = isset($options['yes']);
$dryRun = isset($options['dry-run']);
$validateOnly = isset($options['validate']);
$skipBackup = isset($options['skip-backup']);

// Header
echo COLOR_BOLD . "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║             MeshSilo Upgrade to v1.0.0                        ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";
echo COLOR_RESET . "\n";

// Load configuration
try {
    require_once 'includes/config.php';
    require_once 'includes/migrations.php';
} catch (Exception $e) {
    error("Failed to load configuration: " . $e->getMessage());
    exit(1);
}

// Get database connection
try {
    $db = getDB();
    $dbType = $db->getType();
} catch (Exception $e) {
    error("Could not connect to database: " . $e->getMessage());
    exit(1);
}

// Show system info
info("System Information");
echo "  PHP Version:    " . PHP_VERSION . "\n";
echo "  Database Type:  " . strtoupper($dbType) . "\n";
echo "  Target Version: 1.0.0\n";

// Get current version
$currentVersion = getSetting('app_version', 'unknown');
$schemaVersion = getSetting('schema_version', 'Not set');
echo "  Current Version: " . ($currentVersion === 'unknown' ? 'Pre-v1 (development)' : $currentVersion) . "\n";
echo "  Schema Version:  " . $schemaVersion . "\n\n";

// Validate-only mode
if ($validateOnly) {
    validateUpgrade($db);
    exit(0);
}

// Check pending migrations
$migrations = getMigrationList();
$pending = [];
foreach ($migrations as $m) {
    if (!$m['check']($db)) {
        $pending[] = $m;
    }
}

if (empty($pending)) {
    success("Database is up to date. No migrations needed.");
    echo "\n";
    validateUpgrade($db);
    exit(0);
}

// Show pending migrations
warning(count($pending) . " pending migration(s) found:\n");
foreach ($pending as $m) {
    echo "  - {$m['name']}\n";
    if (!empty($m['description'])) {
        echo "    " . COLOR_BLUE . $m['description'] . COLOR_RESET . "\n";
    }
}
echo "\n";

// Dry run mode
if ($dryRun) {
    info("[DRY RUN] No changes will be made.");
    echo "\nRun without --dry-run to apply these migrations.\n\n";
    exit(0);
}

// Pre-upgrade checks
info("Pre-Upgrade Checks");

// Check 1: Disk space
$storagePath = __DIR__ . '/../storage';
$freeSpace = disk_free_space($storagePath);
$requiredSpace = 100 * 1024 * 1024; // 100MB minimum
if ($freeSpace < $requiredSpace) {
    error("Insufficient disk space. Free: " . formatBytes($freeSpace) . ", Required: 100MB minimum");
    exit(1);
}
echo "  " . COLOR_GREEN . "✓" . COLOR_RESET . " Disk space: " . formatBytes($freeSpace) . " available\n";

// Check 2: Database writeable
$dbPath = defined('DB_PATH') ? DB_PATH : 'storage/db/meshsilo.db';
if ($dbType === 'sqlite' && file_exists($dbPath)) {
    if (!is_writable($dbPath)) {
        error("Database file is not writable: $dbPath");
        exit(1);
    }
    echo "  " . COLOR_GREEN . "✓" . COLOR_RESET . " Database is writable\n";
} else {
    echo "  " . COLOR_GREEN . "✓" . COLOR_RESET . " Database connection OK\n";
}

// Check 3: Storage directory
if (!is_writable($storagePath)) {
    error("Storage directory is not writable: $storagePath");
    exit(1);
}
echo "  " . COLOR_GREEN . "✓" . COLOR_RESET . " Storage directory is writable\n";

// Check 4: Backup directory
$backupDir = $storagePath . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}
if (!is_writable($backupDir)) {
    error("Backup directory is not writable: $backupDir");
    exit(1);
}
echo "  " . COLOR_GREEN . "✓" . COLOR_RESET . " Backup directory ready\n";

echo "\n";

// Confirm upgrade
if (!$autoConfirm) {
    echo COLOR_YELLOW . "This upgrade will modify your database schema." . COLOR_RESET . "\n";
    echo "A backup will be created automatically before any changes.\n\n";
    echo "Continue with upgrade? [y/N] ";

    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);

    if (strtolower(trim($line)) !== 'y') {
        echo "\nUpgrade cancelled.\n";
        exit(0);
    }
    echo "\n";
}

// Create backup
if (!$skipBackup) {
    info("Creating Database Backup");

    $backupPath = createPreUpgradeBackup($dbType, $dbPath);
    if ($backupPath) {
        success("Backup created: $backupPath");
    } else {
        error("Failed to create backup");
        if (!$autoConfirm) {
            echo "Continue without backup? [y/N] ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            fclose($handle);
            if (strtolower(trim($line)) !== 'y') {
                echo "\nUpgrade cancelled.\n";
                exit(0);
            }
        } else {
            exit(1);
        }
    }
    echo "\n";
}

// Run migrations
info("Running Migrations");

$applied = 0;
$errors = 0;
$startTime = microtime(true);

foreach ($pending as $migration) {
    echo "  Applying: {$migration['name']}... ";

    try {
        $migration['apply']($db);
        echo COLOR_GREEN . "✓" . COLOR_RESET . "\n";
        $applied++;
    } catch (Exception $e) {
        echo COLOR_RED . "✗" . COLOR_RESET . "\n";
        echo "    " . COLOR_RED . "Error: " . $e->getMessage() . COLOR_RESET . "\n";
        $errors++;

        // Stop on first error for safety
        error("Migration failed. Your backup is located at: $backupPath");
        echo "\nTo restore: cp '$backupPath' '$dbPath'\n";
        exit(1);
    }
}

$duration = round(microtime(true) - $startTime, 2);

echo "\n";

// Update version info
setSetting('schema_version', date('Y-m-d H:i:s'));
setSetting('last_migration', date('Y-m-d H:i:s'));
setSetting('app_version', '1.0.0');

// Summary
if ($errors === 0) {
    success("Upgrade completed successfully!");
    echo "  Migrations applied: $applied\n";
    echo "  Duration: {$duration}s\n";
} else {
    warning("Upgrade completed with $errors error(s)");
}

echo "\n";

// Post-upgrade validation
validateUpgrade($db);

// Post-upgrade recommendations
echo "\n";
info("Post-Upgrade Recommendations");
echo "  1. Clear browser cache to see UI changes\n";
echo "  2. Test login functionality\n";
echo "  3. Verify model upload and viewing\n";
echo "  4. Check admin panel access\n";
echo "  5. Review new features in Settings > Features\n";
echo "\n";

success("Upgrade to v1.0.0 complete!");
echo "\n";

exit($errors > 0 ? 1 : 0);

// =====================
// Helper Functions
// =====================

function showHelp() {
    echo <<<HELP
MeshSilo Upgrade Script

Usage: php cli/upgrade.php [options]

Options:
  --yes           Non-interactive mode (auto-confirm)
  --dry-run       Preview migrations without applying
  --validate      Only validate the upgrade was successful
  --skip-backup   Skip automatic backup (not recommended)
  --help          Show this help message

Examples:
  php cli/upgrade.php                    # Interactive upgrade
  php cli/upgrade.php --yes              # Auto-confirm all prompts
  php cli/upgrade.php --dry-run          # Preview what will change
  php cli/upgrade.php --validate         # Verify upgrade success

For detailed upgrade instructions, see: docs/UPGRADE.md

HELP;
}

function info($message) {
    echo COLOR_BLUE . "▶ " . COLOR_BOLD . $message . COLOR_RESET . "\n";
}

function success($message) {
    echo COLOR_GREEN . "✓ " . $message . COLOR_RESET . "\n";
}

function warning($message) {
    echo COLOR_YELLOW . "⚠ " . $message . COLOR_RESET . "\n";
}

function error($message) {
    echo COLOR_RED . "✗ " . $message . COLOR_RESET . "\n";
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

function createPreUpgradeBackup($dbType, $dbPath) {
    $timestamp = date('Y-m-d_H-i-s');
    $backupDir = __DIR__ . '/../storage/backups';

    if ($dbType === 'sqlite') {
        $backupPath = $backupDir . "/pre_upgrade_v1.0.0_{$timestamp}.db";

        if (!file_exists($dbPath)) {
            return null;
        }

        // Checkpoint WAL if exists
        try {
            $db = getDB();
            $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (Exception $e) {
            // Ignore checkpoint errors
        }

        if (copy($dbPath, $backupPath)) {
            // Copy WAL and SHM if they exist
            if (file_exists($dbPath . '-wal')) {
                copy($dbPath . '-wal', $backupPath . '-wal');
            }
            if (file_exists($dbPath . '-shm')) {
                copy($dbPath . '-shm', $backupPath . '-shm');
            }
            return $backupPath;
        }
    } else {
        // MySQL backup
        $backupPath = $backupDir . "/pre_upgrade_v1.0.0_{$timestamp}.sql";

        $config = getConfig();
        $cmd = sprintf(
            'mysqldump -h %s -u %s %s > %s 2>&1',
            escapeshellarg($config['db_host'] ?? 'localhost'),
            escapeshellarg($config['db_user'] ?? 'root'),
            escapeshellarg($config['db_name'] ?? 'silo'),
            escapeshellarg($backupPath)
        );

        // Set password via environment
        putenv('MYSQL_PWD=' . ($config['db_password'] ?? ''));
        exec($cmd, $output, $returnCode);
        putenv('MYSQL_PWD=');

        if ($returnCode === 0 && file_exists($backupPath)) {
            return $backupPath;
        }
    }

    return null;
}

function validateUpgrade($db) {
    info("Post-Upgrade Validation");

    $checks = [
        // Core tables
        ['type' => 'table', 'name' => 'users', 'label' => 'Users table'],
        ['type' => 'table', 'name' => 'models', 'label' => 'Models table'],
        ['type' => 'table', 'name' => 'settings', 'label' => 'Settings table'],

        // v1.0.0 tables
        ['type' => 'table', 'name' => 'audit_log', 'label' => 'Audit log table'],
        ['type' => 'table', 'name' => 'retention_policies', 'label' => 'Retention policies table'],
        ['type' => 'table', 'name' => 'model_attachments', 'label' => 'Model attachments table'],
        ['type' => 'table', 'name' => 'password_resets', 'label' => 'Password resets table'],

        // v1.0.0 columns
        ['type' => 'column', 'table' => 'users', 'column' => 'auth_method', 'label' => 'User auth_method column'],
        ['type' => 'column', 'table' => 'models', 'column' => 'approval_status', 'label' => 'Model approval_status column'],

        // Indexes
        ['type' => 'index', 'table' => 'models', 'index' => 'idx_models_parent_id', 'label' => 'Models parent_id index'],
    ];

    $passed = 0;
    $failed = 0;

    foreach ($checks as $check) {
        $exists = false;

        switch ($check['type']) {
            case 'table':
                $exists = tableExists($db, $check['name']);
                break;
            case 'column':
                $exists = columnExists($db, $check['table'], $check['column']);
                break;
            case 'index':
                $exists = indexExists($db, $check['table'], $check['index']);
                break;
        }

        if ($exists) {
            echo "  " . COLOR_GREEN . "✓" . COLOR_RESET . " {$check['label']}\n";
            $passed++;
        } else {
            echo "  " . COLOR_RED . "✗" . COLOR_RESET . " {$check['label']}\n";
            $failed++;
        }
    }

    echo "\n";

    // Check version
    $version = getSetting('app_version', 'unknown');
    if ($version === '1.0.0') {
        echo "  " . COLOR_GREEN . "✓" . COLOR_RESET . " Version: $version\n";
    } else {
        echo "  " . COLOR_YELLOW . "?" . COLOR_RESET . " Version: $version (expected 1.0.0)\n";
    }

    echo "\n";

    if ($failed === 0) {
        success("All validation checks passed ($passed/$passed)");
    } else {
        warning("Validation completed: $passed passed, $failed failed");
        echo "\nRun 'php cli/migrate.php' to apply missing migrations.\n";
    }
}
