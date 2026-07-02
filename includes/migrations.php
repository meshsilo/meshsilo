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
            'name' => 'Relocate attachments to model asset folders',
            'description' => 'Move attachment files from md5-hash folders into the parent model\'s own asset folder and update file_path',
            'check' => function ($db) {
                // Check if any attachments still point to old md5-hash folders
                // New-style paths use the model's folderId; old-style used md5(name+id)
                // We detect old paths by checking if the attachment folder matches the model's folder
                if (!tableExists($db, 'model_attachments')) {
                    return true;
                }
                $sql = 'SELECT COUNT(*) FROM model_attachments a
                        JOIN models m ON a.model_id = m.id
                        WHERE m.file_path IS NOT NULL
                        AND m.file_path != \'\'
                        AND a.file_path NOT LIKE \'%/attachments/%\'';
                $result = $db->querySingle($sql);
                if ((int)$result > 0) {
                    return false;
                }
                // Check for attachments whose folder doesn't match the model's folder
                $stmt = $db->prepare('SELECT a.id, a.file_path, m.file_path AS model_file_path
                    FROM model_attachments a
                    JOIN models m ON a.model_id = m.id
                    WHERE m.file_path IS NOT NULL AND m.file_path != \'\'
                    LIMIT 100');
                $stmt->execute();
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $modelFolder = preg_replace('#^assets/#', '', $row['model_file_path']);
                    if (str_contains($modelFolder, '/')) {
                        $modelFolder = explode('/', $modelFolder)[0];
                    }
                    $attachFolder = explode('/', $row['file_path'])[0] ?? '';
                    if ($attachFolder !== $modelFolder && $attachFolder !== '') {
                        return false;
                    }
                }
                return true;
            },
            'apply' => function ($db) {
                $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
                $uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : $basePath . '/storage/assets/';

                $stmt = $db->prepare('SELECT a.id, a.file_path, a.model_id, m.file_path AS model_file_path, m.name AS model_name
                    FROM model_attachments a
                    JOIN models m ON a.model_id = m.id
                    WHERE m.file_path IS NOT NULL AND m.file_path != \'\'');
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $moved = 0;
                $skipped = 0;
                $errors = 0;

                foreach ($rows as $row) {
                    // Extract model's folder from its file_path ("assets/{folderId}" or "assets/{folderId}/file")
                    $modelFolder = preg_replace('#^assets/#', '', $row['model_file_path']);
                    if (str_contains($modelFolder, '/')) {
                        $modelFolder = explode('/', $modelFolder)[0];
                    }

                    // Current attachment folder
                    $attachParts = explode('/', $row['file_path']);
                    $currentFolder = $attachParts[0] ?? '';
                    $attachFilename = basename($row['file_path']);

                    // Already in the right folder
                    if ($currentFolder === $modelFolder) {
                        $skipped++;
                        continue;
                    }

                    // Build new path
                    $newRelativePath = $modelFolder . '/attachments/' . $attachFilename;
                    $oldDiskPath = $uploadPath . $row['file_path'];
                    $newDiskDir = $uploadPath . $modelFolder . '/attachments';
                    $newDiskPath = $newDiskDir . '/' . $attachFilename;

                    // Skip if source file doesn't exist
                    if (!file_exists($oldDiskPath)) {
                        // Update DB path anyway so it at least points to the right location
                        $upd = $db->prepare('UPDATE model_attachments SET file_path = :path WHERE id = :id');
                        $upd->execute([':path' => $newRelativePath, ':id' => $row['id']]);
                        $skipped++;
                        continue;
                    }

                    // Create target directory
                    if (!is_dir($newDiskDir)) {
                        mkdir($newDiskDir, 0755, true);
                    }

                    // Move file
                    if (rename($oldDiskPath, $newDiskPath)) {
                        $upd = $db->prepare('UPDATE model_attachments SET file_path = :path WHERE id = :id');
                        $upd->execute([':path' => $newRelativePath, ':id' => $row['id']]);
                        $moved++;

                        // Clean up old empty directories
                        $oldDir = dirname($oldDiskPath);
                        if (is_dir($oldDir) && count(scandir($oldDir)) === 2) {
                            @rmdir($oldDir);
                            $parentDir = dirname($oldDir);
                            if (is_dir($parentDir) && count(scandir($parentDir)) === 2) {
                                @rmdir($parentDir);
                            }
                        }
                    } else {
                        $errors++;
                    }
                }

                if (function_exists('logInfo')) {
                    logInfo("Attachment relocation: moved $moved, skipped $skipped, errors $errors");
                }
            },
        ],
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
        [
            'name' => 'Deduplicate model versions and add unique (model_id, version_number) index',
            'description' => 'Remove duplicate model_versions rows sharing a (model_id, version_number) and add a unique index so each model has at most one row per version number',
            'check' => function ($db) {
                // Nothing to index until the model_versions table exists.
                if (!tableExists($db, 'model_versions')) {
                    return true;
                }
                return indexExists($db, 'model_versions', 'idx_model_versions_model_ver');
            },
            'apply' => function ($db) {
                if (!tableExists($db, 'model_versions')) {
                    return;
                }
                // De-duplicate BEFORE creating the index, otherwise the unique
                // index creation fails on any existing collisions. Keep the row
                // with the lowest id per (model_id, version_number); wrapped in a
                // derived table to work around MySQL error 1093.
                $db->exec('DELETE FROM model_versions WHERE id NOT IN (SELECT min_id FROM (SELECT MIN(id) AS min_id FROM model_versions GROUP BY model_id, version_number) AS keep)');
                // Add unique index to prevent future duplicates
                $db->exec('CREATE UNIQUE INDEX idx_model_versions_model_ver ON model_versions (model_id, version_number)');
            },
        ],
        [
            'name' => 'Backfill owner (user_id) on legacy models',
            'description' => 'Assign an owner to models with a NULL user_id so ownership checks apply. Prefers the earliest upload actor from activity_log; falls back to the lowest-id admin. Orphaned content becomes admin-owned (fail-closed) rather than editable/deletable by any user.',
            'check' => function ($db) {
                // Nothing to do until the models.user_id column exists.
                if (!tableExists($db, 'models') || !columnExists($db, 'models', 'user_id')) {
                    return true;
                }
                $nullCount = (int)$db->querySingle('SELECT COUNT(*) FROM models WHERE user_id IS NULL');
                if ($nullCount === 0) {
                    return true; // no legacy orphans left to assign
                }
                // If there is no admin to attribute orphans to, we cannot safely
                // proceed; treat as applied so it does not block other migrations
                // (it will re-run once an admin account exists).
                $adminCount = (int)$db->querySingle('SELECT COUNT(*) FROM users WHERE is_admin = 1');
                return $adminCount === 0;
            },
            'apply' => function ($db) {
                if (!tableExists($db, 'models') || !columnExists($db, 'models', 'user_id')) {
                    return;
                }
                $fallback = $db->querySingle('SELECT id FROM users WHERE is_admin = 1 ORDER BY id ASC LIMIT 1');
                $fallback = ($fallback !== null && $fallback !== false) ? (int)$fallback : 0;
                if (!$fallback) {
                    if (function_exists('logWarning')) {
                        logWarning('Ownership backfill skipped: no admin user to assign orphaned models to');
                    }
                    return;
                }

                $hasActivity = tableExists($db, 'activity_log');

                $stmt = $db->prepare('SELECT id, parent_id FROM models WHERE user_id IS NULL');
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $fromLog = 0;
                $toFallback = 0;
                foreach ($rows as $row) {
                    $modelId = (int)$row['id'];
                    $ownerId = null;

                    if ($hasActivity) {
                        // Uploads are logged against the parent model's id.
                        $lookupId = !empty($row['parent_id']) ? (int)$row['parent_id'] : $modelId;
                        $a = $db->prepare(
                            "SELECT user_id FROM activity_log
                             WHERE entity_type = 'model' AND entity_id = :mid
                               AND user_id IS NOT NULL
                               AND action IN ('upload', 'upload_version', 'create', 'add_part')
                             ORDER BY id ASC LIMIT 1"
                        );
                        $a->execute([':mid' => $lookupId]);
                        $found = $a->fetch(PDO::FETCH_ASSOC);
                        if ($found && $found['user_id'] !== null) {
                            $ownerId = (int)$found['user_id'];
                        }
                    }

                    if ($ownerId === null) {
                        $ownerId = $fallback;
                        $toFallback++;
                    } else {
                        $fromLog++;
                    }

                    $u = $db->prepare('UPDATE models SET user_id = :uid WHERE id = :mid');
                    $u->execute([':uid' => $ownerId, ':mid' => $modelId]);
                }

                if (function_exists('logInfo')) {
                    logInfo("Ownership backfill: assigned $fromLog model(s) from activity_log, $toFallback to fallback admin #$fallback");
                }
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
