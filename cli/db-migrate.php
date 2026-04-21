#!/usr/bin/env php
<?php
/**
 * Database Migration Tool — SQLite to MySQL
 *
 * Migrates all data from a SQLite database to a MySQL database.
 * Files on disk are not affected — only database records are copied.
 *
 * Usage:
 *   php cli/db-migrate.php --host=localhost --name=meshsilo --user=root --pass=secret
 *   php cli/db-migrate.php --host=localhost --name=meshsilo --user=root --pass=secret --port=3306
 *   php cli/db-migrate.php --dry-run --host=localhost --name=meshsilo --user=root --pass=secret
 *   php cli/db-migrate.php --help
 *
 * After migration:
 *   The tool updates config.local.php to point to MySQL automatically.
 *   Restart the application (or Docker container) to use the new database.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line.\n");
}

chdir(dirname(__DIR__));

// Parse options
$options = getopt('', ['host:', 'port:', 'name:', 'user:', 'pass:', 'dry-run', 'force', 'help', 'batch-size:']);

if (isset($options['help'])) {
    echo <<<HELP
SQLite to MySQL Database Migration Tool

Copies all data from the current SQLite database to a MySQL database,
then updates config.local.php to use MySQL.

Usage:
  php cli/db-migrate.php [options]

Required:
  --host=HOST       MySQL hostname (e.g., localhost, db, 192.168.1.100)
  --name=DATABASE   MySQL database name (must already exist)
  --user=USERNAME   MySQL username
  --pass=PASSWORD   MySQL password

Optional:
  --port=PORT       MySQL port (default: 3306)
  --batch-size=N    Rows per INSERT batch (default: 500)
  --dry-run         Show what would be migrated without changing anything
  --help            Show this help

Prerequisites:
  - MySQL database must already exist (CREATE DATABASE meshsilo)
  - MySQL user must have full privileges on the database
  - Current database must be SQLite

Example:
  php cli/db-migrate.php --host=localhost --name=meshsilo --user=meshsilo --pass=secret123

Docker example:
  docker exec meshsilo php cli/db-migrate.php --host=db --name=meshsilo --user=meshsilo --pass=secret123

HELP;
    exit(0);
}

// Validate required options
$mysqlHost = $options['host'] ?? '';
$mysqlPort = $options['port'] ?? '3306';
$mysqlName = $options['name'] ?? '';
$mysqlUser = $options['user'] ?? '';
$mysqlPass = $options['pass'] ?? '';
$batchSize = max(1, min(5000, (int)($options['batch-size'] ?? 500)));
$dryRun = isset($options['dry-run']);

if (!$mysqlHost || !$mysqlName || !$mysqlUser) {
    echo "Error: --host, --name, and --user are required.\n";
    echo "Run with --help for usage information.\n";
    exit(1);
}

// Load full config to get DB_TYPE and DB_PATH constants
require_once 'includes/config.php';

// Verify source is SQLite
if (DB_TYPE !== 'sqlite') {
    echo "Error: Current database is already MySQL. This tool only migrates from SQLite to MySQL.\n";
    exit(1);
}

echo "╔══════════════════════════════════════════════════╗\n";
echo "║     MeshSilo — SQLite to MySQL Migration        ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

if ($dryRun) {
    echo "[DRY RUN] No changes will be made.\n\n";
}

// Connect to source SQLite
echo "Connecting to SQLite... ";
try {
    $sqlite = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "OK\n";
} catch (PDOException $e) {
    echo "FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Connect to target MySQL
echo "Connecting to MySQL ({$mysqlUser}@{$mysqlHost}:{$mysqlPort}/{$mysqlName})... ";
try {
    $dsn = "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlName};charset=utf8mb4";
    $mysql = new PDO($dsn, $mysqlUser, $mysqlPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "OK\n";
} catch (PDOException $e) {
    echo "FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Check if MySQL database has existing tables
$existingTables = $mysql->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$force = isset($options['force']);
if (!empty($existingTables) && !$force) {
    echo "\nWarning: MySQL database '{$mysqlName}' already has " . count($existingTables) . " tables.\n";
    echo "This tool will DROP and recreate all MeshSilo tables.\n";
    echo "Continue? [y/N] ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
}

// Get all SQLite tables
$sqliteTables = [];
$result = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'models_fts%' ORDER BY name");
while ($row = $result->fetch()) {
    $sqliteTables[] = $row['name'];
}

echo "\nFound " . count($sqliteTables) . " tables in SQLite.\n\n";

if ($dryRun) {
    foreach ($sqliteTables as $table) {
        $count = (int)$sqlite->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
        echo "  {$table}: {$count} rows\n";
    }
    echo "\nDry run complete. Run without --dry-run to perform the migration.\n";
    exit(0);
}

// Phase 1: Create MySQL schema
echo "Phase 1: Creating MySQL schema...\n";
$mysqlDb = new Database('mysql', [
    'host' => $mysqlHost,
    'port' => $mysqlPort,
    'name' => $mysqlName,
    'user' => $mysqlUser,
    'pass' => $mysqlPass,
]);

// Drop existing tables in reverse dependency order
$dropOrder = array_reverse($sqliteTables);
foreach ($dropOrder as $table) {
    $quotedTable = ($table === 'groups') ? '`groups`' : $table;
    try {
        $mysql->exec("DROP TABLE IF EXISTS {$quotedTable}");
    } catch (PDOException $e) {
        // Ignore — table may not exist
    }
}

// Initialize schema + run migrations
initializeDatabase($mysqlDb);
runAllMigrations($mysqlDb);
echo "  Schema created and migrations applied.\n";

// Phase 2: Copy data table by table
echo "\nPhase 2: Copying data...\n";

// Determine migration order (respect foreign keys)
// Tables with no foreign key dependencies first, then dependent tables
$migrationOrder = [
    'users', 'categories', 'collections', 'tags', 'settings',
    'groups', 'models',
    'user_groups', 'model_categories', 'model_tags', 'favorites',
    'activity_log', 'recently_viewed', 'model_ratings',
    'model_versions', 'related_models', 'folders',
    'api_keys', 'api_request_log',
    'print_queue', 'print_photos', 'printers', 'print_history',
    'sessions', 'jobs', 'rate_limits', 'rate_limit_hits',
    'password_resets', 'model_links', 'model_attachments',
    'teams', 'team_members', 'team_models', 'team_invites',
    'scheduled_task_history', 'model_annotations',
];

// Add any tables found in SQLite but not in our list (future-proofing)
foreach ($sqliteTables as $table) {
    if (!in_array($table, $migrationOrder)) {
        $migrationOrder[] = $table;
    }
}

$totalRows = 0;
$tablesMigrated = 0;
$tablesSkipped = 0;

// Disable foreign key checks during import
$mysql->exec("SET FOREIGN_KEY_CHECKS = 0");

foreach ($migrationOrder as $table) {
    // Skip tables that don't exist in SQLite
    if (!in_array($table, $sqliteTables)) {
        continue;
    }

    $quotedTable = ($table === 'groups') ? '`groups`' : $table;

    // Get row count
    $count = (int)$sqlite->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
    if ($count === 0) {
        echo "  {$table}: 0 rows (skipped)\n";
        $tablesSkipped++;
        continue;
    }

    // Get column names from SQLite
    $columnsResult = $sqlite->query("PRAGMA table_info(\"{$table}\")");
    $columns = [];
    while ($col = $columnsResult->fetch()) {
        $columns[] = $col['name'];
    }

    // Check which columns exist in MySQL target table
    try {
        $mysqlColumnsResult = $mysql->query("DESCRIBE {$quotedTable}");
        $mysqlColumns = [];
        while ($col = $mysqlColumnsResult->fetch()) {
            $mysqlColumns[] = $col['Field'];
        }
        // Only copy columns that exist in both
        $columns = array_intersect($columns, $mysqlColumns);
    } catch (PDOException $e) {
        echo "  {$table}: table not in MySQL schema (skipped)\n";
        $tablesSkipped++;
        continue;
    }

    if (empty($columns)) {
        echo "  {$table}: no matching columns (skipped)\n";
        $tablesSkipped++;
        continue;
    }

    // Quote column names for MySQL (handle reserved words like `key`, `value`, `groups`)
    $quotedColumns = array_map(function($c) { return "`{$c}`"; }, $columns);
    $columnList = implode(', ', $quotedColumns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    // Clear target table
    $mysql->exec("DELETE FROM {$quotedTable}");

    // Copy in batches
    $offset = 0;
    $inserted = 0;
    $skippedRows = 0;
    $selectColumns = implode(', ', array_map(function($c) { return "\"{$c}\""; }, $columns));

    while ($offset < $count) {
        $rows = $sqlite->query("SELECT {$selectColumns} FROM \"{$table}\" LIMIT {$batchSize} OFFSET {$offset}")->fetchAll();
        if (empty($rows)) break;

        $mysql->beginTransaction();
        $stmt = $mysql->prepare("INSERT INTO {$quotedTable} ({$columnList}) VALUES ({$placeholders})");

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                // Fix type mismatches: MySQL INT columns reject string values
                // SQLite doesn't enforce types so strings can end up in integer columns
                if ($val !== null && is_string($val) && preg_match('/^(entity_id|model_id|user_id|parent_id|group_id|category_id|tag_id|part_count|file_size|original_size|download_count|sort_order|display_order|version_number|max_downloads|download_count|request_count|attempts|max_attempts)$/', $col)) {
                    $val = is_numeric($val) ? (int)$val : null;
                }
                $values[] = $val;
            }
            try {
                $stmt->execute($values);
                $inserted++;
            } catch (PDOException $rowErr) {
                $skippedRows++;
            }
        }

        $mysql->commit();
        $offset += $batchSize;
    }

    echo "  {$table}: {$inserted} rows copied" . ($skippedRows > 0 ? " ({$skippedRows} skipped due to type errors)" : "") . "\n";
    $totalRows += $inserted;
    $tablesMigrated++;
}

// Re-enable foreign key checks
$mysql->exec("SET FOREIGN_KEY_CHECKS = 1");

// Reset auto-increment counters to max ID + 1
echo "\nPhase 3: Resetting auto-increment counters...\n";
$autoIncrementTables = ['users', 'models', 'categories', 'collections', 'tags', 'activity_log',
    'api_keys', 'model_versions', 'model_ratings', 'folders',
    'print_queue', 'printers', 'print_history', 'print_photos',
    'teams', 'team_invites', 'jobs', 'rate_limits', 'model_links', 'model_attachments',
    'model_annotations', 'scheduled_task_history', 'password_resets'];

foreach ($autoIncrementTables as $table) {
    $quotedTable = ($table === 'groups') ? '`groups`' : $table;
    try {
        $maxId = $mysql->query("SELECT MAX(id) FROM {$quotedTable}")->fetchColumn();
        if ($maxId) {
            $mysql->exec("ALTER TABLE {$quotedTable} AUTO_INCREMENT = " . ($maxId + 1));
        }
    } catch (PDOException $e) {
        // Table may not have id column or may not exist
    }
}
echo "  Done.\n";

// Phase 4: Update config.local.php
echo "\nPhase 4: Updating configuration...\n";

// Find config file using absolute paths (same logic as includes/config.php)
$projectRoot = dirname(__DIR__);
$configPath = null;
$searchPaths = [
    $projectRoot . '/storage/db/config.local.php',
    $projectRoot . '/db/config.local.php',
    $projectRoot . '/config.local.php',
];
foreach ($searchPaths as $path) {
    if (file_exists($path)) {
        $configPath = $path;
        break;
    }
}

if (!$configPath) {
    echo "  Warning: Could not find config.local.php — creating at default location\n";
    $configPath = $projectRoot . '/storage/db/config.local.php';
    @mkdir(dirname($configPath), 0755, true);
}

$configContent = "<?php\n";
$configContent .= "/**\n";
$configContent .= " * MeshSilo Database Configuration\n";
$configContent .= " * Migrated from SQLite to MySQL on " . date('Y-m-d H:i:s') . "\n";
$configContent .= " */\n\n";
$configContent .= "define('DB_TYPE', 'mysql');\n";
$configContent .= "define('DB_HOST', '" . addslashes($mysqlHost) . "');\n";
$configContent .= "define('DB_PORT', '" . addslashes($mysqlPort) . "');\n";
$configContent .= "define('DB_NAME', '" . addslashes($mysqlName) . "');\n";
$configContent .= "define('DB_USER', '" . addslashes($mysqlUser) . "');\n";
$configContent .= "define('DB_PASS', '" . addslashes($mysqlPass) . "');\n";
$configContent .= "define('INSTALLED', true);\n";

