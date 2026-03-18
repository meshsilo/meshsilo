<?php

trait DebugProfiler
{
    // =========================================================================
    // PERFORMANCE DEBUGGING
    // =========================================================================

    /**
     * Start a timer
     */
    public static function timerStart(string $name): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::$timers[$name] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];
    }

    /**
     * End a timer and log
     */
    public static function timerEnd(string $name): ?float
    {
        if (!self::isEnabled() || !isset(self::$timers[$name])) {
            return null;
        }

        $start = self::$timers[$name]['start'];
        $memStart = self::$timers[$name]['memory_start'];
        $duration = microtime(true) - $start;
        $memUsed = memory_get_usage(true) - $memStart;

        unset(self::$timers[$name]);

        self::log("Timer '$name' completed", [
            'duration_ms' => round($duration * 1000, 2),
            'memory_used' => self::formatBytes($memUsed),
        ], 'timer');

        return $duration;
    }

    /**
     * Take a memory snapshot
     */
    public static function memorySnapshot(string $label): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::$memorySnapshots[$label] = [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'time' => microtime(true) - self::$startTime,
        ];

        if (self::$config['verbose']) {
            self::log("Memory snapshot: $label", [
                'current' => self::formatBytes(memory_get_usage(true)),
                'peak' => self::formatBytes(memory_get_peak_usage(true)),
            ], 'memory');
        }
    }

    /**
     * Write log entry to file
     */
    private static function writeToFile(array $entry): void
    {
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/debug.log';

        // Rotate if too large (> 5MB)
        if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
            @rename($logFile, $logFile . '.' . date('Y-m-d-His'));
        }

        $contextStr = !empty($entry['context'])
            ? ' ' . json_encode($entry['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        $line = sprintf(
            "[%s] [+%.4fs] [%s] %s%s%s\n",
            date('Y-m-d H:i:s'),
            $entry['time'],
            strtoupper($entry['level']),
            $entry['message'],
            $contextStr,
            $entry['file'] ? " ({$entry['file']}:{$entry['line']})" : ''
        );

        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Write request summary
     */
    private static function writeSummary(): void
    {
        $duration = microtime(true) - self::$startTime;
        $memory = memory_get_peak_usage(true);
        $errors = count(array_filter(self::$logs, fn($l) => in_array($l['level'], ['error', 'fatal'])));
        $warnings = count(array_filter(self::$logs, fn($l) => $l['level'] === 'warning'));

        $summary = sprintf(
            "[%s] === REQUEST COMPLETE === %s %s | %.0fms | %s | %d queries | %d errors | %d warnings\n%s\n",
            date('Y-m-d H:i:s'),
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            $_SERVER['REQUEST_URI'] ?? '',
            $duration * 1000,
            self::formatBytes($memory),
            self::$queryCount,
            $errors,
            $warnings,
            str_repeat('=', 100)
        );

        $logFile = dirname(__DIR__) . '/storage/logs/debug.log';
        @file_put_contents($logFile, $summary, FILE_APPEND | LOCK_EX);
    }

}
