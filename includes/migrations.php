<?php

/**
 * Database Migrations
 *
 * Table creation migrations are auto-generated from getSchema() — the single source of truth.
 * Special migrations (data fixes, schema corrections) are defined manually below.
 *
 * Used by: cli/migrate.php, cli/upgrade.php, app/admin/database.php, app/pages/update.php
 */

/**
 * Extract CREATE TABLE statements from getSchema() and generate migration entries.
 * Each table gets a migration with a tableExists check.
 */
function getTableMigrationsFromSchema(): array
{
    $migrations = [];

    // We need to generate dialect-specific SQL at apply time, so we parse both
    // and let the apply function pick the right one.
    foreach (['sqlite', 'mysql'] as $dialect) {
        $sql = getSchema($dialect);

        // Split into individual statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $stmt) {
            // Match CREATE TABLE statements
            if (preg_match('/CREATE TABLE IF NOT EXISTS [`]?(\w+)[`]?\s*\(/i', $stmt, $m)) {
                $tableName = $m[1];

                if (!isset($migrations[$tableName])) {
                    $migrations[$tableName] = [
                        'name' => "Create $tableName table",
                        'description' => "Create the $tableName table",
                        'table' => $tableName,
                        'sql' => [],
                    ];
                }

                $migrations[$tableName]['sql'][$dialect] = $stmt;
            }
        }
    }

    // Convert to migration entries
    $result = [];
    foreach ($migrations as $tableName => $info) {
        $result[] = [
            'name' => $info['name'],
            'description' => $info['description'],
            'check' => fn($db) => tableExists($db, $tableName),
            'apply' => function ($db) use ($info) {
                $dialect = $db->getType() === 'mysql' ? 'mysql' : 'sqlite';
                $sql = $info['sql'][$dialect] ?? null;
                if ($sql) {
                    $db->exec($sql);
                }
            },
        ];
    }

    return $result;
}

/**
 * Get all pending migrations: auto-generated table creation + special migrations.
 */
function getMigrationList(): array
{
    // Auto-generate table creation migrations from getSchema()
    $migrations = getTableMigrationsFromSchema();

    // Append special migrations (data fixes, schema corrections, index additions)
    $migrations = array_merge($migrations, getSpecialMigrations());

    return $migrations;
}

/**
 * Special migrations that can't be auto-generated from the schema.
 * These handle data migrations, schema corrections, and other one-off fixes.
 */
function getSpecialMigrations(): array
{
    return [
        [
            'name' => 'Deduplicate plugin repositories and add unique URL index',
            'description' => 'Remove duplicate plugin_repositories rows and add a unique index on url to prevent future duplicates',
            'check' => fn($db) => indexExists($db, 'plugin_repositories', 'idx_plugin_repositories_url'),
            'apply' => function ($db) {
                // Delete duplicates, keeping the row with the lowest id
                // Wrapped in derived table to work around MySQL error 1093
                $db->exec('DELETE FROM plugin_repositories WHERE id NOT IN (SELECT min_id FROM (SELECT MIN(id) AS min_id FROM plugin_repositories GROUP BY url) AS keep)');
                // Add unique index to prevent future duplicates
                $db->exec('CREATE UNIQUE INDEX idx_plugin_repositories_url ON plugin_repositories (url)');
            },
        ],
    ];
}

/**
 * Get pending migrations (migrations whose check returns false).
 */
if (!function_exists('getPendingMigrations')) {
    function getPendingMigrations($db, $migrations): array
    {
        $pending = [];
        foreach ($migrations as $m) {
            if (!$m['check']($db)) {
                $pending[] = $m;
            }
        }
        return $pending;
    }
}

/**
 * Show migration status.
 */
if (!function_exists('showMigrationStatus')) {
    function showMigrationStatus($db, $migrations): void
    {
        $applied = 0;
        $pending = 0;

        foreach ($migrations as $m) {
            $isDone = $m['check']($db);
            $status = $isDone ? "\033[32m✓\033[0m" : "\033[33m○\033[0m";
            echo "  $status {$m['name']}\n";
            if ($isDone) {
                $applied++;
            } else {
                $pending++;
            }
        }

        echo "\n\033[1mTotal:\033[0m $applied applied, $pending pending\n";
    }
}
