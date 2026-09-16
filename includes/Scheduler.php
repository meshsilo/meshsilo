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
 */

class Scheduler
{
    private static array $tasks = [];
    private static bool $initialized = false;

    // Built-in task names
    public const TASK_CLEANUP_SESSIONS = 'cleanup:sessions';
    public const TASK_CLEANUP_LOGS = 'cleanup:logs';
    public const TASK_CLEANUP_CACHE = 'cleanup:cache';
    public const TASK_CLEANUP_TEMP = 'cleanup:temp';
    public const TASK_CLEANUP_RATE_LIMITS = 'cleanup:rate_limits';
    public const TASK_INTEGRITY_CHECK = 'integrity:check';
    public const TASK_DEDUP_SCAN = 'dedup:scan';
    public const TASK_QUEUE_PROCESS = 'queue:process';
    public const TASK_THUMBNAILS_GENERATE = 'thumbnails:generate';

    /**
     * Initialize scheduler with default tasks
     */
    public static function init(): void
    {
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
    public static function unregister(string $name): void
    {
        unset(self::$tasks[$name]);
    }

    /**
     * Run all due tasks
     */
    public static function run(): array
    {
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
    public static function runTask(string $name, ?array $task = null): array
    {
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

        // Remember the output-buffer nesting level so a callback that throws
        // (or leaves buffers open) can't leak them into later CLI output.
        $obLevel = ob_get_level();

        try {
            // Set timeout
            set_time_limit($task['timeout']);

            // Run the callback
            $output = null;
            ob_start();
            $result = call_user_func($task['callback']);
            $output = ob_get_clean();

            $duration = (int) round((microtime(true) - $startTime) * 1000);

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
        } catch (\Throwable $e) {
            // Close any output buffer the callback opened before throwing so
            // it doesn't leak and swallow later CLI output. Catch \Throwable
            // (not just Exception) so a TypeError/Error in a task is handled
            // the same way instead of propagating past the lock release.
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }

            $duration = (int) round((microtime(true) - $startTime) * 1000);

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
    public static function isDue(string $schedule, ?int $time = null): bool
    {
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
    private static function matchesCronPart(string $pattern, int $value, int $min, int $max): bool
    {
        // Wildcard
        if ($pattern === '*') {
            return true;
        }

        // List (e.g., "1,3,5")
        if (strpos($pattern, ',') !== false) {
            $values = array_map('intval', explode(',', $pattern));
            return in_array($value, $values);
        }

        // Step (e.g., "*/5", "0-30/5", "5/10") - checked before the plain range
        // so a range-with-step honors its step instead of degrading to the range.
        if (strpos($pattern, '/') !== false) {
            [$range, $stepStr] = explode('/', $pattern, 2);
            $step = (int)$stepStr;
            if ($step <= 0) {
                return false;
            }

            if ($range === '*') {
                // "*/N" spans the field's full range starting at its minimum,
                // so anchor the step to $min. A bare "value % step" is only
                // correct for 0-based fields (minute/hour); for 1-based fields
                // (day-of-month, month) it shifts every match by one - e.g.
                // "*/2" for day matched 2,4,6 instead of the correct 1,3,5.
                return ($value - $min) % $step === 0;
            }

            if (strpos($range, '-') !== false) {
                // Range with step (e.g., "0-30/5")
                [$start, $end] = array_map('intval', explode('-', $range));
            } else {
                // Open-ended step from a start value (e.g., "5/10")
                $start = (int)$range;
                $end = $max;
            }
            return $value >= $start && $value <= $end && ($value - $start) % $step === 0;
        }

        // Range (e.g., "1-5")
        if (strpos($pattern, '-') !== false) {
            [$start, $end] = array_map('intval', explode('-', $pattern));
            return $value >= $start && $value <= $end;
        }

        // Exact match
        return (int)$pattern === $value;
    }

    /**
     * Get all registered tasks
     */
    public static function getTasks(): array
    {
        self::init();
        return self::$tasks;
    }

    /**
     * Get task run history
     */
    public static function getHistory(int $limit = 50): array
    {
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
    private static function isRunning(string $name): bool
    {
        $lockFile = self::getLockFile($name);
        return file_exists($lockFile);
    }

    /**
     * Get lock file path for a task
     */
    private static function getLockFile(string $name): string
    {
        $dir = __DIR__ . '/../storage/cache/locks';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/' . md5($name) . '.lock';
    }

    /**
     * Acquire a lock for a task
     */
    private static function acquireLock(string $lockFile, int $timeout): bool
    {
        // Atomic create: 'x' mode fails if the file already exists, so only one
        // racing process can win. This replaces the previous check-then-create
        // sequence where two schedulers could both pass the existence check and
        // both write the lock.
        $handle = @fopen($lockFile, 'x');

        if ($handle === false) {
            // Lock already held. Reclaim it only if it is stale (older than the
            // task timeout), then retry the atomic create exactly once.
            $lockTime = @filemtime($lockFile);
            if ($lockTime !== false && time() - $lockTime > $timeout) {
                @unlink($lockFile);
                $handle = @fopen($lockFile, 'x');
            }
            if ($handle === false) {
                return false;
            }
        }

        fwrite($handle, (string) getmypid());
        fclose($handle);
        return true;
    }

    /**
     * Release a lock
     */
    private static function releaseLock(string $lockFile): void
    {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Log a task run
     */
    private static function logTaskRun(string $name, string $status, ?int $duration = null, ?string $output = null): void
    {
        if (!function_exists('getDB')) {
            return;
        }

        try {
            $db = getDB();

            // Check if table exists (DB-agnostic)
            if ($db->getType() === 'mysql') {
                $tableCheck = $db->querySingle("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'scheduler_log'");
            } else {
                $tableCheck = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='scheduler_log'");
            }
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

            // Remove orphaned 'started' entries (from old double-logging bug)
            $db->exec("DELETE FROM scheduler_log WHERE status = 'started'");

            // Cleanup old logs (keep last 1000)
            $count = $db->querySingle('SELECT COUNT(*) FROM scheduler_log');
            if ($count > 1000) {
                $deleteCount = $count - 1000;
                $db->exec("DELETE FROM scheduler_log WHERE id IN (SELECT id FROM (SELECT id FROM scheduler_log ORDER BY created_at ASC LIMIT $deleteCount) AS old)");
            }
        } catch (Exception $e) {
            // Silently fail
        }
    }

    /**
     * Register default system tasks
     */
    private static function registerDefaultTasks(): void
    {
        // Session cleanup - every hour
        self::register(self::TASK_CLEANUP_SESSIONS, '0 * * * *', function () {
            if (function_exists('getDB')) {
                $db = getDB();
                $now = time();
                $stmt = $db->prepare("DELETE FROM sessions WHERE expires_at > 0 AND expires_at < :now");
                $stmt->bindValue(':now', $now, PDO::PARAM_INT);
                $stmt->execute();
            }
            return 'Sessions cleaned';
        }, ['description' => 'Clean up expired sessions']);

        // Log cleanup - daily at 3am
        self::register(self::TASK_CLEANUP_LOGS, '0 3 * * *', function () {
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
        self::register(self::TASK_CLEANUP_CACHE, '0 */6 * * *', function () {
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
        self::register(self::TASK_CLEANUP_RATE_LIMITS, '*/15 * * * *', function () {
            if (class_exists('RateLimitMiddleware')) {
                $cleaned = RateLimitMiddleware::cleanup();
                return "Cleaned {$cleaned} rate limit entries";
            }
            return 'RateLimitMiddleware not loaded';
        }, ['description' => 'Clean up expired rate limit data']);

        // Activity log cleanup - daily at 4am
        self::register('cleanup:activity', '0 4 * * *', function () {
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

        // API request log cleanup - daily at 3:30am
        self::register('cleanup:api-log', '30 3 * * *', function () {
            if (function_exists('getDB') && function_exists('getSetting')) {
                $retention = (int)getSetting('api_log_retention_days', 30);
                if ($retention <= 0) {
                    return 'API request log retention disabled (0 = keep forever)';
                }
                $db = getDB();
                $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention} days"));
                $stmt = $db->prepare('DELETE FROM api_request_log WHERE created_at < :cutoff');
                $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
                $stmt->execute();
                return "Cleaned API request logs older than {$retention} days";
            }
            return 'Skipped';
        }, ['description' => 'Clean up old API request log entries']);

        // Database optimization - weekly on Sunday at 5am
        self::register('maintenance:optimize', '0 5 * * 0', function () {
            if (function_exists('getDB')) {
                $db = getDB();
                $db->exec('VACUUM');
                $db->exec('ANALYZE');
                return 'Database optimized';
            }
            return 'Skipped';
        }, ['description' => 'Optimize database (VACUUM and ANALYZE)']);

        // Queue processing - every minute
        self::register(self::TASK_QUEUE_PROCESS, '* * * * *', function () {
            if (!class_exists('Queue')) {
                require_once __DIR__ . '/Queue.php';
            }

            // Drain every queue producers actually push to, not just the
            // default queue. Real work lives on named queues (uploads,
            // conversions, thumbnails, images, pdfs); on non-Docker/cron
            // installs (no long-running queue worker) those would otherwise
            // never be processed.
            $queues = ['default', 'uploads', 'conversions', 'thumbnails', 'images', 'pdfs'];

            // Bounded per-run budget shared across all queues so a single cron
            // tick can't run forever.
            $maxJobs = 25;
            $processed = 0;
            $failed = 0;

            foreach ($queues as $queue) {
                while ($processed + $failed < $maxJobs) {
                    $job = Queue::pop($queue);
                    if (!$job) {
                        break;
                    }

                    // Mark completion/failure like cli/queue-worker.php:
                    // Queue::process() runs the job and throws on error, so
                    // complete() on success and fail() with the message on any
                    // Throwable. Without this, jobs stayed 'processing' forever
                    // (and in Docker got reclaimed and re-run).
                    try {
                        Queue::process($job);
                        Queue::complete((int)$job['id']);
                        $processed++;
                    } catch (\Throwable $e) {
                        Queue::fail((int)$job['id'], $e->getMessage());
                        $failed++;
                    }
                }

                if ($processed + $failed >= $maxJobs) {
                    break;
                }
            }

            if ($processed === 0 && $failed === 0) {
                return 'No jobs in queue';
            }
            return "Processed {$processed} jobs, {$failed} failed";
        }, ['description' => 'Process background job queue']);

        // Thumbnail generation - every 5 minutes
        self::register(self::TASK_THUMBNAILS_GENERATE, '*/5 * * * *', function () {
            if (!class_exists('ThumbnailGenerator')) {
                require_once __DIR__ . '/ThumbnailGenerator.php';
            }

            $results = ThumbnailGenerator::batchGenerate(10);
            if ($results['success'] === 0 && $results['failed'] === 0) {
                return 'No pending thumbnails';
            }
            return "Generated {$results['success']} thumbnails, {$results['failed']} failed";
        }, ['description' => 'Generate thumbnails for new models']);

        // Deduplication scan - every hour
        self::register(self::TASK_DEDUP_SCAN, '0 * * * *', function () {
            if (getSetting('auto_deduplication', '0') !== '1') {
                return 'Auto-deduplication disabled in settings';
            }

            require_once __DIR__ . '/dedup.php';

            if (!function_exists('runDeduplicationScan')) {
                return 'Deduplication function not available';
            }

            // Calculate any missing hashes first, matching CLI behavior.
            // Without this step, models uploaded without a hash are invisible
            // to the dedup scan (findDuplicateHashes filters them out).
            $hashMsg = '';
            if (function_exists('calculateMissingHashes')) {
                $hashResult = calculateMissingHashes();
                $calculated = is_array($hashResult) ? ($hashResult['calculated'] ?? 0) : (int)$hashResult;
                if ($calculated > 0) {
                    $hashMsg = " (calculated {$calculated} missing hashes first)";
                }
            }

            $result = runDeduplicationScan();
            $processed = $result['hashes_processed'] ?? 0;
            $deleted = $result['files_deleted'] ?? 0;
            $saved = $result['space_saved'] ?? 0;
            return "Dedup scan complete: {$processed} hashes processed, {$deleted} files deleted, {$saved} bytes saved{$hashMsg}";
        }, ['description' => 'Scan for duplicate files']);

        // Stale tus upload cleanup - every hour
        self::register('cleanup:tus_uploads', '0 * * * *', function () {
            $tusDir = __DIR__ . '/../storage/uploads/tus';
            if (!is_dir($tusDir)) {
                return 'No tus upload directory';
            }

            $threshold = time() - 86400; // 24 hours
            $cleaned = 0;

            foreach (glob($tusDir . '/*.json') as $infoFile) {
                if (filemtime($infoFile) < $threshold) {
                    $id = pathinfo($infoFile, PATHINFO_FILENAME);
                    @unlink($tusDir . '/' . $id . '.bin');
                    @unlink($infoFile);
                    $cleaned++;
                }
            }

            // Also cleanup orphaned pending_upload models older than 24h
            if (function_exists('getDB')) {
                $db = getDB();
                try {
                    $cutoff = date('Y-m-d H:i:s', $threshold);
                    // Get file_path before deleting so we can clean up the filesystem
                    $stmt = $db->prepare("SELECT id, file_path FROM models WHERE upload_status = 'pending_upload' AND created_at < :cutoff");
                    $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
                    $result = $stmt->execute();
                    $orphanedRows = [];
                    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                        $orphanedRows[] = $row;
                    }

                    foreach ($orphanedRows as $row) {
                        // Remove the assets folder if it exists.
                        // Guard: an empty file_path would resolve to the storage/
                        // root and the recursive delete would wipe everything, so
                        // skip empty paths. Also require the resolved realpath to be
                        // strictly INSIDE storage/assets/ before any recursive delete.
                        $filePath = $row['file_path'] ?? '';
                        if ($filePath !== '') {
                            $assetDir = __DIR__ . '/../storage/' . $filePath;
                            $base = realpath(__DIR__ . '/../storage/assets');
                            $real = realpath($assetDir);
                            if ($base !== false && $real !== false
                                && strpos($real, $base . DIRECTORY_SEPARATOR) === 0
                                && is_dir($real)) {
                                $files = new \RecursiveIteratorIterator(
                                    new \RecursiveDirectoryIterator($real, \RecursiveDirectoryIterator::SKIP_DOTS),
                                    \RecursiveIteratorIterator::CHILD_FIRST
                                );
                                foreach ($files as $f) {
                                    $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
                                }
                                @rmdir($real);
                            }
                        }
                        $delStmt = $db->prepare("DELETE FROM models WHERE id = :id");
                        $delStmt->bindValue(':id', $row['id'], PDO::PARAM_INT);
                        $delStmt->execute();
                    }

                    $orphaned = count($orphanedRows);
                    if ($orphaned > 0) {
                        return "Cleaned {$cleaned} stale uploads, {$orphaned} orphaned models";
                    }
                } catch (\Exception $e) {
                    // upload_status column may not exist yet
                }
            }

            return $cleaned > 0 ? "Cleaned {$cleaned} stale tus uploads" : 'No stale uploads';
        }, ['description' => 'Clean up stale tus upload chunks (>24h)']);

        // Allow plugins to register scheduled tasks
        if (class_exists('PluginManager')) {
            self::$tasks = PluginManager::applyFilter('scheduled_tasks', self::$tasks);
        }
    }

    /**
     * Enable/disable a task
     */
    public static function setEnabled(string $name, bool $enabled): void
    {
        if (isset(self::$tasks[$name])) {
            self::$tasks[$name]['enabled'] = $enabled;
        }
    }

    /**
     * Get next run time for a task
     */
    public static function getNextRun(string $schedule): ?int
    {
        $now = time();

        // Scan minute-by-minute over a one-week horizon. Cron granularity is
        // one minute, so stepping by the minute is both correct and bounded
        // (10080 iterations worst case). The previous version stepped by whole
        // hours and then whole days, which preserved "now"'s minute-of-hour
        // offset: a schedule pinned to a specific minute (e.g. "30 3 * * *"
        // evaluated at 10:05) never matched and the next run showed as "N/A".
        $maxMinutes = 7 * 24 * 60;
        for ($i = 1; $i <= $maxMinutes; $i++) {
            $checkTime = $now + ($i * 60);
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
function runScheduler(): array
{
    return Scheduler::run();
}
