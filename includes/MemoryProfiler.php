<?php

/**
 * Memory Profiler
 *
 * Tracks memory usage throughout request lifecycle.
 * Features:
 * - Peak memory tracking
 * - Memory snapshots at key points
 * - Leak detection across requests
 * - Memory allocation breakdown
 *
 * Enable with: MemoryProfiler::enable();
 */

class MemoryProfiler
{
    private static ?self $instance = null;
    private bool $enabled = false;
    private array $snapshots = [];
    private int $startMemory;
    private float $startTime;
    private string $logFile;
    private int $warningThreshold = 50 * 1024 * 1024; // 50MB
    private int $criticalThreshold = 100 * 1024 * 1024; // 100MB

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->startMemory = memory_get_usage(true);
        $this->startTime = microtime(true);
        $this->logFile = dirname(__DIR__) . '/storage/logs/memory.log';

        // Take initial snapshot
        $this->snapshot('init');
    }

    /**
     * Enable memory profiling
     */
    public static function enable(): void
    {
        self::getInstance()->enabled = true;
    }

    /**
     * Disable memory profiling
     */
    public static function disable(): void
    {
        self::getInstance()->enabled = false;
    }

    /**
     * Take a memory snapshot
     */
    public function snapshot(string $label): void
    {
        if (!$this->enabled && $label !== 'init') {
            return;
        }

        $this->snapshots[] = [
            'label' => $label,
            'timestamp' => microtime(true) - $this->startTime,
            'memory' => memory_get_usage(true),
            'memory_real' => memory_get_usage(false),
            'peak' => memory_get_peak_usage(true),
            'peak_real' => memory_get_peak_usage(false),
            'location' => $this->getCallerLocation(),
        ];
    }

    /**
     * Get all snapshots
     */
    public function getSnapshots(): array
    {
        return $this->snapshots;
    }

    /**
     * Get memory statistics
     */
    public function getStats(): array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = $this->getMemoryLimit();

        // Calculate memory used since start
        $memoryUsed = $currentMemory - $this->startMemory;

        // Find largest growth between snapshots
        $largestGrowth = 0;
        $largestGrowthLabel = '';
        $prevMemory = $this->startMemory;

        foreach ($this->snapshots as $snapshot) {
            $growth = $snapshot['memory'] - $prevMemory;
            if ($growth > $largestGrowth) {
                $largestGrowth = $growth;
                $largestGrowthLabel = $snapshot['label'];
            }
            $prevMemory = $snapshot['memory'];
        }

        return [
            'start_memory' => formatBytes($this->startMemory),
            'current_memory' => formatBytes($currentMemory),
            'peak_memory' => formatBytes($peakMemory),
            'memory_used' => formatBytes($memoryUsed),
            'memory_limit' => formatBytes($memoryLimit),
            'usage_percent' => round(($peakMemory / $memoryLimit) * 100, 1),
            'snapshots' => count($this->snapshots),
            'largest_growth' => formatBytes($largestGrowth),
            'largest_growth_at' => $largestGrowthLabel,
            'status' => $this->getMemoryStatus($peakMemory),
        ];
    }

    /**
     * Get memory status
     */
    private function getMemoryStatus(int $memory): string
    {
        if ($memory >= $this->criticalThreshold) {
            return 'critical';
        }
        if ($memory >= $this->warningThreshold) {
            return 'warning';
        }
        return 'ok';
    }

    /**
     * Get memory limit in bytes
     */
    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int)$limit;

        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Analyze memory growth
     */
    public function analyzeGrowth(): array
    {
        $growth = [];

        for ($i = 1; $i < count($this->snapshots); $i++) {
            $prev = $this->snapshots[$i - 1];
            $curr = $this->snapshots[$i];

            $memGrowth = $curr['memory'] - $prev['memory'];
            $timeGrowth = $curr['timestamp'] - $prev['timestamp'];

            $growth[] = [
                'from' => $prev['label'],
                'to' => $curr['label'],
                'memory_change' => $memGrowth,
                'memory_change_formatted' => ($memGrowth >= 0 ? '+' : '') . formatBytes($memGrowth),
                'duration' => round($timeGrowth * 1000, 2) . 'ms',
                'location' => $curr['location'],
            ];
        }

        // Sort by memory growth
        usort($growth, fn($a, $b) => $b['memory_change'] <=> $a['memory_change']);

        return $growth;
    }

    /**
     * Check for potential memory leaks
     */
    public function checkForLeaks(): array
    {
        $issues = [];

        // Check if memory grew significantly
        if (count($this->snapshots) >= 2) {
            $first = $this->snapshots[0];
            $last = end($this->snapshots);

            $growth = $last['memory'] - $first['memory'];
            $growthPercent = ($growth / $first['memory']) * 100;

            if ($growthPercent > 50) {
                $issues[] = [
                    'type' => 'high_growth',
                    'message' => "Memory grew by {$growthPercent}% during request",
                    'growth' => formatBytes($growth),
                ];
            }
        }

        // Check if peak is close to limit
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = $this->getMemoryLimit();
        $usagePercent = ($peakMemory / $memoryLimit) * 100;

        if ($usagePercent > 80) {
            $issues[] = [
                'type' => 'near_limit',
                'message' => "Peak memory usage at {$usagePercent}% of limit",
                'peak' => formatBytes($peakMemory),
                'limit' => formatBytes($memoryLimit),
            ];
        }

        return $issues;
    }

    /**
     * Export to log file
     */
    public function exportToLog(): void
    {
        $stats = $this->getStats();
        $growth = $this->analyzeGrowth();
        $issues = $this->checkForLeaks();

        $output = [];
        $output[] = "=== Memory Profile Report ===";
        $output[] = "Generated: " . date('Y-m-d H:i:s');
        $output[] = "Request: " . ($_SERVER['REQUEST_URI'] ?? 'CLI');
        $output[] = "";
        $output[] = "Start memory: " . $stats['start_memory'];
        $output[] = "Peak memory: " . $stats['peak_memory'];
        $output[] = "Memory used: " . $stats['memory_used'];
        $output[] = "Memory limit: " . $stats['memory_limit'];
        $output[] = "Usage: " . $stats['usage_percent'] . "%";
        $output[] = "";

        if (!empty($issues)) {
            $output[] = "=== Issues ===";
            foreach ($issues as $issue) {
                $output[] = "[{$issue['type']}] {$issue['message']}";
            }
            $output[] = "";
        }

        $output[] = "=== Memory Growth ===";
        foreach (array_slice($growth, 0, 10) as $g) {
            $output[] = "{$g['from']} -> {$g['to']}: {$g['memory_change_formatted']} ({$g['duration']})";
        }

        $output[] = "";
        $output[] = str_repeat('-', 50);
        $output[] = "";

        file_put_contents($this->logFile, implode("\n", $output), FILE_APPEND);
    }

    /**
     * Get summary for debug output
     */
    public function getSummary(): string
    {
        $stats = $this->getStats();
        $status = $stats['status'];

        $statusIcon = match ($status) {
            'critical' => '🔴',
            'warning' => '🟡',
            default => '🟢',
        };

        return "{$statusIcon} Memory: {$stats['current_memory']} (peak: {$stats['peak_memory']}, {$stats['usage_percent']}%)";
    }

    /**
     * Render debug bar HTML
     */
    public function renderDebugBar(): string
    {
        if (!$this->enabled) {
            return '';
        }

        $stats = $this->getStats();
        $growth = $this->analyzeGrowth();
        $issues = $this->checkForLeaks();

        $statusColor = match ($stats['status']) {
            'critical' => '#f87171',
            'warning' => '#fbbf24',
            default => '#4ade80',
        };

        $html = '<div id="memory-debug-bar" style="position:fixed;bottom:0;right:0;background:#1a1a2e;color:#fff;font-family:monospace;font-size:12px;z-index:99998;max-width:400px;max-height:300px;overflow:auto;border-left:3px solid ' . $statusColor . ';">';
        $html .= '<div style="padding:8px 16px;background:#2a2a3e;cursor:pointer;" onclick="this.parentElement.classList.toggle(\'collapsed\')">';
        $html .= '<strong>Memory</strong> | ' . $stats['peak_memory'] . ' peak (' . $stats['usage_percent'] . '%)';
        $html .= '</div>';

        $html .= '<div class="memory-debug-content" style="padding:16px;">';

        // Stats
        $html .= '<div style="margin-bottom:12px;">';
        $html .= '<div>Start: ' . $stats['start_memory'] . '</div>';
        $html .= '<div>Current: ' . $stats['current_memory'] . '</div>';
        $html .= '<div>Peak: ' . $stats['peak_memory'] . '</div>';
        $html .= '<div>Limit: ' . $stats['memory_limit'] . '</div>';
        $html .= '</div>';

        // Issues
        if (!empty($issues)) {
            $html .= '<div style="color:#f87171;margin-bottom:12px;">';
            foreach ($issues as $issue) {
                $html .= '<div>⚠ ' . htmlspecialchars($issue['message']) . '</div>';
            }
            $html .= '</div>';
        }

        // Top growth
        $html .= '<div style="color:#a5b4fc;"><strong>Top Memory Growth:</strong></div>';
        foreach (array_slice($growth, 0, 5) as $g) {
            if ($g['memory_change'] > 0) {
                $html .= '<div>' . $g['to'] . ': ' . $g['memory_change_formatted'] . '</div>';
            }
        }

        $html .= '</div></div>';
        $html .= '<style>#memory-debug-bar.collapsed .memory-debug-content{display:none;}</style>';

        return $html;
    }

    private function getCallerLocation(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (strpos($file, 'MemoryProfiler') === false) {
                return basename($file) . ':' . ($frame['line'] ?? 0);
            }
        }

        return 'unknown';
    }
}

// Helper functions
function memory_snapshot(string $label): void
{
    MemoryProfiler::getInstance()->snapshot($label);
}

function memory_stats(): array
{
    return MemoryProfiler::getInstance()->getStats();
}
