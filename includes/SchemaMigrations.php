<?php
// Schema migration and verification helpers.
// Split out of Schema.php. Loaded automatically by Schema.php (require_once at top),
// so any file that requires Schema.php also gets these helpers.

// =====================
// Schema Helper Functions
// =====================

if (!function_exists('columnExists')) {
function columnExists($db, $table, $column)
{
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
        $stmt->execute([':table' => $table, ':column' => $column]);
        return $stmt->fetchColumn() > 0;
    } else {
        $result = $db->query("PRAGMA table_info($table)");
        while ($col = $result->fetch()) {
            if ($col['name'] === $column) {
                return true;
            }
        }
        return false;
    }
}
}

if (!function_exists('tableExists')) {
function tableExists($db, $table)
{
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
        $stmt->execute([':table' => $table]);
        return $stmt->fetchColumn() > 0;
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = :table");
        $stmt->execute([':table' => $table]);
        return $stmt->fetchColumn() > 0;
    }
}
}

if (!function_exists('indexExists')) {
function indexExists($db, $table, $indexName)
{
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index");
        $stmt->execute([':table' => $table, ':index' => $indexName]);
        return $stmt->fetchColumn() > 0;
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND tbl_name = :table AND name = :index");
        $stmt->execute([':table' => $table, ':index' => $indexName]);
        return $stmt->fetchColumn() > 0;
    }
}
}

// =====================
// Column Additions (ALTER TABLE)
// =====================

/**
 * Ensure all expected columns exist on core tables.
 * Idempotent - checks before adding. Safe to re-run.
 */
function ensureColumns($db)
{
    $type = $db->getType();

    // Models table columns (added after initial schema)
    $modelColumns = [
        'download_count' => 'INTEGER DEFAULT 0',
        'license' => $type === 'mysql' ? 'VARCHAR(100)' : 'TEXT',
        'is_archived' => 'INTEGER DEFAULT 0',
        'notes' => 'TEXT',
        'is_printed' => 'INTEGER DEFAULT 0',
        'printed_at' => $type === 'mysql' ? 'DATETIME' : 'DATETIME',
        'dim_x' => 'REAL',
        'dim_y' => 'REAL',
        'dim_z' => 'REAL',
        'dim_unit' => 'TEXT DEFAULT "mm"',
        'sort_order' => 'INTEGER DEFAULT 0',
        'current_version' => 'INTEGER DEFAULT 1',
        'thumbnail_path' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'folder_id' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'approval_status' => ($type === 'mysql' ? "VARCHAR(20)" : "TEXT") . " DEFAULT 'approved'",
        'parent_id' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'original_path' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'part_count' => ($type === 'mysql' ? 'INT' : 'INTEGER') . ' DEFAULT 0',
        'print_type' => $type === 'mysql' ? 'VARCHAR(50)' : 'TEXT',
        'original_size' => $type === 'mysql' ? 'BIGINT' : 'INTEGER',
        'file_hash' => $type === 'mysql' ? 'VARCHAR(64)' : 'TEXT',
        'dedup_path' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'view_count' => 'INTEGER DEFAULT 0',
        'integrity_hash' => $type === 'mysql' ? 'VARCHAR(64)' : 'TEXT',
        'integrity_checked_at' => $type === 'mysql' ? 'DATETIME' : 'DATETIME',
        'remix_of' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'external_source_url' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'external_source_id' => $type === 'mysql' ? 'VARCHAR(100)' : 'TEXT',
        'user_id' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'upload_status' => $type === 'mysql' ? 'VARCHAR(20) DEFAULT NULL' : 'TEXT DEFAULT NULL',
        'nest_folders' => ($type === 'mysql' ? 'TINYINT' : 'INTEGER') . ' DEFAULT 0',
    ];

    foreach ($modelColumns as $column => $dataType) {
        if (tableExists($db, 'models') && !columnExists($db, 'models', $column)) {
            $db->exec("ALTER TABLE models ADD COLUMN $column $dataType");
        }
    }

    // Users table columns
    $userColumns = [
        'permissions' => 'TEXT',
        'two_factor_enabled' => 'INTEGER DEFAULT 0',
        'two_factor_secret' => $type === 'mysql' ? 'VARCHAR(255)' : 'TEXT',
        'two_factor_backup_codes' => 'TEXT',
        'two_factor_enabled_at' => $type === 'mysql' ? 'DATETIME' : 'DATETIME',
        'two_factor_last_used' => $type === 'mysql' ? 'BIGINT' : 'INTEGER',
    ];

    foreach ($userColumns as $column => $dataType) {
        if (tableExists($db, 'users') && !columnExists($db, 'users', $column)) {
            $db->exec("ALTER TABLE users ADD COLUMN $column $dataType");
        }
    }

    // Model attachments table columns (added later for PDF compression tracking)
    $attachmentColumns = [
        'pdf_compressed' => 'INTEGER DEFAULT 0',
    ];

    foreach ($attachmentColumns as $column => $dataType) {
        if (tableExists($db, 'model_attachments') && !columnExists($db, 'model_attachments', $column)) {
            $db->exec("ALTER TABLE model_attachments ADD COLUMN $column $dataType");
        }
    }

    // Plugin repositories: access token for private registries/forges
    // (encrypted at rest when encryption is configured)
    $repoColumns = [
        'auth_token' => 'TEXT',
    ];

    foreach ($repoColumns as $column => $dataType) {
        if (tableExists($db, 'plugin_repositories') && !columnExists($db, 'plugin_repositories', $column)) {
            $db->exec("ALTER TABLE plugin_repositories ADD COLUMN $column $dataType");
        }
    }
}

