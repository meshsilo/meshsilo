<?php

/**
 * Database abstraction layer supporting SQLite and MySQL
 */

// SQLite3 type constants for compatibility
if (!defined('SQLITE3_TEXT')) {
    define('SQLITE3_TEXT', 3);
}
if (!defined('SQLITE3_INTEGER')) {
    define('SQLITE3_INTEGER', 1);
}
if (!defined('SQLITE3_FLOAT')) {
    define('SQLITE3_FLOAT', 2);
}
if (!defined('SQLITE3_BLOB')) {
    define('SQLITE3_BLOB', 4);
}
if (!defined('SQLITE3_NULL')) {
    define('SQLITE3_NULL', 5);
}
if (!defined('SQLITE3_ASSOC')) {
    define('SQLITE3_ASSOC', 1);
}

// Statement wrapper for SQLite3 API compatibility
class DatabaseStatement
{
    private $stmt;
    private $params = [];
    private $paramTypes = [];
    private ?DatabaseResult $lastResult = null;
    private ?string $sql = null;

    public function __construct($stmt, ?string $sql = null)
    {
        $this->stmt = $stmt;
        $this->sql = $sql;
    }

    public function bindValue($param, $value, $type = null)
    {
        $this->params[$param] = $value;
        if ($type !== null) {
            $this->paramTypes[$param] = $type;
        }
        return true;
    }

    public function execute($params = null)
    {
        // Bind parameters before execution
        if ($params !== null) {
            // Use type-aware binding instead of PDOStatement::execute($params)
            // PDO::execute() binds ALL values as strings, which breaks
            // LIMIT/OFFSET in MySQL (requires integer binding)
            $this->bindTypedParams($params);
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
            }
        }

        // Profile the actual execution (not just prepare)
        Database::incrementQueryCount();
        $start = microtime(true);
        $this->stmt->execute();
        $time = microtime(true) - $start;
        Database::addQueryTime($time);

