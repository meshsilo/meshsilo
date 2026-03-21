#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line.\n");
}
/**
 * Queue Worker
 *
 * Processes jobs from the background queue.
 *
 * Usage:
 *   php cli/queue-worker.php [options]
 *
 * Options:
 *   --queue=NAME    Queue to process (default: default)
 *   --once          Process one job and exit
 *   --sleep=SEC     Sleep seconds between jobs (default: 3)
 *   --max-jobs=N    Exit after processing N jobs
 *   --max-time=SEC  Exit after running for N seconds
 *   --verbose       Show detailed output
 */

chdir(dirname(__DIR__));

// Wait for the application to be installed before starting
// On first run, there's no config or database yet
$configFile = __DIR__ . '/../storage/db/config.local.php';
$sleepArg = (int)(getopt('', ['sleep:'])['sleep'] ?? 3);
while (!file_exists($configFile)) {
    sleep($sleepArg);
}

require_once 'includes/config.php';
require_once 'includes/Queue.php';

// Parse options
$options = getopt('', ['queue:', 'once', 'sleep:', 'max-jobs:', 'max-time:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Silo Queue Worker

Usage: php cli/queue-worker.php [options]

Options:
  --queue=NAME    Queue to process (default: default)
  --once          Process one job and exit
  --sleep=SEC     Sleep between empty queue checks (default: 3)
  --max-jobs=N    Exit after processing N jobs
  --max-time=SEC  Exit after running for N seconds
  --verbose       Show detailed output
  --help          Show this help

Examples:
  php cli/queue-worker.php --queue=thumbnails
  php cli/queue-worker.php --once --verbose
  php cli/queue-worker.php --max-jobs=100 --max-time=3600

HELP;
    exit(0);
}

$queue = $options['queue'] ?? 'default';
$once = isset($options['once']);
$sleepTime = (int)($options['sleep'] ?? 3);
$maxJobs = isset($options['max-jobs']) ? (int)$options['max-jobs'] : null;
$maxTime = isset($options['max-time']) ? (int)$options['max-time'] : null;
$verbose = isset($options['verbose']);

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

$startTime = time();
$jobsProcessed = 0;

echo "Queue Worker Started\n";
echo "Queue: $queue\n";
echo "PID: " . getmypid() . "\n";
echo "---\n";

// Handle signals for graceful shutdown
$shouldStop = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$shouldStop) {
        echo "\nReceived SIGTERM, finishing current job...\n";
        $shouldStop = true;
    });
    pcntl_signal(SIGINT, function() use (&$shouldStop) {
        echo "\nReceived SIGINT, finishing current job...\n";
        $shouldStop = true;
    });
}

$lastReclaimCheck = 0;

while (!$shouldStop) {
    // Check time limit
    if ($maxTime !== null && (time() - $startTime) >= $maxTime) {
        echo "Max time reached, stopping.\n";
        break;
    }

    // Check job limit
    if ($maxJobs !== null && $jobsProcessed >= $maxJobs) {
        echo "Max jobs reached, stopping.\n";
        break;
    }

    // Process signals
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // Periodically reclaim jobs stuck in "processing" (every 5 minutes)
    // Handles worker crashes where a job was reserved but never completed
    if (time() - $lastReclaimCheck > 300) {
        Queue::reclaimStale(900); // 15 min timeout
        $lastReclaimCheck = time();
    }

    // Get next job
    $job = Queue::pop($queue);

    if ($job === null) {
        if ($once) {
            echo "No jobs available.\n";
            break;
        }

        if ($verbose) {
            echo ".";
        }
        sleep($sleepTime);
        continue;
    }

    $jobsProcessed++;
    $jobId = $job['id'];
    $jobClass = $job['job_class'];

    echo sprintf(
        "[%s] Processing job #%d: %s (attempt %d/%d)\n",
        date('H:i:s'),
        $jobId,
        $jobClass,
        $job['attempts'],
        $job['max_attempts']
    );

    $startJobTime = microtime(true);

    try {
        Queue::process($job);

        $duration = round((microtime(true) - $startJobTime) * 1000);
        echo sprintf("  ✓ Completed in %dms\n", $duration);

        Queue::complete($jobId);

    } catch (\Throwable $e) {
        $duration = round((microtime(true) - $startJobTime) * 1000);
        echo sprintf("  ✗ Failed after %dms: %s\n", $duration, $e->getMessage());

        if ($verbose) {
            echo "    " . $e->getFile() . ":" . $e->getLine() . "\n";
        }

        Queue::fail($jobId, $e->getMessage());

        // Call job's failed method if it exists
        try {
            $class = $job['job_class'];
            if (class_exists($class)) {
                $instance = new $class();
                if (method_exists($instance, 'failed')) {
                    $instance->failed($job['payload'], $e);
                }
            }
        } catch (\Throwable $e2) {
            // Ignore errors in failed handler
        }
    }

    // Memory cleanup after every job — conversions are memory-heavy
    gc_collect_cycles();
    if ($jobsProcessed % 10 === 0 && class_exists('Cache', false)) {
        Cache::getInstance()->clearMemory();
    }

    // Safety valve: exit at 80% of PHP memory limit (supervisor will restart)
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

echo "\n---\n";
echo "Jobs processed: $jobsProcessed\n";
echo "Runtime: " . (time() - $startTime) . " seconds\n";
echo "Worker stopped.\n";