// =====================
// Index Additions
// =====================

/**
 * Ensure critical indexes exist for performance.
 * Idempotent - checks before creating.
 */
function ensureIndexes($db)
{
    $type = $db->getType();

    $indexes = [
        'idx_activity_created' => ['activity_log', 'created_at'],
        'idx_activity_user' => ['activity_log', 'user_id'],
        'idx_recent_user' => ['recently_viewed', 'user_id, viewed_at'],
        'idx_recent_session' => ['recently_viewed', 'session_id, viewed_at'],
        'idx_api_log_created' => ['api_request_log', 'created_at'],
        'idx_api_log_key' => ['api_request_log', 'api_key_id'],
        'idx_models_filename' => ['models', 'filename'],
        'idx_models_parent_id' => ['models', 'parent_id'],
        'idx_models_created_at' => ['models', 'created_at'],
        'idx_model_tags_model' => ['model_tags', 'model_id'],
        'idx_model_tags_tag' => ['model_tags', 'tag_id'],
        'idx_favorites_user' => ['favorites', 'user_id'],
        'idx_favorites_model' => ['favorites', 'model_id'],
        'idx_models_file_hash' => ['models', 'file_hash'],
        'idx_models_dedup_path' => ['models', 'dedup_path'],
        'idx_models_collection' => ['models', 'collection'],
        'idx_models_download_count' => ['models', 'download_count'],
        'idx_models_name' => ['models', 'name'],
        'idx_integrity_log_model' => ['integrity_log', 'model_id'],
        'idx_integrity_log_created' => ['integrity_log', 'created_at'],
        'idx_scheduler_log_task' => ['scheduler_log', 'task_name'],
        'idx_scheduler_log_created' => ['scheduler_log', 'created_at'],
        'idx_event_log_name' => ['event_log', 'event_name'],
        'idx_event_log_user' => ['event_log', 'user_id'],
        'idx_event_log_created' => ['event_log', 'created_at'],
        'idx_rate_limits_expires' => ['rate_limits', 'expires_at'],
        'idx_sessions_user' => ['sessions', 'user_id'],
        'idx_sessions_activity' => ['sessions', 'last_activity'],
        'idx_sessions_expires' => ['sessions', 'expires_at'],
        'idx_audit_event' => ['audit_log', 'event_type, event_name'],
        'idx_audit_user' => ['audit_log', 'user_id'],
        'idx_audit_resource' => ['audit_log', 'resource_type, resource_id'],
        'idx_audit_created' => ['audit_log', 'created_at'],
        'idx_audit_severity' => ['audit_log', 'severity'],
        'idx_model_links_model' => ['model_links', 'model_id'],
        'idx_model_attachments_model' => ['model_attachments', 'model_id'],
        'idx_model_attachments_type' => ['model_attachments', 'file_type'],
        'idx_password_resets_email' => ['password_resets', 'email'],
        'idx_password_resets_token' => ['password_resets', 'token'],
        'idx_password_resets_expires' => ['password_resets', 'expires_at'],
        'idx_rate_limit_hits_key' => ['rate_limit_hits', 'key_hash'],
        // Composite/FK indexes needed by both SQLite and MySQL (browse grid, category
        // filter, batched first-part lookups, owner joins, annotation cascade).
        'idx_models_parent_created' => ['models', 'parent_id, created_at'],
        'idx_models_parent_original' => ['models', 'parent_id, original_path'],
        'idx_model_categories_composite' => ['model_categories', 'category_id, model_id'],
        'idx_models_user_id' => ['models', 'user_id'],
        'idx_annotations_model_id' => ['annotations', 'model_id'],
    ];

    foreach ($indexes as $indexName => [$table, $columns]) {
        try {
            if (tableExists($db, $table) && !indexExists($db, $table, $indexName)) {
                $db->exec("CREATE INDEX $indexName ON $table($columns)");
            }
        } catch (Exception $e) {
            // Index might already exist under different name, safe to skip
        }
    }

    // MySQL-only composite indexes
    if ($type === 'mysql') {
        $compositeIndexes = [
            'idx_recently_viewed_user_time' => ['recently_viewed', 'user_id, viewed_at'],
            'idx_activity_user_time' => ['activity_log', 'user_id, created_at'],
        ];

        foreach ($compositeIndexes as $indexName => [$table, $columns]) {
            try {
                if (tableExists($db, $table) && !indexExists($db, $table, $indexName)) {
                    $db->exec("CREATE INDEX $indexName ON $table($columns)");
                }
            } catch (Exception $e) {
                // Safe to skip
            }
        }
    }
}