        if ($this->sql !== null && function_exists('logQuery')) {
            logQuery($this->sql, [], $time);
        } elseif ($time > 0.1 && function_exists('logWarning')) {
            logWarning('Slow query detected', ['time_seconds' => round($time, 3)]);
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
    private function bindTypedParams(array $params, array $types = [], bool $adjustIndex = true): void
    {
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
    private function mapToPdoType($type): int
    {
        if (
            $type === PDO::PARAM_INT || $type === PDO::PARAM_STR ||
            $type === PDO::PARAM_NULL || $type === PDO::PARAM_BOOL
        ) {
            return $type;
        }
        // Map SQLITE3_* constants to PDO equivalents
        switch ($type) {
            case SQLITE3_INTEGER:
                return PDO::PARAM_INT;
            case SQLITE3_TEXT:
                return PDO::PARAM_STR;
            case SQLITE3_NULL:
                return PDO::PARAM_NULL;
            case SQLITE3_FLOAT:
                return PDO::PARAM_STR; // PDO has no float type
            case SQLITE3_BLOB:
                return PDO::PARAM_LOB;
            default:
                return PDO::PARAM_STR;
        }
    }

    // Convenience method: execute and fetch single column
    // If execute() was already called, fetches from that result
    public function fetchColumn($column = 0)
    {
        $result = $this->lastResult ?? $this->execute();
        return $result->fetchColumn($column);
    }

    // Convenience method: execute and fetch single row
    // If execute() was already called, fetches from that result
    // Safe to call in loops - lastResult persists until next execute()
    public function fetch($mode = PDO::FETCH_ASSOC)
    {
        $result = $this->lastResult ?? $this->execute();
        return $result->fetch($mode);
    }

    // Convenience method: execute and fetch as array (SQLite3 compat)
    // If execute() was already called, fetches from that result
    // Safe to call in loops - lastResult persists until next execute()
    public function fetchArray($mode = SQLITE3_ASSOC)
    {
        $result = $this->lastResult ?? $this->execute();
        return $result->fetchArray($mode);
    }

    // Convenience method: execute and fetch all rows
    public function fetchAll($mode = PDO::FETCH_ASSOC)
    {
        $result = $this->lastResult ?? $this->execute();
        return $result->fetchAll($mode);
    }
}

// Result wrapper for SQLite3 API compatibility
class DatabaseResult
{
    private $stmt;

    public function __construct($stmt)
    {
        $this->stmt = $stmt;
    }

    public function fetchArray($mode = SQLITE3_ASSOC)
    {
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch($mode = PDO::FETCH_ASSOC)
    {
        return $this->stmt->fetch($mode);
    }

    public function fetchAll($mode = PDO::FETCH_ASSOC)
    {
        return $this->stmt->fetchAll($mode);
    }

    public function fetchColumn($column = 0)
    {
        return $this->stmt->fetchColumn($column);
    }
}

// Database wrapper class for unified interface
class Database
{
    private $pdo;
    private $type;
    private static $queryCount = 0;
    private static $queryTime = 0;

    public function __construct($type, $config = [])
    {
        $this->type = $type;

        if ($type === 'mysql') {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
            $pdoOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            // Retry connection up to 3 times for MySQL (handles dropped connections)
            $maxRetries = 3;
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $this->pdo = new PDO($dsn, $config['user'], $config['pass'], $pdoOptions);
                    break;
                } catch (\PDOException $e) {
                    if ($attempt === $maxRetries) {
                        throw $e;
                    }
                    sleep(1);
                }
            }
            // MySQL-specific optimizations
            $this->pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        } else {
            $this->pdo = new PDO('sqlite:' . $config['path'], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // SQLite-specific optimizations
            $this->pdo->exec('PRAGMA journal_mode = WAL'); // Write-Ahead Logging
            $this->pdo->exec('PRAGMA busy_timeout = 5000'); // Wait up to 5s for locks
            $this->pdo->exec('PRAGMA synchronous = NORMAL'); // Faster writes
            $this->pdo->exec('PRAGMA cache_size = -131072'); // 128MB cache
            $this->pdo->exec('PRAGMA temp_store = MEMORY'); // Temp tables in memory
            $this->pdo->exec('PRAGMA mmap_size = 268435456'); // 256MB memory-mapped I/O
            $this->pdo->exec('PRAGMA foreign_keys = ON'); // Enable referential integrity
        }
    }

    // Query profiling methods
    public static function getQueryCount(): int
    {
        return self::$queryCount;
    }

    public static function getQueryTime(): float
    {
        return self::$queryTime;
    }

    public static function resetStats(): void
    {
        self::$queryCount = 0;
        self::$queryTime = 0;
    }

    public static function incrementQueryCount(): void
    {
        self::$queryCount++;
    }

    public static function addQueryTime(float $time): void
    {
        self::$queryTime += $time;
    }

    private function profileQuery(callable $callback, $sql = null)
    {
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

    public function getType()
    {
        return $this->type;
    }

    public function getPDO()
    {
        return $this->pdo;
    }

    /**
     * Normalize SQLite-specific SQL syntax for MySQL compatibility.
     * Converts INSERT OR IGNORE → INSERT IGNORE, INSERT OR REPLACE → REPLACE INTO,
     * and backticks MySQL reserved words used as table/column names.
     */
    private function normalizeSql($sql)
    {
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

    public function prepare($sql)
    {
        $sql = $this->normalizeSql($sql);
        return new DatabaseStatement($this->pdo->prepare($sql), $sql);
    }

    public function query($sql)
    {
        $sql = $this->normalizeSql($sql);
        // Return wrapped result for SQLite3 API compatibility with profiling
        return $this->profileQuery(function () use ($sql) {
            return new DatabaseResult($this->pdo->query($sql));
        }, $sql);
    }

    public function exec($sql)
    {
        $sql = $this->normalizeSql($sql);
        return $this->profileQuery(function () use ($sql) {
            return $this->pdo->exec($sql);
        }, $sql);
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    public function querySingle($sql)
    {
        $sql = $this->normalizeSql($sql);
        $stmt = $this->pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    // SQLite3 compatibility shim
    public function lastInsertRowID()
    {
        return $this->lastInsertId();
    }

    // Get the number of rows changed by the last statement
    public function changes()
    {
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
function getDB()
{
    static $db = null;

    if ($db === null) {
        try {
            /** @phpstan-ignore identical.alwaysFalse */
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
function initializeDatabase($db)
{
    $type = $db->getType();

    if ($type === 'mysql') {
        $schema = getMySQLSchema();
    } else {
        $schema = getSQLiteSchema();
    }

    // Split and execute statements
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    foreach ($statements as $stmt) {
        $db->exec($stmt);
    }
}

// Load domain-specific functions from focused sub-files
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/helpers/user-helpers.php';
require_once __DIR__ . '/helpers/tag-helpers.php';
require_once __DIR__ . '/helpers/category-helpers.php';
require_once __DIR__ . '/helpers/favorite-helpers.php';
require_once __DIR__ . '/helpers/activity-helpers.php';
require_once __DIR__ . '/helpers/storage-helpers.php';
require_once __DIR__ . '/helpers/model-helpers.php';
require_once __DIR__ . '/helpers/batch-helpers.php';
