#!/usr/bin/env php
<?php
/**
 * Database Migration CLI Tool
 *
 * Usage:
 *   php cli/migrate.php              # Run all pending migrations
 *   php cli/migrate.php --status     # Show migration status
 *   php cli/migrate.php --dry-run    # Preview migrations without applying
 *   php cli/migrate.php --backup     # Create backup before migrating
 *
 * Add to upgrade scripts:
 *   cd /path/to/silo && php cli/migrate.php --backup
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Change to project root
chdir(__DIR__ . '/..');

// Load configuration (handles database setup)
require_once 'includes/config.php';
require_once 'includes/migrations.php';

// Parse arguments
$options = getopt('', ['status', 'dry-run', 'backup', 'help', 'verbose', 'force']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$showStatus = isset($options['status']);
$dryRun = isset($options['dry-run']);
$backup = isset($options['backup']);
$verbose = isset($options['verbose']);
$force = isset($options['force']);

// Get database connection
try {
    $db = getDB();
} catch (Exception $e) {
    echo "\033[31mError: Could not connect to database.\033[0m\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

$dbType = $db->getType();
echo "Database type: " . strtoupper($dbType) . "\n";

// Define all migrations
$migrations = getMigrationList();

if ($showStatus) {
    showMigrationStatus($db, $migrations);
    exit(0);
}

// Check what needs to be migrated
$pending = getPendingMigrations($db, $migrations);

if (empty($pending)) {
    echo "\033[32m✓ Database is up to date. No migrations needed.\033[0m\n";
    exit(0);
}

echo "\n" . count($pending) . " pending migration(s) found:\n";
foreach ($pending as $m) {
    echo "  - {$m['name']}\n";
}
echo "\n";

if ($dryRun) {
    echo "\033[33m[DRY RUN] No changes will be made.\033[0m\n";
    exit(0);
}

// Create backup if requested
if ($backup) {
    createBackup();
}

// Run migrations
echo "Running migrations...\n\n";
$applied = 0;
$errors = 0;

foreach ($pending as $migration) {
    echo "  Applying: {$migration['name']}... ";

    try {
        $migration['apply']($db);
        echo "\033[32m✓\033[0m\n";
        $applied++;

        if ($verbose && !empty($migration['description'])) {
            echo "    {$migration['description']}\n";
        }
    } catch (Exception $e) {
        echo "\033[31m✗\033[0m\n";
        echo "    Error: " . $e->getMessage() . "\n";
        $errors++;

        if (!$force) {
            echo "\n\033[31mMigration failed. Use --force to continue despite errors.\033[0m\n";
            exit(1);
        }
    }
}

echo "\n";
if ($errors === 0) {
    echo "\033[32m✓ All migrations applied successfully!\033[0m\n";
} else {
    echo "\033[33m⚠ Completed with $errors error(s).\033[0m\n";
}

echo "Applied: $applied, Errors: $errors\n";

// Update schema version
setSetting('schema_version', date('Y-m-d H:i:s'));
setSetting('last_migration', date('Y-m-d H:i:s'));

exit($errors > 0 ? 1 : 0);

// =====================
// Helper Functions
// =====================

function showHelp() {
    echo <<<HELP
Silo Database Migration Tool

Usage: php cli/migrate.php [options]

Options:
  --status      Show migration status without making changes
  --dry-run     Preview what migrations would be applied
  --backup      Create database backup before migrating
  --verbose     Show detailed migration descriptions
  --force       Continue even if a migration fails
  --help        Show this help message

Examples:
  php cli/migrate.php --status          # Check what needs to be migrated
  php cli/migrate.php --dry-run         # Preview migrations
  php cli/migrate.php --backup          # Backup and migrate
  php cli/migrate.php --backup --verbose # Verbose migration with backup

HELP;
}

function showMigrationStatus($db, $migrations) {
    echo "\nMigration Status\n";
    echo str_repeat("=", 60) . "\n\n";

    $applied = 0;
    $pending = 0;

    foreach ($migrations as $m) {
        $status = $m['check']($db);
        if ($status) {
            echo "  \033[32m✓\033[0m {$m['name']}\n";
            $applied++;
        } else {
            echo "  \033[33m○\033[0m {$m['name']} \033[33m(pending)\033[0m\n";
            $pending++;
        }
    }

    echo "\n" . str_repeat("-", 60) . "\n";
    echo "Applied: $applied, Pending: $pending\n";

    // Show schema version
    $schemaVersion = getSetting('schema_version', 'Unknown');
    $lastMigration = getSetting('last_migration', 'Never');
    echo "\nSchema version: $schemaVersion\n";
    echo "Last migration: $lastMigration\n";
}

function getPendingMigrations($db, $migrations) {
    $pending = [];
    foreach ($migrations as $m) {
        if (!$m['check']($db)) {
            $pending[] = $m;
        }
    }
    return $pending;
}

function createBackup() {
    $dbPath = defined('DB_PATH') ? DB_PATH : 'storage/db/meshsilo.db';

    if (!file_exists($dbPath)) {
        echo "\033[33mWarning: SQLite database not found, skipping backup.\033[0m\n";
        return;
    }

    $backupDir = dirname($dbPath);
    $backupPath = $backupDir . '/backup_pre_migration_' . date('Y-m-d_H-i-s') . '.db';

    echo "Creating backup... ";
    if (copy($dbPath, $backupPath)) {
        echo "\033[32m✓\033[0m\n";
        echo "  Backup saved to: $backupPath\n\n";
    } else {
        echo "\033[31m✗\033[0m\n";
        echo "  Warning: Could not create backup.\n\n";
    }
}

