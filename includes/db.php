<?php
/**
 * Database abstraction layer supporting SQLite and MySQL
 */

// SQLite3 type constants for compatibility
if (!defined('SQLITE3_TEXT')) define('SQLITE3_TEXT', 3);
if (!defined('SQLITE3_INTEGER')) define('SQLITE3_INTEGER', 1);
if (!defined('SQLITE3_NULL')) define('SQLITE3_NULL', 5);
if (!defined('SQLITE3_ASSOC')) define('SQLITE3_ASSOC', 1);

// Statement wrapper for SQLite3 API compatibility
class DatabaseStatement {
    private $stmt;
    private $params = [];

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function bindValue($param, $value, $type = null) {
        $this->params[$param] = $value;
        return true;
    }

    public function execute($params = null) {
        if ($params !== null) {
            $this->stmt->execute($params);
        } else {
            $this->stmt->execute($this->params);
        }
        $this->params = [];
        return new DatabaseResult($this->stmt);
    }

    // Convenience method: execute and fetch single column
    public function fetchColumn($column = 0) {
        $result = $this->execute();
        return $result->fetchColumn($column);
    }

    // Convenience method: execute and fetch single row
    public function fetch($mode = PDO::FETCH_ASSOC) {
        $result = $this->execute();
        return $result->fetch($mode);
    }

    // Convenience method: execute and fetch as array (SQLite3 compat)
    public function fetchArray($mode = SQLITE3_ASSOC) {
        $result = $this->execute();
        return $result->fetchArray($mode);
    }
}

// Result wrapper for SQLite3 API compatibility
class DatabaseResult {
    private $stmt;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function fetchArray($mode = SQLITE3_ASSOC) {
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch($mode = PDO::FETCH_ASSOC) {
        return $this->stmt->fetch($mode);
    }

    public function fetchColumn($column = 0) {
        return $this->stmt->fetchColumn($column);
    }
}

// Database wrapper class for unified interface
class Database {
    private $pdo;
    private $type;

