<?php

/**
 * Database Migrations
 *
 * Shared migration definitions for cli/migrate.php, app/admin/database.php, app/pages/update.php.
 * Uses helper functions to generate MySQL/SQLite CREATE TABLE and ALTER TABLE statements.
 */

/**
 * Convert a MySQL column definition to SQLite syntax via regex.
 */
function mysqlToSqlite(string $colDef): string
{
    $replacements = [
        '/BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT/i' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        '/INT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i'      => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        '/TINYINT\(\d+\)/i'                             => 'INTEGER',
        '/TINYINT/i'                                    => 'INTEGER',
        '/BIGINT UNSIGNED/i'                            => 'INTEGER',
        '/BIGINT/i'                                     => 'INTEGER',
        '/\bINT\b(?!EGER)/i'                            => 'INTEGER',
        '/VARCHAR\(\d+\)/i'                             => 'TEXT',
        '/DECIMAL\(\d+,\d+\)/i'                         => 'REAL',
        '/DOUBLE/i'                                     => 'REAL',
        '/TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP/i' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        '/TIMESTAMP/i'                                  => 'DATETIME',
    ];
    $result = $colDef;
    foreach ($replacements as $pattern => $replacement) {
        $result = preg_replace($pattern, $replacement, $result);
    }
    return $result;
}

/**
 * Build a CREATE TABLE migration entry.
 *
 * @param string $name        Migration display name
 * @param string $desc        Migration description
 * @param string $table       Table name
 * @param array  $columns     MySQL-syntax column definitions
 * @param array  $constraints FOREIGN KEY lines (same syntax for both engines)
 * @param array  $indexes     ['idx_name' => 'col(s)'] for CREATE INDEX
 * @param array  $options     uniqueKeys, mysqlInlineIndexes, sqliteOverrides, primaryKey
 */
function createTableMigration(
    string $name,
    string $desc,
    string $table,
    array $columns,
    array $constraints = [],
    array $indexes = [],
    array $options = []
): array {
    return [
        'name' => $name,
        'description' => $desc,
        'check' => fn($db) => tableExists($db, $table),
        'apply' => function ($db) use ($table, $columns, $constraints, $indexes, $options) {
            $type = $db->getType();
            $uniqueKeys = $options['uniqueKeys'] ?? [];
            $mysqlInlineIndexes = $options['mysqlInlineIndexes'] ?? [];
            $sqliteOverrides = $options['sqliteOverrides'] ?? [];
            $primaryKey = $options['primaryKey'] ?? null;

            $colLines = [];
            foreach ($columns as $key => $col) {
                $colName = is_string($key) ? $key : null;
                if ($type === 'mysql') {
                    $colLines[] = $col;
                } else {
                    if ($colName && isset($sqliteOverrides[$colName])) {
                        $colLines[] = $sqliteOverrides[$colName];
                    } else {
                        $colLines[] = mysqlToSqlite($col);
                    }
                }
            }

            if ($primaryKey !== null) {
                $colLines[] = "PRIMARY KEY ($primaryKey)";
            }

            foreach ($uniqueKeys as $keyName => $keyCols) {
                if ($type === 'mysql') {
                    $colLines[] = "UNIQUE KEY $keyName ($keyCols)";
                } else {
                    $colLines[] = "UNIQUE ($keyCols)";
                }
            }

            foreach ($constraints as $fk) {
                $colLines[] = $fk;
            }

            if ($type === 'mysql') {
                foreach ($mysqlInlineIndexes as $idx) {
                    $colLines[] = $idx;
                }
            }

            $body = implode(",\n                        ", $colLines);
            $suffix = $type === 'mysql' ? ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ')';
            $sql = "CREATE TABLE $table (\n                        $body\n                    $suffix";
            $db->exec($sql);

            // Create indexes
            foreach ($indexes as $idxName => $idxCols) {
                $db->exec("CREATE INDEX $idxName ON $table($idxCols)");
            }

            // SQLite: convert MySQL inline indexes to separate CREATE INDEX
            if ($type !== 'mysql') {
                foreach ($mysqlInlineIndexes as $idx) {
                    if (preg_match('/INDEX\s+(\w+)\s*\(([^)]+)\)/i', $idx, $m)) {
                        $db->exec("CREATE INDEX {$m[1]} ON $table({$m[2]})");
                    }
                }
            }
        }
    ];
}

