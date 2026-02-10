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
    private $paramTypes = [];
    private ?DatabaseResult $lastResult = null;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function bindValue($param, $value, $type = null) {
        $this->params[$param] = $value;
        if ($type !== null) {
            $this->paramTypes[$param] = $type;
        }
        return true;
    }

    public function execute($params = null) {
        if ($params !== null) {
            // Use type-aware binding instead of PDOStatement::execute($params)
            // PDO::execute() binds ALL values as strings, which breaks
            // LIMIT/OFFSET in MySQL (requires integer binding)
            $this->bindTypedParams($params);
            $this->stmt->execute();
        } else {
            $execParams = $this->params;
            $execTypes = $this->paramTypes;
            if (!empty($execParams)) {
                $firstKey = array_keys($execParams)[0];
                if (is_int($firstKey)) {
                    // Positional placeholders - sort by key for proper ordering
                    ksort($execParams);
                }
                // Keys from bindValue() are already PDO-ready (1-based positional or named)
                $this->bindTypedParams($execParams, $execTypes, false);
                $this->stmt->execute();
            } else {
                $this->stmt->execute();
            }
        }
        $this->params = [];
        $this->paramTypes = [];
        $this->lastResult = new DatabaseResult($this->stmt);
        return $this->lastResult;
    }

    /**
     * Bind parameters with proper PDO types.
     * Detects PHP value types to use PDO::PARAM_INT for integers,
     * which is required for MySQL LIMIT/OFFSET clauses.
     */
    private function bindTypedParams(array $params, array $types = [], bool $adjustIndex = true): void {
        foreach ($params as $key => $value) {
            // PDO bindValue uses 1-based index for positional params
            // adjustIndex=true: external 0-based arrays need +1
            // adjustIndex=false: pre-stored params from bindValue() are already PDO-ready
            $bindKey = (is_int($key) && $adjustIndex) ? $key + 1 : $key;

            // Use explicitly provided type, or detect from PHP value type
            if (isset($types[$key])) {
                $pdoType = $this->mapToPdoType($types[$key]);
                $this->stmt->bindValue($bindKey, $value, $pdoType);
            } elseif (is_int($value)) {
                $this->stmt->bindValue($bindKey, $value, PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $this->stmt->bindValue($bindKey, (int)$value, PDO::PARAM_INT);
            } elseif (is_null($value)) {
                $this->stmt->bindValue($bindKey, null, PDO::PARAM_NULL);
            } else {
                $this->stmt->bindValue($bindKey, $value, PDO::PARAM_STR);
            }
        }
    }

    /**
     * Map SQLite3 type constants to PDO type constants
     */
    private function mapToPdoType($type): int {
        if ($type === PDO::PARAM_INT || $type === PDO::PARAM_STR ||
            $type === PDO::PARAM_NULL || $type === PDO::PARAM_BOOL) {
            return $type;
        }
        // Map SQLITE3_* constants to PDO equivalents
        switch ($type) {
            case SQLITE3_INTEGER: return PDO::PARAM_INT;
            case SQLITE3_TEXT: return PDO::PARAM_STR;
            case SQLITE3_NULL: return PDO::PARAM_NULL;
            case SQLITE3_FLOAT: return PDO::PARAM_STR; // PDO has no float type
            case SQLITE3_BLOB: return PDO::PARAM_LOB;
            default: return PDO::PARAM_STR;
        }
    }

    // Convenience method: execute and fetch single column
    // If execute() was already called, fetches from that result
    public function fetchColumn($column = 0) {
        $result = $this->lastResult ?? $this->execute();
        return $result->fetchColumn($column);
    }

    // Convenience method: execute and fetch single row
    // If execute() was already called, fetches from that result
    // Safe to call in loops - lastResult persists until next execute()
    public function fetch($mode = PDO::FETCH_ASSOC) {
        $result = $this->lastResult ?? $this->execute();
        return $result->fetch($mode);
    }

    // Convenience method: execute and fetch as array (SQLite3 compat)
    // If execute() was already called, fetches from that result
    // Safe to call in loops - lastResult persists until next execute()
    public function fetchArray($mode = SQLITE3_ASSOC) {
        $result = $this->lastResult ?? $this->execute();
        return $result->fetchArray($mode);
    }

    // Convenience method: execute and fetch all rows
    public function fetchAll($mode = PDO::FETCH_ASSOC) {
        $result = $this->lastResult ?? $this->execute();
        return $result->fetchAll($mode);
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

    public function fetchAll($mode = PDO::FETCH_ASSOC) {
        return $this->stmt->fetchAll($mode);
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
            $this->pdo->exec('PRAGMA cache_size = -131072'); // 128MB cache
            $this->pdo->exec('PRAGMA temp_store = MEMORY'); // Temp tables in memory
            $this->pdo->exec('PRAGMA mmap_size = 268435456'); // 256MB memory-mapped I/O
            $this->pdo->exec('PRAGMA foreign_keys = ON'); // Enable referential integrity
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

    /**
     * Normalize SQLite-specific SQL syntax for MySQL compatibility.
     * Converts INSERT OR IGNORE → INSERT IGNORE, INSERT OR REPLACE → REPLACE INTO,
     * and backticks MySQL reserved words used as table/column names.
     */
    private function normalizeSql($sql) {
        if ($this->type !== 'mysql') {
            return $sql;
        }
        // INSERT OR IGNORE INTO → INSERT IGNORE INTO
        $sql = preg_replace('/\bINSERT\s+OR\s+IGNORE\s+INTO\b/i', 'INSERT IGNORE INTO', $sql);
        // INSERT OR REPLACE INTO → REPLACE INTO
        $sql = preg_replace('/\bINSERT\s+OR\s+REPLACE\s+INTO\b/i', 'REPLACE INTO', $sql);

        // MySQL 8.0 reserved word: GROUPS is used as a table name throughout the codebase.
        // Automatically backtick it when it appears after SQL keywords that precede table names.
        // The \b word boundary prevents matching "user_groups" or other compound names.
        $sql = preg_replace('/\b(FROM|JOIN|INTO|UPDATE|TABLE|EXISTS)\s+groups\b/i', '$1 `groups`', $sql);

        // SQLite: INTEGER PRIMARY KEY AUTOINCREMENT → MySQL: INT AUTO_INCREMENT PRIMARY KEY
        $sql = preg_replace('/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i', 'INT AUTO_INCREMENT PRIMARY KEY', $sql);
        // Catch any remaining standalone AUTOINCREMENT → AUTO_INCREMENT
        $sql = preg_replace('/\bAUTOINCREMENT\b/i', 'AUTO_INCREMENT', $sql);

        // MySQL/MariaDB: TEXT columns cannot have UNIQUE constraints without a prefix length.
        // Convert "TEXT [NOT NULL] UNIQUE" → "VARCHAR(255) [NOT NULL] UNIQUE" in CREATE TABLE statements.
        // VARCHAR(255) is also valid in SQLite (treated as TEXT affinity), so this is safe for both.
        $sql = preg_replace('/\bTEXT\s+(NOT\s+NULL\s+)?UNIQUE\b/i', 'VARCHAR(255) $1UNIQUE', $sql);

        return $sql;
    }

    public function prepare($sql) {
        $sql = $this->normalizeSql($sql);
        // Return wrapped statement for SQLite3 API compatibility with profiling
        return $this->profileQuery(function() use ($sql) {
            return new DatabaseStatement($this->pdo->prepare($sql));
        }, $sql);
    }

    public function query($sql) {
        $sql = $this->normalizeSql($sql);
        // Return wrapped result for SQLite3 API compatibility with profiling
        return $this->profileQuery(function() use ($sql) {
            return new DatabaseResult($this->pdo->query($sql));
        }, $sql);
    }

    public function exec($sql) {
        $sql = $this->normalizeSql($sql);
        return $this->profileQuery(function() use ($sql) {
            return $this->pdo->exec($sql);
        }, $sql);
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    public function querySingle($sql) {
        $sql = $this->normalizeSql($sql);
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
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :login1 OR email = :login2');
        $result = $stmt->execute([':login1' => $login, ':login2' => $login]);
        return $result->fetch();
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

// Check if an index exists
function indexExists($db, $table, $indexName) {
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
        'idx_models_category_id' => ['table' => 'models', 'column' => 'category_id', 'reason' => 'category filtering'],
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
function initializeDefaultSettings($db) {
    $type = $db->getType();

    $defaults = [
        // Core site settings
        'site_name' => 'MeshSilo',
        'site_description' => 'Your 3D Model Library',
        'site_url' => '/',
        'force_site_url' => '0',
        'site_tagline' => '3D Model Library',
        'models_per_page' => '20',
        'allow_registration' => '1',
        'require_approval' => '0',
        'allowed_extensions' => 'stl,3mf,gcode,zip',
        'auto_convert_stl' => '0',
        'auto_deduplication' => '0',

        // Feature toggles
        'enable_categories' => '1',
        'enable_collections' => '1',
        'enable_tags' => '1',
        'enable_activity_log' => '1',
        'enable_access_log' => '0',

        // Theme and display
        'default_theme' => 'dark',
        'allow_user_theme' => '1',
        'default_sort' => 'newest',
        'default_view' => 'grid',

        // Maintenance
        'maintenance_mode' => '0',
        'maintenance_admin_bypass' => '1',
        'maintenance_bypass_secret' => '',
        'maintenance_whitelist_ips' => '',
        'maintenance_message' => '',
        'maintenance_title' => 'Maintenance Mode',

        // Activity log
        'activity_log_retention_days' => '90',
        'activity_log_retention' => '90',
        'audit_logging_enabled' => '1',

        // Mail
        'mail_driver' => 'mail',
        'mail_host' => 'localhost',
        'mail_port' => '587',
        'mail_username' => '',
        'mail_password' => '',
        'mail_encryption' => 'tls',
        'mail_from_address' => 'noreply@example.com',
        'mail_from_name' => 'MeshSilo',
        'admin_email' => '',

        // Storage
        'storage_type' => 'local',
        's3_endpoint' => '',
        's3_bucket' => '',
        's3_access_key' => '',
        's3_secret_key' => '',
        's3_region' => 'us-east-1',
        's3_path_style' => '0',
        's3_public_url' => '',

        // Rate limiting
        'rate_limiting' => '1',
        'rate_limit_storage' => 'file',

        // Branding
        'logo_path' => '',
        'favicon_path' => '',
        'brand_primary_color' => '#6366f1',
        'brand_secondary_color' => '#8b5cf6',
        'brand_accent_color' => '#06b6d4',
        'brand_background_color' => '#f9fafb',
        'brand_text_color' => '#111827',
        'custom_css' => '',
        'custom_head_html' => '',
        'custom_footer_html' => '',
        'brand_font_family' => 'Inter, system-ui, sans-serif',
        'brand_border_radius' => '0.5rem',
        'dark_mode_enabled' => '0',
        'brand_dark_background' => '#1f2937',
        'brand_dark_text' => '#f9fafb',

        // Routing and performance
        'seo_redirects' => '1',
        'route_caching' => '0',
        'route_profiling' => '0',

        'currency' => 'USD',

        // File types
        'file_type_config' => '{}',

        // Homepage
        'homepage_config' => '',

        // Signed URLs
        'signed_url_secret' => '',

        // Update checker
        'update_check_enabled' => '1',

        // CORS
        'cors_allowed_origins' => '',
        'cors_allowed_methods' => '',
        'cors_allowed_headers' => '',
        'cors_allow_credentials' => '0',

        // Default group
        'default_group' => '1',

        // Schema tracking
        'schema_version' => '',
        'last_migration' => '',

        // Slicers
        'enabled_slicers' => '',
    ];

    try {
        if ($type === 'mysql') {
            $stmt = $db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (:key, :value)');
        } else {
            $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
        }

        foreach ($defaults as $key => $value) {
            $stmt->execute([':key' => $key, ':value' => $value]);
        }
    } catch (Exception $e) {
        logDebug('Settings initialization skipped', ['error' => $e->getMessage()]);
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
    $allowedExtensions = array_map('trim', explode(',', $setting));

    if (class_exists('PluginManager')) {
        $allowedExtensions = PluginManager::applyFilter('supported_file_types', $allowedExtensions);
    }

    return $allowedExtensions;
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

// Get all tags (cached for 5 minutes)
function getAllTags($useCache = true) {
    $cacheKey = 'all_tags';

    // Try to get from cache first
    if ($useCache) {
        $cached = cache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    try {
        $db = getDB();
        $result = $db->query('SELECT * FROM tags ORDER BY name');
        $tags = [];
        while ($row = $result->fetch()) {
            $tags[] = $row;
        }

        // Cache for 5 minutes
        cache_set($cacheKey, $tags, 300);
        return $tags;
    } catch (Exception $e) {
        return [];
    }
}

// Get all categories with model counts (cached for 5 minutes)
function getAllCategories($useCache = true) {
    $cacheKey = 'all_categories';

    // Try to get from cache first
    if ($useCache) {
        $cached = cache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    try {
        $db = getDB();
        $result = $db->query('SELECT c.*, COUNT(mc.model_id) as model_count FROM categories c LEFT JOIN model_categories mc ON c.id = mc.category_id GROUP BY c.id ORDER BY c.name');
        $categories = [];
        while ($row = $result->fetch()) {
            $categories[] = $row;
        }

        // Cache for 5 minutes
        cache_set($cacheKey, $categories, 300);
        return $categories;
    } catch (Exception $e) {
        return [];
    }
}

// Invalidate tags cache (call after adding/removing tags)
function invalidateTagsCache() {
    cache_forget('all_tags');
}

// Invalidate categories cache (call after modifying categories)
function invalidateCategoriesCache() {
    cache_forget('all_categories');
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

// Get categories for a single model
function getCategoriesForModel($modelId) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT c.id, c.name
            FROM categories c
            JOIN model_categories mc ON c.id = mc.category_id
            WHERE mc.model_id = :model_id
            ORDER BY c.name
        ');
        $stmt->execute([':model_id' => $modelId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Get categories for multiple models in batch (reduces N+1 queries)
function getCategoriesForModels(array $modelIds) {
    if (empty($modelIds)) {
        return [];
    }

    try {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
        $query = "
            SELECT mc.model_id, c.id, c.name
            FROM model_categories mc
            JOIN categories c ON mc.category_id = c.id
            WHERE mc.model_id IN ($placeholders)
            ORDER BY c.name
        ";
        $stmt = $db->prepare($query);

        $index = 1;
        foreach ($modelIds as $id) {
            $stmt->bindValue($index++, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Group categories by model_id
        $categoriesByModel = [];
        while ($row = $stmt->fetch()) {
            $categoriesByModel[$row['model_id']][] = [
                'id' => $row['id'],
                'name' => $row['name']
            ];
        }

        return $categoriesByModel;
    } catch (Exception $e) {
        return [];
    }
}

// Get absolute file path for a model/part
function getModelFilePath($model) {
    $basePath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/../storage/assets/';
    $filePath = $model['dedup_path'] ?? $model['file_path'] ?? '';
    if (empty($filePath)) {
        return null;
    }
    // Handle both relative and absolute paths
    if (strpos($filePath, '/') === 0 || strpos($filePath, ':\\') !== false) {
        return $filePath;
    }
    return rtrim($basePath, '/') . '/' . ltrim($filePath, '/');
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
        invalidateTagsCache();
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
        invalidateTagsCache();
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

        // Limit to 50 most recent per user/session using parameterized queries
        if ($userId) {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE user_id = :user_id AND id NOT IN (SELECT id FROM recently_viewed WHERE user_id = :user_id2 ORDER BY viewed_at DESC LIMIT 50)');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id2', $userId, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE session_id = :session_id AND id NOT IN (SELECT id FROM recently_viewed WHERE session_id = :session_id2 ORDER BY viewed_at DESC LIMIT 50)');
            $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_STR);
            $stmt->bindValue(':session_id2', $sessionId, PDO::PARAM_STR);
            $stmt->execute();
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
    } catch (\Throwable $e) {
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
        $stmt = $db->prepare('DELETE FROM related_models WHERE (model_id = :model_id1 AND related_model_id = :related_id1) OR (model_id = :related_id2 AND related_model_id = :model_id2)');
        $stmt->execute([':model_id1' => $modelId, ':related_id1' => $relatedModelId, ':related_id2' => $relatedModelId, ':model_id2' => $modelId]);
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
    } catch (\Throwable $e) {
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
    } catch (\Throwable $e) {
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
    } catch (\Throwable $e) {
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

/**
 * Trigger webhook for an event (delegated to plugins via filter)
 */
function triggerWebhook($event, $payload) {
    if (class_exists('PluginManager')) {
        PluginManager::applyFilter('trigger_webhook', null, $event, $payload);
    }
}

// =====================
// Batch Operations
// =====================

/**
 * Batch insert multiple rows into a table
 * @param string $table Table name
 * @param array $columns Column names
 * @param array $rows Array of row data arrays
 * @param int $chunkSize Number of rows per insert (default 100)
 * @return int Number of rows inserted
 */
function batchInsert(string $table, array $columns, array $rows, int $chunkSize = 100): int {
    if (empty($rows) || empty($columns)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();
    $inserted = 0;

    // Build column list
    $columnList = implode(', ', array_map(function($col) use ($type) {
        return $type === 'mysql' ? "`$col`" : "\"$col\"";
    }, $columns));

    // Process in chunks to avoid memory issues
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));

        $sql = "INSERT INTO $table ($columnList) VALUES $placeholders";
        $stmt = $db->prepare($sql);

        // Flatten values array
        $values = [];
        foreach ($chunk as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt->execute($values);
        $inserted += count($chunk);
    }

    return $inserted;
}

/**
 * Batch insert with IGNORE (skip duplicates)
 */
function batchInsertIgnore(string $table, array $columns, array $rows, int $chunkSize = 100): int {
    if (empty($rows) || empty($columns)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();
    $inserted = 0;

    $columnList = implode(', ', array_map(function($col) use ($type) {
        return $type === 'mysql' ? "`$col`" : "\"$col\"";
    }, $columns));

    $insertKeyword = $type === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));

        $sql = "$insertKeyword INTO $table ($columnList) VALUES $placeholders";
        $stmt = $db->prepare($sql);

        $values = [];
        foreach ($chunk as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt->execute($values);
        $inserted += $stmt->rowCount();
    }

    return $inserted;
}

/**
 * Batch update using CASE statements (more efficient than individual updates)
 * @param string $table Table name
 * @param string $idColumn Primary key column
 * @param string $updateColumn Column to update
 * @param array $updates Array of [id => value] pairs
 * @return int Number of rows affected
 */
function batchUpdate(string $table, string $idColumn, string $updateColumn, array $updates): int {
    if (empty($updates)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();

    $ids = array_keys($updates);
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));

    // Build CASE statement
    $caseStmt = "CASE $idColumn ";
    $params = [];
    foreach ($updates as $id => $value) {
        $caseStmt .= "WHEN ? THEN ? ";
        $params[] = $id;
        $params[] = $value;
    }
    $caseStmt .= "END";

    // Add IDs for WHERE clause
    foreach ($ids as $id) {
        $params[] = $id;
    }

    $sql = "UPDATE $table SET $updateColumn = $caseStmt WHERE $idColumn IN ($placeholders)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}
