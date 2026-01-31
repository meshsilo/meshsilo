<?php
/**
 * Task Scheduler for Silo
 *
 * A simple cron-like task scheduler that can be triggered by:
 * 1. System cron (recommended): * * * * * php /path/to/silo/cli/scheduler.php
 * 2. Web request to /cron endpoint (with secret key)
 * 3. Manual execution via admin panel
 *
 * Usage:
 *   Scheduler::register('cleanup', '0 * * * *', function() { ... });
 *   Scheduler::register('daily-backup', '0 2 * * *', [BackupTask::class, 'run']);
 */

class Scheduler {
    private static array $tasks = [];
    private static bool $initialized = false;

    // Built-in task names
    public const TASK_CLEANUP_SESSIONS = 'cleanup:sessions';
    public const TASK_CLEANUP_LOGS = 'cleanup:logs';
    public const TASK_CLEANUP_CACHE = 'cleanup:cache';
    public const TASK_CLEANUP_TEMP = 'cleanup:temp';
    public const TASK_CLEANUP_RATE_LIMITS = 'cleanup:rate_limits';
    public const TASK_BACKUP_DATABASE = 'backup:database';
    public const TASK_BACKUP_FULL = 'backup:full';
    public const TASK_INTEGRITY_CHECK = 'integrity:check';
    public const TASK_DEDUP_SCAN = 'dedup:scan';
    public const TASK_STATS_CALCULATE = 'stats:calculate';
    public const TASK_WEBHOOKS_RETRY = 'webhooks:retry';
    public const TASK_DEMO_RESET = 'demo:reset';

    /**
     * Initialize scheduler with default tasks
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        self::registerDefaultTasks();
        self::$initialized = true;
    }

    /**
     * Register a scheduled task
     *
     * @param string $name Unique task name
     * @param string $schedule Cron expression (minute hour day month weekday)
     * @param callable $callback Task callback
     * @param array $options Additional options
     */
    public static function register(
        string $name,
        string $schedule,
        callable $callback,
        array $options = []
    ): void {
        self::$tasks[$name] = [
            'name' => $name,
            'schedule' => $schedule,
            'callback' => $callback,
            'enabled' => $options['enabled'] ?? true,
            'timeout' => $options['timeout'] ?? 300, // 5 minutes default
            'overlap' => $options['overlap'] ?? false, // Allow concurrent runs
            'description' => $options['description'] ?? '',
        ];
    }

    /**
     * Unregister a task
     */
    public static function unregister(string $name): void {
        unset(self::$tasks[$name]);
    }

    /**
     * Run all due tasks
     */
    public static function run(): array {
        self::init();

        $results = [];
        $now = time();

        foreach (self::$tasks as $name => $task) {
            if (!$task['enabled']) {
                continue;
            }

            // Check if task is due
            if (!self::isDue($task['schedule'], $now)) {
                continue;
            }

            // Check if task is already running (prevent overlap)
            if (!$task['overlap'] && self::isRunning($name)) {
                $results[$name] = ['status' => 'skipped', 'reason' => 'already running'];
                continue;
            }

            // Run the task
            $results[$name] = self::runTask($name, $task);
        }

        return $results;
    }

