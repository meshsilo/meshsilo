<?php
/**
 * Database abstraction layer supporting SQLite and MySQL
 */

// SQLite3 type constants for compatibility
if (!defined('SQLITE3_TEXT')) define('SQLITE3_TEXT', 3);
if (!defined('SQLITE3_INTEGER')) define('SQLITE3_INTEGER', 1);
if (!defined('SQLITE3_FLOAT')) define('SQLITE3_FLOAT', 2);
if (!defined('SQLITE3_BLOB')) define('SQLITE3_BLOB', 4);
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
            // For positional placeholders (?), PDO::execute() expects 0-indexed array
            // but bindValue uses 1-based indices, so convert to array_values
            $execParams = $this->params;
            if (!empty($execParams)) {
                $firstKey = array_keys($execParams)[0];
                if (is_int($firstKey)) {
                    // Positional placeholders - ensure 0-indexed array
                    ksort($execParams);
                    $execParams = array_values($execParams);
                }
            }
            $this->stmt->execute($execParams);
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
    private static $queryCount = 0;
    private static $queryTime = 0;

    public function __construct($type, $config = []) {
        $this->type = $type;

        if ($type === 'mysql') {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true, // Persistent connections
                PDO::ATTR_EMULATE_PREPARES => false, // Native prepared statements
            ]);
            // MySQL-specific optimizations
            $this->pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        } else {
            $this->pdo = new PDO('sqlite:' . $config['path'], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true, // Persistent connections
            ]);
            // SQLite-specific optimizations
            $this->pdo->exec('PRAGMA journal_mode = WAL'); // Write-Ahead Logging
            $this->pdo->exec('PRAGMA synchronous = NORMAL'); // Faster writes
            $this->pdo->exec('PRAGMA cache_size = -64000'); // 64MB cache
            $this->pdo->exec('PRAGMA temp_store = MEMORY'); // Temp tables in memory
            $this->pdo->exec('PRAGMA mmap_size = 30000000000'); // Memory-mapped I/O
        }
    }

    // Query profiling methods
    public static function getQueryCount(): int {
        return self::$queryCount;
    }

    public static function getQueryTime(): float {
        return self::$queryTime;
    }

    public static function resetStats(): void {
        self::$queryCount = 0;
        self::$queryTime = 0;
    }

    private function profileQuery(callable $callback, $sql = null) {
        self::$queryCount++;
        $start = microtime(true);
        $result = $callback();
        $time = microtime(true) - $start;
        self::$queryTime += $time;

        // Log query using the new database logging channel
        if ($sql !== null && function_exists('logQuery')) {
            logQuery($sql, [], $time);
        } elseif ($time > 0.1 && function_exists('logWarning')) {
            // Fallback for slow queries without SQL context
            logWarning('Slow query detected', ['time_seconds' => round($time, 3)]);
        }

        return $result;
    }

    public function getType() {
        return $this->type;
    }

    public function getPDO() {
        return $this->pdo;
    }

    public function prepare($sql) {
        // Return wrapped statement for SQLite3 API compatibility with profiling
        return $this->profileQuery(function() use ($sql) {
            return new DatabaseStatement($this->pdo->prepare($sql));
        }, $sql);
    }

    public function query($sql) {
        // Return wrapped result for SQLite3 API compatibility with profiling
        return $this->profileQuery(function() use ($sql) {
            return new DatabaseResult($this->pdo->query($sql));
        }, $sql);
    }

    public function exec($sql) {
        return $this->profileQuery(function() use ($sql) {
            return $this->pdo->exec($sql);
        }, $sql);
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

            // Verify schema is ready (web: quick check only, CLI: runs full migrations)
            // Web requests never run migrations to avoid 300s timeout issues
            // Run 'php cli/migrate.php' after deployments to apply schema changes
            verifySchemaReady($db);
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
function verifySchemaReady($db) {
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
            echo '<!DOCTYPE html><html><head><title>Database Setup Required</title></head><body style="font-family: system-ui, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px;">';
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
function runMigrations($db) {
    $type = $db->getType();

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
        'enable_tags' => '1',
        'allowed_extensions' => 'stl,3mf,gcode,zip',
        'auto_deduplication' => '0',
        'last_deduplication' => '',
        'oidc_enabled' => '0',
        'oidc_provider_url' => '',
        'oidc_client_id' => '',
        'oidc_client_secret' => '',
        'oidc_button_text' => 'Sign in with SSO',
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
    // Webhooks Migration
    // =====================
    if (!tableExists($db, 'webhooks')) {
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
        logInfo('Migration: Created webhooks table');
    }

    // Webhook Delivery Log Migration
    if (!tableExists($db, 'webhook_deliveries')) {
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
        logInfo('Migration: Created webhook_deliveries table');
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
    // Smart Collections Migration
    // =====================
    if (!tableExists($db, 'smart_collections')) {
        if ($type === 'mysql') {
            $db->exec('CREATE TABLE smart_collections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                rules TEXT NOT NULL,
                is_public TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } else {
            $db->exec('CREATE TABLE smart_collections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                name TEXT NOT NULL,
                description TEXT,
                rules TEXT NOT NULL,
                is_public INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
        logInfo('Migration: Created smart_collections table');
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
        'idx_models_category_id' => ['table' => 'models', 'column' => 'category_id', 'reason' => 'category filtering'],
        'idx_model_tags_model' => ['table' => 'model_tags', 'column' => 'model_id', 'reason' => 'tag lookups'],
        'idx_model_tags_tag' => ['table' => 'model_tags', 'column' => 'tag_id', 'reason' => 'reverse tag lookups'],
        'idx_favorites_user' => ['table' => 'favorites', 'column' => 'user_id', 'reason' => 'user favorites'],
        'idx_favorites_model' => ['table' => 'favorites', 'column' => 'model_id', 'reason' => 'model favorite count'],
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
}

// Settings cache storage
$GLOBALS['_settings_cache'] = [];

// Get a setting value (with in-memory cache for performance)
function getSetting($key, $default = null) {
    // Return cached value if available
    if (array_key_exists($key, $GLOBALS['_settings_cache'])) {
        return $GLOBALS['_settings_cache'][$key];
    }

    try {
        $db = getDB();
        $type = $db->getType();
        $keyCol = $type === 'mysql' ? '`key`' : 'key';
        $stmt = $db->prepare("SELECT value FROM settings WHERE $keyCol = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        $value = $row ? $row['value'] : $default;

        // Cache the value (including nulls/defaults)
        $GLOBALS['_settings_cache'][$key] = $value;
        return $value;
    } catch (Exception $e) {
        $GLOBALS['_settings_cache'][$key] = $default;
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

        // Update cache with new value
        $GLOBALS['_settings_cache'][$key] = $value;

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
    $setting = getSetting('allowed_extensions', 'stl,3mf,gcode,zip');
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

// =====================
// Tag Functions
// =====================

// Get all tags
function getAllTags() {
    try {
        $db = getDB();
        $result = $db->query('SELECT * FROM tags ORDER BY name');
        $tags = [];
        while ($row = $result->fetch()) {
            $tags[] = $row;
        }
        return $tags;
    } catch (Exception $e) {
        return [];
    }
}

// Get tags for a model
function getModelTags($modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT t.* FROM tags t
            JOIN model_tags mt ON t.id = mt.tag_id
            WHERE mt.model_id = :model_id
            ORDER BY t.name
        ');
        $stmt->execute([':model_id' => $modelId]);
        $tags = [];
        while ($row = $stmt->fetch()) {
            $tags[] = $row;
        }
        return $tags;
    } catch (Exception $e) {
        return [];
    }
}

// Get tags for multiple models in one query (optimized for N+1 prevention)
function getTagsForModels(array $modelIds) {
    if (empty($modelIds)) {
        return [];
    }

    try {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
        $query = "
            SELECT mt.model_id, t.id, t.name, t.color
            FROM model_tags mt
            JOIN tags t ON mt.tag_id = t.id
            WHERE mt.model_id IN ($placeholders)
            ORDER BY t.name
        ";
        $stmt = $db->prepare($query);

        $index = 1;
        foreach ($modelIds as $id) {
            $stmt->bindValue($index++, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Group tags by model_id
        $tagsByModel = [];
        while ($row = $stmt->fetch()) {
            $tagsByModel[$row['model_id']][] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'color' => $row['color']
            ];
        }

        return $tagsByModel;
    } catch (Exception $e) {
        return [];
    }
}

// Get first part for multiple parent models in one query (optimized for N+1 prevention)
function getFirstPartsForModels(array $modelIds) {
    if (empty($modelIds)) {
        return [];
    }

    try {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));

        // Get first part for each parent (using subquery to get minimum id per parent)
        $query = "
            SELECT m.*
            FROM models m
            INNER JOIN (
                SELECT parent_id, MIN(id) as first_id
                FROM models
                WHERE parent_id IN ($placeholders)
                GROUP BY parent_id
            ) first ON m.id = first.first_id
            ORDER BY m.original_path
        ";
        $stmt = $db->prepare($query);

        $index = 1;
        foreach ($modelIds as $id) {
            $stmt->bindValue($index++, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Group by parent_id
        $partsByParent = [];
        while ($row = $stmt->fetch()) {
            $partsByParent[$row['parent_id']] = $row;
        }

        return $partsByParent;
    } catch (Exception $e) {
        return [];
    }
}

// Add tag to model
function addTagToModel($modelId, $tagId) {
    try {
        $db = getDB();
        $type = $db->getType();
        $insertIgnore = $type === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $stmt = $db->prepare("$insertIgnore INTO model_tags (model_id, tag_id) VALUES (:model_id, :tag_id)");
        $stmt->execute([':model_id' => $modelId, ':tag_id' => $tagId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Remove tag from model
function removeTagFromModel($modelId, $tagId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM model_tags WHERE model_id = :model_id AND tag_id = :tag_id');
        $stmt->execute([':model_id' => $modelId, ':tag_id' => $tagId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Create a new tag
function createTag($name, $color = '#6366f1') {
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO tags (name, color) VALUES (:name, :color)');
        $stmt->execute([':name' => trim($name), ':color' => $color]);
        return $db->lastInsertId();
    } catch (Exception $e) {
        return false;
    }
}

// Delete a tag
function deleteTag($tagId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM tags WHERE id = :id');
        $stmt->execute([':id' => $tagId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get tag by name (case insensitive)
function getTagByName($name) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM tags WHERE LOWER(name) = LOWER(:name)');
        $stmt->execute([':name' => trim($name)]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

// Get or create tag by name
function getOrCreateTag($name, $color = '#6366f1') {
    $tag = getTagByName($name);
    if ($tag) {
        return $tag['id'];
    }
    return createTag($name, $color);
}

// =====================
// Favorites Functions
// =====================

// Check if model is favorited by user
function isModelFavorited($modelId, $userId = null) {
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) return false;

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM favorites WHERE model_id = :model_id AND user_id = :user_id');
        $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Toggle favorite status
function toggleFavorite($modelId, $userId = null) {
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) return ['success' => false, 'error' => 'Not logged in'];

    try {
        $db = getDB();

        if (isModelFavorited($modelId, $userId)) {
            $stmt = $db->prepare('DELETE FROM favorites WHERE model_id = :model_id AND user_id = :user_id');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
            return ['success' => true, 'favorited' => false];
        } else {
            $stmt = $db->prepare('INSERT INTO favorites (model_id, user_id) VALUES (:model_id, :user_id)');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
            return ['success' => true, 'favorited' => true];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Get user's favorites
function getUserFavorites($userId = null, $limit = 50) {
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) return [];

    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT m.* FROM models m
            JOIN favorites f ON m.id = f.model_id
            WHERE f.user_id = :user_id AND m.parent_id IS NULL
            ORDER BY f.created_at DESC
            LIMIT :limit
        ');
        $stmt->execute([':user_id' => $userId, ':limit' => $limit]);
        $favorites = [];
        while ($row = $stmt->fetch()) {
            $favorites[] = $row;
        }
        return $favorites;
    } catch (Exception $e) {
        return [];
    }
}

// Get favorite count for a model
function getModelFavoriteCount($modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) FROM favorites WHERE model_id = :model_id');
        $stmt->execute([':model_id' => $modelId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// =====================
// Activity Log Functions
// =====================

// Log an activity
function logActivity($action, $entityType, $entityId = null, $entityName = null, $details = null) {
    if (getSetting('enable_activity_log', '1') !== '1') {
        return true;
    }

    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $db->prepare('
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, entity_name, details, ip_address)
            VALUES (:user_id, :action, :entity_type, :entity_id, :entity_name, :details, :ip_address)
        ');
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':entity_name' => $entityName,
            ':details' => is_array($details) ? json_encode($details) : $details,
            ':ip_address' => $ipAddress
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get activity log entries
function getActivityLog($limit = 50, $offset = 0, $filters = []) {
    try {
        $db = getDB();
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'al.action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $params[':entity_type'] = $filters['entity_type'];
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT al.*, u.username
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE $whereClause
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);

        $activities = [];
        while ($row = $stmt->fetch()) {
            $activities[] = $row;
        }
        return $activities;
    } catch (Exception $e) {
        return [];
    }
}

// Clean old activity log entries
function cleanActivityLog() {
    $retentionDays = (int)getSetting('activity_log_retention_days', '90');
    if ($retentionDays <= 0) return true;

    try {
        $db = getDB();
        $type = $db->getType();

        if ($type === 'mysql') {
            $stmt = $db->prepare('DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        } else {
            $stmt = $db->prepare("DELETE FROM activity_log WHERE created_at < datetime('now', '-' || :days || ' days')");
        }
        $stmt->execute([':days' => $retentionDays]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// =====================
// Recently Viewed Functions
// =====================

// Record a model view
function recordModelView($modelId) {
    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $sessionId = session_id();

        // Delete existing entry for this model (user or session based)
        if ($userId) {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE model_id = :model_id AND user_id = :user_id');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
        } else {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE model_id = :model_id AND session_id = :session_id');
            $stmt->execute([':model_id' => $modelId, ':session_id' => $sessionId]);
        }

        // Insert new entry
        $stmt = $db->prepare('
            INSERT INTO recently_viewed (user_id, session_id, model_id)
            VALUES (:user_id, :session_id, :model_id)
        ');
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id' => $sessionId,
            ':model_id' => $modelId
        ]);

        // Limit to 50 most recent per user/session
        if ($userId) {
            $db->exec("DELETE FROM recently_viewed WHERE user_id = $userId AND id NOT IN (SELECT id FROM recently_viewed WHERE user_id = $userId ORDER BY viewed_at DESC LIMIT 50)");
        } else {
            $db->exec("DELETE FROM recently_viewed WHERE session_id = '$sessionId' AND id NOT IN (SELECT id FROM recently_viewed WHERE session_id = '$sessionId' ORDER BY viewed_at DESC LIMIT 50)");
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get recently viewed models
function getRecentlyViewed($limit = 10) {
    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $sessionId = session_id();

        if ($userId) {
            $stmt = $db->prepare('
                SELECT m.* FROM models m
                JOIN recently_viewed rv ON m.id = rv.model_id
                WHERE rv.user_id = :user_id AND m.parent_id IS NULL
                ORDER BY rv.viewed_at DESC
                LIMIT :limit
            ');
            $stmt->execute([':user_id' => $userId, ':limit' => $limit]);
        } else {
            $stmt = $db->prepare('
                SELECT m.* FROM models m
                JOIN recently_viewed rv ON m.id = rv.model_id
                WHERE rv.session_id = :session_id AND m.parent_id IS NULL
                ORDER BY rv.viewed_at DESC
                LIMIT :limit
            ');
            $stmt->execute([':session_id' => $sessionId, ':limit' => $limit]);
        }

        $models = [];
        while ($row = $stmt->fetch()) {
            $models[] = $row;
        }
        return $models;
    } catch (Exception $e) {
        return [];
    }
}

// =====================
// Download Count Functions
// =====================

// Increment download count
function incrementDownloadCount($modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE models SET download_count = download_count + 1 WHERE id = :id');
        $stmt->execute([':id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get download count
function getDownloadCount($modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT download_count FROM models WHERE id = :id');
        $stmt->execute([':id' => $modelId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['download_count'] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// =====================
// License Constants
// =====================

function getLicenseOptions() {
    return [
        '' => 'No License Specified',
        'cc0' => 'CC0 (Public Domain)',
        'cc-by' => 'CC BY (Attribution)',
        'cc-by-sa' => 'CC BY-SA (Attribution-ShareAlike)',
        'cc-by-nc' => 'CC BY-NC (Attribution-NonCommercial)',
        'cc-by-nc-sa' => 'CC BY-NC-SA (Attribution-NonCommercial-ShareAlike)',
        'cc-by-nd' => 'CC BY-ND (Attribution-NoDerivatives)',
        'cc-by-nc-nd' => 'CC BY-NC-ND (Attribution-NonCommercial-NoDerivatives)',
        'mit' => 'MIT License',
        'gpl' => 'GPL (GNU General Public License)',
        'proprietary' => 'Proprietary / All Rights Reserved',
        'other' => 'Other'
    ];
}

function getLicenseName($key) {
    $options = getLicenseOptions();
    return $options[$key] ?? $key;
}

// =====================
// Print Queue Functions
// =====================

function isInPrintQueue($userId, $modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM print_queue WHERE user_id = :user_id AND model_id = :model_id');
        $stmt->execute([':user_id' => $userId, ':model_id' => $modelId]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

function addToPrintQueue($userId, $modelId, $priority = 0, $notes = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT OR REPLACE INTO print_queue (user_id, model_id, priority, notes) VALUES (:user_id, :model_id, :priority, :notes)');
        $stmt->execute([
            ':user_id' => $userId,
            ':model_id' => $modelId,
            ':priority' => $priority,
            ':notes' => $notes
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function removeFromPrintQueue($userId, $modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM print_queue WHERE user_id = :user_id AND model_id = :model_id');
        $stmt->execute([':user_id' => $userId, ':model_id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function togglePrintQueue($userId, $modelId) {
    if (isInPrintQueue($userId, $modelId)) {
        removeFromPrintQueue($userId, $modelId);
        return false;
    } else {
        addToPrintQueue($userId, $modelId);
        return true;
    }
}

function getUserPrintQueue($userId, $limit = 100) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT pq.*, m.name as model_name, m.file_path, m.print_type,
                   c.name as category_name
            FROM print_queue pq
            JOIN models m ON pq.model_id = m.id
            LEFT JOIN categories c ON m.category_id = c.id
            WHERE pq.user_id = :user_id AND m.parent_id IS NULL
            ORDER BY pq.priority DESC, pq.added_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function updatePrintQueuePriority($userId, $modelId, $priority) {
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE print_queue SET priority = :priority WHERE user_id = :user_id AND model_id = :model_id');
        $stmt->execute([':priority' => $priority, ':user_id' => $userId, ':model_id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getPrintQueueCount($userId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM print_queue WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['count'] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// =====================
// Related Models Functions
// =====================

function getRelatedModels($modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT rm.*, m.name, m.file_path, m.print_type, m.created_at
            FROM related_models rm
            JOIN models m ON rm.related_model_id = m.id
            WHERE rm.model_id = :model_id AND m.parent_id IS NULL
            ORDER BY rm.created_at DESC
        ');
        $stmt->execute([':model_id' => $modelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function addRelatedModel($modelId, $relatedModelId, $relationshipType = 'related') {
    if ($modelId == $relatedModelId) return false;
    try {
        $db = getDB();
        // Add relation both ways
        $stmt = $db->prepare('INSERT OR IGNORE INTO related_models (model_id, related_model_id, relationship_type) VALUES (:model_id, :related_id, :type)');
        $stmt->execute([':model_id' => $modelId, ':related_id' => $relatedModelId, ':type' => $relationshipType]);
        $stmt->execute([':model_id' => $relatedModelId, ':related_id' => $modelId, ':type' => $relationshipType]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function removeRelatedModel($modelId, $relatedModelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM related_models WHERE (model_id = :model_id AND related_model_id = :related_id) OR (model_id = :related_id AND related_model_id = :model_id)');
        $stmt->execute([':model_id' => $modelId, ':related_id' => $relatedModelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// =====================
// Version History Functions
// =====================

function getModelVersions($modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT mv.*, u.username as created_by_name
            FROM model_versions mv
            LEFT JOIN users u ON mv.created_by = u.id
            WHERE mv.model_id = :model_id
            ORDER BY mv.version_number DESC
        ');
        $stmt->execute([':model_id' => $modelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function addModelVersion($modelId, $filePath, $fileSize, $fileHash, $changelog = '', $createdBy = null) {
    try {
        $db = getDB();
        // Get current max version
        $stmt = $db->prepare('SELECT MAX(version_number) as max_ver FROM model_versions WHERE model_id = :model_id');
        $stmt->execute([':model_id' => $modelId]);
        $row = $stmt->fetch();
        $nextVersion = ($row && $row['max_ver']) ? $row['max_ver'] + 1 : 1;

        $stmt = $db->prepare('
            INSERT INTO model_versions (model_id, version_number, file_path, file_size, file_hash, changelog, created_by)
            VALUES (:model_id, :version, :file_path, :file_size, :file_hash, :changelog, :created_by)
        ');
        $stmt->execute([
            ':model_id' => $modelId,
            ':version' => $nextVersion,
            ':file_path' => $filePath,
            ':file_size' => $fileSize,
            ':file_hash' => $fileHash,
            ':changelog' => $changelog,
            ':created_by' => $createdBy
        ]);

        // Update model's current version
        $stmt = $db->prepare('UPDATE models SET current_version = :version WHERE id = :id');
        $stmt->execute([':version' => $nextVersion, ':id' => $modelId]);

        return $nextVersion;
    } catch (Exception $e) {
        return false;
    }
}

function getModelVersion($modelId, $versionNumber) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM model_versions WHERE model_id = :model_id AND version_number = :version');
        $stmt->execute([':model_id' => $modelId, ':version' => $versionNumber]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// =====================
// Storage Usage Functions
// =====================

function getStorageUsageByCategory() {
    try {
        $db = getDB();
        $stmt = $db->query('
            SELECT c.id, c.name,
                   COUNT(m.id) as model_count,
                   SUM(m.original_size) as total_size
            FROM categories c
            LEFT JOIN models m ON m.category_id = c.id AND m.parent_id IS NULL
            GROUP BY c.id, c.name
            ORDER BY total_size DESC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getStorageUsageByUser() {
    try {
        $db = getDB();
        $stmt = $db->query('
            SELECT u.id, u.username,
                   COUNT(m.id) as model_count,
                   SUM(m.original_size) as total_size
            FROM users u
            LEFT JOIN models m ON m.user_id = u.id AND m.parent_id IS NULL
            GROUP BY u.id, u.username
            ORDER BY total_size DESC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getTotalStorageUsage() {
    try {
        $db = getDB();
        $stmt = $db->query('
            SELECT COUNT(*) as model_count,
                   SUM(original_size) as total_size
            FROM models
            WHERE parent_id IS NULL
        ');
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['model_count' => 0, 'total_size' => 0];
    }
}

function getDedupStorageSavings() {
    try {
        $db = getDB();
        // Get total file sizes
        $stmt = $db->query('SELECT SUM(original_size) as total FROM models WHERE original_size > 0');
        $totalRow = $stmt->fetch();
        $totalSize = $totalRow ? (int)$totalRow['total'] : 0;

        // Get actual deduplicated storage (unique hashes)
        $stmt = $db->query('SELECT SUM(size) as actual FROM (SELECT file_hash, MAX(original_size) as size FROM models WHERE file_hash IS NOT NULL GROUP BY file_hash)');
        $actualRow = $stmt->fetch();
        $actualSize = $actualRow ? (int)$actualRow['actual'] : 0;

        return [
            'total_size' => $totalSize,
            'actual_size' => $actualSize,
            'saved_size' => $totalSize - $actualSize,
            'saved_percent' => $totalSize > 0 ? round(($totalSize - $actualSize) / $totalSize * 100, 1) : 0
        ];
    } catch (Exception $e) {
        return ['total_size' => 0, 'actual_size' => 0, 'saved_size' => 0, 'saved_percent' => 0];
    }
}

// =====================
// Part Ordering Functions
// =====================

function updatePartOrder($partId, $sortOrder) {
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE models SET sort_order = :sort_order WHERE id = :id');
        $stmt->execute([':sort_order' => $sortOrder, ':id' => $partId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function reorderParts($parentId, $partIds) {
    try {
        $db = getDB();
        $db->beginTransaction();
        foreach ($partIds as $index => $partId) {
            $stmt = $db->prepare('UPDATE models SET sort_order = :sort_order WHERE id = :id AND parent_id = :parent_id');
            $stmt->execute([':sort_order' => $index, ':id' => $partId, ':parent_id' => $parentId]);
        }
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

// =====================
// Model Dimensions Functions
// =====================

function updateModelDimensions($modelId, $dimX, $dimY, $dimZ, $unit = 'mm') {
    try {
        $db = getDB();
        $stmt = $db->prepare('UPDATE models SET dim_x = :x, dim_y = :y, dim_z = :z, dim_unit = :unit WHERE id = :id');
        $stmt->execute([':x' => $dimX, ':y' => $dimY, ':z' => $dimZ, ':unit' => $unit, ':id' => $modelId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getModelDimensions($modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT dim_x, dim_y, dim_z, dim_unit FROM models WHERE id = :id');
        $stmt->execute([':id' => $modelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['dim_x'] !== null) {
            return $row;
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

// =====================
// Webhook Functions
// =====================

function getWebhookEvents() {
    return [
        'model.created',
        'model.updated',
        'model.deleted',
        'model.downloaded',
        'category.created',
        'category.deleted',
        'tag.created',
        'tag.deleted',
        'collection.created',
        'collection.deleted'
    ];
}

function getAllWebhooks() {
    try {
        $db = getDB();
        $result = $db->query('SELECT * FROM webhooks ORDER BY created_at DESC');
        $webhooks = [];
        while ($row = $result->fetch()) {
            $webhooks[] = $row;
        }
        return $webhooks;
    } catch (Exception $e) {
        return [];
    }
}

function getActiveWebhooksForEvent($event) {
    try {
        $db = getDB();
        $result = $db->query('SELECT * FROM webhooks WHERE is_active = 1');
        $webhooks = [];
        while ($row = $result->fetch()) {
            $events = json_decode($row['events'], true) ?: [];
            if (in_array($event, $events) || in_array('*', $events)) {
                $webhooks[] = $row;
            }
        }
        return $webhooks;
    } catch (Exception $e) {
        return [];
    }
}

function getWebhookById($id) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM webhooks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function createWebhook($url, $events, $secret = null, $name = null, $isActive = true) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO webhooks (name, url, secret, events, is_active)
            VALUES (:name, :url, :secret, :events, :is_active)
        ');
        $stmt->execute([
            ':name' => $name,
            ':url' => $url,
            ':secret' => $secret,
            ':events' => json_encode($events),
            ':is_active' => $isActive ? 1 : 0
        ]);
        return $db->lastInsertId();
    } catch (Exception $e) {
        logException($e, ['action' => 'create_webhook']);
        return false;
    }
}

function updateWebhook($id, $url, $events, $secret, $name, $isActive) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            UPDATE webhooks SET name = :name, url = :url, secret = :secret,
            events = :events, is_active = :is_active WHERE id = :id
        ');
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':url' => $url,
            ':secret' => $secret,
            ':events' => json_encode($events),
            ':is_active' => $isActive ? 1 : 0
        ]);
        return true;
    } catch (Exception $e) {
        logException($e, ['action' => 'update_webhook']);
        return false;
    }
}

function deleteWebhookById($id) {
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM webhooks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function triggerWebhook($event, $payload) {
    $webhooks = getActiveWebhooksForEvent($event);
    if (empty($webhooks)) {
        return;
    }

    $payload['event'] = $event;
    $payload['timestamp'] = date('c');
    $jsonPayload = json_encode($payload);

    foreach ($webhooks as $webhook) {
        // Run webhook delivery asynchronously if possible
        deliverWebhook($webhook, $event, $jsonPayload);
    }
}

function deliverWebhook($webhook, $event, $jsonPayload) {
    $startTime = microtime(true);

    $headers = [
        'Content-Type: application/json',
        'User-Agent: Silo-Webhook/1.0',
        'X-Webhook-Event: ' . $event
    ];

    // Add signature if secret is set
    if (!empty($webhook['secret'])) {
        $signature = hash_hmac('sha256', $jsonPayload, $webhook['secret']);
        $headers[] = 'X-Webhook-Signature: sha256=' . $signature;
    }

    $ch = curl_init($webhook['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    $success = $statusCode >= 200 && $statusCode < 300;

    // Log delivery
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO webhook_deliveries (webhook_id, event, payload, response_code, response_body, success, duration_ms)
            VALUES (:webhook_id, :event, :payload, :response_code, :response_body, :success, :duration_ms)
        ');
        $stmt->execute([
            ':webhook_id' => $webhook['id'],
            ':event' => $event,
            ':payload' => $jsonPayload,
            ':response_code' => $statusCode,
            ':response_body' => substr($response ?: $error, 0, 10000),
            ':success' => $success ? 1 : 0,
            ':duration_ms' => $durationMs
        ]);

        // Update webhook stats
        $type = $db->getType();
        if ($success) {
            if ($type === 'mysql') {
                $stmt = $db->prepare('UPDATE webhooks SET last_triggered_at = NOW(), last_status_code = :code, failure_count = 0 WHERE id = :id');
            } else {
                $stmt = $db->prepare('UPDATE webhooks SET last_triggered_at = CURRENT_TIMESTAMP, last_status_code = :code, failure_count = 0 WHERE id = :id');
            }
        } else {
            if ($type === 'mysql') {
                $stmt = $db->prepare('UPDATE webhooks SET last_triggered_at = NOW(), last_status_code = :code, failure_count = failure_count + 1 WHERE id = :id');
            } else {
                $stmt = $db->prepare('UPDATE webhooks SET last_triggered_at = CURRENT_TIMESTAMP, last_status_code = :code, failure_count = failure_count + 1 WHERE id = :id');
            }
        }
        $stmt->execute([':code' => $statusCode, ':id' => $webhook['id']]);
    } catch (Exception $e) {
        logException($e, ['action' => 'log_webhook_delivery']);
    }

    return $success;
}
