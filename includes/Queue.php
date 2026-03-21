<?php

/**
 * Background Job Queue
 *
 * Provides a simple database-backed job queue for background processing.
 * Jobs are stored in SQLite and processed by a CLI worker.
 *
 * Usage:
 *   Queue::push('SendEmail', ['to' => 'user@example.com']);
 *   Queue::push('GenerateThumbnail', ['model_id' => 123], 'thumbnails');
 *   Queue::later('+5 minutes', 'CleanupTempFiles', []);
 */

class Queue
{
    private static $db = null;

    // Job statuses
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    // Default queue
    const DEFAULT_QUEUE = 'default';

    /**
     * Get database connection
     */
    private static function db()
    {
        if (self::$db === null) {
            self::$db = function_exists('getDB') ? getDB() : null;
            self::ensureTable();
        }
        return self::$db;
    }

    /**
     * Ensure jobs table exists
     */
    private static function ensureTable(): void
    {
        $db = self::$db;
        if (!$db) {
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue VARCHAR(255) NOT NULL DEFAULT 'default',
                job_class VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 3,
                status VARCHAR(50) DEFAULT 'pending',
                available_at DATETIME NOT NULL,
                reserved_at DATETIME,
                completed_at DATETIME,
                error_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_jobs_queue_status ON jobs(queue, status, available_at)");
    }

    /**
     * Push a job onto the queue
     */
    public static function push(string $jobClass, array $data = [], string $queue = self::DEFAULT_QUEUE): int
    {
        return self::pushAt(date('Y-m-d H:i:s'), $jobClass, $data, $queue);
    }

    /**
     * Push a job to run later
     */
    public static function later($delay, string $jobClass, array $data = [], string $queue = self::DEFAULT_QUEUE): int
    {
        if (is_string($delay)) {
            $delay = strtotime($delay);
        } elseif (is_int($delay)) {
            $delay = time() + $delay;
        }

        return self::pushAt(date('Y-m-d H:i:s', $delay), $jobClass, $data, $queue);
    }

    /**
     * Push a job at a specific time
     */
    private static function pushAt(string $availableAt, string $jobClass, array $data, string $queue): int
    {
        $db = self::db();
        if (!$db) {
            return 0;
        }

        $stmt = $db->prepare("
            INSERT INTO jobs (queue, job_class, payload, available_at, status)
            VALUES (:queue, :job_class, :payload, :available_at, :status)
        ");

        $stmt->bindValue(':queue', $queue, PDO::PARAM_STR);
        $stmt->bindValue(':job_class', $jobClass, PDO::PARAM_STR);
        $stmt->bindValue(':payload', json_encode($data), PDO::PARAM_STR);
        $stmt->bindValue(':available_at', $availableAt, PDO::PARAM_STR);
        $stmt->bindValue(':status', self::STATUS_PENDING, PDO::PARAM_STR);

        $stmt->execute();
        return $db->lastInsertRowID();
    }

    /**
     * Reclaim jobs stuck in "processing" for longer than the given timeout.
     * This handles worker crashes (OOM, container restart) where a job
     * was reserved but never completed or failed.
     */
    public static function reclaimStale(int $timeoutSeconds = 900): int
    {
        $db = self::db();
        if (!$db) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', time() - $timeoutSeconds);

        $stmt = $db->prepare("
            UPDATE jobs
            SET status = :pending, reserved_at = NULL
            WHERE status = :processing
            AND reserved_at < :cutoff
        ");
        $stmt->bindValue(':pending', self::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->bindValue(':processing', self::STATUS_PROCESSING, PDO::PARAM_STR);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->execute();

        $reclaimed = $db->changes();
        if ($reclaimed > 0 && function_exists('logWarning')) {
            logWarning("Reclaimed $reclaimed stale job(s) stuck in processing");
        }

        return $reclaimed;
    }

    /**
     * Get the next available job from a queue
     */
    public static function pop(string $queue = self::DEFAULT_QUEUE, int $maxRetries = 3): ?array
    {
        $db = self::db();
        if (!$db) {
            return null;
        }

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $now = date('Y-m-d H:i:s');

            // Find and reserve a job atomically
            $stmt = $db->prepare("
                SELECT * FROM jobs
                WHERE queue = :queue
                AND status = :status
                AND available_at <= :now
                ORDER BY available_at ASC
                LIMIT 1
            ");

            $stmt->bindValue(':queue', $queue, PDO::PARAM_STR);
            $stmt->bindValue(':status', self::STATUS_PENDING, PDO::PARAM_STR);
            $stmt->bindValue(':now', $now, PDO::PARAM_STR);

            $result = $stmt->execute();
            $job = $result->fetchArray(PDO::FETCH_ASSOC);

            if (!$job) {
                return null;
            }

            // Reserve the job
            $stmt = $db->prepare("
                UPDATE jobs
                SET status = :status, reserved_at = :reserved_at, attempts = attempts + 1
                WHERE id = :id AND status = :pending_status
            ");

            $stmt->bindValue(':status', self::STATUS_PROCESSING, PDO::PARAM_STR);
            $stmt->bindValue(':reserved_at', $now, PDO::PARAM_STR);
            $stmt->bindValue(':id', $job['id'], PDO::PARAM_INT);
            $stmt->bindValue(':pending_status', self::STATUS_PENDING, PDO::PARAM_STR);

            $stmt->execute();

            // Check if we actually got it (in case of race condition)
            if ($db->changes() > 0) {
                $job['payload'] = json_decode($job['payload'], true);
                return $job;
            }
            // Race condition - another worker got it, retry
        }

        return null;
    }

    /**
     * Mark a job as completed
     */
    public static function complete(int $jobId): bool
    {
        $db = self::db();
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("
            UPDATE jobs
            SET status = :status, completed_at = :completed_at
            WHERE id = :id
        ");

        $stmt->bindValue(':status', self::STATUS_COMPLETED, PDO::PARAM_STR);
        $stmt->bindValue(':completed_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);

        return $stmt->execute() !== false;
    }

    /**
     * Mark a job as failed
     */
    public static function fail(int $jobId, string $error): bool
    {
        $db = self::db();
        if (!$db) {
            return false;
        }

        // Get job to check attempts
        $stmt = $db->prepare("SELECT attempts, max_attempts FROM jobs WHERE id = :id");
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $job = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

        if (!$job) {
            return false;
        }

        // If we haven't exceeded max attempts, put back in queue
        if ($job['attempts'] < $job['max_attempts']) {
            // Exponential backoff: 1 min, 5 min, 25 min...
            $delay = pow(5, $job['attempts']) * 60;
            $availableAt = date('Y-m-d H:i:s', time() + $delay);

            $stmt = $db->prepare("
                UPDATE jobs
                SET status = :status, available_at = :available_at, error_message = :error
                WHERE id = :id
            ");

            $stmt->bindValue(':status', self::STATUS_PENDING, PDO::PARAM_STR);
            $stmt->bindValue(':available_at', $availableAt, PDO::PARAM_STR);
            $stmt->bindValue(':error', $error, PDO::PARAM_STR);
            $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);

            return $stmt->execute() !== false;
        }

        // Max attempts exceeded, mark as permanently failed
        $stmt = $db->prepare("
            UPDATE jobs
            SET status = :status, error_message = :error
            WHERE id = :id
        ");

        $stmt->bindValue(':status', self::STATUS_FAILED, PDO::PARAM_STR);
        $stmt->bindValue(':error', $error, PDO::PARAM_STR);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);

        return $stmt->execute() !== false;
    }

    /**
     * Delete a job
     */
    public static function delete(int $jobId): bool
    {
        $db = self::db();
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("DELETE FROM jobs WHERE id = :id");
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);

        return $stmt->execute() !== false;
    }

    /**
     * Get queue statistics
     */
    public static function stats(?string $queue = null): array
    {
        $db = self::db();
        if (!$db) {
            return [];
        }

        $where = $queue ? "WHERE queue = :queue" : "";

        $sql = "
            SELECT
                queue,
                status,
                COUNT(*) as count
            FROM jobs
            $where
            GROUP BY queue, status
        ";

        $stmt = $db->prepare($sql);
        if ($queue) {
            $stmt->bindValue(':queue', $queue, PDO::PARAM_STR);
        }

        $result = $stmt->execute();
        $stats = [];

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            if (!isset($stats[$row['queue']])) {
                $stats[$row['queue']] = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
            }
            $stats[$row['queue']][$row['status']] = (int)$row['count'];
        }

        return $stats;
    }

    /**
     * Clear completed jobs older than X hours
     */
    public static function prune(int $hours = 24): int
    {
        $db = self::db();
        if (!$db) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));

        $stmt = $db->prepare("
            DELETE FROM jobs
            WHERE status = :status
            AND completed_at < :cutoff
        ");

        $stmt->bindValue(':status', self::STATUS_COMPLETED, PDO::PARAM_STR);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);

        $stmt->execute();
        return $db->changes();
    }

    /**
     * Retry all failed jobs
     */
    public static function retryFailed(string $queue = self::DEFAULT_QUEUE): int
    {
        $db = self::db();
        if (!$db) {
            return 0;
        }

        $stmt = $db->prepare("
            UPDATE jobs
            SET status = :pending, attempts = 0, available_at = :now, error_message = NULL
            WHERE queue = :queue AND status = :failed
        ");

        $stmt->bindValue(':pending', self::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':queue', $queue, PDO::PARAM_STR);
        $stmt->bindValue(':failed', self::STATUS_FAILED, PDO::PARAM_STR);

        $stmt->execute();
        return $db->changes();
    }

    /**
     * Get active (pending + processing) jobs
     */
    public static function active(int $limit = 20): array
    {
        $db = self::db();
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT * FROM jobs
            WHERE status IN (:pending, :processing)
            ORDER BY created_at DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':pending', self::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->bindValue(':processing', self::STATUS_PROCESSING, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $result = $stmt->execute();
        $jobs = [];

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $row['payload'] = json_decode($row['payload'], true);
            $jobs[] = $row;
        }

        return $jobs;
    }

    /**
     * Get count of active (pending + processing) jobs
     */
    public static function activeCount(): int
    {
        $db = self::db();
        if (!$db) {
            return 0;
        }

        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM jobs
            WHERE status IN (:pending, :processing)
        ");

        $stmt->bindValue(':pending', self::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->bindValue(':processing', self::STATUS_PROCESSING, PDO::PARAM_STR);

        $result = $stmt->execute();
        $row = $result->fetchArray(PDO::FETCH_ASSOC);

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Get failed jobs
     */
    public static function failed(int $limit = 50): array
    {
        $db = self::db();
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT * FROM jobs
            WHERE status = :status
            ORDER BY created_at DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':status', self::STATUS_FAILED, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $result = $stmt->execute();
        $jobs = [];

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $row['payload'] = json_decode($row['payload'], true);
            $jobs[] = $row;
        }

        return $jobs;
    }

    /**
     * Process a job (used by worker)
     */
    public static function process(array $job): bool
    {
        $class = $job['job_class'];
        $data = $job['payload'];

        try {
            // Check if job class exists
            if (!class_exists($class)) {
                // Try to load from jobs directory
                $file = dirname(__DIR__) . '/jobs/' . $class . '.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            }

            if (!class_exists($class)) {
                throw new \Exception("Job class not found: $class");
            }

            $instance = new $class();

            if (!method_exists($instance, 'handle')) {
                throw new \Exception("Job class must have a handle() method");
            }

            $instance->handle($data);
            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}

/**
 * Base Job Class
 */
abstract class Job
{
    public int $maxAttempts = 3;
    public int $timeout = 300; // 5 minutes

    abstract public function handle(array $data): void;

    public function failed(array $data, \Throwable $e): void
    {
        // Override in subclass to handle failures
    }
}
