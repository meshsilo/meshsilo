<?php
/**
 * Query Analyzer
 *
 * Profiles database queries to find slow queries and N+1 problems.
 * Features:
 * - Logs all queries with execution time
 * - Detects N+1 query patterns
 * - Identifies slow queries
 * - Provides query statistics
 *
 * Enable with: QueryAnalyzer::enable();
 */

class QueryAnalyzer {
    private static ?self $instance = null;
    private bool $enabled = false;
    private array $queries = [];
    private float $slowThreshold = 0.1; // 100ms
    private int $n1Threshold = 5; // queries with same pattern
    private string $logFile;

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logFile = dirname(__DIR__) . '/storage/logs/queries.log';
    }

    /**
     * Enable query analysis
     */
    public static function enable(): void {
        self::getInstance()->enabled = true;
    }

    /**
     * Disable query analysis
     */
    public static function disable(): void {
        self::getInstance()->enabled = false;
    }

    /**
     * Check if enabled
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Log a query
     */
    public function logQuery(string $sql, array $params, float $duration, ?string $location = null): void {
        if (!$this->enabled) return;

        $normalizedSql = $this->normalizeSql($sql);

        $this->queries[] = [
            'sql' => $sql,
            'normalized' => $normalizedSql,
            'params' => $params,
            'duration' => $duration,
            'location' => $location ?? $this->getCallerLocation(),
            'timestamp' => microtime(true),
            'is_slow' => $duration >= $this->slowThreshold,
        ];
    }

    /**
     * Get all logged queries
     */
    public function getQueries(): array {
        return $this->queries;
    }

    /**
     * Get query statistics
     */
    public function getStats(): array {
        $totalQueries = count($this->queries);
        $totalTime = array_sum(array_column($this->queries, 'duration'));
        $slowQueries = array_filter($this->queries, fn($q) => $q['is_slow']);

        // Group by normalized SQL to find duplicates
        $patterns = [];
        foreach ($this->queries as $query) {
            $pattern = $query['normalized'];
            if (!isset($patterns[$pattern])) {
                $patterns[$pattern] = [
                    'count' => 0,
                    'total_time' => 0,
                    'example' => $query['sql'],
                ];
            }
            $patterns[$pattern]['count']++;
            $patterns[$pattern]['total_time'] += $query['duration'];
        }

        // Find N+1 patterns
        $n1Patterns = array_filter($patterns, fn($p) => $p['count'] >= $this->n1Threshold);

        // Sort patterns by total time
        uasort($patterns, fn($a, $b) => $b['total_time'] <=> $a['total_time']);

        return [
            'total_queries' => $totalQueries,
            'total_time' => round($totalTime * 1000, 2) . 'ms',
            'average_time' => $totalQueries > 0 ? round(($totalTime / $totalQueries) * 1000, 2) . 'ms' : '0ms',
            'slow_queries' => count($slowQueries),
            'n1_patterns' => count($n1Patterns),
            'unique_patterns' => count($patterns),
            'top_patterns' => array_slice($patterns, 0, 10, true),
            'n1_issues' => $n1Patterns,
        ];
    }

    /**
     * Get slow queries
     */
    public function getSlowQueries(): array {
        $slow = array_filter($this->queries, fn($q) => $q['is_slow']);
        usort($slow, fn($a, $b) => $b['duration'] <=> $a['duration']);
        return $slow;
    }

    /**
     * Detect N+1 query patterns
     */
    public function detectN1(): array {
        $patterns = [];
        foreach ($this->queries as $query) {
            $pattern = $query['normalized'];
            if (!isset($patterns[$pattern])) {
                $patterns[$pattern] = [
                    'count' => 0,
                    'locations' => [],
                    'example' => $query['sql'],
                ];
            }
            $patterns[$pattern]['count']++;
            $patterns[$pattern]['locations'][] = $query['location'];
        }

        return array_filter($patterns, fn($p) => $p['count'] >= $this->n1Threshold);
    }

    /**
     * Export queries to log file
     */
    public function exportToLog(): void {
        $stats = $this->getStats();
        $output = [];

        $output[] = "=== Query Analysis Report ===";
        $output[] = "Generated: " . date('Y-m-d H:i:s');
        $output[] = "Total queries: " . $stats['total_queries'];
        $output[] = "Total time: " . $stats['total_time'];
        $output[] = "Slow queries: " . $stats['slow_queries'];
        $output[] = "N+1 patterns: " . $stats['n1_patterns'];
        $output[] = "";

        if (!empty($stats['n1_issues'])) {
            $output[] = "=== N+1 Query Issues ===";
            foreach ($stats['n1_issues'] as $pattern => $data) {
                $output[] = "Pattern ({$data['count']}x, " . round($data['total_time'] * 1000, 2) . "ms total):";
                $output[] = "  " . $data['example'];
                $output[] = "";
            }
        }

        $slowQueries = $this->getSlowQueries();
        if (!empty($slowQueries)) {
            $output[] = "=== Slow Queries ===";
            foreach (array_slice($slowQueries, 0, 20) as $query) {
                $output[] = round($query['duration'] * 1000, 2) . "ms: " . $query['sql'];
                $output[] = "  Location: " . $query['location'];
                $output[] = "";
            }
        }

        file_put_contents($this->logFile, implode("\n", $output), FILE_APPEND);
    }

    /**
     * Get a summary for the current request
     */
    public function getSummary(): string {
        $stats = $this->getStats();
        $summary = "Queries: {$stats['total_queries']} ({$stats['total_time']})";

        if ($stats['slow_queries'] > 0) {
            $summary .= " | Slow: {$stats['slow_queries']}";
        }

        if ($stats['n1_patterns'] > 0) {
            $summary .= " | N+1: {$stats['n1_patterns']}";
        }

        return $summary;
    }

    /**
     * Normalize SQL for pattern matching
     */
    private function normalizeSql(string $sql): string {
        // Remove extra whitespace
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // Replace numeric values
        $sql = preg_replace('/\b\d+\b/', '?', $sql);

        // Replace string values
        $sql = preg_replace("/'[^']*'/", '?', $sql);
        $sql = preg_replace('/"[^"]*"/', '?', $sql);

        // Replace IN lists
        $sql = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', $sql);

        return $sql;
    }

    /**
     * Get caller location
     */
    private function getCallerLocation(): string {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (strpos($file, 'QueryAnalyzer') === false &&
                strpos($file, 'db.php') === false) {
                return basename($file) . ':' . ($frame['line'] ?? 0);
            }
        }

        return 'unknown';
    }

    /**
     * Set slow query threshold
     */
    public function setSlowThreshold(float $seconds): self {
        $this->slowThreshold = $seconds;
        return $this;
    }

    /**
     * Set N+1 detection threshold
     */
    public function setN1Threshold(int $count): self {
        $this->n1Threshold = $count;
        return $this;
    }

    /**
     * Clear logged queries
     */
    public function clear(): void {
        $this->queries = [];
    }

    /**
     * Render debug bar HTML
     */
    public function renderDebugBar(): string {
        if (!$this->enabled || empty($this->queries)) {
            return '';
        }

        $stats = $this->getStats();
        $slowQueries = $this->getSlowQueries();

        $html = '<div id="query-debug-bar" style="position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#fff;font-family:monospace;font-size:12px;z-index:99999;max-height:300px;overflow:auto;">';
        $html .= '<div style="padding:8px 16px;background:#2a2a3e;cursor:pointer;" onclick="this.parentElement.classList.toggle(\'collapsed\')">';
        $html .= '<strong>Query Analyzer</strong> | ' . $this->getSummary();
        $html .= '</div>';

        $html .= '<div class="query-debug-content" style="padding:16px;">';

        if (!empty($stats['n1_issues'])) {
            $html .= '<div style="color:#f87171;margin-bottom:16px;"><strong>N+1 Issues Detected!</strong></div>';
            foreach ($stats['n1_issues'] as $pattern => $data) {
                $html .= '<div style="background:#2a2a3e;padding:8px;margin-bottom:8px;border-left:3px solid #f87171;">';
                $html .= '<div style="color:#f87171;">Executed ' . $data['count'] . 'x (' . round($data['total_time'] * 1000, 2) . 'ms)</div>';
                $html .= '<code style="color:#a5b4fc;">' . htmlspecialchars($data['example']) . '</code>';
                $html .= '</div>';
            }
        }

        if (!empty($slowQueries)) {
            $html .= '<div style="color:#fbbf24;margin:16px 0 8px;"><strong>Slow Queries</strong></div>';
            foreach (array_slice($slowQueries, 0, 5) as $query) {
                $html .= '<div style="background:#2a2a3e;padding:8px;margin-bottom:4px;">';
                $html .= '<span style="color:#fbbf24;">' . round($query['duration'] * 1000, 2) . 'ms</span> ';
                $html .= '<code style="color:#a5b4fc;">' . htmlspecialchars(substr($query['sql'], 0, 100)) . '</code>';
                $html .= '</div>';
            }
        }

        $html .= '</div></div>';
        $html .= '<style>#query-debug-bar.collapsed .query-debug-content{display:none;}</style>';

        return $html;
    }
}

