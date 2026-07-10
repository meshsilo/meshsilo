<?php

/**
 * Database query profiling for the Debug system.
 *
 * Extracted from Debug.php as a cohesive collaborator. Composed into the
 * Debug facade via `use`, so every existing static call (Debug::query,
 * Debug::queryStart, Debug::queryEnd) keeps working unchanged. Shares the
 * Debug class's private static state ($queryCount, $queries, $config,
 * $startTime) exactly as the DebugProfiler / DebugPanels traits do.
 */
trait DebugQueryLog
{
    // =========================================================================
    // DATABASE DEBUGGING
    // =========================================================================

    /**
     * Log a database query
     */
    public static function query(string $sql, array $params = [], ?float $duration = null): void
    {
        if (!self::isEnabled() || !self::$config['log_queries']) {
            return;
        }

        self::$queryCount++;

        $queryInfo = [
            'index' => self::$queryCount,
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'time' => microtime(true) - self::$startTime,
            'trace' => self::getShortTrace(3),
        ];

        self::$queries[] = $queryInfo;

        if (self::$config['verbose']) {
            self::log("Query #{$queryInfo['index']}: " . substr($sql, 0, 100), [
                'params' => $params,
                'duration_ms' => $duration ? round($duration * 1000, 2) : null,
            ], 'query');
        }

        // Warn on slow queries (> 100ms)
        if ($duration && $duration > 0.1) {
            self::warn("Slow query ({$queryInfo['index']}): " . round($duration * 1000) . "ms", [
                'sql' => substr($sql, 0, 200),
            ]);
        }
    }

    /**
     * Start a query timer
     */
    public static function queryStart(): float
    {
        return microtime(true);
    }

    /**
     * End query timer and log
     */
    public static function queryEnd(float $start, string $sql, array $params = []): void
    {
        $duration = microtime(true) - $start;
        self::query($sql, $params, $duration);
    }
}
