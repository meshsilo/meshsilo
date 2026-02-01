<?php
/**
 * Query Batcher
 *
 * Collects and batches database queries to reduce round trips.
 * Supports deferred loading patterns for N+1 query prevention.
 *
 * Usage:
 *   $batcher = QueryBatcher::getInstance();
 *
 *   // Register queries to be batched
 *   $batcher->batch('users', 'SELECT * FROM users WHERE id IN (:ids)', [1, 2, 3]);
 *   $batcher->batch('models', 'SELECT * FROM models WHERE user_id IN (:ids)', [1, 2, 3]);
 *
 *   // Execute all batched queries at once
 *   $results = $batcher->execute();
 */

class QueryBatcher {
    private static ?QueryBatcher $instance = null;
    private $db;
    private array $batches = [];
    private array $results = [];
    private bool $autoExecute = false;

    private function __construct($db = null) {
        $this->db = $db ?? (function_exists('getDB') ? getDB() : null);
    }

    public static function getInstance($db = null): self {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    /**
     * Add a query to the batch
     *
     * @param string $key Unique identifier for this query result
     * @param string $table Table name for simple lookups
     * @param array $ids Array of IDs to fetch
     * @param string $idColumn Column name for the ID (default: 'id')
     * @return self
     */
    public function batch(string $key, string $table, array $ids, string $idColumn = 'id'): self {
        if (empty($ids)) {
            return $this;
        }

        // Merge with existing batch if present
        if (isset($this->batches[$key])) {
            $this->batches[$key]['ids'] = array_unique(array_merge(
                $this->batches[$key]['ids'],
                $ids
            ));
        } else {
            $this->batches[$key] = [
                'table' => $table,
                'ids' => array_unique($ids),
                'id_column' => $idColumn
            ];
        }

        return $this;
    }

    /**
     * Add a raw SQL query to the batch
     */
    public function batchRaw(string $key, string $sql, array $bindings = []): self {
        $this->batches[$key] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'raw' => true
        ];
        return $this;
    }

    /**
     * Execute all batched queries
     */
    public function execute(): array {
        if (empty($this->db)) {
            throw new RuntimeException('Database connection not available');
        }

        foreach ($this->batches as $key => $batch) {
            if (isset($batch['raw']) && $batch['raw']) {
                // Execute raw SQL
                $this->results[$key] = $this->executeRaw($batch['sql'], $batch['bindings']);
            } else {
                // Execute batch lookup
                $this->results[$key] = $this->executeBatch(
                    $batch['table'],
                    $batch['ids'],
                    $batch['id_column']
                );
            }
        }

        // Clear batches after execution
        $this->batches = [];

        return $this->results;
    }

    /**
     * Execute a batch lookup query
     */
    private function executeBatch(string $table, array $ids, string $idColumn): array {
        if (empty($ids)) {
            return [];
        }

        // Build IN clause with placeholders
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM {$table} WHERE {$idColumn} IN ({$placeholders})";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($ids));

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row[$idColumn]] = $row;
        }

        return $results;
    }

    /**
     * Execute a raw SQL query
     */
    private function executeRaw(string $sql, array $bindings): array {
        $stmt = $this->db->prepare($sql);

        foreach ($bindings as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value, $type);
            } else {
                $stmt->bindValue($key, $value, $type);
            }
        }

        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Get results for a specific batch key
     */
    public function get(string $key): ?array {
        // Auto-execute if not yet executed
        if (!isset($this->results[$key]) && isset($this->batches[$key])) {
            $this->execute();
        }

        return $this->results[$key] ?? null;
    }

    /**
     * Get a single item from batch results
     */
    public function getOne(string $key, $id) {
        $results = $this->get($key);
        return $results[$id] ?? null;
    }

    /**
     * Check if batch has pending queries
     */
    public function hasPending(): bool {
        return !empty($this->batches);
    }

    /**
     * Clear all batches and results
     */
    public function clear(): void {
        $this->batches = [];
        $this->results = [];
    }

    /**
     * Get batch statistics
     */
    public function stats(): array {
        return [
            'pending_batches' => count($this->batches),
            'cached_results' => count($this->results),
            'total_ids' => array_sum(array_map(function($batch) {
                return isset($batch['ids']) ? count($batch['ids']) : 0;
            }, $this->batches))
        ];
    }
}

/**
 * Deferred Loader for N+1 prevention
 *
 * Collects lookups during iteration and executes them in one batch.
 */
class DeferredLoader {
    private QueryBatcher $batcher;
    private string $table;
    private string $idColumn;
    private string $key;
    private array $pendingIds = [];
    private bool $executed = false;

    public function __construct(string $table, string $idColumn = 'id') {
        $this->batcher = QueryBatcher::getInstance();
        $this->table = $table;
        $this->idColumn = $idColumn;
        $this->key = 'deferred_' . $table . '_' . uniqid();
    }

    /**
     * Register an ID to be loaded
     */
    public function defer($id): self {
        $this->pendingIds[] = $id;
        return $this;
    }

    /**
     * Register multiple IDs to be loaded
     */
    public function deferMany(array $ids): self {
        $this->pendingIds = array_merge($this->pendingIds, $ids);
        return $this;
    }

    /**
     * Load all deferred items and return results
     */
    public function load(): array {
        if (!$this->executed && !empty($this->pendingIds)) {
            $this->batcher->batch($this->key, $this->table, $this->pendingIds, $this->idColumn);
            $this->batcher->execute();
            $this->executed = true;
        }

        return $this->batcher->get($this->key) ?? [];
    }

    /**
     * Get a single loaded item
     */
    public function get($id) {
        $results = $this->load();
        return $results[$id] ?? null;
    }
}

/**
 * Helper function to create a deferred loader
 */
function deferLoad(string $table, string $idColumn = 'id'): DeferredLoader {
    return new DeferredLoader($table, $idColumn);
}