/**
 * Build an ALTER TABLE ADD COLUMN migration entry.
 *
 * @param string $name     Migration display name
 * @param string $desc     Migration description
 * @param string $table    Table name
 * @param string $checkCol Column to check for existence (skip if already present)
 * @param array  $columns  MySQL-syntax ALTER TABLE ADD COLUMN definitions (without "ALTER TABLE ... ADD COLUMN")
 * @param array  $indexes  ['idx_name' => 'col(s)'] for CREATE INDEX
 */
function addColumnsMigration(
    string $name,
    string $desc,
    string $table,
    string $checkCol,
    array $columns,
    array $indexes = []
): array {
    return [
        'name' => $name,
        'description' => $desc,
        'check' => fn($db) => columnExists($db, $table, $checkCol),
        'apply' => function ($db) use ($table, $columns, $indexes) {
            $type = $db->getType();
            foreach ($columns as $col) {
                $def = ($type === 'mysql') ? $col : mysqlToSqlite($col);
                $db->exec("ALTER TABLE $table ADD COLUMN $def");
            }
            foreach ($indexes as $idxName => $idxCols) {
                $db->exec("CREATE INDEX $idxName ON $table($idxCols)");
            }
        }
    ];
}

function getMigrationList()
{
    return [
        // Core tables
        createTableMigration('Tags table', 'Stores tag names and colors for model organization', 'tags', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'name VARCHAR(100) NOT NULL UNIQUE',
            'color VARCHAR(7) DEFAULT "#6366f1"',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ]),
        createTableMigration('Model-Tags junction table', 'Links models to their tags (many-to-many)', 'model_tags', [
            'model_id INT NOT NULL',
            'tag_id INT NOT NULL',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
            'FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE',
        ], [], ['primaryKey' => 'model_id, tag_id']),
        createTableMigration('Favorites table', 'Tracks user favorites/bookmarks', 'favorites', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'user_id INT NOT NULL',
            'model_id INT NOT NULL',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
        ], [], ['uniqueKeys' => ['unique_favorite' => 'user_id, model_id']]),
        createTableMigration('Activity log table', 'Audit trail for user actions', 'activity_log', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'user_id INT',
            'action VARCHAR(50) NOT NULL',
            'entity_type VARCHAR(50)',
            'entity_id INT',
            'entity_name VARCHAR(255)',
            'details TEXT',
            'ip_address VARCHAR(45)',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
        ], [
            'idx_activity_created' => 'created_at',
            'idx_activity_user' => 'user_id',
        ]),
        createTableMigration('Recently viewed table', 'Tracks recently viewed models per user/session', 'recently_viewed', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'user_id INT',
            'session_id VARCHAR(64)',
            'model_id INT NOT NULL',
            'viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
        ], [
            'idx_recently_viewed_user' => 'user_id, viewed_at',
        ]),
        createTableMigration('Print queue table', 'Manages print queue items with status and priority', 'print_queue', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'user_id INT NOT NULL',
            'model_id INT NOT NULL',
            'part_id INT',
            'priority INT DEFAULT 0',
            'status VARCHAR(20) DEFAULT "queued"',
            'notes TEXT',
            'added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
        ]),
        // Model columns
        addColumnsMigration('Models: download_count column', 'Track download counts per model', 'models', 'download_count', [
            'download_count INT DEFAULT 0',
        ]),
        addColumnsMigration('Models: license column', 'Store license type for models', 'models', 'license', [
            'license VARCHAR(50)',
        ]),
        addColumnsMigration('Models: is_archived column', 'Soft archive models without deletion', 'models', 'is_archived', [
            'is_archived TINYINT DEFAULT 0',
        ]),
        addColumnsMigration('Models: print tracking columns', 'Track printed status and notes per part', 'models', 'is_printed', [
            'is_printed TINYINT DEFAULT 0',
            'printed_at TIMESTAMP NULL',
            'notes TEXT',
        ]),
        addColumnsMigration('Models: dimension columns', 'Store calculated model dimensions', 'models', 'dim_x', [
            'dim_x DECIMAL(10,2)',
            'dim_y DECIMAL(10,2)',
            'dim_z DECIMAL(10,2)',
            'volume DECIMAL(15,2)',
        ]),
        // API
        createTableMigration('API keys table', 'Store API keys for programmatic access', 'api_keys', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'user_id INT NOT NULL',
            'name VARCHAR(255) NOT NULL',
            'key_hash VARCHAR(64) NOT NULL UNIQUE',
            'key_prefix VARCHAR(12) NOT NULL',
            'permissions TEXT',
            'is_active TINYINT DEFAULT 1',
            'expires_at TIMESTAMP NULL',
            'last_used_at TIMESTAMP NULL',
            'request_count INT DEFAULT 0',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ]),
        createTableMigration('API request log table', 'Log API requests for analytics', 'api_request_log', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'api_key_id INT NOT NULL',
            'method VARCHAR(10) NOT NULL',
            'endpoint VARCHAR(255) NOT NULL',
            'ip_address VARCHAR(45)',
            'user_agent VARCHAR(500)',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE',
        ], [
            'idx_api_log_created' => 'created_at',
        ]),
        // Core features
        createTableMigration('Print photos table', 'Store photos of printed models', 'print_photos', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'user_id INT',
            'filename VARCHAR(255) NOT NULL',
            'file_path VARCHAR(500) NOT NULL',
            'caption TEXT',
            'is_primary TINYINT DEFAULT 0',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
        ]),
        createTableMigration('Printers table', 'Store printer profiles and specifications', 'printers', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'user_id INT',
            'name VARCHAR(255) NOT NULL',
            'manufacturer VARCHAR(255)',
            'model VARCHAR(255)',
            'bed_x DECIMAL(10,2)',
            'bed_y DECIMAL(10,2)',
            'bed_z DECIMAL(10,2)',
            'print_type VARCHAR(50) DEFAULT "fdm"',
            'is_default TINYINT DEFAULT 0',
            'notes TEXT',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ]),
        createTableMigration('Model ratings table', 'Store user ratings for models', 'model_ratings', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'user_id INT NOT NULL',
            'rating TINYINT NOT NULL',
            'review TEXT',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ], [], ['uniqueKeys' => ['unique_rating' => 'model_id, user_id']]),
        createTableMigration('Folders table', 'Hierarchical folder organization for models', 'folders', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'parent_id INT',
            'user_id INT',
            'name VARCHAR(255) NOT NULL',
            'description TEXT',
            'color VARCHAR(7)',
            'sort_order INT DEFAULT 0',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE',
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ]),
        addColumnsMigration('Models: folder_id column', 'Link models to folders', 'models', 'folder_id', [
            'folder_id INT',
        ]),
        addColumnsMigration('Models: approval columns', 'Support upload approval workflow', 'models', 'approval_status', [
            "approval_status VARCHAR(20) DEFAULT 'approved'",
            'approved_by INT',
            'approved_at TIMESTAMP NULL',
        ]),
        // Users: storage limit columns (same SQL for both engines)
        [
            'name' => 'Users: storage limit columns',
            'description' => 'Per-user storage and model limits',
            'check' => fn($db) => columnExists($db, 'users', 'storage_limit_mb'),
            'apply' => function ($db) {
                $db->exec('ALTER TABLE users ADD COLUMN storage_limit_mb INTEGER DEFAULT 0');
                $db->exec('ALTER TABLE users ADD COLUMN model_limit INTEGER DEFAULT 0');
            }
        ],
        // Two-Factor Authentication
        addColumnsMigration('Users: two-factor authentication columns', 'TOTP-based 2FA with backup codes', 'users', 'two_factor_enabled', [
            'two_factor_secret VARCHAR(64)',
            'two_factor_backup_codes TEXT',
            'two_factor_enabled TINYINT DEFAULT 0',
            'two_factor_enabled_at TIMESTAMP NULL',
        ]),
        // File Integrity
        addColumnsMigration('Models: integrity hash columns', 'SHA-256 checksums for file integrity verification', 'models', 'integrity_hash', [
            'integrity_hash VARCHAR(64)',
            'integrity_checked_at TIMESTAMP NULL',
        ]),
        createTableMigration('Integrity log table', 'Log file integrity issues (missing/corrupted files)', 'integrity_log', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'status VARCHAR(20) NOT NULL',
            'message TEXT',
            'details TEXT',
            'resolved TINYINT DEFAULT 0',
            'resolution TEXT',
            'resolved_at TIMESTAMP NULL',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
        ], [
            'idx_integrity_log_model' => 'model_id',
            'idx_integrity_log_created' => 'created_at',
        ]),
        // Scheduler
        createTableMigration('Scheduler log table', 'Track scheduled task execution history', 'scheduler_log', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'task_name VARCHAR(100) NOT NULL',
            'status VARCHAR(20) NOT NULL',
            'output TEXT',
            'duration_ms INT',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [], [
            'idx_scheduler_log_task' => 'task_name',
            'idx_scheduler_log_created' => 'created_at',
        ]),
        // Event System
        createTableMigration('Event log table', 'Store emitted events for audit and replay', 'event_log', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'event_name VARCHAR(100) NOT NULL',
            'user_id INT',
            'data TEXT',
            'ip_address VARCHAR(45)',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
        ], [
            'idx_event_log_name' => 'event_name',
            'idx_event_log_user' => 'user_id',
            'idx_event_log_created' => 'created_at',
        ]),
        // Rate Limiting
        createTableMigration('Rate limit table', 'Track rate limit counters per IP/key', 'rate_limits', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'identifier VARCHAR(100) NOT NULL',
            'endpoint VARCHAR(255) NOT NULL',
            'request_count INT DEFAULT 1',
            'window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [], [
            'idx_rate_limits_window' => 'window_start',
        ], ['uniqueKeys' => ['unique_rate_limit' => 'identifier, endpoint']]),
        // Sessions
        createTableMigration('Sessions table', 'Database-backed session storage', 'sessions', [
            'id VARCHAR(128) PRIMARY KEY',
            'user_id INT',
            'data TEXT',
            'ip_address VARCHAR(45)',
            'user_agent VARCHAR(500)',
            'last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ], [
            'idx_sessions_user' => 'user_id',
            'idx_sessions_activity' => 'last_activity',
        ]),
        // Model Analysis
        createTableMigration('Model analysis table', 'Store automated model analysis results (overhangs, printability)', 'model_analysis', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL UNIQUE',
            'overhang_percentage DECIMAL(5,2)',
            'support_required TINYINT DEFAULT 0',
            'optimal_orientation TEXT',
            'thin_wall_warnings TEXT',
            'printability_score INT',
            'analysis_warnings TEXT',
            'estimated_print_time INT',
            'estimated_filament_grams DECIMAL(10,2)',
            'analyzed_at DATETIME',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
        ], [
            'idx_model_analysis_score' => 'printability_score',
        ]),
        // Remix/Fork Tracking
        addColumnsMigration('Related models: remix columns', 'Track remix/fork relationships between models', 'related_models', 'is_remix', [
            'is_remix TINYINT DEFAULT 0',
            'remix_notes TEXT',
            'created_by INT',
        ]),
        addColumnsMigration('Models: remix source columns', 'Track original source for remixed models', 'models', 'remix_of', [
            'remix_of INT',
            'external_source_url VARCHAR(500)',
            'external_source_id VARCHAR(100)',
        ]),
        // Import Jobs
        createTableMigration('Import jobs table', 'Track bulk import jobs from external sources', 'import_jobs', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'source_url VARCHAR(500) NOT NULL',
            'source_type VARCHAR(50) NOT NULL',
            'status VARCHAR(20) DEFAULT "pending"',
            'total_items INT DEFAULT 0',
            'imported_items INT DEFAULT 0',
            'failed_items INT DEFAULT 0',
            'settings TEXT',
            'error_log TEXT',
            'created_by INT NOT NULL',
            'started_at DATETIME',
            'completed_at DATETIME',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE',
        ], [
            'idx_import_jobs_status' => 'status',
            'idx_import_jobs_user' => 'created_by',
        ]),
        createTableMigration('Import job items table', 'Track individual items within an import job', 'import_job_items', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'job_id INT NOT NULL',
            'external_id VARCHAR(100)',
            'external_url VARCHAR(500)',
            'name VARCHAR(255)',
            'status VARCHAR(20) DEFAULT "pending"',
            'model_id INT',
            'error_message TEXT',
            'metadata TEXT',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE',
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE SET NULL',
        ], [
            'idx_import_items_job' => 'job_id',
            'idx_import_items_status' => 'status',
        ]),
        // Batch conversion queue
        createTableMigration('Conversion queue table', 'Queue for batch STL to 3MF conversions', 'conversion_queue', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'source_format VARCHAR(10) NOT NULL',
            'target_format VARCHAR(10) NOT NULL',
            'status VARCHAR(20) DEFAULT "pending"',
            'priority INT DEFAULT 0',
            'error_message TEXT',
            'queued_by INT',
            'started_at DATETIME',
            'completed_at DATETIME',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
            'FOREIGN KEY (queued_by) REFERENCES users(id) ON DELETE SET NULL',
        ], [
            'idx_conversion_status' => 'status, priority',
        ]),
        // Advanced Audit Logging
        createTableMigration('Audit log table', 'Enhanced audit logging for compliance and security', 'audit_log', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'event_type VARCHAR(50) NOT NULL',
            'event_name VARCHAR(100) NOT NULL',
            'severity VARCHAR(20) DEFAULT "info"',
            'user_id INT',
            'ip_address VARCHAR(45)',
            'user_agent VARCHAR(500)',
            'resource_type VARCHAR(50)',
            'resource_id INT',
            'resource_name VARCHAR(255)',
            'old_value TEXT',
            'new_value TEXT',
            'metadata TEXT',
            'session_id VARCHAR(128)',
            'request_id VARCHAR(36)',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
        ], [
            'idx_audit_event' => 'event_type, event_name',
            'idx_audit_user' => 'user_id',
            'idx_audit_resource' => 'resource_type, resource_id',
            'idx_audit_created' => 'created_at',
            'idx_audit_severity' => 'severity',
        ]),
        createTableMigration('Share links table', 'Public share links for models', 'share_links', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'token VARCHAR(64) NOT NULL UNIQUE',
            'password_hash VARCHAR(255)',
            'expires_at DATETIME',
            'download_limit INT',
            'download_count INT DEFAULT 0',
            'is_active TINYINT DEFAULT 1',
            'created_by INT NOT NULL',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
            'FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE',
        ], [
            'idx_share_links_token' => 'token',
        ]),
        createTableMigration('Print history table', 'Track print history with settings and results', 'print_history', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'user_id INT NOT NULL',
            'printer_id INT',
            'status VARCHAR(20) DEFAULT "completed"',
            'filament_type VARCHAR(50)',
            'filament_color VARCHAR(50)',
            'filament_used_grams DECIMAL(10,2)',
            'print_time_minutes INT',
            'layer_height DECIMAL(4,2)',
            'infill_percentage INT',
            'notes TEXT',
            'rating INT',
            'printed_at DATETIME',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
            'FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL',
        ], [
            'idx_print_history_model' => 'model_id',
            'idx_print_history_user' => 'user_id',
        ]),
        // Special: Remove license settings (custom check/apply)
        [
            'name' => 'Remove license settings',
            'description' => 'Clean up license_email, license_key, license_cache, license_last_sync from settings',
            'check' => function ($db) {
                $type = $db->getType();
                $keyCol = $type === 'mysql' ? '`key`' : 'key';
                $result = $db->querySingle("SELECT COUNT(*) FROM settings WHERE $keyCol IN ('license_email','license_key','license_cache','license_last_sync')");
                return (int)$result === 0;
            },
            'apply' => function ($db) {
                $type = $db->getType();
                $keyCol = $type === 'mysql' ? '`key`' : 'key';
                $db->exec("DELETE FROM settings WHERE $keyCol IN ('license_email','license_key','license_cache','license_last_sync')");
            }
        ],
        createTableMigration('Password resets table', 'Stores password reset tokens for forgot password flow', 'password_resets', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'email VARCHAR(255) NOT NULL',
            'token VARCHAR(64) NOT NULL UNIQUE',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'expires_at TIMESTAMP NOT NULL',
            'used_at TIMESTAMP NULL',
        ], [], [], [
            'mysqlInlineIndexes' => [
                'INDEX idx_password_resets_email (email)',
                'INDEX idx_password_resets_token (token)',
                'INDEX idx_password_resets_expires (expires_at)',
            ],
        ]),
        createTableMigration('Model links table', 'Stores external links attached to models (docs, videos, forums, repos, etc.)', 'model_links', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'title VARCHAR(255) NOT NULL',
            'url TEXT NOT NULL',
            'link_type VARCHAR(50) DEFAULT "other"',
            'sort_order INT DEFAULT 0',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
        ], [], [
            'mysqlInlineIndexes' => [
                'INDEX idx_model_links_model (model_id)',
            ],
        ]),
        // Special: Fix rate_limits table schema (custom check/apply with DROP TABLE)
        [
            'name' => 'Fix rate_limits table schema',
            'description' => 'Update rate_limits table to match RateLimitMiddleware requirements',
            'check' => function ($db) {
                if (!tableExists($db, 'rate_limits')) {
                    return true; // Will be created by earlier migration
                }
                // Check if new schema columns exist
                return columnExists($db, 'rate_limits', 'key_name') &&
                       columnExists($db, 'rate_limits', 'expires_at');
            },
            'apply' => function ($db) {
                $type = $db->getType();

                // Drop old table and create new one with correct schema
                $db->exec('DROP TABLE IF EXISTS rate_limits');

                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE rate_limits (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        key_name VARCHAR(255) NOT NULL UNIQUE,
                        data TEXT,
                        expires_at INT NOT NULL,
                        INDEX idx_rate_limits_expires (expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE rate_limits (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        key_name TEXT NOT NULL UNIQUE,
                        data TEXT,
                        expires_at INTEGER NOT NULL
                    )');
                    $db->exec('CREATE INDEX idx_rate_limits_expires ON rate_limits(expires_at)');
                }
            }
        ],
        // Special: Performance indexes (conditional multi-table index creation)
        [
            'name' => 'Performance indexes',
            'description' => 'Add indexes on frequently queried columns for faster lookups',
            'check' => function ($db) {
                return indexExists($db, 'models', 'idx_models_parent_id');
            },
            'apply' => function ($db) {
                $type = $db->getType();

                // Index on models.parent_id (used in multi-part queries)
                if (!indexExists($db, 'models', 'idx_models_parent_id')) {
                    $db->exec('CREATE INDEX idx_models_parent_id ON models(parent_id)');
                }

                // Index on models.created_at (used for sorting)
                if (!indexExists($db, 'models', 'idx_models_created_at')) {
                    $db->exec('CREATE INDEX idx_models_created_at ON models(created_at)');
                }

                // Index on model_tags.model_id (many-to-many queries)
                if (!indexExists($db, 'model_tags', 'idx_model_tags_model_id')) {
                    $db->exec('CREATE INDEX idx_model_tags_model_id ON model_tags(model_id)');
                }

                // Index on model_categories.model_id (category filtering)
                if (!indexExists($db, 'model_categories', 'idx_model_categories_model_id')) {
                    $db->exec('CREATE INDEX idx_model_categories_model_id ON model_categories(model_id)');
                }

                // Index on activity_log.created_at (retention/analytics)
                if (tableExists($db, 'activity_log') && !indexExists($db, 'activity_log', 'idx_activity_log_created_at')) {
                    $db->exec('CREATE INDEX idx_activity_log_created_at ON activity_log(created_at)');
                }

                // Index on favorites.user_id (user favorites lookup)
                if (tableExists($db, 'favorites') && !indexExists($db, 'favorites', 'idx_favorites_user_id')) {
                    $db->exec('CREATE INDEX idx_favorites_user_id ON favorites(user_id)');
                }
            }
        ],
        createTableMigration('Model attachments table', 'Stores document and image attachments for models', 'model_attachments', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'filename VARCHAR(255) NOT NULL',
            'file_path TEXT NOT NULL',
            'file_type VARCHAR(20) NOT NULL',
            'mime_type VARCHAR(100)',
            'file_size INT',
            'original_filename VARCHAR(255)',
            'display_order INT DEFAULT 0',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
        ], [], [
            'mysqlInlineIndexes' => [
                'INDEX idx_model_attachments_model (model_id)',
                'INDEX idx_model_attachments_type (file_type)',
            ],
        ]),
        createTableMigration('Collections table', 'Stores named collections for organizing models', 'collections', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'col_name' => 'name VARCHAR(255) NOT NULL UNIQUE',
            'description TEXT',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [], [], [
            'sqliteOverrides' => [
                'col_name' => 'name VARCHAR(255) NOT NULL UNIQUE',
            ],
        ]),
        createTableMigration('Annotations table', 'Stores 3D model annotations with position and normal data', 'annotations', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'model_id INT NOT NULL',
            'user_id INT NOT NULL',
            'position_x DOUBLE NOT NULL',
            'position_y DOUBLE NOT NULL',
            'position_z DOUBLE NOT NULL',
            'normal_x DOUBLE DEFAULT 0',
            'normal_y DOUBLE DEFAULT 0',
            'normal_z DOUBLE DEFAULT 1',
            'content TEXT NOT NULL',
            'color VARCHAR(7) DEFAULT "#ff0000"',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ], [
            'FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE',
            'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ]),
        // Special: Sessions table expires_at column (conditional tableExists guard)
        [
            'name' => 'Sessions table expires_at column',
            'description' => 'Add expires_at column to sessions table if missing',
            'check' => function ($db) {
                if (!tableExists($db, 'sessions')) {
                    return true;
                }
                return columnExists($db, 'sessions', 'expires_at');
            },
            'apply' => function ($db) {
                if (!tableExists($db, 'sessions')) {
                    return;
                }
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE sessions ADD COLUMN expires_at INT NOT NULL DEFAULT 0');
                } else {
                    $db->exec('ALTER TABLE sessions ADD COLUMN expires_at INTEGER NOT NULL DEFAULT 0');
                }
                if (!indexExists($db, 'sessions', 'idx_sessions_expires')) {
                    $db->exec('CREATE INDEX idx_sessions_expires ON sessions(expires_at)');
                }
            }
        ],
        // Plugin system
        createTableMigration('Plugins table', 'Plugin system - installed plugins', 'plugins', [
            'id VARCHAR(100) PRIMARY KEY',
            'name VARCHAR(200) NOT NULL',
            'version VARCHAR(20) NOT NULL DEFAULT \'1.0.0\'',
            'description TEXT',
            'author VARCHAR(200)',
            'is_active TINYINT(1) NOT NULL DEFAULT 0',
            'settings TEXT',
            'installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ]),
        createTableMigration('Plugin repositories table', 'Plugin system - repository sources', 'plugin_repositories', [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'name VARCHAR(200) NOT NULL',
            'url VARCHAR(500) NOT NULL',
            'is_official TINYINT(1) NOT NULL DEFAULT 0',
            'last_fetched TIMESTAMP NULL',
            'registry_cache TEXT',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ]),
        // Model ownership
        addColumnsMigration('Models user_id column', 'Add user_id to models for ownership tracking', 'models', 'user_id', [
            'user_id INT NULL',
        ], [
            'idx_models_user_id' => 'user_id',
        ]),
        // Migrate part files into subdirectories matching original_path folders
        [
            'name' => 'Migrate part files to folder subdirectories',
            'description' => 'Move part files into subdirectories matching their original_path folder structure and update file_path',
            'check' => function ($db) {
                // Check if any parts have folder structure in original_path but flat file_path
                $sql = "SELECT COUNT(*) as cnt FROM models
                        WHERE parent_id IS NOT NULL
                        AND original_path IS NOT NULL
                        AND original_path LIKE '%/%'
                        AND file_path NOT LIKE '%/' || REPLACE(original_path, '\\', '/') ";
                // For MySQL, use CONCAT instead of ||
                $type = $db->getType();
                if ($type === 'mysql') {
                    $sql = "SELECT COUNT(*) as cnt FROM models
                            WHERE parent_id IS NOT NULL
                            AND original_path IS NOT NULL
                            AND original_path LIKE '%/%'
                            AND file_path NOT LIKE CONCAT('%/', REPLACE(original_path, '\\\\', '/'))";
                }
                $stmt = $db->prepare($sql);
                $result = $stmt->execute();
                $row = $result->fetchArray(PDO::FETCH_ASSOC);
                return ($row['cnt'] ?? 0) === 0;
            },
            'apply' => function ($db) {
                $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

                // Find parts with folder structure in original_path where file_path is flat
                $stmt = $db->prepare("SELECT id, file_path, original_path FROM models
                                      WHERE parent_id IS NOT NULL
                                      AND original_path IS NOT NULL
                                      AND original_path LIKE '%/%'");
                $result = $stmt->execute();

                $moved = 0;
                $skipped = 0;
                while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                    $originalPath = str_replace('\\', '/', $row['original_path']);
                    $subDir = dirname($originalPath);
                    $filename = basename($originalPath);

                    // Current file_path is like "assets/{hash}/filename.stl"
                    // New file_path should be "assets/{hash}/{subDir}/filename.stl"
                    $currentFilePath = $row['file_path'];
                    $currentDir = dirname($currentFilePath);
                    $currentFilename = basename($currentFilePath);

                    // Build expected new path
                    $newFilePath = $currentDir . '/' . $subDir . '/' . $currentFilename;

                    // Skip if file_path already contains the subfolder
                    if (strpos($currentFilePath, '/' . $subDir . '/') !== false) {
                        $skipped++;
                        continue;
                    }

                    // Physical file paths — file_path stores "assets/..." but files
                    // are physically in "storage/assets/..." on disk
                    $storagePrefix = (strpos($currentFilePath, 'assets/') === 0) ? 'storage/' : '';
                    $oldDiskPath = $basePath . '/' . $storagePrefix . $currentFilePath;
                    $newDiskDir = $basePath . '/' . $storagePrefix . $currentDir . '/' . $subDir;
                    $newDiskPath = $newDiskDir . '/' . $currentFilename;

                    // Skip if source file doesn't exist
                    if (!file_exists($oldDiskPath)) {
                        $skipped++;
                        continue;
                    }

                    // Create subdirectory if needed
                    if (!is_dir($newDiskDir)) {
                        mkdir($newDiskDir, 0755, true);
                    }

                    // Move the file
                    if (rename($oldDiskPath, $newDiskPath)) {
                        // Update file_path in database
                        $updateStmt = $db->prepare('UPDATE models SET file_path = :new_path WHERE id = :id');
                        $updateStmt->bindValue(':new_path', $newFilePath, PDO::PARAM_STR);
                        $updateStmt->bindValue(':id', $row['id'], PDO::PARAM_INT);
                        $updateStmt->execute();
                        $moved++;
                    } else {
                        $skipped++;
                    }
                }

                if (function_exists('logInfo')) {
                    logInfo("Part file migration: moved $moved files, skipped $skipped");
                }
            }
        ],
        // Performance indexes for sorting and filtering
        [
            'name' => 'Performance indexes for models table',
            'description' => 'Add indexes for download_count, name, and is_archived columns used in sorting and filtering',
            'check' => function ($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $stmt = $db->prepare("SHOW INDEX FROM models WHERE Key_name = 'idx_models_download_count'");
                    $stmt->execute();
                    return $stmt->fetch() !== false;
                } else {
                    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_models_download_count'");
                    $result = $stmt->execute();
                    return $result->fetchArray() !== false;
                }
            },
            'apply' => function ($db) {
                $db->exec('CREATE INDEX idx_models_download_count ON models(download_count)');
                $db->exec('CREATE INDEX idx_models_name ON models(name)');
                try {
                    $db->exec('CREATE INDEX idx_models_is_archived ON models(is_archived)');
                } catch (Exception $e) {
                }
                try {
                    $db->exec('CREATE INDEX idx_model_categories_composite ON model_categories(category_id, model_id)');
                } catch (Exception $e) {
                }
            }
        ],

        // Fix jobs table TEXT columns with defaults for MySQL compatibility
        [
            'name' => 'Fix jobs table VARCHAR columns',
            'description' => 'Convert TEXT columns with defaults to VARCHAR for MySQL strict mode',
            'check' => function ($db) {
                if ($db->getType() !== 'mysql' || !tableExists($db, 'jobs')) {
                    return true; // Only needed for MySQL, skip if not applicable
                }
                try {
                    $result = $db->query("SHOW COLUMNS FROM jobs WHERE Field = 'queue'");
                    $col = $result->fetch();
                    return $col && stripos($col['Type'], 'varchar') !== false;
                } catch (Exception $e) {
                    return true;
                }
            },
            'apply' => function ($db) {
                if ($db->getType() !== 'mysql') return;
                try {
                    $db->exec("ALTER TABLE jobs MODIFY COLUMN queue VARCHAR(255) NOT NULL DEFAULT 'default'");
                    $db->exec("ALTER TABLE jobs MODIFY COLUMN job_class VARCHAR(255) NOT NULL");
                    $db->exec("ALTER TABLE jobs MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
                    logInfo('Migration: Fixed jobs table VARCHAR columns for MySQL');
                } catch (Exception $e) {
                    logWarning('Migration: Fix jobs VARCHAR skipped', ['error' => $e->getMessage()]);
                }
            }
        ],
    ];
}
