<?php
/**
 * SQL Query Builder
 *
 * Provides a fluent interface for building SQL queries with
 * automatic parameter binding to prevent SQL injection.
 * Supports both SQLite and MySQL.
 */

class QueryBuilder {
    private $db;
    private string $table = '';
    private array $select = ['*'];
    private array $joins = [];
    private array $wheres = [];
    private array $orWheres = [];
    private array $bindings = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $distinct = false;

    /**
     * Create a new query builder instance
     */
    public function __construct($db = null) {
        $this->db = $db ?? (function_exists('getDB') ? getDB() : null);
    }

    /**
     * Static factory
     */
    public static function table(string $table): self {
        $builder = new self();
        $builder->table = $table;
        return $builder;
    }

    /**
     * Set the table
     */
    public function from(string $table): self {
        $this->table = $table;
        return $this;
    }

    /**
     * Select columns
     */
    public function select(...$columns): self {
        $this->select = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                $this->select = array_merge($this->select, $col);
            } else {
                $this->select[] = $col;
            }
        }
        return $this;
    }

    /**
     * Add columns to select
     */
    public function addSelect(...$columns): self {
        foreach ($columns as $col) {
            if (is_array($col)) {
                $this->select = array_merge($this->select, $col);
            } else {
                $this->select[] = $col;
            }
        }
        return $this;
    }

    /**
     * Select distinct
     */
    public function distinct(): self {
        $this->distinct = true;
        return $this;
    }

    /**
     * Add a where clause
     */
    public function where($column, $operator = null, $value = null): self {
        // Handle array of conditions
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->where($key, '=', $val);
            }
            return $this;
        }

        // Handle callable (nested where)
        if (is_callable($column)) {
            $nested = new self($this->db);
            $column($nested);
            if (!empty($nested->wheres)) {
                $this->wheres[] = ['type' => 'nested', 'query' => $nested];
            }
            return $this;
        }

        // Handle two-argument form: where('column', 'value')
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => strtoupper($operator),
            'value' => $value
        ];

        return $this;
    }

    /**
     * Add an OR where clause
     */
    public function orWhere($column, $operator = null, $value = null): self {
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        $this->orWheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => strtoupper($operator),
            'value' => $value
        ];

        return $this;
    }

    /**
     * Where column is NULL
     */
    public function whereNull(string $column): self {
        $this->wheres[] = ['type' => 'null', 'column' => $column];
        return $this;
    }

    /**
     * Where column is NOT NULL
     */
    public function whereNotNull(string $column): self {
        $this->wheres[] = ['type' => 'notNull', 'column' => $column];
        return $this;
    }

    /**
     * Where column IN array
     */
    public function whereIn(string $column, array $values): self {
        $this->wheres[] = ['type' => 'in', 'column' => $column, 'values' => $values];
        return $this;
    }

    /**
     * Where column NOT IN array
     */
    public function whereNotIn(string $column, array $values): self {
        $this->wheres[] = ['type' => 'notIn', 'column' => $column, 'values' => $values];
        return $this;
    }

    /**
     * Where column BETWEEN values
     */
    public function whereBetween(string $column, $min, $max): self {
        $this->wheres[] = ['type' => 'between', 'column' => $column, 'min' => $min, 'max' => $max];
        return $this;
    }

    /**
     * Where column LIKE pattern
     */
    public function whereLike(string $column, string $pattern): self {
        $this->wheres[] = ['type' => 'basic', 'column' => $column, 'operator' => 'LIKE', 'value' => $pattern];
        return $this;
    }

    /**
     * Where raw SQL
     */
    public function whereRaw(string $sql, array $bindings = []): self {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => $bindings];
        return $this;
    }

    /**
     * Join another table
     */
    public function join(string $table, string $first, string $operator, string $second): self {
        $this->joins[] = [
            'type' => 'INNER',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        return $this;
    }

    /**
     * Left join another table
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self {
        $this->joins[] = [
            'type' => 'LEFT',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        return $this;
    }

    /**
     * Order by column
     */
    public function orderBy(string $column, string $direction = 'ASC'): self {
        $this->orderBy[] = [
            'column' => $column,
            'direction' => strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC'
        ];
        return $this;
    }

    /**
     * Order by descending
     */
    public function orderByDesc(string $column): self {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by latest (created_at DESC)
     */
    public function latest(string $column = 'created_at'): self {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by oldest (created_at ASC)
     */
    public function oldest(string $column = 'created_at'): self {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * Group by columns
     */
    public function groupBy(...$columns): self {
        foreach ($columns as $col) {
            $this->groupBy[] = $col;
        }
        return $this;
    }

    /**
     * Limit results
     */
    public function limit(int $limit): self {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Alias for limit
     */
    public function take(int $count): self {
        return $this->limit($count);
    }

    /**
     * Offset results
     */
    public function offset(int $offset): self {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Alias for offset
     */
    public function skip(int $count): self {
        return $this->offset($count);
    }

    /**
     * Paginate results
     */
    public function forPage(int $page, int $perPage = 15): self {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Build SELECT query
     */
    public function toSql(): string {
        $sql = 'SELECT ';

        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        $sql .= implode(', ', $this->select);
        $sql .= ' FROM ' . $this->table;

        // Joins
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
        }

        // Where clauses
        $whereSql = $this->buildWheres();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        // Group by
        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        // Order by
        if (!empty($this->orderBy)) {
            $parts = [];
            foreach ($this->orderBy as $order) {
                $parts[] = "{$order['column']} {$order['direction']}";
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        // Limit and offset
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Build WHERE clause
     */
    private function buildWheres(): string {
        $this->bindings = [];
        $parts = [];

        foreach ($this->wheres as $where) {
            $parts[] = $this->buildWhere($where);
        }

        $sql = implode(' AND ', $parts);

        // Add OR conditions
        foreach ($this->orWheres as $where) {
            $sql .= ' OR ' . $this->buildWhere($where);
        }

        return $sql;
    }

    /**
     * Build single WHERE condition
     */
    private function buildWhere(array $where): string {
        switch ($where['type']) {
            case 'basic':
                $placeholder = $this->addBinding($where['value']);
                return "{$where['column']} {$where['operator']} $placeholder";

            case 'null':
                return "{$where['column']} IS NULL";

            case 'notNull':
                return "{$where['column']} IS NOT NULL";

            case 'in':
                $placeholders = [];
                foreach ($where['values'] as $value) {
                    $placeholders[] = $this->addBinding($value);
                }
                return "{$where['column']} IN (" . implode(', ', $placeholders) . ")";

            case 'notIn':
                $placeholders = [];
                foreach ($where['values'] as $value) {
                    $placeholders[] = $this->addBinding($value);
                }
                return "{$where['column']} NOT IN (" . implode(', ', $placeholders) . ")";

            case 'between':
                $min = $this->addBinding($where['min']);
                $max = $this->addBinding($where['max']);
                return "{$where['column']} BETWEEN $min AND $max";

            case 'raw':
                foreach ($where['bindings'] as $value) {
                    $this->addBinding($value);
                }
                return $where['sql'];

            case 'nested':
                $nested = $where['query'];
                $nestedSql = $nested->buildWheres();
                $this->bindings = array_merge($this->bindings, $nested->bindings);
                return "($nestedSql)";

            default:
                return '1=1';
        }
    }

    /**
     * Add a binding and return placeholder
     */
    private function addBinding($value): string {
        $key = ':p' . count($this->bindings);
        $this->bindings[$key] = $value;
        return $key;
    }

    /**
     * Get bindings
     */
    public function getBindings(): array {
        return $this->bindings;
    }

    /**
     * Execute query and return all results
     */
    public function get(): array {
        $sql = $this->toSql();
        $stmt = $this->db->prepare($sql);

        foreach ($this->bindings as $key => $value) {
            $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($key, $value, $type);
        }

        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Get first result
     */
    public function first(): ?array {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * Get first result or throw exception
     */
    public function firstOrFail(): array {
        $result = $this->first();
        if ($result === null) {
            throw new RuntimeException("No records found in {$this->table}");
        }
        return $result;
    }

    /**
     * Find by ID
     */
    public function find(int $id): ?array {
        return $this->where('id', $id)->first();
    }

    /**
     * Get single column values
     */
    public function pluck(string $column, ?string $key = null): array {
        $this->select($column);
        if ($key) {
            $this->addSelect($key);
        }

        $results = $this->get();
        $plucked = [];

        foreach ($results as $row) {
            if ($key && isset($row[$key])) {
                $plucked[$row[$key]] = $row[$column];
            } else {
                $plucked[] = $row[$column];
            }
        }

        return $plucked;
    }

    /**
     * Get count of results
     */
    public function count(): int {
        $this->select = ['COUNT(*) as count'];
        $result = $this->first();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get sum of column
     */
    public function sum(string $column): float {
        $this->select = ["SUM($column) as sum"];
        $result = $this->first();
        return (float)($result['sum'] ?? 0);
    }

    /**
     * Get average of column
     */
    public function avg(string $column): float {
        $this->select = ["AVG($column) as avg"];
        $result = $this->first();
        return (float)($result['avg'] ?? 0);
    }

    /**
     * Get max of column
     */
    public function max(string $column) {
        $this->select = ["MAX($column) as max"];
        $result = $this->first();
        return $result['max'] ?? null;
    }

    /**
     * Get min of column
     */
    public function min(string $column) {
        $this->select = ["MIN($column) as min"];
        $result = $this->first();
        return $result['min'] ?? null;
    }

    /**
     * Check if any records exist
     */
    public function exists(): bool {
        return $this->count() > 0;
    }

    /**
     * Check if no records exist
     */
    public function doesntExist(): bool {
        return !$this->exists();
    }

    /**
     * Insert a record
     */
    public function insert(array $data): int {
        $columns = array_keys($data);
        $placeholders = [];
        $this->bindings = [];

        foreach ($data as $key => $value) {
            $placeholders[] = $this->addBinding($value);
        }

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        foreach ($this->bindings as $key => $value) {
            $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($key, $value, $type);
        }

        $stmt->execute();
        return $this->db->lastInsertRowID();
    }

    /**
     * Insert multiple records
     */
    public function insertMany(array $records): bool {
        if (empty($records)) return true;

        $columns = array_keys($records[0]);

        foreach ($records as $data) {
            $this->bindings = [];
            $placeholders = [];

            foreach ($data as $value) {
                $placeholders[] = $this->addBinding($value);
            }

            $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $this->db->prepare($sql);
            foreach ($this->bindings as $key => $value) {
                $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
                $stmt->bindValue($key, $value, $type);
            }
            $stmt->execute();
        }

        return true;
    }

    /**
     * Update records
     */
    public function update(array $data): int {
        $sets = [];
        $this->bindings = [];

        foreach ($data as $column => $value) {
            $placeholder = $this->addBinding($value);
            $sets[] = "$column = $placeholder";
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);

        $whereSql = $this->buildWheres();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($this->bindings as $key => $value) {
            $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($key, $value, $type);
        }

        $stmt->execute();
        return $this->db->changes();
    }

    /**
     * Delete records
     */
    public function delete(): int {
        $sql = "DELETE FROM {$this->table}";

        $this->bindings = [];
        $whereSql = $this->buildWheres();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($this->bindings as $key => $value) {
            $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($key, $value, $type);
        }

        $stmt->execute();
        return $this->db->changes();
    }

    /**
     * Increment a column
     */
    public function increment(string $column, int $amount = 1): int {
        $sql = "UPDATE {$this->table} SET $column = $column + :amount";

        $this->bindings = [':amount' => $amount];
        $whereSql = $this->buildWheres();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($this->bindings as $key => $value) {
            $stmt->bindValue($key, $value, SQLITE3_INTEGER);
        }

        $stmt->execute();
        return $this->db->changes();
    }

    /**
     * Decrement a column
     */
    public function decrement(string $column, int $amount = 1): int {
        return $this->increment($column, -$amount);
    }

    /**
     * Paginate results
     */
    public function paginate(int $perPage = 15, int $page = 1): array {
        // Get total count
        $countBuilder = clone $this;
        $total = $countBuilder->count();

        // Get results for page
        $this->forPage($page, $perPage);
        $items = $this->get();

        $lastPage = (int)ceil($total / $perPage);

        return [
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    /**
     * Clone for subqueries
     */
    public function __clone() {
        // Deep clone arrays
    }
}

/**
 * Helper function
 */
function db(string $table = ''): QueryBuilder {
    $builder = new QueryBuilder();
    if ($table) {
        $builder->from($table);
    }
    return $builder;
}
