<?php
/**
 * Environment Configuration Loader
 *
 * Loads configuration from .env files for environment-based configuration.
 * Supports:
 * - Variable interpolation (${VAR})
 * - Comments (# or ;)
 * - Quoted values
 * - Multiline values
 * - Type casting
 */

class Env {
    private static bool $loaded = false;
    private static array $variables = [];
    private static string $path;

    /**
     * Load environment variables from .env file
     */
    public static function load(?string $path = null): void {
        if (self::$loaded && $path === null) {
            return;
        }

        // Mark as loaded early to prevent infinite recursion from variable interpolation
        // (processValue -> get -> load cycle when ${VAR} references exist)
        self::$loaded = true;

        self::$path = $path ?? dirname(__DIR__) . '/.env';

        if (!file_exists(self::$path)) {
            // Try .env.example as fallback for development
            $examplePath = dirname(__DIR__) . '/.env.example';
            if (file_exists($examplePath)) {
                self::$path = $examplePath;
            } else {
                self::$loaded = true;
                return;
            }
        }

        $lines = file(self::$path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            $line = trim($line);
            if (empty($line) || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            // Parse line
            if (strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Skip if empty name
            if (empty($name)) {
                continue;
            }

            // Process value
            $value = self::processValue($value);

            // Store and set
            self::$variables[$name] = $value;

            // Set in $_ENV and putenv if not already set
            if (!isset($_ENV[$name])) {
                $_ENV[$name] = $value;
            }
            if (getenv($name) === false) {
                putenv("$name=$value");
            }
        }

    }

    /**
     * Process a value (handle quotes, interpolation, etc.)
     */
    private static function processValue(string $value): string {
        // Handle quoted strings
        if (preg_match('/^"(.*)\"$/', $value, $matches)) {
            $value = $matches[1];
            // Handle escape sequences
            $value = str_replace(['\\n', '\\r', '\\t', '\\"'], ["\n", "\r", "\t", '"'], $value);
        } elseif (preg_match("/^'(.*)'$/", $value, $matches)) {
            // Single quotes - no interpolation
            return $matches[1];
        }

        // Handle inline comments (only for unquoted values)
        if (!preg_match('/^["\']/', $value) && strpos($value, '#') !== false) {
            $value = trim(explode('#', $value)[0]);
        }

        // Variable interpolation
        $value = preg_replace_callback('/\${([A-Za-z_][A-Za-z0-9_]*)}/', function($matches) {
            return self::get($matches[1], '');
        }, $value);

        return $value;
    }

    /**
     * Get an environment variable
     */
    public static function get(string $key, $default = null) {
        // Load if not loaded
        if (!self::$loaded) {
            self::load();
        }

        // Check our cache first
        if (isset(self::$variables[$key])) {
            return self::cast(self::$variables[$key]);
        }

        // Check $_ENV
        if (isset($_ENV[$key])) {
            return self::cast($_ENV[$key]);
        }

        // Check getenv
        $value = getenv($key);
        if ($value !== false) {
            return self::cast($value);
        }

        return $default;
    }

    /**
     * Cast value to appropriate type
     */
    private static function cast(string $value) {
        $lower = strtolower($value);

        // Boolean
        if ($lower === 'true' || $lower === '(true)') {
            return true;
        }
        if ($lower === 'false' || $lower === '(false)') {
            return false;
        }

        // Null
        if ($lower === 'null' || $lower === '(null)') {
            return null;
        }

        // Empty
        if ($lower === 'empty' || $lower === '(empty)') {
            return '';
        }

        // Numeric
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        return $value;
    }

    /**
     * Check if an environment variable exists
     */
    public static function has(string $key): bool {
        if (!self::$loaded) {
            self::load();
        }

        return isset(self::$variables[$key]) ||
               isset($_ENV[$key]) ||
               getenv($key) !== false;
    }

    /**
     * Set an environment variable at runtime
     */
    public static function set(string $key, $value): void {
        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;

        self::$variables[$key] = $stringValue;
        $_ENV[$key] = $stringValue;
        putenv("$key=$stringValue");
    }

    /**
     * Get all loaded variables
     */
    public static function all(): array {
        if (!self::$loaded) {
            self::load();
        }

        return self::$variables;
    }

    /**
     * Require an environment variable (throws if not set)
     */
    public static function require(string $key) {
        $value = self::get($key);

        if ($value === null) {
            throw new \RuntimeException("Required environment variable '$key' is not set.");
        }

        return $value;
    }

    /**
     * Get string value
     */
    public static function string(string $key, string $default = ''): string {
        $value = self::get($key, $default);
        return is_string($value) ? $value : (string)$value;
    }

    /**
     * Get integer value
     */
    public static function int(string $key, int $default = 0): int {
        $value = self::get($key, $default);
        return is_int($value) ? $value : (int)$value;
    }

    /**
     * Get float value
     */
    public static function float(string $key, float $default = 0.0): float {
        $value = self::get($key, $default);
        return is_float($value) ? $value : (float)$value;
    }

    /**
     * Get boolean value
     */
    public static function bool(string $key, bool $default = false): bool {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on']);
        }

        return (bool)$value;
    }

    /**
     * Get array value (comma-separated)
     */
    public static function array(string $key, array $default = []): array {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return $value;
        }

        return array_map('trim', explode(',', $value));
    }

    /**
     * Get JSON value
     */
    public static function json(string $key, array $default = []): array {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * Check if running in production
     */
    public static function isProduction(): bool {
        return self::get('APP_ENV', 'production') === 'production';
    }

    /**
     * Check if running in development
     */
    public static function isDevelopment(): bool {
        $env = self::get('APP_ENV', 'production');
        return in_array($env, ['development', 'dev', 'local']);
    }

    /**
     * Check if debug mode is enabled
     */
    public static function isDebug(): bool {
        return self::bool('APP_DEBUG', false);
    }

    /**
     * Create a .env file from example
     */
    public static function createFromExample(array $values = []): bool {
        $examplePath = dirname(__DIR__) . '/.env.example';
        $envPath = dirname(__DIR__) . '/.env';

        if (!file_exists($examplePath)) {
            return false;
        }

        $content = file_get_contents($examplePath);

        // Replace values
        foreach ($values as $key => $value) {
            $escaped = addslashes($value);
            // Match KEY=value or KEY="value" patterns
            $content = preg_replace(
                "/^{$key}=.*/m",
                "{$key}=\"{$escaped}\"",
                $content
            );
        }

        return file_put_contents($envPath, $content) !== false;
    }
}

// ========================================
// Helper Function (overrides the one in helpers.php)
// ========================================

if (!function_exists('env')) {
    /**
     * Get an environment variable
     */
    function env(string $key, $default = null) {
        return Env::get($key, $default);
    }
}

// Auto-load environment on include
Env::load();
