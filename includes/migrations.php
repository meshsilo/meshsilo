<?php
/**
 * Database Migrations
 *
 * Shared migration definitions used by:
 * - cli/migrate.php
 * - app/admin/database.php
 * - app/pages/update.php
 *
 * Each migration has:
 * - name: Display name
 * - description: What it does
 * - check: Function that returns true if already applied
 * - apply: Function that applies the migration
 */

function getMigrationList() {
    return [
        // Core tables
        [
            'name' => 'Tags table',
            'description' => 'Stores tag names and colors for model organization',
            'check' => fn($db) => tableExists($db, 'tags'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Model-Tags junction table',
            'description' => 'Links models to their tags (many-to-many)',
            'check' => fn($db) => tableExists($db, 'model_tags'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Favorites table',
            'description' => 'Tracks user favorites/bookmarks',
            'check' => fn($db) => tableExists($db, 'favorites'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Activity log table',
            'description' => 'Audit trail for user actions',
            'check' => fn($db) => tableExists($db, 'activity_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE activity_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT,
                        action VARCHAR(50) NOT NULL,
                        entity_type VARCHAR(50),
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
                        entity_type TEXT,
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
            }
        ],
        [
            'name' => 'Recently viewed table',
            'description' => 'Tracks recently viewed models per user/session',
            'check' => fn($db) => tableExists($db, 'recently_viewed'),
            'apply' => function($db) {
                $type = $db->getType();
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
                    $db->exec('CREATE INDEX idx_recently_viewed_user ON recently_viewed(user_id, viewed_at)');
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
                    $db->exec('CREATE INDEX idx_recently_viewed_user ON recently_viewed(user_id, viewed_at)');
                }
            }
        ],
        [
            'name' => 'Print queue table',
            'description' => 'Manages print queue items with status and priority',
            'check' => fn($db) => tableExists($db, 'print_queue'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE print_queue (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        model_id INT NOT NULL,
                        part_id INT,
                        priority INT DEFAULT 0,
                        status VARCHAR(20) DEFAULT "queued",
                        notes TEXT,
                        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE print_queue (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        model_id INTEGER NOT NULL,
                        part_id INTEGER,
                        priority INTEGER DEFAULT 0,
                        status TEXT DEFAULT "queued",
                        notes TEXT,
                        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
                    )');
                }
            }
        ],
        // Model columns
        [
            'name' => 'Models: download_count column',
            'description' => 'Track download counts per model',
            'check' => fn($db) => columnExists($db, 'models', 'download_count'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE models ADD COLUMN download_count INT DEFAULT 0');
                } else {
                    $db->exec('ALTER TABLE models ADD COLUMN download_count INTEGER DEFAULT 0');
                }
            }
        ],
        [
            'name' => 'Models: license column',
            'description' => 'Store license type for models',
            'check' => fn($db) => columnExists($db, 'models', 'license'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE models ADD COLUMN license VARCHAR(50)');
                } else {
                    $db->exec('ALTER TABLE models ADD COLUMN license TEXT');
                }
            }
        ],
        [
            'name' => 'Models: is_archived column',
            'description' => 'Soft archive models without deletion',
            'check' => fn($db) => columnExists($db, 'models', 'is_archived'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE models ADD COLUMN is_archived TINYINT DEFAULT 0');
                } else {
                    $db->exec('ALTER TABLE models ADD COLUMN is_archived INTEGER DEFAULT 0');
                }
            }
        ],
        [
            'name' => 'Models: print tracking columns',
            'description' => 'Track printed status and notes per part',
            'check' => fn($db) => columnExists($db, 'models', 'is_printed'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE models ADD COLUMN is_printed TINYINT DEFAULT 0');
                    $db->exec('ALTER TABLE models ADD COLUMN printed_at TIMESTAMP NULL');
                    $db->exec('ALTER TABLE models ADD COLUMN notes TEXT');
                } else {
                    $db->exec('ALTER TABLE models ADD COLUMN is_printed INTEGER DEFAULT 0');
                    $db->exec('ALTER TABLE models ADD COLUMN printed_at DATETIME');
                    $db->exec('ALTER TABLE models ADD COLUMN notes TEXT');
                }
            }
        ],
        [
            'name' => 'Models: dimension columns',
            'description' => 'Store calculated model dimensions',
            'check' => fn($db) => columnExists($db, 'models', 'dim_x'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE models ADD COLUMN dim_x DECIMAL(10,2)');
                    $db->exec('ALTER TABLE models ADD COLUMN dim_y DECIMAL(10,2)');
                    $db->exec('ALTER TABLE models ADD COLUMN dim_z DECIMAL(10,2)');
                    $db->exec('ALTER TABLE models ADD COLUMN volume DECIMAL(15,2)');
                } else {
                    $db->exec('ALTER TABLE models ADD COLUMN dim_x REAL');
                    $db->exec('ALTER TABLE models ADD COLUMN dim_y REAL');
                    $db->exec('ALTER TABLE models ADD COLUMN dim_z REAL');
                    $db->exec('ALTER TABLE models ADD COLUMN volume REAL');
                }
            }
        ],
        // API & Webhooks
        [
            'name' => 'API keys table',
            'description' => 'Store API keys for programmatic access',
            'check' => fn($db) => tableExists($db, 'api_keys'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'API request log table',
            'description' => 'Log API requests for analytics',
            'check' => fn($db) => tableExists($db, 'api_request_log'),
            'apply' => function($db) {
                $type = $db->getType();
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
                }
            }
        ],
        [
            'name' => 'Webhooks table',
            'description' => 'Store webhook endpoints and configuration',
            'check' => fn($db) => tableExists($db, 'webhooks'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE webhooks (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255),
                        url VARCHAR(500) NOT NULL,
                        secret VARCHAR(255),
                        events TEXT NOT NULL,
                        is_active TINYINT DEFAULT 1,
                        last_triggered_at TIMESTAMP NULL,
                        last_status_code INT,
                        failure_count INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE webhooks (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT,
                        url TEXT NOT NULL,
                        secret TEXT,
                        events TEXT NOT NULL,
                        is_active INTEGER DEFAULT 1,
                        last_triggered_at DATETIME,
                        last_status_code INTEGER,
                        failure_count INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Webhook deliveries table',
            'description' => 'Log webhook delivery attempts',
            'check' => fn($db) => tableExists($db, 'webhook_deliveries'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE webhook_deliveries (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        webhook_id INT NOT NULL,
                        event VARCHAR(100) NOT NULL,
                        payload TEXT NOT NULL,
                        response_code INT,
                        response_body TEXT,
                        success TINYINT DEFAULT 0,
                        duration_ms INT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_webhook_del_created ON webhook_deliveries(created_at)');
                } else {
                    $db->exec('CREATE TABLE webhook_deliveries (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        webhook_id INTEGER NOT NULL,
                        event TEXT NOT NULL,
                        payload TEXT NOT NULL,
                        response_code INTEGER,
                        response_body TEXT,
                        success INTEGER DEFAULT 0,
                        duration_ms INTEGER,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_webhook_del_created ON webhook_deliveries(created_at)');
                }
            }
        ],
        // Enterprise features
        [
            'name' => 'Print photos table',
            'description' => 'Store photos of printed models',
            'check' => fn($db) => tableExists($db, 'print_photos'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Printers table',
            'description' => 'Store printer profiles and specifications',
            'check' => fn($db) => tableExists($db, 'printers'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Model ratings table',
            'description' => 'Store user ratings for models',
            'check' => fn($db) => tableExists($db, 'model_ratings'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE model_ratings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        user_id INT NOT NULL,
                        rating TINYINT NOT NULL,
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
                        rating INTEGER NOT NULL,
                        review TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (model_id, user_id),
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )');
                }
            }
        ],
        [
            'name' => 'Folders table',
            'description' => 'Hierarchical folder organization for models',
            'check' => fn($db) => tableExists($db, 'folders'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Models: folder_id column',
            'description' => 'Link models to folders',
            'check' => fn($db) => columnExists($db, 'models', 'folder_id'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE models ADD COLUMN folder_id INT');
                } else {
                    $db->exec('ALTER TABLE models ADD COLUMN folder_id INTEGER');
                }
            }
        ],
        [
            'name' => 'Models: approval columns',
            'description' => 'Support upload approval workflow',
            'check' => fn($db) => columnExists($db, 'models', 'approval_status'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec("ALTER TABLE models ADD COLUMN approval_status VARCHAR(20) DEFAULT 'approved'");
                    $db->exec('ALTER TABLE models ADD COLUMN approved_by INT');
                    $db->exec('ALTER TABLE models ADD COLUMN approved_at TIMESTAMP NULL');
                } else {
                    $db->exec("ALTER TABLE models ADD COLUMN approval_status TEXT DEFAULT 'approved'");
                    $db->exec('ALTER TABLE models ADD COLUMN approved_by INTEGER');
                    $db->exec('ALTER TABLE models ADD COLUMN approved_at DATETIME');
                }
            }
        ],
        [
            'name' => 'Users: storage limit columns',
            'description' => 'Per-user storage and model limits',
            'check' => fn($db) => columnExists($db, 'users', 'storage_limit_mb'),
            'apply' => function($db) {
                $db->exec('ALTER TABLE users ADD COLUMN storage_limit_mb INTEGER DEFAULT 0');
                $db->exec('ALTER TABLE users ADD COLUMN model_limit INTEGER DEFAULT 0');
            }
        ],
        // Teams
        [
            'name' => 'Teams table',
            'description' => 'Team/workspace definitions',
            'check' => fn($db) => tableExists($db, 'teams'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Team members table',
            'description' => 'Team membership with roles',
            'check' => fn($db) => tableExists($db, 'team_members'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Team models table',
            'description' => 'Models shared with teams',
            'check' => fn($db) => tableExists($db, 'team_models'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        [
            'name' => 'Team invites table',
            'description' => 'Pending team invitations',
            'check' => fn($db) => tableExists($db, 'team_invites'),
            'apply' => function($db) {
                $type = $db->getType();
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
            }
        ],
        // Two-Factor Authentication
        [
            'name' => 'Users: two-factor authentication columns',
            'description' => 'TOTP-based 2FA with backup codes',
            'check' => fn($db) => columnExists($db, 'users', 'two_factor_enabled'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(64)');
                    $db->exec('ALTER TABLE users ADD COLUMN two_factor_backup_codes TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT DEFAULT 0');
                    $db->exec('ALTER TABLE users ADD COLUMN two_factor_enabled_at TIMESTAMP NULL');
                } else {
                    $db->exec('ALTER TABLE users ADD COLUMN two_factor_secret TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN two_factor_backup_codes TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN two_factor_enabled INTEGER DEFAULT 0');
                    $db->exec('ALTER TABLE users ADD COLUMN two_factor_enabled_at DATETIME');
                }
            }
        ],
        // File Integrity
        [
            'name' => 'Models: integrity hash columns',
            'description' => 'SHA-256 checksums for file integrity verification',
            'check' => fn($db) => columnExists($db, 'models', 'integrity_hash'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE models ADD COLUMN integrity_hash VARCHAR(64)');
                    $db->exec('ALTER TABLE models ADD COLUMN integrity_checked_at TIMESTAMP NULL');
                } else {
                    $db->exec('ALTER TABLE models ADD COLUMN integrity_hash TEXT');
                    $db->exec('ALTER TABLE models ADD COLUMN integrity_checked_at DATETIME');
                }
            }
        ],
        [
            'name' => 'Integrity log table',
            'description' => 'Log file integrity issues (missing/corrupted files)',
            'check' => fn($db) => tableExists($db, 'integrity_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE integrity_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        status VARCHAR(20) NOT NULL,
                        message TEXT,
                        details TEXT,
                        resolved TINYINT DEFAULT 0,
                        resolution TEXT,
                        resolved_at TIMESTAMP NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_integrity_log_model ON integrity_log(model_id)');
                    $db->exec('CREATE INDEX idx_integrity_log_created ON integrity_log(created_at)');
                } else {
                    $db->exec('CREATE TABLE integrity_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        status TEXT NOT NULL,
                        message TEXT,
                        details TEXT,
                        resolved INTEGER DEFAULT 0,
                        resolution TEXT,
                        resolved_at DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_integrity_log_model ON integrity_log(model_id)');
                    $db->exec('CREATE INDEX idx_integrity_log_created ON integrity_log(created_at)');
                }
            }
        ],
        // Scheduler
        [
            'name' => 'Scheduler log table',
            'description' => 'Track scheduled task execution history',
            'check' => fn($db) => tableExists($db, 'scheduler_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE scheduler_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        task_name VARCHAR(100) NOT NULL,
                        status VARCHAR(20) NOT NULL,
                        output TEXT,
                        duration_ms INT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_scheduler_log_task ON scheduler_log(task_name)');
                    $db->exec('CREATE INDEX idx_scheduler_log_created ON scheduler_log(created_at)');
                } else {
                    $db->exec('CREATE TABLE scheduler_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        task_name TEXT NOT NULL,
                        status TEXT NOT NULL,
                        output TEXT,
                        duration_ms INTEGER,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                    $db->exec('CREATE INDEX idx_scheduler_log_task ON scheduler_log(task_name)');
                    $db->exec('CREATE INDEX idx_scheduler_log_created ON scheduler_log(created_at)');
                }
            }
        ],
        // Event System
        [
            'name' => 'Event log table',
            'description' => 'Store emitted events for audit and replay',
            'check' => fn($db) => tableExists($db, 'event_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE event_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        event_name VARCHAR(100) NOT NULL,
                        user_id INT,
                        data TEXT,
                        ip_address VARCHAR(45),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_event_log_name ON event_log(event_name)');
                    $db->exec('CREATE INDEX idx_event_log_user ON event_log(user_id)');
                    $db->exec('CREATE INDEX idx_event_log_created ON event_log(created_at)');
                } else {
                    $db->exec('CREATE TABLE event_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        event_name TEXT NOT NULL,
                        user_id INTEGER,
                        data TEXT,
                        ip_address TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    )');
                    $db->exec('CREATE INDEX idx_event_log_name ON event_log(event_name)');
                    $db->exec('CREATE INDEX idx_event_log_user ON event_log(user_id)');
                    $db->exec('CREATE INDEX idx_event_log_created ON event_log(created_at)');
                }
            }
        ],
        // Rate Limiting
        [
            'name' => 'Rate limit table',
            'description' => 'Track rate limit counters per IP/key',
            'check' => fn($db) => tableExists($db, 'rate_limits'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE rate_limits (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        identifier VARCHAR(100) NOT NULL,
                        endpoint VARCHAR(255) NOT NULL,
                        request_count INT DEFAULT 1,
                        window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_rate_limit (identifier, endpoint)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_rate_limits_window ON rate_limits(window_start)');
                } else {
                    $db->exec('CREATE TABLE rate_limits (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        identifier TEXT NOT NULL,
                        endpoint TEXT NOT NULL,
                        request_count INTEGER DEFAULT 1,
                        window_start DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (identifier, endpoint)
                    )');
                    $db->exec('CREATE INDEX idx_rate_limits_window ON rate_limits(window_start)');
                }
            }
        ],
        // Sessions table for database-backed sessions
        [
            'name' => 'Sessions table',
            'description' => 'Database-backed session storage',
            'check' => fn($db) => tableExists($db, 'sessions'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE sessions (
                        id VARCHAR(128) PRIMARY KEY,
                        user_id INT,
                        data TEXT,
                        ip_address VARCHAR(45),
                        user_agent VARCHAR(500),
                        last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_sessions_user ON sessions(user_id)');
                    $db->exec('CREATE INDEX idx_sessions_activity ON sessions(last_activity)');
                } else {
                    $db->exec('CREATE TABLE sessions (
                        id TEXT PRIMARY KEY,
                        user_id INTEGER,
                        data TEXT,
                        ip_address TEXT,
                        user_agent TEXT,
                        last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_sessions_user ON sessions(user_id)');
                    $db->exec('CREATE INDEX idx_sessions_activity ON sessions(last_activity)');
                }
            }
        ],

        // =====================================================================
        // MODEL MANAGEMENT FEATURES
        // =====================================================================

        // Model Analysis
        [
            'name' => 'Model analysis table',
            'description' => 'Store automated model analysis results (overhangs, printability)',
            'check' => fn($db) => tableExists($db, 'model_analysis'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE model_analysis (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL UNIQUE,
                        overhang_percentage DECIMAL(5,2),
                        support_required TINYINT DEFAULT 0,
                        optimal_orientation TEXT,
                        thin_wall_warnings TEXT,
                        printability_score INT,
                        analysis_warnings TEXT,
                        estimated_print_time INT,
                        estimated_filament_grams DECIMAL(10,2),
                        analyzed_at DATETIME,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_model_analysis_score ON model_analysis(printability_score)');
                } else {
                    $db->exec('CREATE TABLE model_analysis (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL UNIQUE,
                        overhang_percentage REAL,
                        support_required INTEGER DEFAULT 0,
                        optimal_orientation TEXT,
                        thin_wall_warnings TEXT,
                        printability_score INTEGER,
                        analysis_warnings TEXT,
                        estimated_print_time INTEGER,
                        estimated_filament_grams REAL,
                        analyzed_at DATETIME,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_model_analysis_score ON model_analysis(printability_score)');
                }
            }
        ],

        // Remix/Fork Tracking
        [
            'name' => 'Related models: remix columns',
            'description' => 'Track remix/fork relationships between models',
            'check' => fn($db) => columnExists($db, 'related_models', 'is_remix'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE related_models ADD COLUMN is_remix TINYINT DEFAULT 0');
                    $db->exec('ALTER TABLE related_models ADD COLUMN remix_notes TEXT');
                    $db->exec('ALTER TABLE related_models ADD COLUMN created_by INT');
                } else {
                    $db->exec('ALTER TABLE related_models ADD COLUMN is_remix INTEGER DEFAULT 0');
                    $db->exec('ALTER TABLE related_models ADD COLUMN remix_notes TEXT');
                    $db->exec('ALTER TABLE related_models ADD COLUMN created_by INTEGER');
                }
            }
        ],

        // Models: remix source tracking
        [
            'name' => 'Models: remix source columns',
            'description' => 'Track original source for remixed models',
            'check' => fn($db) => columnExists($db, 'models', 'remix_of'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE models ADD COLUMN remix_of INT');
                    $db->exec('ALTER TABLE models ADD COLUMN external_source_url VARCHAR(500)');
                    $db->exec('ALTER TABLE models ADD COLUMN external_source_id VARCHAR(100)');
                } else {
                    $db->exec('ALTER TABLE models ADD COLUMN remix_of INTEGER');
                    $db->exec('ALTER TABLE models ADD COLUMN external_source_url TEXT');
                    $db->exec('ALTER TABLE models ADD COLUMN external_source_id TEXT');
                }
            }
        ],

        // Import Jobs
        [
            'name' => 'Import jobs table',
            'description' => 'Track bulk import jobs from external sources',
            'check' => fn($db) => tableExists($db, 'import_jobs'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE import_jobs (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        source_url VARCHAR(500) NOT NULL,
                        source_type VARCHAR(50) NOT NULL,
                        status VARCHAR(20) DEFAULT "pending",
                        total_items INT DEFAULT 0,
                        imported_items INT DEFAULT 0,
                        failed_items INT DEFAULT 0,
                        settings TEXT,
                        error_log TEXT,
                        created_by INT NOT NULL,
                        started_at DATETIME,
                        completed_at DATETIME,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_import_jobs_status ON import_jobs(status)');
                    $db->exec('CREATE INDEX idx_import_jobs_user ON import_jobs(created_by)');
                } else {
                    $db->exec('CREATE TABLE import_jobs (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        source_url TEXT NOT NULL,
                        source_type TEXT NOT NULL,
                        status TEXT DEFAULT "pending",
                        total_items INTEGER DEFAULT 0,
                        imported_items INTEGER DEFAULT 0,
                        failed_items INTEGER DEFAULT 0,
                        settings TEXT,
                        error_log TEXT,
                        created_by INTEGER NOT NULL,
                        started_at DATETIME,
                        completed_at DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_import_jobs_status ON import_jobs(status)');
                    $db->exec('CREATE INDEX idx_import_jobs_user ON import_jobs(created_by)');
                }
            }
        ],

        // Import job items
        [
            'name' => 'Import job items table',
            'description' => 'Track individual items within an import job',
            'check' => fn($db) => tableExists($db, 'import_job_items'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE import_job_items (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        job_id INT NOT NULL,
                        external_id VARCHAR(100),
                        external_url VARCHAR(500),
                        name VARCHAR(255),
                        status VARCHAR(20) DEFAULT "pending",
                        model_id INT,
                        error_message TEXT,
                        metadata TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_import_items_job ON import_job_items(job_id)');
                    $db->exec('CREATE INDEX idx_import_items_status ON import_job_items(status)');
                } else {
                    $db->exec('CREATE TABLE import_job_items (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        job_id INTEGER NOT NULL,
                        external_id TEXT,
                        external_url TEXT,
                        name TEXT,
                        status TEXT DEFAULT "pending",
                        model_id INTEGER,
                        error_message TEXT,
                        metadata TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE SET NULL
                    )');
                    $db->exec('CREATE INDEX idx_import_items_job ON import_job_items(job_id)');
                    $db->exec('CREATE INDEX idx_import_items_status ON import_job_items(status)');
                }
            }
        ],

        // Batch conversion queue
        [
            'name' => 'Conversion queue table',
            'description' => 'Queue for batch STL to 3MF conversions',
            'check' => fn($db) => tableExists($db, 'conversion_queue'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE conversion_queue (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        source_format VARCHAR(10) NOT NULL,
                        target_format VARCHAR(10) NOT NULL,
                        status VARCHAR(20) DEFAULT "pending",
                        priority INT DEFAULT 0,
                        error_message TEXT,
                        queued_by INT,
                        started_at DATETIME,
                        completed_at DATETIME,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (queued_by) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_conversion_status ON conversion_queue(status, priority)');
                } else {
                    $db->exec('CREATE TABLE conversion_queue (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        source_format TEXT NOT NULL,
                        target_format TEXT NOT NULL,
                        status TEXT DEFAULT "pending",
                        priority INTEGER DEFAULT 0,
                        error_message TEXT,
                        queued_by INTEGER,
                        started_at DATETIME,
                        completed_at DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (queued_by) REFERENCES users(id) ON DELETE SET NULL
                    )');
                    $db->exec('CREATE INDEX idx_conversion_status ON conversion_queue(status, priority)');
                }
            }
        ],

        // =====================================================================
        // ENTERPRISE AUTHENTICATION
        // =====================================================================

        // SAML SSO
        [
            'name' => 'Users: SAML SSO columns',
            'description' => 'SAML identity provider integration',
            'check' => fn($db) => columnExists($db, 'users', 'saml_id'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE users ADD COLUMN saml_id VARCHAR(255)');
                    $db->exec('ALTER TABLE users ADD COLUMN saml_idp VARCHAR(100)');
                    $db->exec('ALTER TABLE users ADD COLUMN saml_attributes TEXT');
                    $db->exec('CREATE INDEX idx_users_saml ON users(saml_id)');
                } else {
                    $db->exec('ALTER TABLE users ADD COLUMN saml_id TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN saml_idp TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN saml_attributes TEXT');
                    $db->exec('CREATE INDEX idx_users_saml ON users(saml_id)');
                }
            }
        ],

        // LDAP/AD
        [
            'name' => 'Users: LDAP columns',
            'description' => 'LDAP/Active Directory integration',
            'check' => fn($db) => columnExists($db, 'users', 'ldap_dn'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('ALTER TABLE users ADD COLUMN ldap_dn VARCHAR(500)');
                    $db->exec('ALTER TABLE users ADD COLUMN ldap_guid VARCHAR(64)');
                    $db->exec('ALTER TABLE users ADD COLUMN ldap_groups TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN ldap_synced_at DATETIME');
                    $db->exec('CREATE INDEX idx_users_ldap ON users(ldap_dn(191))');
                } else {
                    $db->exec('ALTER TABLE users ADD COLUMN ldap_dn TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN ldap_guid TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN ldap_groups TEXT');
                    $db->exec('ALTER TABLE users ADD COLUMN ldap_synced_at DATETIME');
                    $db->exec('CREATE INDEX idx_users_ldap ON users(ldap_dn)');
                }
            }
        ],

        // Auth method tracking
        [
            'name' => 'Users: auth method column',
            'description' => 'Track authentication method per user',
            'check' => fn($db) => columnExists($db, 'users', 'auth_method'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec("ALTER TABLE users ADD COLUMN auth_method VARCHAR(20) DEFAULT 'local'");
                    $db->exec('ALTER TABLE users ADD COLUMN last_auth_at DATETIME');
                    $db->exec('ALTER TABLE users ADD COLUMN last_auth_ip VARCHAR(45)');
                } else {
                    $db->exec("ALTER TABLE users ADD COLUMN auth_method TEXT DEFAULT 'local'");
                    $db->exec('ALTER TABLE users ADD COLUMN last_auth_at DATETIME');
                    $db->exec('ALTER TABLE users ADD COLUMN last_auth_ip TEXT');
                }
            }
        ],

        // =====================================================================
        // ADVANCED AUDIT LOGGING
        // =====================================================================

        [
            'name' => 'Audit log table',
            'description' => 'Enhanced audit logging for compliance and security',
            'check' => fn($db) => tableExists($db, 'audit_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE audit_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        event_type VARCHAR(50) NOT NULL,
                        event_name VARCHAR(100) NOT NULL,
                        severity VARCHAR(20) DEFAULT "info",
                        user_id INT,
                        ip_address VARCHAR(45),
                        user_agent VARCHAR(500),
                        resource_type VARCHAR(50),
                        resource_id INT,
                        resource_name VARCHAR(255),
                        old_value TEXT,
                        new_value TEXT,
                        metadata TEXT,
                        session_id VARCHAR(128),
                        request_id VARCHAR(36),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_audit_event ON audit_log(event_type, event_name)');
                    $db->exec('CREATE INDEX idx_audit_user ON audit_log(user_id)');
                    $db->exec('CREATE INDEX idx_audit_resource ON audit_log(resource_type, resource_id)');
                    $db->exec('CREATE INDEX idx_audit_created ON audit_log(created_at)');
                    $db->exec('CREATE INDEX idx_audit_severity ON audit_log(severity)');
                } else {
                    $db->exec('CREATE TABLE audit_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        event_type TEXT NOT NULL,
                        event_name TEXT NOT NULL,
                        severity TEXT DEFAULT "info",
                        user_id INTEGER,
                        ip_address TEXT,
                        user_agent TEXT,
                        resource_type TEXT,
                        resource_id INTEGER,
                        resource_name TEXT,
                        old_value TEXT,
                        new_value TEXT,
                        metadata TEXT,
                        session_id TEXT,
                        request_id TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    )');
                    $db->exec('CREATE INDEX idx_audit_event ON audit_log(event_type, event_name)');
                    $db->exec('CREATE INDEX idx_audit_user ON audit_log(user_id)');
                    $db->exec('CREATE INDEX idx_audit_resource ON audit_log(resource_type, resource_id)');
                    $db->exec('CREATE INDEX idx_audit_created ON audit_log(created_at)');
                    $db->exec('CREATE INDEX idx_audit_severity ON audit_log(severity)');
                }
            }
        ],

        // =====================================================================
        // DATA RETENTION & COMPLIANCE
        // =====================================================================

        [
            'name' => 'Retention policies table',
            'description' => 'Configure data retention rules',
            'check' => fn($db) => tableExists($db, 'retention_policies'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE retention_policies (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        description TEXT,
                        entity_type VARCHAR(50) NOT NULL,
                        condition_field VARCHAR(100),
                        condition_operator VARCHAR(20),
                        condition_value VARCHAR(255),
                        action VARCHAR(20) NOT NULL,
                        retention_days INT,
                        is_active TINYINT DEFAULT 1,
                        last_executed_at DATETIME,
                        items_affected INT DEFAULT 0,
                        created_by INT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE retention_policies (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        description TEXT,
                        entity_type TEXT NOT NULL,
                        condition_field TEXT,
                        condition_operator TEXT,
                        condition_value TEXT,
                        action TEXT NOT NULL,
                        retention_days INTEGER,
                        is_active INTEGER DEFAULT 1,
                        last_executed_at DATETIME,
                        items_affected INTEGER DEFAULT 0,
                        created_by INTEGER,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    )');
                }
            }
        ],

        [
            'name' => 'Legal holds table',
            'description' => 'Prevent deletion of items under legal hold',
            'check' => fn($db) => tableExists($db, 'legal_holds'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE legal_holds (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        description TEXT,
                        entity_type VARCHAR(50) NOT NULL,
                        entity_id INT NOT NULL,
                        reason TEXT NOT NULL,
                        reference_number VARCHAR(100),
                        created_by INT NOT NULL,
                        expires_at DATETIME,
                        released_at DATETIME,
                        released_by INT,
                        release_reason TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (released_by) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_legal_holds_entity ON legal_holds(entity_type, entity_id)');
                    $db->exec('CREATE INDEX idx_legal_holds_active ON legal_holds(released_at)');
                } else {
                    $db->exec('CREATE TABLE legal_holds (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        description TEXT,
                        entity_type TEXT NOT NULL,
                        entity_id INTEGER NOT NULL,
                        reason TEXT NOT NULL,
                        reference_number TEXT,
                        created_by INTEGER NOT NULL,
                        expires_at DATETIME,
                        released_at DATETIME,
                        released_by INTEGER,
                        release_reason TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (released_by) REFERENCES users(id) ON DELETE SET NULL
                    )');
                    $db->exec('CREATE INDEX idx_legal_holds_entity ON legal_holds(entity_type, entity_id)');
                    $db->exec('CREATE INDEX idx_legal_holds_active ON legal_holds(released_at)');
                }
            }
        ],

        [
            'name' => 'Retention execution log table',
            'description' => 'Track retention policy execution history',
            'check' => fn($db) => tableExists($db, 'retention_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE retention_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        policy_id INT NOT NULL,
                        action_taken VARCHAR(20) NOT NULL,
                        items_processed INT DEFAULT 0,
                        items_affected INT DEFAULT 0,
                        items_skipped INT DEFAULT 0,
                        error_count INT DEFAULT 0,
                        details TEXT,
                        started_at DATETIME,
                        completed_at DATETIME,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (policy_id) REFERENCES retention_policies(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_retention_log_policy ON retention_log(policy_id)');
                } else {
                    $db->exec('CREATE TABLE retention_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        policy_id INTEGER NOT NULL,
                        action_taken TEXT NOT NULL,
                        items_processed INTEGER DEFAULT 0,
                        items_affected INTEGER DEFAULT 0,
                        items_skipped INTEGER DEFAULT 0,
                        error_count INTEGER DEFAULT 0,
                        details TEXT,
                        started_at DATETIME,
                        completed_at DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (policy_id) REFERENCES retention_policies(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_retention_log_policy ON retention_log(policy_id)');
                }
            }
        ],

        // =====================================================================
        // ADVANCED ANALYTICS
        // =====================================================================

        [
            'name' => 'Scheduled reports table',
            'description' => 'Configure automated report generation and delivery',
            'check' => fn($db) => tableExists($db, 'scheduled_reports'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE scheduled_reports (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        description TEXT,
                        report_type VARCHAR(50) NOT NULL,
                        filters TEXT,
                        columns TEXT,
                        schedule VARCHAR(50) NOT NULL,
                        recipients TEXT NOT NULL,
                        format VARCHAR(20) DEFAULT "csv",
                        include_charts TINYINT DEFAULT 0,
                        is_active TINYINT DEFAULT 1,
                        last_run_at DATETIME,
                        last_status VARCHAR(20),
                        last_error TEXT,
                        next_run_at DATETIME,
                        created_by INT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_scheduled_reports_next ON scheduled_reports(next_run_at)');
                    $db->exec('CREATE INDEX idx_scheduled_reports_active ON scheduled_reports(is_active)');
                } else {
                    $db->exec('CREATE TABLE scheduled_reports (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        description TEXT,
                        report_type TEXT NOT NULL,
                        filters TEXT,
                        columns TEXT,
                        schedule TEXT NOT NULL,
                        recipients TEXT NOT NULL,
                        format TEXT DEFAULT "csv",
                        include_charts INTEGER DEFAULT 0,
                        is_active INTEGER DEFAULT 1,
                        last_run_at DATETIME,
                        last_status TEXT,
                        last_error TEXT,
                        next_run_at DATETIME,
                        created_by INTEGER,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    )');
                    $db->exec('CREATE INDEX idx_scheduled_reports_next ON scheduled_reports(next_run_at)');
                    $db->exec('CREATE INDEX idx_scheduled_reports_active ON scheduled_reports(is_active)');
                }
            }
        ],

        [
            'name' => 'Report execution log table',
            'description' => 'Track report generation history',
            'check' => fn($db) => tableExists($db, 'report_log'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE report_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        report_id INT,
                        report_type VARCHAR(50) NOT NULL,
                        status VARCHAR(20) NOT NULL,
                        file_path VARCHAR(500),
                        file_size BIGINT,
                        row_count INT,
                        recipients_notified INT DEFAULT 0,
                        error_message TEXT,
                        execution_time_ms INT,
                        triggered_by INT,
                        started_at DATETIME,
                        completed_at DATETIME,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (report_id) REFERENCES scheduled_reports(id) ON DELETE SET NULL,
                        FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_report_log_report ON report_log(report_id)');
                    $db->exec('CREATE INDEX idx_report_log_created ON report_log(created_at)');
                } else {
                    $db->exec('CREATE TABLE report_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        report_id INTEGER,
                        report_type TEXT NOT NULL,
                        status TEXT NOT NULL,
                        file_path TEXT,
                        file_size INTEGER,
                        row_count INTEGER,
                        recipients_notified INTEGER DEFAULT 0,
                        error_message TEXT,
                        execution_time_ms INTEGER,
                        triggered_by INTEGER,
                        started_at DATETIME,
                        completed_at DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (report_id) REFERENCES scheduled_reports(id) ON DELETE SET NULL,
                        FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL
                    )');
                    $db->exec('CREATE INDEX idx_report_log_report ON report_log(report_id)');
                    $db->exec('CREATE INDEX idx_report_log_created ON report_log(created_at)');
                }
            }
        ],

        [
            'name' => 'Dashboard widgets table',
            'description' => 'Store custom dashboard widget configurations',
            'check' => fn($db) => tableExists($db, 'dashboard_widgets'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE dashboard_widgets (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        widget_type VARCHAR(50) NOT NULL,
                        title VARCHAR(255),
                        config TEXT,
                        position_x INT DEFAULT 0,
                        position_y INT DEFAULT 0,
                        width INT DEFAULT 1,
                        height INT DEFAULT 1,
                        is_visible TINYINT DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_dashboard_widgets_user ON dashboard_widgets(user_id)');
                } else {
                    $db->exec('CREATE TABLE dashboard_widgets (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        widget_type TEXT NOT NULL,
                        title TEXT,
                        config TEXT,
                        position_x INTEGER DEFAULT 0,
                        position_y INTEGER DEFAULT 0,
                        width INTEGER DEFAULT 1,
                        height INTEGER DEFAULT 1,
                        is_visible INTEGER DEFAULT 1,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_dashboard_widgets_user ON dashboard_widgets(user_id)');
                }
            }
        ],

        [
            'name' => 'Saved filters table',
            'description' => 'Store reusable filter presets for reports',
            'check' => fn($db) => tableExists($db, 'saved_filters'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE saved_filters (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        name VARCHAR(255) NOT NULL,
                        entity_type VARCHAR(50) NOT NULL,
                        filters TEXT NOT NULL,
                        is_default TINYINT DEFAULT 0,
                        is_shared TINYINT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_saved_filters_user ON saved_filters(user_id)');
                } else {
                    $db->exec('CREATE TABLE saved_filters (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        name TEXT NOT NULL,
                        entity_type TEXT NOT NULL,
                        filters TEXT NOT NULL,
                        is_default INTEGER DEFAULT 0,
                        is_shared INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_saved_filters_user ON saved_filters(user_id)');
                }
            }
        ],

        // Share links table (if not exists)
        [
            'name' => 'Share links table',
            'description' => 'Public share links for models',
            'check' => fn($db) => tableExists($db, 'share_links'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE share_links (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        token VARCHAR(64) NOT NULL UNIQUE,
                        password_hash VARCHAR(255),
                        expires_at DATETIME,
                        download_limit INT,
                        download_count INT DEFAULT 0,
                        is_active TINYINT DEFAULT 1,
                        created_by INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_share_links_token ON share_links(token)');
                } else {
                    $db->exec('CREATE TABLE share_links (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        token TEXT NOT NULL UNIQUE,
                        password_hash TEXT,
                        expires_at DATETIME,
                        download_limit INTEGER,
                        download_count INTEGER DEFAULT 0,
                        is_active INTEGER DEFAULT 1,
                        created_by INTEGER NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_share_links_token ON share_links(token)');
                }
            }
        ],

        // Print history table (if not exists)
        [
            'name' => 'Print history table',
            'description' => 'Track print history with settings and results',
            'check' => fn($db) => tableExists($db, 'print_history'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE print_history (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        user_id INT NOT NULL,
                        printer_id INT,
                        status VARCHAR(20) DEFAULT "completed",
                        filament_type VARCHAR(50),
                        filament_color VARCHAR(50),
                        filament_used_grams DECIMAL(10,2),
                        print_time_minutes INT,
                        layer_height DECIMAL(4,2),
                        infill_percentage INT,
                        notes TEXT,
                        rating INT,
                        printed_at DATETIME,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                    $db->exec('CREATE INDEX idx_print_history_model ON print_history(model_id)');
                    $db->exec('CREATE INDEX idx_print_history_user ON print_history(user_id)');
                } else {
                    $db->exec('CREATE TABLE print_history (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        user_id INTEGER NOT NULL,
                        printer_id INTEGER,
                        status TEXT DEFAULT "completed",
                        filament_type TEXT,
                        filament_color TEXT,
                        filament_used_grams REAL,
                        print_time_minutes INTEGER,
                        layer_height REAL,
                        infill_percentage INTEGER,
                        notes TEXT,
                        rating INTEGER,
                        printed_at DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL
                    )');
                    $db->exec('CREATE INDEX idx_print_history_model ON print_history(model_id)');
                    $db->exec('CREATE INDEX idx_print_history_user ON print_history(user_id)');
                }
            }
        ],
        // Remove stale license settings
        [
            'name' => 'Remove license settings',
            'description' => 'Clean up license_email, license_key, license_cache, license_last_sync from settings',
            'check' => function($db) {
                $type = $db->getType();
                $keyCol = $type === 'mysql' ? '`key`' : 'key';
                $result = $db->querySingle("SELECT COUNT(*) FROM settings WHERE $keyCol IN ('license_email','license_key','license_cache','license_last_sync')");
                return (int)$result === 0;
            },
            'apply' => function($db) {
                $type = $db->getType();
                $keyCol = $type === 'mysql' ? '`key`' : 'key';
                $db->exec("DELETE FROM settings WHERE $keyCol IN ('license_email','license_key','license_cache','license_last_sync')");
            }
        ],
        // Password Reset Tokens
        [
            'name' => 'Password resets table',
            'description' => 'Stores password reset tokens for forgot password flow',
            'check' => fn($db) => tableExists($db, 'password_resets'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE password_resets (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        email VARCHAR(255) NOT NULL,
                        token VARCHAR(64) NOT NULL UNIQUE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        expires_at TIMESTAMP NOT NULL,
                        used_at TIMESTAMP NULL,
                        INDEX idx_password_resets_email (email),
                        INDEX idx_password_resets_token (token),
                        INDEX idx_password_resets_expires (expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE password_resets (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        email TEXT NOT NULL,
                        token TEXT NOT NULL UNIQUE,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        expires_at DATETIME NOT NULL,
                        used_at DATETIME
                    )');
                    $db->exec('CREATE INDEX idx_password_resets_email ON password_resets(email)');
                    $db->exec('CREATE INDEX idx_password_resets_token ON password_resets(token)');
                    $db->exec('CREATE INDEX idx_password_resets_expires ON password_resets(expires_at)');
                }
            }
        ],
        // Model External Links
        [
            'name' => 'Model links table',
            'description' => 'Stores external links attached to models (docs, videos, forums, repos, etc.)',
            'check' => fn($db) => tableExists($db, 'model_links'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE model_links (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        title VARCHAR(255) NOT NULL,
                        url TEXT NOT NULL,
                        link_type VARCHAR(50) DEFAULT "other",
                        sort_order INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        INDEX idx_model_links_model (model_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE model_links (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        title TEXT NOT NULL,
                        url TEXT NOT NULL,
                        link_type TEXT DEFAULT "other",
                        sort_order INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_model_links_model ON model_links(model_id)');
                }
            }
        ],
        // Fix rate_limits table schema for RateLimitMiddleware
        [
            'name' => 'Fix rate_limits table schema',
            'description' => 'Update rate_limits table to match RateLimitMiddleware requirements',
            'check' => function($db) {
                if (!tableExists($db, 'rate_limits')) {
                    return true; // Will be created by earlier migration
                }
                // Check if new schema columns exist
                return columnExists($db, 'rate_limits', 'key_name') &&
                       columnExists($db, 'rate_limits', 'expires_at');
            },
            'apply' => function($db) {
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
        // Performance indexes for common queries
        [
            'name' => 'Performance indexes',
            'description' => 'Add indexes on frequently queried columns for faster lookups',
            'check' => function($db) {
                return indexExists($db, 'models', 'idx_models_parent_id');
            },
            'apply' => function($db) {
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
        // Model attachments table for images and PDFs
        [
            'name' => 'Model attachments table',
            'description' => 'Stores document and image attachments for models',
            'check' => fn($db) => tableExists($db, 'model_attachments'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE model_attachments (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        filename VARCHAR(255) NOT NULL,
                        file_path TEXT NOT NULL,
                        file_type VARCHAR(20) NOT NULL,
                        mime_type VARCHAR(100),
                        file_size INT,
                        original_filename VARCHAR(255),
                        display_order INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        INDEX idx_model_attachments_model (model_id),
                        INDEX idx_model_attachments_type (file_type)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE model_attachments (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        filename TEXT NOT NULL,
                        file_path TEXT NOT NULL,
                        file_type TEXT NOT NULL,
                        mime_type TEXT,
                        file_size INTEGER,
                        original_filename TEXT,
                        display_order INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
                    )');
                    $db->exec('CREATE INDEX idx_model_attachments_model ON model_attachments(model_id)');
                    $db->exec('CREATE INDEX idx_model_attachments_type ON model_attachments(file_type)');
                }
            }
        ],
        [
            'name' => 'Collections table',
            'description' => 'Stores named collections for organizing models',
            'check' => fn($db) => tableExists($db, 'collections'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE collections (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL UNIQUE,
                        description TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE collections (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name VARCHAR(255) NOT NULL UNIQUE,
                        description TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )');
                }
            }
        ],
        [
            'name' => 'Annotations table',
            'description' => 'Stores 3D model annotations with position and normal data',
            'check' => fn($db) => tableExists($db, 'annotations'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE annotations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        model_id INT NOT NULL,
                        user_id INT NOT NULL,
                        position_x DOUBLE NOT NULL,
                        position_y DOUBLE NOT NULL,
                        position_z DOUBLE NOT NULL,
                        normal_x DOUBLE DEFAULT 0,
                        normal_y DOUBLE DEFAULT 0,
                        normal_z DOUBLE DEFAULT 1,
                        content TEXT NOT NULL,
                        color VARCHAR(7) DEFAULT "#ff0000",
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE annotations (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        model_id INTEGER NOT NULL,
                        user_id INTEGER NOT NULL,
                        position_x REAL NOT NULL,
                        position_y REAL NOT NULL,
                        position_z REAL NOT NULL,
                        normal_x REAL DEFAULT 0,
                        normal_y REAL DEFAULT 0,
                        normal_z REAL DEFAULT 1,
                        content TEXT NOT NULL,
                        color TEXT DEFAULT "#ff0000",
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    )');
                }
            }
        ],
        [
            'name' => 'RUM metrics table',
            'description' => 'Stores Real User Monitoring performance metrics',
            'check' => fn($db) => tableExists($db, 'rum_metrics'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE rum_metrics (
                        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                        url VARCHAR(255),
                        referrer VARCHAR(255),
                        user_agent VARCHAR(255),
                        connection_type VARCHAR(20),
                        lcp INT,
                        fid INT,
                        cls DECIMAL(5,3),
                        fcp INT,
                        fp INT,
                        ttfb INT,
                        dom_content_loaded INT,
                        page_load INT,
                        dom_interactive INT,
                        resource_count INT,
                        js_errors INT,
                        created_at DATETIME,
                        INDEX idx_rum_url (url),
                        INDEX idx_rum_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE rum_metrics (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        url TEXT,
                        referrer TEXT,
                        user_agent TEXT,
                        connection_type TEXT,
                        lcp INTEGER,
                        fid INTEGER,
                        cls REAL,
                        fcp INTEGER,
                        fp INTEGER,
                        ttfb INTEGER,
                        dom_content_loaded INTEGER,
                        page_load INTEGER,
                        dom_interactive INTEGER,
                        resource_count INTEGER,
                        js_errors INTEGER,
                        created_at TEXT
                    )');
                    $db->exec('CREATE INDEX idx_rum_url ON rum_metrics(url)');
                    $db->exec('CREATE INDEX idx_rum_created ON rum_metrics(created_at)');
                }
            }
        ],
        [
            'name' => 'RUM errors table',
            'description' => 'Stores Real User Monitoring JavaScript error data',
            'check' => fn($db) => tableExists($db, 'rum_errors'),
            'apply' => function($db) {
                $type = $db->getType();
                if ($type === 'mysql') {
                    $db->exec('CREATE TABLE rum_errors (
                        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                        url VARCHAR(255),
                        message VARCHAR(500),
                        source VARCHAR(255),
                        line_number INT,
                        created_at DATETIME,
                        INDEX idx_rum_errors_url (url),
                        INDEX idx_rum_errors_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                } else {
                    $db->exec('CREATE TABLE rum_errors (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        url TEXT,
                        message TEXT,
                        source TEXT,
                        line_number INTEGER,
                        created_at TEXT
                    )');
                    $db->exec('CREATE INDEX idx_rum_errors_url ON rum_errors(url)');
                    $db->exec('CREATE INDEX idx_rum_errors_created ON rum_errors(created_at)');
                }
            }
        ],
    ];
}