// =====================
// Full-Text Search Setup
// =====================

/**
 * Ensure full-text search indexes and triggers are set up.
 * Handles MySQL FULLTEXT and SQLite FTS5.
 */
function ensureFTS($db)
{
    $type = $db->getType();

    if (!tableExists($db, 'models') || !tableExists($db, 'settings')) {
        return;
    }

    // Check current FTS version
    $currentFtsVersion = 0;
    try {
        $keyCol = $type === 'mysql' ? '`key`' : 'key';
        $stmt = $db->prepare("SELECT value FROM settings WHERE $keyCol = 'fts_version'");
        $stmt->execute();
        $currentFtsVersion = (int)($stmt->fetchColumn() ?: '0');
    } catch (Exception $e) {
        // Settings table might not have fts_version yet
    }

    if ($type === 'mysql') {
        // MySQL: Create/upgrade FULLTEXT index
        try {
            $result = $db->query("SHOW INDEX FROM models WHERE Key_name = 'idx_models_fulltext'");
            $hasFulltext = ($result->fetch() !== false);

            if (!$hasFulltext) {
                $db->exec('CREATE FULLTEXT INDEX idx_models_fulltext ON models(name, description, creator, notes)');
            } elseif ($currentFtsVersion < 2) {
                // Upgrade to include notes
                $db->exec('ALTER TABLE models DROP INDEX idx_models_fulltext');
                $db->exec('CREATE FULLTEXT INDEX idx_models_fulltext ON models(name, description, creator, notes)');
            }

            if ($currentFtsVersion < 3) {
                $db->exec("INSERT INTO settings (`key`, `value`, updated_at) VALUES ('fts_version', '3', NOW()) ON DUPLICATE KEY UPDATE `value` = '3', updated_at = NOW()");
            }
        } catch (Exception $e) {
            if (function_exists('logDebug')) {
                logDebug('FTS MySQL setup skipped', ['error' => $e->getMessage()]);
            }
        }
    } elseif ($type === 'sqlite') {
        // SQLite: Create/upgrade FTS5 virtual table
        if ($currentFtsVersion < 3) {
            try {
                $db->exec('DROP TRIGGER IF EXISTS models_fts_insert');
                $db->exec('DROP TRIGGER IF EXISTS models_fts_delete');
                $db->exec('DROP TRIGGER IF EXISTS models_fts_update');
                $db->exec('DROP TABLE IF EXISTS models_fts');

                $db->exec("
                    CREATE VIRTUAL TABLE models_fts USING fts5(
                        name, description, creator, notes,
                        content='models',
                        content_rowid='id'
                    )
                ");

                $db->exec("
                    INSERT INTO models_fts(rowid, name, description, creator, notes)
                    SELECT id, name, COALESCE(description, ''), COALESCE(creator, ''), COALESCE(notes, '')
                    FROM models
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_insert AFTER INSERT ON models
                    BEGIN
                        INSERT INTO models_fts(rowid, name, description, creator, notes)
                        VALUES (NEW.id, NEW.name, COALESCE(NEW.description, ''), COALESCE(NEW.creator, ''), COALESCE(NEW.notes, ''));
                    END
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_delete AFTER DELETE ON models
                    BEGIN
                        DELETE FROM models_fts WHERE rowid = OLD.id;
                    END
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_update AFTER UPDATE ON models
                    BEGIN
                        UPDATE models_fts
                        SET name = NEW.name,
                            description = COALESCE(NEW.description, ''),
                            creator = COALESCE(NEW.creator, ''),
                            notes = COALESCE(NEW.notes, '')
                        WHERE rowid = NEW.id;
                    END
                ");

                $db->exec("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('fts_version', '3', CURRENT_TIMESTAMP)");
            } catch (Exception $e) {
                if (function_exists('logWarning')) {
                    logWarning('FTS SQLite setup skipped', ['error' => $e->getMessage()]);
                }
            }
        }
    }
}

// =====================
// Migration Orchestrator
// =====================

/**
 * Run all migrations: table creation, column additions, indexes, FTS, default settings.
 * Replaces the old runMigrations() function.
 */
function runAllMigrations($db)
{
    $type = $db->getType();

    // Set busy timeout for SQLite
    if ($type === 'sqlite') {
        $db->exec('PRAGMA busy_timeout = 10000');
    }

    // If core tables don't exist, initialize full schema
    if (!tableExists($db, 'users')) {
        initializeDatabase($db);
        initializeDefaultSettings($db);
        return;
    }

    // 1. Run table creation migrations (derived from getSchema)
    require_once __DIR__ . '/migrations.php';
    $migrations = getMigrationList();
    foreach ($migrations as $migration) {
        if (!$migration['check']($db)) {
            try {
                $migration['apply']($db);
                if (function_exists('logInfo')) {
                    logInfo('Migration applied: ' . $migration['name']);
                }
            } catch (Exception $e) {
                if (function_exists('logWarning')) {
                    logWarning('Migration failed: ' . $migration['name'], ['error' => $e->getMessage()]);
                }
            }
        }
    }

    // 2. Ensure all columns exist on core tables
    ensureColumns($db);

    // 3. Ensure indexes exist
    ensureIndexes($db);

    // 4. Set up full-text search
    ensureFTS($db);

    // 5. Ensure default settings
    initializeDefaultSettings($db);
}