/**
 * Wrap PDO to log queries
 */
class ProfiledPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ProfiledPDOStatement(parent::prepare($query, $options), $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $start = microtime(true);
        $result = parent::query($query, $fetchMode, ...$fetchModeArgs);
        $duration = microtime(true) - $start;

        QueryAnalyzer::getInstance()->logQuery($query, [], $duration);

        return $result;
    }

    public function exec(string $statement): int|false {
        $start = microtime(true);
        $result = parent::exec($statement);
        $duration = microtime(true) - $start;

        QueryAnalyzer::getInstance()->logQuery($statement, [], $duration);

        return $result;
    }
}

class ProfiledPDOStatement extends PDOStatement {
    private PDOStatement $stmt;
    private string $query;

    public function __construct(PDOStatement $stmt, string $query) {
        $this->stmt = $stmt;
        $this->query = $query;
    }

    public function execute(?array $params = null): bool {
        $start = microtime(true);
        $result = $this->stmt->execute($params);
        $duration = microtime(true) - $start;

        QueryAnalyzer::getInstance()->logQuery($this->query, $params ?? [], $duration);

        return $result;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->stmt->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        return $this->stmt->fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed {
        return $this->stmt->fetchColumn($column);
    }

    public function rowCount(): int {
        return $this->stmt->rowCount();
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        return $this->stmt->bindValue($param, $value, $type);
    }

    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool {
        return $this->stmt->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }
}
