<?php
/**
 * PHPUnit Bootstrap
 *
 * Sets up the testing environment for Silo unit tests.
 */

// Set testing environment
define('TESTING', true);
define('APP_ROOT', dirname(__DIR__));

// Set test-specific constants
define('SITE_NAME', 'Silo Test');
define('SITE_URL', 'http://localhost');

// Load Composer autoloader if available
$autoloader = APP_ROOT . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// Load helper functions
require_once APP_ROOT . '/includes/helpers.php';

// Load Schema (needed by migrations.php)
require_once APP_ROOT . '/includes/Schema.php';

// Start session for CSRF tests
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SQLite3 type constants for compatibility (needed by db wrappers)
if (!defined('SQLITE3_TEXT')) define('SQLITE3_TEXT', 3);
if (!defined('SQLITE3_INTEGER')) define('SQLITE3_INTEGER', 1);
if (!defined('SQLITE3_FLOAT')) define('SQLITE3_FLOAT', 2);
if (!defined('SQLITE3_BLOB')) define('SQLITE3_BLOB', 4);
if (!defined('SQLITE3_NULL')) define('SQLITE3_NULL', 5);
if (!defined('SQLITE3_ASSOC')) define('SQLITE3_ASSOC', 1);

/**
 * Test-compatible database result wrapper
 * Mimics DatabaseResult from includes/db.php
 */
class TestDatabaseResult {
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

/**
 * Test-compatible database statement wrapper
 * Mimics DatabaseStatement from includes/db.php
 */
class TestDatabaseStatement {
    private $stmt;
    private $params = [];
    private $executed = false;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function bindValue($param, $value, $type = null) {
        if ($type !== null) {
            $pdoType = $this->mapType($type);
            $this->stmt->bindValue($param, $value, $pdoType);
        } elseif (is_int($value)) {
            $this->stmt->bindValue($param, $value, PDO::PARAM_INT);
        } elseif (is_null($value)) {
            $this->stmt->bindValue($param, null, PDO::PARAM_NULL);
        } else {
            $this->stmt->bindValue($param, $value, PDO::PARAM_STR);
        }
        return true;
    }

    public function execute($params = null) {
        if ($params !== null) {
            $this->stmt->execute($params);
        } else {
            $this->stmt->execute();
        }
        $this->executed = true;
        return new TestDatabaseResult($this->stmt);
    }

    public function fetchArray($mode = SQLITE3_ASSOC) {
        if (!$this->executed) $this->execute();
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetchColumn($column = 0) {
        if (!$this->executed) $this->execute();
        return $this->stmt->fetchColumn($column);
    }

    public function fetch($mode = PDO::FETCH_ASSOC) {
        if (!$this->executed) $this->execute();
        return $this->stmt->fetch($mode);
    }

    public function fetchAll($mode = PDO::FETCH_ASSOC) {
        if (!$this->executed) $this->execute();
        return $this->stmt->fetchAll($mode);
    }

    private function mapType($type): int {
        if ($type === PDO::PARAM_INT || $type === PDO::PARAM_STR ||
            $type === PDO::PARAM_NULL || $type === PDO::PARAM_BOOL) {
            return $type;
        }
        return match ($type) {
            SQLITE3_INTEGER => PDO::PARAM_INT,
            SQLITE3_TEXT => PDO::PARAM_STR,
            SQLITE3_NULL => PDO::PARAM_NULL,
            SQLITE3_FLOAT => PDO::PARAM_STR,
            SQLITE3_BLOB => PDO::PARAM_LOB,
            default => PDO::PARAM_STR,
        };
    }
}

/**
 * Test-compatible database wrapper
 * Mimics Database from includes/db.php with same API
 */
class TestDatabase {
    private PDO $pdo;
    private string $type;

