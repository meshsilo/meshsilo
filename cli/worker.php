<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line.\n");
}
/**
 * Job Queue Worker
 *
 * Processes background jobs from the queue.
 *
 * Usage:
 *   php cli/worker.php                    Process jobs from default queue
 *   php cli/worker.php --queue=thumbnails Process jobs from specific queue
 *   php cli/worker.php --once             Process one job and exit
 *   php cli/worker.php --stats            Show queue statistics
 *   php cli/worker.php --retry            Retry failed jobs
 *   php cli/worker.php --clear            Clear failed jobs
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/JobQueue.php';

// Parse command line options
$options = getopt('', ['queue:', 'once', 'stats', 'retry', 'clear', 'help', 'timeout:']);

$queue = $options['queue'] ?? 'default';
$once = isset($options['once']);
$showStats = isset($options['stats']);
$retry = isset($options['retry']);
$clear = isset($options['clear']);
$timeout = (int)($options['timeout'] ?? 300);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$jobQueue = JobQueue::getInstance();

// Handle special commands
if ($showStats) {
    showQueueStats($jobQueue, $queue);
    exit(0);
}

if ($retry) {
    $count = $jobQueue->retryFailed($queue);
    echo "Retried $count failed jobs\n";
    exit(0);
}

if ($clear) {
    $count = $jobQueue->clearFailed($queue);
    echo "Cleared $count failed jobs\n";
    exit(0);
}

// Start worker
echo "Starting worker for queue: $queue\n";
echo "Press Ctrl+C to stop\n\n";

function getMemoryLimitBytes(): int
{
    $limit = ini_get('memory_limit');
    if ($limit === '-1') {
        return 2 * 1024 * 1024 * 1024;
    }
    $value = (int)$limit;
    $unit = strtolower(substr(trim($limit), -1));
    return match ($unit) {
        'g' => $value * 1024 * 1024 * 1024,
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
}

$processed = 0;
$failed = 0;
$startTime = time();

// Handle shutdown
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function() use (&$running) {
        echo "\nReceived SIGTERM, shutting down...\n";
        $running = false;
    });
    pcntl_signal(SIGINT, function() use (&$running) {
        echo "\nReceived SIGINT, shutting down...\n";
        $running = false;
    });
}

while ($running) {
    // Release stale jobs
    $jobQueue->releaseStale($timeout);

    // Get next job
    $job = $jobQueue->pop($queue);

    if (!$job) {
        if ($once) {
            echo "No jobs available\n";
            break;
        }
        // Sleep and retry
        sleep(1);
        continue;
    }

    $jobId = $job['id'];
    $payload = $job['payload'];
    $jobClass = $payload['class'] ?? 'Unknown';
    $jobData = $payload['data'] ?? [];

    echo "[" . date('H:i:s') . "] Processing job #$jobId: $jobClass\n";

    try {
        // Create and execute job
        if (class_exists($jobClass)) {
            $jobInstance = new $jobClass($jobData);
            $jobInstance->handle();

            $jobQueue->complete($jobId);
            $processed++;
            echo "[" . date('H:i:s') . "] ✓ Completed job #$jobId\n";
        } else {
            throw new Exception("Job class not found: $jobClass");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        $jobQueue->fail($jobId, $error);
        $failed++;
        echo "[" . date('H:i:s') . "] ✗ Failed job #$jobId: $error\n";

        // Call failed handler if available
        if (isset($jobInstance) && method_exists($jobInstance, 'failed')) {
            try {
                $jobInstance->failed($e);
            } catch (Exception $e2) {
                // Ignore errors in failed handler
            }
        }
    }

    // Periodic memory cleanup
    if ($processed % 10 === 0) {
        if (class_exists('Cache', false)) {
            Cache::getInstance()->clearMemory();
        }
        gc_collect_cycles();
    }

    // Safety valve: exit at 80% of PHP memory limit
    $memLimit = getMemoryLimitBytes();
    if (memory_get_usage(true) > $memLimit * 0.8) {
        echo sprintf("Memory usage %dMB exceeds 80%% of %dMB limit, restarting.\n",
            round(memory_get_usage(true) / 1024 / 1024),
            round($memLimit / 1024 / 1024)
        );
        break;
    }

    if ($once) {
        break;
    }
}

// Show summary
$duration = time() - $startTime;
echo "\n=== Worker Summary ===\n";
echo "Duration: " . formatDuration($duration) . "\n";
echo "Processed: $processed jobs\n";
echo "Failed: $failed jobs\n";
echo "Rate: " . ($duration > 0 ? round($processed / $duration * 60, 2) : 0) . " jobs/minute\n";

function showQueueStats(JobQueue $queue, string $queueName): void {
    $stats = $queue->stats($queueName);

    echo "=== Queue: $queueName ===\n";
    echo "Pending: " . $stats['pending'] . "\n";
    echo "Reserved: " . $stats['reserved'] . "\n";
    echo "Failed: " . $stats['failed'] . "\n";
    echo "Total: " . ($stats['pending'] + $stats['reserved'] + $stats['failed']) . "\n";
}

function formatDuration(int $seconds): string {
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    }
    return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
}

function showHelp(): void {
    echo "MeshSilo Job Queue Worker\n\n";
    echo "Usage: php cli/worker.php [options]\n\n";
    echo "Options:\n";
    echo "  --queue=NAME    Process jobs from specific queue (default: default)\n";
    echo "  --once          Process one job and exit\n";
    echo "  --stats         Show queue statistics\n";
    echo "  --retry         Retry all failed jobs\n";
    echo "  --clear         Clear all failed jobs\n";
    echo "  --timeout=SEC   Stale job timeout in seconds (default: 300)\n";
    echo "  --help          Show this help message\n";
}
