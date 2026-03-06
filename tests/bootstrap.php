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

// Start session for CSRF tests
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mock functions that depend on database
if (!function_exists('getDB')) {
    function getDB() {
        static $db = null;
        if ($db === null) {
            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Create minimal schema for tests
            $db->exec("
                CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
            ");
        }
        return $db;
    }
}

if (!function_exists('getSetting')) {
    function getSetting(string $key, $default = null) {
        return $default;
    }
}

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
    }

    protected function tearDown(): void {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Call a protected/private method
     */
    protected function callMethod($object, string $method, array $args = []) {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    /**
     * Set a protected/private property
     */
    protected function setProperty($object, string $property, $value): void {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    /**
     * Get a protected/private property
     */
    protected function getProperty($object, string $property) {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }
}