    public function __construct(string $type = 'sqlite', array $config = []) {
        $this->type = $type;
        $path = $config['path'] ?? ':memory:';
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function getType(): string {
        return $this->type;
    }

    public function getPDO(): PDO {
        return $this->pdo;
    }

    public function prepare($sql): TestDatabaseStatement {
        return new TestDatabaseStatement($this->pdo->prepare($sql));
    }

    public function query($sql): TestDatabaseResult {
        return new TestDatabaseResult($this->pdo->query($sql));
    }

    public function exec($sql) {
        return $this->pdo->exec($sql);
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    public function lastInsertRowID() {
        return $this->lastInsertId();
    }

    public function changes(): int {
        $result = $this->pdo->query('SELECT changes()');
        return (int) $result->fetchColumn();
    }

    public function querySingle($sql) {
        $stmt = $this->pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }
}

// Mock functions that depend on database
if (!function_exists('getDB')) {
    function getDB() {
        // Feature tests can override the DB via $GLOBALS['_test_db']
        if (isset($GLOBALS['_test_db'])) {
            return $GLOBALS['_test_db'];
        }
        static $db = null;
        if ($db === null) {
            $db = new TestDatabase('sqlite', ['path' => ':memory:']);
            $db->exec("
                CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
            ");
            $db->exec("
                CREATE TABLE IF NOT EXISTS tags (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    color TEXT DEFAULT '#6366f1',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $db->exec("
                CREATE TABLE IF NOT EXISTS model_tags (
                    model_id INTEGER,
                    tag_id INTEGER,
                    PRIMARY KEY (model_id, tag_id)
                )
            ");
        }
        return $db;
    }
}

if (!function_exists('getSetting')) {
    function getSetting(string $key, $default = null) {
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT value FROM settings WHERE key = :k');
            $stmt->bindValue(':k', $key, PDO::PARAM_STR);
            $result = $stmt->execute();
            $row = $result->fetchArray(PDO::FETCH_ASSOC);
            if ($row && isset($row['value'])) {
                return $row['value'];
            }
        } catch (\Throwable $e) {
            // Table may not exist — fall through to default
        }
        return $default;
    }
}

if (!function_exists('setSetting')) {
    function setSetting(string $key, $value): bool {
        try {
            $db = getDB();
            $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:k, :v)');
            $stmt->bindValue(':k', $key, PDO::PARAM_STR);
            $stmt->bindValue(':v', (string)$value, PDO::PARAM_STR);
            $stmt->execute();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

// Load production auth and permissions (real implementations - needed by helpers and tests)
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';

if (!function_exists('isFeatureEnabled')) {
    function isFeatureEnabled(string $feature): bool {
        return true;
    }
}

// Load helper files (depend on getDB() defined above)
require_once APP_ROOT . '/includes/helpers/user-helpers.php';
require_once APP_ROOT . '/includes/helpers/tag-helpers.php';
require_once APP_ROOT . '/includes/helpers/category-helpers.php';
require_once APP_ROOT . '/includes/helpers/favorite-helpers.php';
require_once APP_ROOT . '/includes/helpers/activity-helpers.php';
require_once APP_ROOT . '/includes/helpers/storage-helpers.php';
require_once APP_ROOT . '/includes/helpers/model-helpers.php';
require_once APP_ROOT . '/includes/helpers/batch-helpers.php';

if (!function_exists('logError')) {
    function logError($message, $context = []) {
        // Silent for tests
    }
}

if (!function_exists('logException')) {
    function logException($e, $context = []) {
        // Silent for tests
    }
}

if (!function_exists('logInfo')) {
    function logInfo($message, $context = []) {
        // Silent for tests
    }
}

if (!function_exists('logWarning')) {
    function logWarning($message, $context = []) {
        // Silent for tests
    }
}

if (!function_exists('logQuery')) {
    function logQuery($sql, $params = [], $time = 0) {
        // Silent for tests
    }
}

/**
 * Test Case Base Class
 */
abstract class SiloTestCase extends \PHPUnit\Framework\TestCase {
    protected function setUp(): void {
        parent::setUp();
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        // Clear cache between tests to prevent stale data leaking
        if (class_exists('Cache')) {
            try { Cache::getInstance()->flush(); } catch (\Throwable $e) {}
        }
    }

    protected function tearDown(): void {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        // Ensure test DB override is cleaned up
        unset($GLOBALS['_test_db']);
        parent::tearDown();
    }

    /**
     * Call a protected/private method
     */
    protected function callMethod($object, string $method, array $args = []) {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        // setAccessible not needed since PHP 8.1
        return $method->invokeArgs($object, $args);
    }

    /**
     * Set a protected/private property
     */
    protected function setProperty($object, string $property, $value): void {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        // setAccessible not needed since PHP 8.1
        $prop->setValue($object, $value);
    }

    /**
     * Get a protected/private property
     */
    protected function getProperty($object, string $property) {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        // setAccessible not needed since PHP 8.1
        return $prop->getValue($object);
    }
}
