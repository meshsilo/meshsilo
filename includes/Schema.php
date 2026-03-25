<?php
// Database schema definitions and migration helpers

/**
 * Generate database schema for the given database type.
 * Single source of truth — dialect differences handled via PHP interpolation.
 */
function getSchema(string $type = 'sqlite'): string
{
    $mysql = $type === 'mysql';
    $autoId = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int = $mysql ? 'INT' : 'INTEGER';
    $tinyint = $mysql ? 'TINYINT' : 'INTEGER';
    $bigint = $mysql ? 'BIGINT' : 'INTEGER';
    $varchar = fn(int $len) => $mysql ? "VARCHAR($len)" : 'TEXT';
    $ts = $mysql ? 'TIMESTAMP' : 'DATETIME';
    $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $insertIgnore = $mysql ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
    $groups = $mysql ? '`groups`' : 'groups';
    $key = $mysql ? '`key`' : 'key';
    $value = $mysql ? '`value`' : 'value';

    return "
CREATE TABLE IF NOT EXISTS users (
    id $autoId,
    username {$varchar(255)} NOT NULL UNIQUE,
    email {$varchar(255)} NOT NULL UNIQUE,
    password {$varchar(255)} NOT NULL,
    is_admin $tinyint DEFAULT 0,
    permissions TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS models (
    id $autoId,
    name {$varchar(255)} NOT NULL,
    filename {$varchar(255)},
    file_path {$varchar(500)},
    file_size $bigint,
    file_type {$varchar(50)},
    description TEXT,
    creator {$varchar(255)},
    collection {$varchar(255)},
    source_url {$varchar(500)},
    parent_id $int,
    original_path {$varchar(500)},
    part_count $int DEFAULT 0,
    print_type {$varchar(50)},
    original_size $bigint,
    file_hash {$varchar(64)},
    dedup_path {$varchar(500)},
    created_at $ts DEFAULT CURRENT_TIMESTAMP,
    updated_at $ts DEFAULT CURRENT_TIMESTAMP$onUpdate,
    FOREIGN KEY (parent_id) REFERENCES models(id) ON DELETE CASCADE
)$engine;

CREATE TABLE IF NOT EXISTS categories (
    id $autoId,
    name {$varchar(255)} NOT NULL UNIQUE
)$engine;

CREATE TABLE IF NOT EXISTS model_categories (
    model_id $int,
    category_id $int,
    PRIMARY KEY (model_id, category_id),
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
)$engine;

$insertIgnore categories (name) VALUES ('Functional');
$insertIgnore categories (name) VALUES ('Decorative');
$insertIgnore categories (name) VALUES ('Tools');
$insertIgnore categories (name) VALUES ('Gaming');
$insertIgnore categories (name) VALUES ('Art');
$insertIgnore categories (name) VALUES ('Mechanical');

CREATE TABLE IF NOT EXISTS collections (
    id $autoId,
    name {$varchar(255)} NOT NULL UNIQUE,
    description TEXT,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS $groups (
    id $autoId,
    name {$varchar(255)} NOT NULL UNIQUE,
    description TEXT,
    permissions TEXT,
    is_system $tinyint DEFAULT 0,
    created_at $ts DEFAULT CURRENT_TIMESTAMP
)$engine;

CREATE TABLE IF NOT EXISTS user_groups (
    user_id $int,
    group_id $int,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES $groups(id) ON DELETE CASCADE
)$engine;

$insertIgnore $groups (name, description, permissions, is_system) VALUES ('Admin', 'Full system access', '[\"upload\",\"delete\",\"edit\",\"admin\",\"view_stats\"]', 1);
$insertIgnore $groups (name, description, permissions, is_system) VALUES ('Users', 'Default user permissions', '[\"upload\",\"view_stats\"]', 1);

CREATE TABLE IF NOT EXISTS settings (
    $key {$varchar(255)} PRIMARY KEY,
    $value TEXT,
    updated_at $ts DEFAULT CURRENT_TIMESTAMP$onUpdate
)$engine";
}

// Backward-compatible wrappers
function getSQLiteSchema(): string { return getSchema('sqlite'); }
function getMySQLSchema(): string { return getSchema('mysql'); }

// Get user by username or email
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

// Check if a table exists
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

// Check if an index exists
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

/**
 * Verify database schema is ready for web requests.
 *
 * Web requests do NOT run migrations - they only verify core tables exist.
 * Migrations must be run via CLI: php cli/migrate.php
 *
 * This prevents timeout issues from running 50+ migration checks on web requests.
 *
 * @param Database $db Database connection
 * @return bool True if schema is ready, false otherwise
 */
function verifySchemaReady($db)
{
    // CLI scripts run full migrations
    if (php_sapi_name() === 'cli') {
        runMigrations($db);
        return true;
    }

    // Web requests: Quick check for core tables only (single query)
    // If core tables don't exist, show migration required message
    try {
        $type = $db->getType();

        // Single quick query to check if core tables exist
        if ($type === 'mysql') {
            $result = $db->query("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'models', 'settings')");
        } else {
            $result = $db->query("SELECT COUNT(*) as cnt FROM sqlite_master WHERE type='table' AND name IN ('users', 'models', 'settings')");
        }

        $row = $result->fetch();
        $tableCount = $row ? (int)$row['cnt'] : 0;

        if ($tableCount < 3) {
            // Core tables missing - migrations needed
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!DOCTYPE html><html lang="en"><head><title>Database Setup Required</title></head><body style="font-family: system-ui, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px;">';
            echo '<h1>Database Setup Required</h1>';
            echo '<p>The database schema needs to be initialized or updated.</p>';
            echo '<p>Please run the following command:</p>';
            echo '<pre style="background: #f4f4f4; padding: 15px; border-radius: 5px;">php cli/migrate.php</pre>';
            echo '<p>Or in Docker:</p>';
            echo '<pre style="background: #f4f4f4; padding: 15px; border-radius: 5px;">docker exec meshsilo php cli/migrate.php</pre>';
            echo '</body></html>';
            exit(1);
        }

        return true;
    } catch (Exception $e) {
        // Database query failed - likely fresh install
        if (function_exists('logException')) {
            logException($e, ['action' => 'verify_schema']);
        }
        return false;
    }
}

// Run database migrations
function runMigrations($db)
{
    $type = $db->getType();

    // Set busy timeout for SQLite to wait for locks instead of failing immediately
    if ($type === 'sqlite') {
        $db->exec('PRAGMA busy_timeout = 10000'); // 10 seconds
    }

    // Ensure tables exist
    if (!tableExists($db, 'users')) {
        initializeDatabase($db);
        return;
    }

    // =====================
    // Priority 1 & 2 Feature Migrations
    // =====================

    // Migration: Tags table
    if (!tableExists($db, 'tags')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE tags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                color VARCHAR(7) DEFAULT "#6366f1",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                color TEXT DEFAULT "#6366f1",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )');
        }
        logInfo('Migration: Created tags table');
    }

    // Migration: Model-Tags junction table
    if (!tableExists($db, 'model_tags')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE model_tags (
                model_id INT NOT NULL,
                tag_id INT NOT NULL,
                PRIMARY KEY (model_id, tag_id),
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE model_tags (
                model_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (model_id, tag_id),
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created model_tags table');
    }

    // Migration: Favorites table
    if (!tableExists($db, 'favorites')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE favorites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                model_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_favorite (user_id, model_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE favorites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                model_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, model_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created favorites table');
    }

    // Migration: Activity log table
    if (!tableExists($db, 'activity_log')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                action VARCHAR(50) NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT,
                entity_name VARCHAR(255),
                details TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $db->exec('CREATE INDEX idx_activity_created ON activity_log(created_at)');
            $db->exec('CREATE INDEX idx_activity_user ON activity_log(user_id)');
        } else {
            $db->exec('CREATE TABLE activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id INTEGER,
                entity_name TEXT,
                details TEXT,
                ip_address TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )');
            $db->exec('CREATE INDEX idx_activity_created ON activity_log(created_at)');
            $db->exec('CREATE INDEX idx_activity_user ON activity_log(user_id)');
        }
        logInfo('Migration: Created activity_log table');
    }

    // Migration: Recently viewed table
    if (!tableExists($db, 'recently_viewed')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE recently_viewed (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                session_id VARCHAR(64),
                model_id INT NOT NULL,
                viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $db->exec('CREATE INDEX idx_recent_user ON recently_viewed(user_id, viewed_at)');
            $db->exec('CREATE INDEX idx_recent_session ON recently_viewed(session_id, viewed_at)');
        } else {
            $db->exec('CREATE TABLE recently_viewed (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                session_id TEXT,
                model_id INTEGER NOT NULL,
                viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
            )');
            $db->exec('CREATE INDEX idx_recent_user ON recently_viewed(user_id, viewed_at)');
            $db->exec('CREATE INDEX idx_recent_session ON recently_viewed(session_id, viewed_at)');
        }
        logInfo('Migration: Created recently_viewed table');
    }

    // Migration: Add download_count to models
    if (!columnExists($db, 'models', 'download_count')) {
        $db->exec('ALTER TABLE models ADD COLUMN download_count INTEGER DEFAULT 0');
        logInfo('Migration: Added download_count column to models table');
    }

    // Migration: Add license to models
    if (!columnExists($db, 'models', 'license')) {
        if ($type === 'mysql') {
            $db->exec('ALTER TABLE models ADD COLUMN license VARCHAR(100)');
        } else {
            $db->exec('ALTER TABLE models ADD COLUMN license TEXT');
        }
        logInfo('Migration: Added license column to models table');
    }

    // Migration: Add is_archived to models
    if (!columnExists($db, 'models', 'is_archived')) {
        $db->exec('ALTER TABLE models ADD COLUMN is_archived INTEGER DEFAULT 0');
        logInfo('Migration: Added is_archived column to models table');
    }

    // Migration: Add notes to models (for parts)
    if (!columnExists($db, 'models', 'notes')) {
        $db->exec('ALTER TABLE models ADD COLUMN notes TEXT');
        logInfo('Migration: Added notes column to models table');
    }

    // Migration: Add is_printed to models (for parts)
    if (!columnExists($db, 'models', 'is_printed')) {
        $db->exec('ALTER TABLE models ADD COLUMN is_printed INTEGER DEFAULT 0');
        logInfo('Migration: Added is_printed column to models table');
    }

    // Migration: Add printed_at to models
    if (!columnExists($db, 'models', 'printed_at')) {
        $db->exec('ALTER TABLE models ADD COLUMN printed_at DATETIME');
        logInfo('Migration: Added printed_at column to models table');
    }

    // =====================
    // Priority 3 & 4 Feature Migrations
    // =====================

    // Migration: Print queue table
    if (!tableExists($db, 'print_queue')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE print_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                model_id INT NOT NULL,
                priority INT DEFAULT 0,
                notes TEXT,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_queue (user_id, model_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE print_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                model_id INTEGER NOT NULL,
                priority INTEGER DEFAULT 0,
                notes TEXT,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, model_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created print_queue table');
    }

    // Migration: Model versions table
    if (!tableExists($db, 'model_versions')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE model_versions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                version_number INT NOT NULL,
                file_path VARCHAR(500),
                file_size BIGINT,
                file_hash VARCHAR(64),
                changelog TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE model_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id INTEGER NOT NULL,
                version_number INTEGER NOT NULL,
                file_path TEXT,
                file_size INTEGER,
                file_hash TEXT,
                changelog TEXT,
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )');
        }
        logInfo('Migration: Created model_versions table');
    }

    // Migration: Related models table
    if (!tableExists($db, 'related_models')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE related_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                related_model_id INT NOT NULL,
                relationship_type VARCHAR(50) DEFAULT "related",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_relation (model_id, related_model_id),
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (related_model_id) REFERENCES models(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE related_models (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id INTEGER NOT NULL,
                related_model_id INTEGER NOT NULL,
                relationship_type TEXT DEFAULT "related",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (model_id, related_model_id),
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (related_model_id) REFERENCES models(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created related_models table');
    }

    // Migration: Add dimension columns to models
    if (!columnExists($db, 'models', 'dim_x')) {
        $db->exec('ALTER TABLE models ADD COLUMN dim_x REAL');
        $db->exec('ALTER TABLE models ADD COLUMN dim_y REAL');
        $db->exec('ALTER TABLE models ADD COLUMN dim_z REAL');
        $db->exec('ALTER TABLE models ADD COLUMN dim_unit TEXT DEFAULT "mm"');
        logInfo('Migration: Added dimension columns to models table');
    }

    // Migration: Add sort_order column for part ordering
    if (!columnExists($db, 'models', 'sort_order')) {
        $db->exec('ALTER TABLE models ADD COLUMN sort_order INTEGER DEFAULT 0');
        logInfo('Migration: Added sort_order column to models table');
    }

    // Migration: Add current_version to models
    if (!columnExists($db, 'models', 'current_version')) {
        $db->exec('ALTER TABLE models ADD COLUMN current_version INTEGER DEFAULT 1');
        logInfo('Migration: Added current_version column to models table');
    }

    // Migration: Add thumbnail_path to models (for custom thumbnails)
    if (!columnExists($db, 'models', 'thumbnail_path')) {
        if ($type === 'mysql') {
            $db->exec('ALTER TABLE models ADD COLUMN thumbnail_path VARCHAR(500)');
        } else {
            $db->exec('ALTER TABLE models ADD COLUMN thumbnail_path TEXT');
        }
        logInfo('Migration: Added thumbnail_path column to models table');
    }

    // Migration: Add permissions column to users
    if (!columnExists($db, 'users', 'permissions')) {
        $db->exec('ALTER TABLE users ADD COLUMN permissions TEXT');
        logInfo('Migration: Added permissions column to users table');
    }


    // Migration: Add model columns
    $modelColumns = [
        'parent_id' => $type === 'mysql' ? 'INT' : 'INTEGER',
        'original_path' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT',
        'part_count' => $type === 'mysql' ? 'INT DEFAULT 0' : 'INTEGER DEFAULT 0',
        'print_type' => $type === 'mysql' ? 'VARCHAR(50)' : 'TEXT',
        'original_size' => $type === 'mysql' ? 'BIGINT' : 'INTEGER',
        'file_hash' => $type === 'mysql' ? 'VARCHAR(64)' : 'TEXT',
        'dedup_path' => $type === 'mysql' ? 'VARCHAR(500)' : 'TEXT'
    ];

    foreach ($modelColumns as $column => $dataType) {
        if (!columnExists($db, 'models', $column)) {
            $db->exec("ALTER TABLE models ADD COLUMN $column $dataType");
            logInfo("Migration: Added $column column to models table");
        }
    }

    // Ensure groups table exists
    if (!tableExists($db, 'groups')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE `groups` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                permissions TEXT,
                is_system TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                permissions TEXT,
                is_system INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )');
        }
        logInfo('Migration: Created groups table');
    }

    // Ensure user_groups table exists
    if (!tableExists($db, 'user_groups')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE user_groups (
                user_id INT,
                group_id INT,
                PRIMARY KEY (user_id, group_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE user_groups (
                user_id INTEGER,
                group_id INTEGER,
                PRIMARY KEY (user_id, group_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created user_groups table');
    }

    // Ensure default groups exist
    $groupsTable = $type === 'mysql' ? '`groups`' : 'groups';
    $result = $db->query("SELECT id FROM $groupsTable WHERE name = 'Admin'");
    if (!$result->fetch()) {
        $adminPerms = json_encode(['upload', 'delete', 'edit', 'admin', 'view_stats']);
        $stmt = $db->prepare("INSERT INTO $groupsTable (name, description, permissions, is_system) VALUES ('Admin', 'Full system access', :perms, 1)");
        $stmt->execute([':perms' => $adminPerms]);
        logInfo('Migration: Created Admin group');

        // Assign existing admin users to Admin group
        $adminGroupId = $db->lastInsertId();
        if ($type === 'mysql') {
            $db->exec("INSERT IGNORE INTO user_groups (user_id, group_id) SELECT id, $adminGroupId FROM users WHERE is_admin = 1");
        } else {
            $db->exec("INSERT OR IGNORE INTO user_groups (user_id, group_id) SELECT id, $adminGroupId FROM users WHERE is_admin = 1");
        }
        logInfo('Migration: Assigned admin users to Admin group');
    }

    $result = $db->query("SELECT id FROM $groupsTable WHERE name = 'Users'");
    if (!$result->fetch()) {
        $userPerms = json_encode(['upload', 'view_stats']);
        $stmt = $db->prepare("INSERT INTO $groupsTable (name, description, permissions, is_system) VALUES ('Users', 'Default user permissions', :perms, 1)");
        $stmt->execute([':perms' => $userPerms]);
        logInfo('Migration: Created Users group');
    }

    // Ensure settings table exists
    if (!tableExists($db, 'settings')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE settings (
                `key` VARCHAR(255) PRIMARY KEY,
                `value` TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )');
        }
        logInfo('Migration: Created settings table');
    }

    // Initialize default settings
    $defaultSettings = [
        'auto_convert_stl' => '0',
        'site_name' => 'Silo',
        'site_description' => 'Your 3D Model Library',
        'models_per_page' => '20',
        'allow_registration' => '1',
        'require_approval' => '0',
        'enable_categories' => '1',
        'enable_collections' => '1',
        'enable_tags' => '1',
        'allowed_extensions' => DEFAULT_ALLOWED_EXTENSIONS,
        'auto_deduplication' => '0',
        'last_deduplication' => '',
        'site_url' => '',
        'force_site_url' => '0',
        // Theme settings
        'default_theme' => 'dark',
        'allow_user_theme' => '1',
        // View settings
        'default_view' => 'grid',
        'default_sort' => 'newest',
        // Activity log settings
        'enable_activity_log' => '1',
        'activity_log_retention_days' => '90'
    ];

    $keyCol = $type === 'mysql' ? '`key`' : 'key';
    $valueCol = $type === 'mysql' ? '`value`' : 'value';
    $insertIgnore = $type === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

    foreach ($defaultSettings as $key => $value) {
        $stmt = $db->prepare("$insertIgnore INTO settings ($keyCol, $valueCol) VALUES (:key, :value)");
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    // =====================
    // API Keys Migration
    // =====================
    if (!tableExists($db, 'api_keys')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE api_keys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                key_hash VARCHAR(64) NOT NULL UNIQUE,
                key_prefix VARCHAR(12) NOT NULL,
                permissions TEXT,
                is_active TINYINT DEFAULT 1,
                expires_at TIMESTAMP NULL,
                last_used_at TIMESTAMP NULL,
                request_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                key_hash TEXT NOT NULL UNIQUE,
                key_prefix TEXT NOT NULL,
                permissions TEXT,
                is_active INTEGER DEFAULT 1,
                expires_at DATETIME,
                last_used_at DATETIME,
                request_count INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created api_keys table');
    }

    // API Request Log Migration
    if (!tableExists($db, 'api_request_log')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE api_request_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                api_key_id INT NOT NULL,
                method VARCHAR(10) NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45),
                user_agent VARCHAR(500),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $db->exec('CREATE INDEX idx_api_log_created ON api_request_log(created_at)');
            $db->exec('CREATE INDEX idx_api_log_key ON api_request_log(api_key_id)');
        } else {
            $db->exec('CREATE TABLE api_request_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                api_key_id INTEGER NOT NULL,
                method TEXT NOT NULL,
                endpoint TEXT NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
            )');
            $db->exec('CREATE INDEX idx_api_log_created ON api_request_log(created_at)');
            $db->exec('CREATE INDEX idx_api_log_key ON api_request_log(api_key_id)');
        }
        logInfo('Migration: Created api_request_log table');
    }

    // =====================
    // Print Photos Migration
    // =====================
    if (!tableExists($db, 'print_photos')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE print_photos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                user_id INT,
                filename VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                caption TEXT,
                is_primary TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE print_photos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id INTEGER NOT NULL,
                user_id INTEGER,
                filename TEXT NOT NULL,
                file_path TEXT NOT NULL,
                caption TEXT,
                is_primary INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )');
        }
        logInfo('Migration: Created print_photos table');
    }

    // =====================
    // Printer Profiles Migration
    // =====================
    if (!tableExists($db, 'printers')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE printers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                name VARCHAR(255) NOT NULL,
                manufacturer VARCHAR(255),
                model VARCHAR(255),
                bed_x DECIMAL(10,2),
                bed_y DECIMAL(10,2),
                bed_z DECIMAL(10,2),
                print_type VARCHAR(50) DEFAULT "fdm",
                is_default TINYINT DEFAULT 0,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE printers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                name TEXT NOT NULL,
                manufacturer TEXT,
                model TEXT,
                bed_x REAL,
                bed_y REAL,
                bed_z REAL,
                print_type TEXT DEFAULT "fdm",
                is_default INTEGER DEFAULT 0,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created printers table');
    }

    // =====================
    // Print History Migration
    // =====================
    if (!tableExists($db, 'print_history')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE print_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                user_id INT,
                printer_id INT,
                print_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                duration_minutes INT,
                filament_used_g DECIMAL(10,2),
                filament_type VARCHAR(100),
                filament_color VARCHAR(100),
                success TINYINT DEFAULT 1,
                quality_rating INT,
                notes TEXT,
                settings TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE print_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id INTEGER NOT NULL,
                user_id INTEGER,
                printer_id INTEGER,
                print_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                duration_minutes INTEGER,
                filament_used_g REAL,
                filament_type TEXT,
                filament_color TEXT,
                success INTEGER DEFAULT 1,
                quality_rating INTEGER,
                notes TEXT,
                settings TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL
            )');
        }
        logInfo('Migration: Created print_history table');
    }

    // =====================
    // Share Links Migration
    // =====================
    if (!tableExists($db, 'share_links')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE share_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                user_id INT,
                token VARCHAR(64) NOT NULL UNIQUE,
                password_hash VARCHAR(255),
                expires_at TIMESTAMP NULL,
                max_downloads INT,
                download_count INT DEFAULT 0,
                is_active TINYINT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $db->exec('CREATE INDEX idx_share_token ON share_links(token)');
        } else {
            $db->exec('CREATE TABLE share_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id INTEGER NOT NULL,
                user_id INTEGER,
                token TEXT NOT NULL UNIQUE,
                password_hash TEXT,
                expires_at DATETIME,
                max_downloads INTEGER,
                download_count INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )');
            $db->exec('CREATE INDEX idx_share_token ON share_links(token)');
        }
        logInfo('Migration: Created share_links table');
    }

    // =====================
    // Model Ratings Migration
    // =====================
    if (!tableExists($db, 'model_ratings')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE model_ratings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                user_id INT NOT NULL,
                printability INT,
                quality INT,
                difficulty INT,
                review TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_rating (model_id, user_id),
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE model_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                printability INTEGER,
                quality INTEGER,
                difficulty INTEGER,
                review TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (model_id, user_id),
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created model_ratings table');
    }

    // =====================
    // Folders Migration
    // =====================
    if (!tableExists($db, 'folders')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE folders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                parent_id INT,
                user_id INT,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                color VARCHAR(7),
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER,
                user_id INTEGER,
                name TEXT NOT NULL,
                description TEXT,
                color TEXT,
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created folders table');
    }

    // Add folder_id to models if not exists
    if (!columnExists($db, 'models', 'folder_id')) {
        if ($type === 'mysql') {
            $db->exec('ALTER TABLE models ADD COLUMN folder_id INT');
        } else {
            $db->exec('ALTER TABLE models ADD COLUMN folder_id INTEGER');
        }
        logInfo('Migration: Added folder_id column to models table');
    }

    // =====================
    // Upload Approval Queue Migration
    // =====================
    if (!columnExists($db, 'models', 'approval_status')) {
        if ($type === 'mysql') {
            $db->exec("ALTER TABLE models ADD COLUMN approval_status VARCHAR(20) DEFAULT 'approved'");
            $db->exec('ALTER TABLE models ADD COLUMN approved_by INT');
            $db->exec('ALTER TABLE models ADD COLUMN approved_at TIMESTAMP NULL');
        } else {
            $db->exec("ALTER TABLE models ADD COLUMN approval_status TEXT DEFAULT 'approved'");
            $db->exec('ALTER TABLE models ADD COLUMN approved_by INTEGER');
            $db->exec('ALTER TABLE models ADD COLUMN approved_at DATETIME');
        }
        logInfo('Migration: Added approval columns to models table');
    }

    // =====================
    // User Storage Limits Migration
    // =====================
    if (!columnExists($db, 'users', 'storage_limit_mb')) {
        $db->exec('ALTER TABLE users ADD COLUMN storage_limit_mb INTEGER DEFAULT 0');
        $db->exec('ALTER TABLE users ADD COLUMN model_limit INTEGER DEFAULT 0');
        logInfo('Migration: Added storage limit columns to users table');
    }

    // =====================
    // Teams/Workspaces Tables
    // =====================
    if (!tableExists($db, 'teams')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE teams (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                owner_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE teams (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                owner_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created teams table');
    }

    if (!tableExists($db, 'team_members')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE team_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                user_id INT NOT NULL,
                role VARCHAR(50) DEFAULT "member",
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_membership (team_id, user_id),
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE team_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                team_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT DEFAULT "member",
                joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (team_id, user_id),
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created team_members table');
    }

    if (!tableExists($db, 'team_models')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE team_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                model_id INT NOT NULL,
                shared_by INT NOT NULL,
                permissions VARCHAR(50) DEFAULT "read",
                shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_share (team_id, model_id),
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE team_models (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                team_id INTEGER NOT NULL,
                model_id INTEGER NOT NULL,
                shared_by INTEGER NOT NULL,
                permissions TEXT DEFAULT "read",
                shared_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (team_id, model_id),
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created team_models table');
    }

    if (!tableExists($db, 'team_invites')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE team_invites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                email VARCHAR(255) NOT NULL,
                role VARCHAR(50) DEFAULT "member",
                token VARCHAR(64) NOT NULL UNIQUE,
                invited_by INT NOT NULL,
                status VARCHAR(20) DEFAULT "pending",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE team_invites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                team_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                role TEXT DEFAULT "member",
                token TEXT NOT NULL UNIQUE,
                invited_by INTEGER NOT NULL,
                status TEXT DEFAULT "pending",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created team_invites table');
    }

    // =====================
    // Performance Optimization: Add critical indexes
    // =====================
    $criticalIndexes = [
        'idx_models_filename' => ['table' => 'models', 'column' => 'filename', 'reason' => 'orphan file detection'],
        'idx_models_parent_id' => ['table' => 'models', 'column' => 'parent_id', 'reason' => 'part queries'],
        'idx_models_created_at' => ['table' => 'models', 'column' => 'created_at', 'reason' => 'sorting by date'],
        'idx_models_user_id' => ['table' => 'models', 'column' => 'user_id', 'reason' => 'user filtering'],
'idx_model_tags_model' => ['table' => 'model_tags', 'column' => 'model_id', 'reason' => 'tag lookups'],
        'idx_model_tags_tag' => ['table' => 'model_tags', 'column' => 'tag_id', 'reason' => 'reverse tag lookups'],
        'idx_favorites_user' => ['table' => 'favorites', 'column' => 'user_id', 'reason' => 'user favorites'],
        'idx_favorites_model' => ['table' => 'favorites', 'column' => 'model_id', 'reason' => 'model favorite count'],
        'idx_models_file_hash' => ['table' => 'models', 'column' => 'file_hash', 'reason' => 'deduplication lookups'],
        'idx_models_dedup_path' => ['table' => 'models', 'column' => 'dedup_path', 'reason' => 'dedup file reference checks'],
        'idx_models_collection' => ['table' => 'models', 'column' => 'collection', 'reason' => 'collection filtering'],
    ];

    // Composite indexes for common query patterns (only for MySQL, SQLite handles these well enough)
    $compositeIndexes = [
        'idx_models_parent_created' => ['table' => 'models', 'columns' => ['parent_id', 'created_at'], 'reason' => 'parts with sorting'],
        'idx_models_parent_original' => ['table' => 'models', 'columns' => ['parent_id', 'original_path'], 'reason' => 'ordered part retrieval'],
        'idx_recently_viewed_user_time' => ['table' => 'recently_viewed', 'columns' => ['user_id', 'viewed_at'], 'reason' => 'user view history'],
        'idx_activity_user_time' => ['table' => 'activity_log', 'columns' => ['user_id', 'created_at'], 'reason' => 'user activity history'],
    ];

    // Covering indexes - include frequently queried columns for index-only scans
    // These avoid the need to access the main table for common queries
    $coveringIndexes = [
        'idx_models_parent_null_created' => [
            'table' => 'models',
            'sql' => 'CREATE INDEX idx_models_parent_null_created ON models(parent_id, created_at) WHERE parent_id IS NULL',
            'check_only' => true,
            'reason' => 'homepage recent models - index-only scan'
        ],
    ];

    foreach ($criticalIndexes as $indexName => $indexInfo) {
        try {
            $indexExists = false;

            if ($type === 'mysql') {
                $result = $db->query("SHOW INDEX FROM {$indexInfo['table']} WHERE Key_name = '$indexName'");
                $indexExists = ($result->fetch() !== false);
            } else {
                $result = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND name='$indexName'");
                $indexExists = ($result->fetch() !== false);
            }

            if (!$indexExists && tableExists($db, $indexInfo['table'])) {
                $db->exec("CREATE INDEX $indexName ON {$indexInfo['table']}({$indexInfo['column']})");
                logInfo("Migration: Created index $indexName", ['reason' => $indexInfo['reason']]);
            }
        } catch (Exception $e) {
            // Index might already exist or table doesn't exist yet, safe to ignore
            logDebug("Migration: Index $indexName skipped", ['error' => $e->getMessage()]);
        }
    }

    // Add composite indexes (only for MySQL as they provide more benefit there)
    if ($type === 'mysql') {
        foreach ($compositeIndexes as $indexName => $indexInfo) {
            try {
                $result = $db->query("SHOW INDEX FROM {$indexInfo['table']} WHERE Key_name = '$indexName'");
                $indexExists = ($result->fetch() !== false);

                if (!$indexExists && tableExists($db, $indexInfo['table'])) {
                    $columns = implode(', ', $indexInfo['columns']);
                    $db->exec("CREATE INDEX $indexName ON {$indexInfo['table']}($columns)");
                    logInfo("Migration: Created composite index $indexName", ['reason' => $indexInfo['reason']]);
                }
            } catch (Exception $e) {
                logDebug("Migration: Composite index $indexName skipped", ['error' => $e->getMessage()]);
            }
        }
    }

    // Add covering/partial indexes (advanced optimizations)
    foreach ($coveringIndexes as $indexName => $indexInfo) {
        try {
            $indexExists = false;

            if ($type === 'mysql') {
                $result = $db->query("SHOW INDEX FROM {$indexInfo['table']} WHERE Key_name = '$indexName'");
                $indexExists = ($result->fetch() !== false);
            } else {
                $result = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND name='$indexName'");
                $indexExists = ($result->fetch() !== false);
            }

            if (!$indexExists && tableExists($db, $indexInfo['table'])) {
                // Use custom SQL for covering indexes (may include WHERE clause)
                $db->exec($indexInfo['sql']);
                logInfo("Migration: Created covering index $indexName", ['reason' => $indexInfo['reason']]);
            }
        } catch (Exception $e) {
            // Partial indexes may not be supported on all DB versions
            logDebug("Migration: Covering index $indexName skipped", ['error' => $e->getMessage()]);
        }
    }

    // =====================
    // Full-Text Search Indexes (for fast search)
    // =====================
    if ($type === 'mysql' && tableExists($db, 'models')) {
        try {
            // Check if FULLTEXT index exists
            $result = $db->query("SHOW INDEX FROM models WHERE Key_name = 'idx_models_fulltext'");
            if ($result->fetch() === false) {
                $db->exec('CREATE FULLTEXT INDEX idx_models_fulltext ON models(name, description, creator)');
                logInfo('Migration: Created FULLTEXT index on models', ['reason' => 'fast search']);
            }
        } catch (Exception $e) {
            logDebug('Migration: FULLTEXT index skipped', ['error' => $e->getMessage()]);
        }
    } elseif ($type === 'sqlite' && tableExists($db, 'models')) {
        // SQLite FTS5 virtual table for full-text search
        try {
            if (!tableExists($db, 'models_fts')) {
                $db->exec("
                    CREATE VIRTUAL TABLE models_fts USING fts5(
                        name, description, creator,
                        content='models',
                        content_rowid='id'
                    )
                ");

                // Populate FTS table
                $db->exec("
                    INSERT INTO models_fts(rowid, name, description, creator)
                    SELECT id, name, COALESCE(description, ''), COALESCE(creator, '')
                    FROM models WHERE parent_id IS NULL
                ");

                // Create triggers to keep FTS in sync
                $db->exec("
                    CREATE TRIGGER models_fts_insert AFTER INSERT ON models
                    WHEN NEW.parent_id IS NULL
                    BEGIN
                        INSERT INTO models_fts(rowid, name, description, creator)
                        VALUES (NEW.id, NEW.name, COALESCE(NEW.description, ''), COALESCE(NEW.creator, ''));
                    END
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_delete AFTER DELETE ON models
                    WHEN OLD.parent_id IS NULL
                    BEGIN
                        DELETE FROM models_fts WHERE rowid = OLD.id;
                    END
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_update AFTER UPDATE ON models
                    WHEN NEW.parent_id IS NULL
                    BEGIN
                        UPDATE models_fts
                        SET name = NEW.name,
                            description = COALESCE(NEW.description, ''),
                            creator = COALESCE(NEW.creator, '')
                        WHERE rowid = NEW.id;
                    END
                ");

                logInfo('Migration: Created FTS5 virtual table for models', ['reason' => 'fast full-text search']);
            }
        } catch (Exception $e) {
            logDebug('Migration: FTS5 table skipped', ['error' => $e->getMessage()]);
        }
    }

    // =====================
    // FTS v2: Add notes column to full-text search indexes
    // Uses fts_version setting to track whether this migration has run.
    // FTS5 virtual tables don't support PRAGMA table_info(), so column inspection
    // is not reliable — settings-based versioning avoids the problem entirely.
    // =====================
    try {
        $keyCol = $type === 'mysql' ? '`key`' : 'key';
        $ftsVersionStmt = $db->prepare("SELECT value FROM settings WHERE $keyCol = 'fts_version'");
        $ftsVersionStmt->execute();
        $currentFtsVersion = (int)($ftsVersionStmt->fetchColumn() ?: '0');
    } catch (Exception $e) {
        $currentFtsVersion = 0;
    }

    if ($currentFtsVersion < 2) {
        if ($type === 'mysql' && tableExists($db, 'models')) {
            try {
                // Drop old FULLTEXT index (without notes) and recreate with notes
                $indexResult = $db->query("SHOW INDEX FROM models WHERE Key_name = 'idx_models_fulltext'");
                if ($indexResult && $indexResult->fetch() !== false) {
                    $db->exec('ALTER TABLE models DROP INDEX idx_models_fulltext');
                }
                $db->exec('CREATE FULLTEXT INDEX idx_models_fulltext ON models(name, description, creator, notes)');
                $db->exec("INSERT INTO settings (`key`, `value`, updated_at) VALUES ('fts_version', '2', NOW()) ON DUPLICATE KEY UPDATE `value` = '2', updated_at = NOW()");
                logInfo('Migration: Updated FULLTEXT index to include notes column');
            } catch (Exception $e) {
                logDebug('Migration: FTS v2 MySQL skipped', ['error' => $e->getMessage()]);
            }
        } elseif ($type === 'sqlite' && tableExists($db, 'models')) {
            try {
                // Drop existing triggers
                $db->exec('DROP TRIGGER IF EXISTS models_fts_insert');
                $db->exec('DROP TRIGGER IF EXISTS models_fts_delete');
                $db->exec('DROP TRIGGER IF EXISTS models_fts_update');

                // Drop and recreate FTS table with notes column
                $db->exec('DROP TABLE IF EXISTS models_fts');
                $db->exec("
                    CREATE VIRTUAL TABLE models_fts USING fts5(
                        name, description, creator, notes,
                        content='models',
                        content_rowid='id'
                    )
                ");

                // Repopulate from models
                $db->exec("
                    INSERT INTO models_fts(rowid, name, description, creator, notes)
                    SELECT id, name, COALESCE(description, ''), COALESCE(creator, ''), COALESCE(notes, '')
                    FROM models WHERE parent_id IS NULL
                ");

                // Recreate triggers with notes included
                $db->exec("
                    CREATE TRIGGER models_fts_insert AFTER INSERT ON models
                    WHEN NEW.parent_id IS NULL
                    BEGIN
                        INSERT INTO models_fts(rowid, name, description, creator, notes)
                        VALUES (NEW.id, NEW.name, COALESCE(NEW.description, ''), COALESCE(NEW.creator, ''), COALESCE(NEW.notes, ''));
                    END
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_delete AFTER DELETE ON models
                    WHEN OLD.parent_id IS NULL
                    BEGIN
                        DELETE FROM models_fts WHERE rowid = OLD.id;
                    END
                ");

                $db->exec("
                    CREATE TRIGGER models_fts_update AFTER UPDATE ON models
                    WHEN NEW.parent_id IS NULL
                    BEGIN
                        UPDATE models_fts
                        SET name = NEW.name,
                            description = COALESCE(NEW.description, ''),
                            creator = COALESCE(NEW.creator, ''),
                            notes = COALESCE(NEW.notes, '')
                        WHERE rowid = NEW.id;
                    END
                ");

                $db->exec("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('fts_version', '2', CURRENT_TIMESTAMP)");
                logInfo('Migration: Rebuilt FTS5 table to include notes column');
            } catch (Exception $e) {
                logWarning('Migration: FTS v2 SQLite skipped', ['error' => $e->getMessage()]);
            }
        }
    }

    // =====================
    // FTS v3: Include parts in search index
    // =====================
    if ($currentFtsVersion < 3) {
        if ($type === 'sqlite' && tableExists($db, 'models')) {
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

                // Populate with ALL models (parents and parts)
                $db->exec("
                    INSERT INTO models_fts(rowid, name, description, creator, notes)
                    SELECT id, name, COALESCE(description, ''), COALESCE(creator, ''), COALESCE(notes, '')
                    FROM models
                ");

                // Triggers for ALL models (no parent_id filter)
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
                logInfo('Migration: FTS v3 - expanded search index to include parts');
            } catch (Exception $e) {
                logWarning('Migration: FTS v3 SQLite skipped', ['error' => $e->getMessage()]);
            }
        } elseif ($type === 'mysql' && tableExists($db, 'models')) {
            // MySQL FULLTEXT already indexes all rows — just bump the version
            $db->exec("INSERT INTO settings (`key`, `value`, updated_at) VALUES ('fts_version', '3', NOW()) ON DUPLICATE KEY UPDATE `value` = '3', updated_at = NOW()");
        }
    }

    // =====================
    // Ensure all default settings exist in database
    // Uses INSERT OR IGNORE / INSERT IGNORE so existing values are never overwritten
    // =====================
    initializeDefaultSettings($db);
}

/**
 * Initialize all default settings in the database.
 * Uses INSERT OR IGNORE (SQLite) / INSERT IGNORE (MySQL) so existing values are preserved.
 * Called by runMigrations() to ensure all settings exist after install/upgrade.
 */