// Backup old config
$backupPath = $configPath . '.sqlite.bak';
if (!copy($configPath, $backupPath)) {
    echo "  Warning: Could not create backup at {$backupPath}\n";
} else {
    echo "  Backed up old config to {$backupPath}\n";
}

// Write new config
if (file_put_contents($configPath, $configContent) === false) {
    echo "  ERROR: Failed to write config to {$configPath}\n";
    echo "  Please update the file manually with the MySQL credentials.\n";
    exit(1);
}
echo "  Updated {$configPath} to use MySQL\n";

// Summary
echo "\n╔══════════════════════════════════════════════════╗\n";
echo "║              Migration Complete!                 ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";
echo "  Tables migrated: {$tablesMigrated}\n";
echo "  Tables skipped:  {$tablesSkipped}\n";
echo "  Total rows:      {$totalRows}\n\n";
echo "  Config updated:  {$configPath}\n";
echo "  Config backup:   {$backupPath}\n";
echo "  SQLite file:     " . DB_PATH . " (preserved)\n\n";
echo "Next steps:\n";
echo "  1. Restart the application (or Docker container)\n";
echo "  2. Verify the application works with MySQL\n";
echo "  3. Once confirmed, you can delete the SQLite file\n\n";
echo "To revert to SQLite:\n";
echo "  cp {$backupPath} {$configPath}\n\n";
