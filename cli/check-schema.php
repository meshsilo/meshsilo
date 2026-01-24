#!/usr/bin/env php
<?php
/**
 * Database Schema Checker CLI Tool
 *
 * Usage:
 *   php cli/check-schema.php              # Show current schema
 *   php cli/check-schema.php --all        # Show all tables
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Change to project root
chdir(__DIR__ . '/..');

// Load configuration
require_once 'includes/config.php';

// Parse arguments
$options = getopt('', ['all', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
MeshSilo Database Schema Checker

Usage: php cli/check-schema.php [options]

Options:
  --all         Show all tables in the database
  --help        Show this help message

Examples:
  php cli/check-schema.php              # Show models table schema
  php cli/check-schema.php --all        # Show all tables

HELP;
    exit(0);
}

$showAll = isset($options['all']);

// Get database connection
try {
    $db = getDB();
} catch (Exception $e) {
    echo "\033[31mError: Could not connect to database.\033[0m\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

$dbType = $db->getType();
echo "Database type: " . strtoupper($dbType) . "\n\n";

// Show all tables if requested
if ($showAll) {
    echo "All tables in database:\n";
    echo str_repeat("-", 80) . "\n";

    try {
        if ($dbType === 'sqlite') {
            $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        } else {
            $result = $db->query("SHOW TABLES");
        }

        if ($result) {
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                echo "  - " . $row[0] . "\n";
            }
        }
    } catch (Exception $e) {
        echo "Error listing tables: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

// Show models table schema
echo "Models table schema:\n";
echo str_repeat("-", 80) . "\n";

try {
    // Check if models table exists
    if ($dbType === 'sqlite') {
        $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='models'");
    } else {
        $tableCheck = $db->query("SHOW TABLES LIKE 'models'");
    }
    $tableExists = $tableCheck && $tableCheck->fetch();

    if ($tableExists) {
        if ($dbType === 'sqlite') {
            $result = $db->query("PRAGMA table_info(models)");
        } else {
            $result = $db->query("DESCRIBE models");
        }

        printf("%-5s %-30s %-15s %-10s %-10s\n", "CID", "Name", "Type", "NotNull", "Default");
        echo str_repeat("-", 80) . "\n";

        $columnCount = 0;
        if ($result) {
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                if ($dbType === 'sqlite') {
                    printf("%-5s %-30s %-15s %-10s %-10s\n",
                        $row['cid'],
                        $row['name'],
                        $row['type'],
                        $row['notnull'] ? 'YES' : 'NO',
                        $row['dflt_value'] ?? 'NULL'
                    );
                } else {
                    printf("%-5s %-30s %-15s %-10s %-10s\n",
                        $columnCount,
                        $row['Field'],
                        $row['Type'],
                        $row['Null'] === 'NO' ? 'YES' : 'NO',
                        $row['Default'] ?? 'NULL'
                    );
                }
                $columnCount++;
            }
        }
        echo str_repeat("-", 80) . "\n";
        echo "Total columns: $columnCount\n";
    } else {
        echo "\033[33mModels table does not exist!\033[0m\n";
        echo "Database may need to be initialized.\n";
        echo "Run: php cli/migrate.php\n";
    }
} catch (Exception $e) {
    echo "\033[31mError checking models table: " . $e->getMessage() . "\033[0m\n";
}

echo "\n";

// Check schema version from settings
echo "Schema status:\n";
echo str_repeat("-", 80) . "\n";

try {
    // Check if settings table exists
    if ($dbType === 'sqlite') {
        $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='settings'");
    } else {
        $tableCheck = $db->query("SHOW TABLES LIKE 'settings'");
    }
    $tableExists = $tableCheck && $tableCheck->fetch();

    if ($tableExists) {
        $schemaVersion = getSetting('schema_version', 'Not set');
        $lastMigration = getSetting('last_migration', 'Never');

        echo "  Schema version: $schemaVersion\n";
        echo "  Last migration: $lastMigration\n";
        echo "\n\033[32m✓ Database schema is initialized.\033[0m\n";
        echo "Run 'php cli/migrate.php --status' to check for pending migrations.\n";
    } else {
        echo "\033[33mSettings table does not exist!\033[0m\n";
        echo "Database may need to be initialized.\n";
        echo "Run: php cli/migrate.php\n";
    }
} catch (Exception $e) {
    echo "\033[31mError checking schema status: " . $e->getMessage() . "\033[0m\n";
    echo "Run: php cli/migrate.php\n";
}