    public function __construct($type, $config = []) {
        $this->type = $type;

        if ($type === 'mysql') {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } else {
            $this->pdo = new PDO('sqlite:' . $config['path'], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        }
    }

    public function getType() {
        return $this->type;
    }

    public function getPDO() {
        return $this->pdo;
    }

    public function prepare($sql) {
        // Return wrapped statement for SQLite3 API compatibility
        return new DatabaseStatement($this->pdo->prepare($sql));
    }

    public function query($sql) {
        // Return wrapped result for SQLite3 API compatibility
        return new DatabaseResult($this->pdo->query($sql));
    }

    public function exec($sql) {
        return $this->pdo->exec($sql);
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    public function querySingle($sql) {
        $stmt = $this->pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    // SQLite3 compatibility shim
    public function lastInsertRowID() {
        return $this->lastInsertId();
    }

    // Get the number of rows changed by the last statement
    public function changes() {
        if ($this->type === 'mysql') {
            // MySQL uses ROW_COUNT()
            $result = $this->pdo->query('SELECT ROW_COUNT()');
            return (int) $result->fetchColumn();
        } else {
            // SQLite uses changes()
            $result = $this->pdo->query('SELECT changes()');
            return (int) $result->fetchColumn();
        }
    }
}

// Get database connection
function getDB() {
    static $db = null;

    if ($db === null) {
        try {
            if (DB_TYPE === 'mysql') {
                $db = new Database('mysql', [
                    'host' => defined('DB_HOST') ? DB_HOST : 'localhost',
                    'port' => defined('DB_PORT') ? DB_PORT : '3306',
                    'name' => defined('DB_NAME') ? DB_NAME : 'silo',
                    'user' => defined('DB_USER') ? DB_USER : '',
                    'pass' => defined('DB_PASS') ? DB_PASS : ''
                ]);
            } else {
                $dbPath = DB_PATH;
                $dbExists = file_exists($dbPath);

                $db = new Database('sqlite', ['path' => $dbPath]);

                // Initialize database if it doesn't exist
                if (!$dbExists) {
                    initializeDatabase($db);
                    logInfo('Database initialized', ['path' => $dbPath]);
                }
            }

            // Run migrations
            runMigrations($db);
        } catch (Exception $e) {
            logException($e, ['action' => 'database_connect']);
            throw $e;
        }
    }

    return $db;
}

// Initialize a new database
function initializeDatabase($db) {
    $type = $db->getType();

    if ($type === 'mysql') {
        $schema = getMySQLSchema();
    } else {
        $schema = getSQLiteSchema();
    }

    // Split and execute statements
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $db->exec($stmt);
        }
    }
}

// Get SQLite schema
function getSQLiteSchema() {
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    is_admin INTEGER DEFAULT 0,
    permissions TEXT,
    oidc_id TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    filename TEXT,
    file_path TEXT,
    file_size INTEGER,
    file_type TEXT,
    description TEXT,
    creator TEXT,
    collection TEXT,
    source_url TEXT,
    parent_id INTEGER,
    original_path TEXT,
    part_count INTEGER DEFAULT 0,
    print_type TEXT,
    original_size INTEGER,
    file_hash TEXT,
    dedup_path TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES models(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS model_categories (
    model_id INTEGER,
    category_id INTEGER,
    PRIMARY KEY (model_id, category_id),
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO categories (name) VALUES ('Functional');
INSERT OR IGNORE INTO categories (name) VALUES ('Decorative');
INSERT OR IGNORE INTO categories (name) VALUES ('Tools');
INSERT OR IGNORE INTO categories (name) VALUES ('Gaming');
INSERT OR IGNORE INTO categories (name) VALUES ('Art');
INSERT OR IGNORE INTO categories (name) VALUES ('Mechanical');

CREATE TABLE IF NOT EXISTS collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    permissions TEXT,
    is_system INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_groups (
    user_id INTEGER,
    group_id INTEGER,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO groups (name, description, permissions, is_system) VALUES ('Admin', 'Full system access', '["upload","delete","edit","admin","view_stats"]', 1);
INSERT OR IGNORE INTO groups (name, description, permissions, is_system) VALUES ('Users', 'Default user permissions', '["upload","view_stats"]', 1);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
SQL;
}

// Get MySQL schema
function getMySQLSchema() {
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_admin TINYINT DEFAULT 0,
    permissions TEXT,
    oidc_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    filename VARCHAR(255),
    file_path VARCHAR(500),
    file_size BIGINT,
    file_type VARCHAR(50),
    description TEXT,
    creator VARCHAR(255),
    collection VARCHAR(255),
    source_url VARCHAR(500),
    parent_id INT,
    original_path VARCHAR(500),
    part_count INT DEFAULT 0,
    print_type VARCHAR(50),
    original_size BIGINT,
    file_hash VARCHAR(64),
    dedup_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS model_categories (
    model_id INT,
    category_id INT,
    PRIMARY KEY (model_id, category_id),
    FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO categories (name) VALUES ('Functional'), ('Decorative'), ('Tools'), ('Gaming'), ('Art'), ('Mechanical');

CREATE TABLE IF NOT EXISTS collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `groups` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    permissions TEXT,
    is_system TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_groups (
    user_id INT,
    group_id INT,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `groups` (name, description, permissions, is_system) VALUES
    ('Admin', 'Full system access', '["upload","delete","edit","admin","view_stats"]', 1),
    ('Users', 'Default user permissions', '["upload","view_stats"]', 1);

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(255) PRIMARY KEY,
    `value` TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
}

// Get user by username or email
function getUserByLogin($login) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :login OR email = :login');
        $stmt->execute([':login' => $login]);
        return $stmt->fetch();
    } catch (Exception $e) {
        logException($e, ['action' => 'get_user_by_login', 'login' => $login]);
        return null;
    }
}

// Verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Check if a column exists in a table
function columnExists($db, $table, $column) {
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
function tableExists($db, $table) {
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

// Run database migrations
function runMigrations($db) {
    $type = $db->getType();

    // Ensure tables exist
    if (!tableExists($db, 'users')) {
        initializeDatabase($db);
        return;
    }

    // Migration: Add permissions column to users
    if (!columnExists($db, 'users', 'permissions')) {
        $db->exec('ALTER TABLE users ADD COLUMN permissions TEXT');
        logInfo('Migration: Added permissions column to users table');
    }

    // Migration: Add oidc_id column to users
    if (!columnExists($db, 'users', 'oidc_id')) {
        if ($type === 'mysql') {
            $db->exec('ALTER TABLE users ADD COLUMN oidc_id VARCHAR(255)');
        } else {
            $db->exec('ALTER TABLE users ADD COLUMN oidc_id TEXT');
        }
        logInfo('Migration: Added oidc_id column to users table');
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
        'allowed_extensions' => 'stl,3mf,zip',
        'auto_deduplication' => '0',
        'last_deduplication' => '',
        'oidc_enabled' => '0',
        'oidc_provider_url' => '',
        'oidc_client_id' => '',
        'oidc_client_secret' => '',
        'oidc_button_text' => 'Sign in with SSO',
        'site_url' => '',
        'force_site_url' => '0'
    ];

    $keyCol = $type === 'mysql' ? '`key`' : 'key';
    $valueCol = $type === 'mysql' ? '`value`' : 'value';
    $insertIgnore = $type === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

    foreach ($defaultSettings as $key => $value) {
        $stmt = $db->prepare("$insertIgnore INTO settings ($keyCol, $valueCol) VALUES (:key, :value)");
        $stmt->execute([':key' => $key, ':value' => $value]);
    }
}

// Get a setting value
function getSetting($key, $default = null) {
    try {
        $db = getDB();
        $type = $db->getType();
        $keyCol = $type === 'mysql' ? '`key`' : 'key';
        $stmt = $db->prepare("SELECT value FROM settings WHERE $keyCol = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Set a setting value
function setSetting($key, $value) {
    try {
        $db = getDB();
        $type = $db->getType();

        if ($type === 'mysql') {
            $stmt = $db->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (:key, :value, NOW()) ON DUPLICATE KEY UPDATE `value` = :value2, updated_at = NOW()');
            $stmt->execute([':key' => $key, ':value' => $value, ':value2' => $value]);
        } else {
            $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)');
            $stmt->execute([':key' => $key, ':value' => $value]);
        }
        return true;
    } catch (Exception $e) {
        logException($e, ['action' => 'set_setting', 'key' => $key]);
        return false;
    }
}

// Get all settings
function getAllSettings() {
    try {
        $db = getDB();
        $type = $db->getType();
        $keyCol = $type === 'mysql' ? '`key`' : 'key';
        $result = $db->query("SELECT $keyCol as setting_key, value FROM settings");
        $settings = [];
        while ($row = $result->fetch()) {
            $settings[$row['setting_key']] = $row['value'];
        }
        return $settings;
    } catch (Exception $e) {
        return [];
    }
}

// Get allowed file extensions (configurable via settings)
function getAllowedExtensions() {
    $setting = getSetting('allowed_extensions', 'stl,3mf,zip');
    return array_map('trim', explode(',', $setting));
}

// Get model extensions (non-zip file types that can be 3D rendered)
function getModelExtensions() {
    $allowed = getAllowedExtensions();
    return array_filter($allowed, fn($ext) => $ext !== 'zip');
}

// Check if an extension is allowed
function isExtensionAllowed($extension) {
    return in_array(strtolower($extension), getAllowedExtensions());
}

// Check if an extension is a model format (not a container like zip)
function isModelExtension($extension) {
    return in_array(strtolower($extension), getModelExtensions());
}