    /**
     * Run a specific task by name
     */
    public static function runTask(string $name, ?array $task = null): array {
        self::init();

        if ($task === null) {
            if (!isset(self::$tasks[$name])) {
                return ['status' => 'error', 'error' => 'Task not found'];
            }
            $task = self::$tasks[$name];
        }

        $startTime = microtime(true);
        $lockFile = self::getLockFile($name);

        // Acquire lock
        if (!$task['overlap']) {
            if (!self::acquireLock($lockFile, $task['timeout'])) {
                return ['status' => 'skipped', 'reason' => 'could not acquire lock'];
            }
        }

        try {
            // Log task start
            self::logTaskRun($name, 'started');

            // Set timeout
            set_time_limit($task['timeout']);

            // Run the callback
            $output = null;
            ob_start();
            $result = call_user_func($task['callback']);
            $output = ob_get_clean();

            $duration = round((microtime(true) - $startTime) * 1000);

            // Log task completion
            self::logTaskRun($name, 'completed', $duration, $output);

            // Emit event
            if (class_exists('Events')) {
                Events::emit('scheduler.task_completed', [
                    'task' => $name,
                    'duration_ms' => $duration,
                    'result' => $result
                ]);
            }

            return [
                'status' => 'success',
                'duration_ms' => $duration,
                'output' => $output,
                'result' => $result
            ];
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);

            self::logTaskRun($name, 'failed', $duration, $e->getMessage());

            if (class_exists('Events')) {
                Events::emit('scheduler.task_failed', [
                    'task' => $name,
                    'error' => $e->getMessage()
                ]);
            }

            return [
                'status' => 'error',
                'duration_ms' => $duration,
                'error' => $e->getMessage()
            ];
        } finally {
            // Release lock
            if (!$task['overlap']) {
                self::releaseLock($lockFile);
            }
        }
    }

    /**
     * Check if a cron expression is due
     */
    public static function isDue(string $schedule, ?int $time = null): bool {
        $time = $time ?? time();
        $parts = preg_split('/\s+/', trim($schedule));

        if (count($parts) !== 5) {
            return false;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        $currentMinute = (int)date('i', $time);
        $currentHour = (int)date('G', $time);
        $currentDay = (int)date('j', $time);
        $currentMonth = (int)date('n', $time);
        $currentWeekday = (int)date('w', $time);

        return self::matchesCronPart($minute, $currentMinute, 0, 59)
            && self::matchesCronPart($hour, $currentHour, 0, 23)
            && self::matchesCronPart($day, $currentDay, 1, 31)
            && self::matchesCronPart($month, $currentMonth, 1, 12)
            && self::matchesCronPart($weekday, $currentWeekday, 0, 6);
    }

    /**
     * Match a single cron part
     */
    private static function matchesCronPart(string $pattern, int $value, int $min, int $max): bool {
        // Wildcard
        if ($pattern === '*') {
            return true;
        }

        // List (e.g., "1,3,5")
        if (strpos($pattern, ',') !== false) {
            $values = array_map('intval', explode(',', $pattern));
            return in_array($value, $values);
        }

        // Range (e.g., "1-5")
        if (strpos($pattern, '-') !== false) {
            [$start, $end] = array_map('intval', explode('-', $pattern));
            return $value >= $start && $value <= $end;
        }

        // Step (e.g., "*/5")
        if (strpos($pattern, '/') !== false) {
            [$range, $step] = explode('/', $pattern);
            $step = (int)$step;

            if ($range === '*') {
                return $value % $step === 0;
            }

            // Range with step (e.g., "0-30/5")
            if (strpos($range, '-') !== false) {
                [$start, $end] = array_map('intval', explode('-', $range));
                return $value >= $start && $value <= $end && ($value - $start) % $step === 0;
            }
        }

        // Exact match
        return (int)$pattern === $value;
    }

    /**
     * Get all registered tasks
     */
    public static function getTasks(): array {
        self::init();
        return self::$tasks;
    }

    /**
     * Get task run history
     */
    public static function getHistory(int $limit = 50): array {
        if (!function_exists('getDB')) {
            return [];
        }

        try {
            $db = getDB();
            $stmt = $db->prepare('
                SELECT * FROM scheduler_log
                ORDER BY created_at DESC
                LIMIT :limit
            ');
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $result = $stmt->execute();

            $history = [];
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $history[] = $row;
            }

            return $history;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Check if a task is currently running
     */
    private static function isRunning(string $name): bool {
        $lockFile = self::getLockFile($name);
        return file_exists($lockFile);
    }

    /**
     * Get lock file path for a task
     */
    private static function getLockFile(string $name): string {
        $dir = __DIR__ . '/../storage/cache/locks';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/' . md5($name) . '.lock';
    }

    /**
     * Acquire a lock for a task
     */
    private static function acquireLock(string $lockFile, int $timeout): bool {
        // Check if lock exists and is stale
        if (file_exists($lockFile)) {
            $lockTime = filemtime($lockFile);
            if (time() - $lockTime > $timeout) {
                // Stale lock, remove it
                unlink($lockFile);
            } else {
                return false;
            }
        }

        // Create lock file
        return file_put_contents($lockFile, getmypid()) !== false;
    }

    /**
     * Release a lock
     */
    private static function releaseLock(string $lockFile): void {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Log a task run
     */
    private static function logTaskRun(string $name, string $status, ?int $duration = null, ?string $output = null): void {
        if (!function_exists('getDB')) {
            return;
        }

        try {
            $db = getDB();

            // Check if table exists
            $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='scheduler_log'");
            if (!$tableCheck) {
                return;
            }

            $stmt = $db->prepare('
                INSERT INTO scheduler_log (task_name, status, duration_ms, output, created_at)
                VALUES (:name, :status, :duration, :output, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':duration', $duration, PDO::PARAM_INT);
            $stmt->bindValue(':output', $output ? substr($output, 0, 10000) : null, PDO::PARAM_STR);
            $stmt->execute();

            // Cleanup old logs (keep last 1000)
            $db->exec('DELETE FROM scheduler_log WHERE id NOT IN (SELECT id FROM scheduler_log ORDER BY created_at DESC LIMIT 1000)');
        } catch (Exception $e) {
            // Silently fail
        }
    }

    /**
     * Register default system tasks
     */
    private static function registerDefaultTasks(): void {
        // Session cleanup - every hour
        self::register(self::TASK_CLEANUP_SESSIONS, '0 * * * *', function() {
            if (function_exists('getDB')) {
                $db = getDB();
                // Clean expired sessions (older than 24 hours)
                $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
                $stmt = $db->prepare("DELETE FROM sessions WHERE last_activity < :cutoff");
                $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
                $stmt->execute();
            }
            return 'Sessions cleaned';
        }, ['description' => 'Clean up expired sessions']);

        // Log cleanup - daily at 3am
        self::register(self::TASK_CLEANUP_LOGS, '0 3 * * *', function() {
            $logsDir = __DIR__ . '/../storage/logs';
            $cleaned = 0;

            if (is_dir($logsDir)) {
                $threshold = time() - (30 * 86400); // 30 days
                foreach (glob($logsDir . '/*.log.*') as $file) {
                    if (filemtime($file) < $threshold) {
                        unlink($file);
                        $cleaned++;
                    }
                }
            }

            return "Cleaned {$cleaned} old log files";
        }, ['description' => 'Clean up old log files']);

        // Cache cleanup - every 6 hours
        self::register(self::TASK_CLEANUP_CACHE, '0 */6 * * *', function() {
            $cacheDir = __DIR__ . '/../storage/cache';
            $cleaned = 0;

            if (is_dir($cacheDir)) {
                $threshold = time() - 86400; // 24 hours
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getMTime() < $threshold) {
                        // Don't delete .gitkeep
                        if ($file->getFilename() !== '.gitkeep') {
                            unlink($file->getPathname());
                            $cleaned++;
                        }
                    }
                }
            }

            return "Cleaned {$cleaned} cache files";
        }, ['description' => 'Clean up expired cache files']);

        // Rate limit cleanup - every 15 minutes
        self::register(self::TASK_CLEANUP_RATE_LIMITS, '*/15 * * * *', function() {
            if (class_exists('RateLimitMiddleware')) {
                $cleaned = RateLimitMiddleware::cleanup();
                return "Cleaned {$cleaned} rate limit entries";
            }
            return 'RateLimitMiddleware not loaded';
        }, ['description' => 'Clean up expired rate limit data']);

        // Activity log cleanup - daily at 4am
        self::register('cleanup:activity', '0 4 * * *', function() {
            if (function_exists('getDB') && function_exists('getSetting')) {
                $db = getDB();
                $retention = (int)getSetting('activity_log_retention', 90);
                $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention} days"));
                $stmt = $db->prepare("DELETE FROM activity_log WHERE created_at < :cutoff");
                $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
                $stmt->execute();
                return "Cleaned activity logs older than {$retention} days";
            }
            return 'Skipped';
        }, ['description' => 'Clean up old activity log entries']);

        // Demo mode reset - every hour (only runs if demo mode is enabled)
        self::register(self::TASK_DEMO_RESET, '0 * * * *', function() {
            if (!function_exists('getSetting') || getSetting('demo_mode', '0') !== '1') {
                return 'Demo mode not enabled, skipped';
            }

            if (!class_exists('DemoMode')) {
                require_once __DIR__ . '/DemoMode.php';
            }

            $demo = new DemoMode();
            $result = $demo->resetToDemo();

            if ($result['success']) {
                $msgs = implode('; ', $result['messages'] ?? []);
                return "Demo reset completed: $msgs";
            } else {
                $errs = implode('; ', $result['errors'] ?? []);
                throw new Exception("Demo reset failed: $errs");
            }
        }, [
            'description' => 'Reset demo instance to sample data (hourly)',
            'timeout' => 600,
        ]);

        // Database optimization - weekly on Sunday at 5am
        self::register('maintenance:optimize', '0 5 * * 0', function() {
            if (function_exists('getDB')) {
                $db = getDB();
                $db->exec('VACUUM');
                $db->exec('ANALYZE');
                return 'Database optimized';
            }
            return 'Skipped';
        }, ['description' => 'Optimize database (VACUUM and ANALYZE)']);
    }

    /**
     * Enable/disable a task
     */
    public static function setEnabled(string $name, bool $enabled): void {
        if (isset(self::$tasks[$name])) {
            self::$tasks[$name]['enabled'] = $enabled;
        }
    }

    /**
     * Get next run time for a task
     */
    public static function getNextRun(string $schedule): ?int {
        $now = time();

        // Check next 60 minutes
        for ($i = 1; $i <= 60; $i++) {
            $checkTime = $now + ($i * 60);
            if (self::isDue($schedule, $checkTime)) {
                return $checkTime;
            }
        }

        // Check next 24 hours
        for ($i = 1; $i <= 24; $i++) {
            $checkTime = $now + ($i * 3600);
            if (self::isDue($schedule, $checkTime)) {
                return $checkTime;
            }
        }

        // Check next 7 days
        for ($i = 1; $i <= 7; $i++) {
            $checkTime = $now + ($i * 86400);
            if (self::isDue($schedule, $checkTime)) {
                return $checkTime;
            }
        }

        return null;
    }
}

/**
 * Helper function to run the scheduler
 */
function runScheduler(): array {
    return Scheduler::run();
}
