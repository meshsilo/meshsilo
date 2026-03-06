<?php
/**
 * Server Timing Headers Middleware
 *
 * Adds Server-Timing headers to responses for performance debugging.
 * These headers are visible in browser DevTools Network panel.
 *
 * Example output:
 * Server-Timing: db;dur=23.5;desc="Database queries", php;dur=45.2;desc="PHP processing"
 */

require_once __DIR__ . '/MiddlewareInterface.php';

class ServerTimingMiddleware implements MiddlewareInterface {
    private static array $timings = [];
    private static float $requestStart;
    private static bool $enabled = true;

    public function __construct(array $options = []) {
        // Only enable Server-Timing headers in debug mode to avoid exposing internal data
        $debugMode = $options['enabled'] ?? (getenv('APP_DEBUG') === 'true')
            || (function_exists('getSetting') && getSetting('debug_mode', '0') === '1');
        self::$enabled = $debugMode;
        self::$requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    }

    public function handle(array $params): bool {
        if (!self::$enabled) {
            return true;
        }

        // Register shutdown function to add timing headers
        register_shutdown_function([self::class, 'sendTimingHeaders']);

        // Add initial timing
        self::start('total');

        return true;
    }

    /**
     * Start timing a segment
     */
    public static function start(string $name): void {
        if (!self::$enabled) return;
        self::$timings[$name] = [
            'start' => microtime(true),
            'end' => null,
            'description' => null
        ];
    }

    /**
     * End timing a segment
     */
    public static function end(string $name, ?string $description = null): void {
        if (!self::$enabled) return;
        if (isset(self::$timings[$name])) {
            self::$timings[$name]['end'] = microtime(true);
            self::$timings[$name]['description'] = $description;
        }
    }

    /**
     * Add a complete timing entry
     */
    public static function add(string $name, float $durationMs, ?string $description = null): void {
        if (!self::$enabled) return;
        self::$timings[$name] = [
            'duration' => $durationMs,
            'description' => $description
        ];
    }

    /**
     * Time a callable and add the timing
     */
    public static function measure(string $name, callable $callback, ?string $description = null) {
        if (!self::$enabled) {
            return $callback();
        }

        self::start($name);
        $result = $callback();
        self::end($name, $description);
        return $result;
    }

    /**
     * Send the Server-Timing header
     */
    public static function sendTimingHeaders(): void {
        if (!self::$enabled || headers_sent()) {
            return;
        }

        // End total timing
        self::end('total', 'Total request time');

        $timingParts = [];

        foreach (self::$timings as $name => $data) {
            if (isset($data['duration'])) {
                $duration = $data['duration'];
            } elseif (isset($data['start'])) {
                $end = $data['end'] ?? microtime(true);
                $duration = ($end - $data['start']) * 1000; // Convert to ms
            } else {
                continue;
            }

            // Format: name;dur=123.45;desc="Description"
            $part = self::sanitizeMetricName($name);
            $part .= ';dur=' . round($duration, 2);

            if (!empty($data['description'])) {
                $part .= ';desc="' . addslashes($data['description']) . '"';
            }

            $timingParts[] = $part;
        }

        if (!empty($timingParts)) {
            header('Server-Timing: ' . implode(', ', $timingParts));
        }

        // Also add total processing time for easy access
        $totalTime = (microtime(true) - self::$requestStart) * 1000;
        header('X-Response-Time: ' . round($totalTime, 2) . 'ms');
    }

    /**
     * Sanitize metric name to be RFC-compliant
     */
    private static function sanitizeMetricName(string $name): string {
        // Server-Timing metric names must be tokens (no spaces, special chars)
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Get all collected timings
     */
    public static function getTimings(): array {
        $result = [];
        foreach (self::$timings as $name => $data) {
            if (isset($data['duration'])) {
                $duration = $data['duration'];
            } elseif (isset($data['start'])) {
                $end = $data['end'] ?? microtime(true);
                $duration = ($end - $data['start']) * 1000;
            } else {
                continue;
            }
            $result[$name] = round($duration, 2);
        }
        return $result;
    }

    /**
     * Reset timings (useful for testing)
     */
    public static function reset(): void {
        self::$timings = [];
        self::$requestStart = microtime(true);
    }
}

/**
 * Helper function for easy timing
 */
if (!function_exists('serverTiming')) {
    function serverTiming(string $name, ?callable $callback = null, ?string $description = null) {
        if ($callback) {
            return ServerTimingMiddleware::measure($name, $callback, $description);
        }
        ServerTimingMiddleware::start($name);
    }
}

if (!function_exists('serverTimingEnd')) {
    function serverTimingEnd(string $name, ?string $description = null): void {
        ServerTimingMiddleware::end($name, $description);
    }
}
