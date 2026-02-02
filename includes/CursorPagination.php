<?php
/**
 * Cursor-based Pagination
 *
 * Provides efficient pagination for large datasets using cursor-based navigation.
 * Benefits:
 * - Consistent performance regardless of page depth (page 1000 is as fast as page 1)
 * - No OFFSET overhead that slows down on deep pages
 * - Handles concurrent inserts/deletes gracefully
 * - Perfect for infinite scroll implementations
 */

class CursorPagination {
    private PDO $db;
    private string $table;
    private string $orderColumn = 'id';
    private string $orderDirection = 'DESC';
    private int $limit = 20;
    private array $conditions = [];
    private array $params = [];
    private array $selectColumns = ['*'];

    /**
     * Create a new cursor paginator
     */
    public function __construct(string $table, ?PDO $db = null) {
        $this->table = $table;
        $this->db = $db ?? getDB();
    }

    /**
     * Set columns to select
     */
    public function select(array $columns): self {
        $this->selectColumns = $columns;
        return $this;
    }

    /**
     * Set ordering column and direction
     */
    public function orderBy(string $column, string $direction = 'DESC'): self {
        $this->orderColumn = $column;
        $this->orderDirection = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        return $this;
    }

    /**
     * Set items per page
     */
    public function limit(int $limit): self {
        $this->limit = max(1, min(100, $limit));
        return $this;
    }

    /**
     * Add a WHERE condition
     */
    public function where(string $column, $value, string $operator = '='): self {
        $placeholder = ':where_' . count($this->conditions);
        $this->conditions[] = "$column $operator $placeholder";
        $this->params[$placeholder] = $value;
        return $this;
    }

    /**
     * Add a raw WHERE condition
     */
    public function whereRaw(string $condition, array $params = []): self {
        $this->conditions[] = $condition;
        foreach ($params as $key => $value) {
            $this->params[$key] = $value;
        }
        return $this;
    }

    /**
     * Get paginated results
     *
     * @param string|null $cursor The cursor value (typically the last item's ID or order column value)
     * @return array{items: array, next_cursor: string|null, prev_cursor: string|null, has_more: bool}
     */
    public function paginate(?string $cursor = null): array {
        $columns = implode(', ', $this->selectColumns);
        $conditions = $this->conditions;
        $params = $this->params;

        // Add cursor condition
        if ($cursor !== null) {
            $cursorData = $this->decodeCursor($cursor);
            if ($cursorData) {
                $op = $this->orderDirection === 'DESC' ? '<' : '>';
                $conditions[] = "{$this->orderColumn} $op :cursor_value";
                $params[':cursor_value'] = $cursorData['value'];
            }
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Fetch one extra to check if there are more items
        $sql = "SELECT $columns FROM {$this->table} $whereClause
                ORDER BY {$this->orderColumn} {$this->orderDirection}
                LIMIT " . ($this->limit + 1);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if there are more items
        $hasMore = count($items) > $this->limit;
        if ($hasMore) {
            array_pop($items); // Remove the extra item
        }

        // Generate cursors
        $nextCursor = null;
        $prevCursor = null;

        if (!empty($items)) {
            $lastItem = end($items);
            if ($hasMore) {
                $nextCursor = $this->encodeCursor($lastItem[$this->orderColumn]);
            }

            $firstItem = reset($items);
            if ($cursor !== null) {
                $prevCursor = $this->encodeCursor($firstItem[$this->orderColumn], 'prev');
            }
        }

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
            'prev_cursor' => $prevCursor,
            'has_more' => $hasMore,
            'count' => count($items),
        ];
    }

    /**
     * Get previous page (for backward navigation)
     */
    public function paginateBackward(string $cursor): array {
        $cursorData = $this->decodeCursor($cursor);
        if (!$cursorData) {
            return $this->paginate();
        }

        // Temporarily reverse direction
        $originalDirection = $this->orderDirection;
        $this->orderDirection = $originalDirection === 'DESC' ? 'ASC' : 'DESC';

        $columns = implode(', ', $this->selectColumns);
        $conditions = $this->conditions;
        $params = $this->params;

        $op = $this->orderDirection === 'DESC' ? '<' : '>';
        $conditions[] = "{$this->orderColumn} $op :cursor_value";
        $params[':cursor_value'] = $cursorData['value'];

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT $columns FROM {$this->table} $whereClause
                ORDER BY {$this->orderColumn} {$this->orderDirection}
                LIMIT " . ($this->limit + 1);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Restore direction
        $this->orderDirection = $originalDirection;

        // Reverse items to maintain original order
        $items = array_reverse($items);

        $hasMore = count($items) > $this->limit;
        if ($hasMore) {
            array_shift($items);
        }

        $nextCursor = null;
        $prevCursor = null;

        if (!empty($items)) {
            $lastItem = end($items);
            $nextCursor = $this->encodeCursor($lastItem[$this->orderColumn]);

            $firstItem = reset($items);
            if ($hasMore) {
                $prevCursor = $this->encodeCursor($firstItem[$this->orderColumn], 'prev');
            }
        }

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
            'prev_cursor' => $prevCursor,
            'has_more' => $hasMore,
            'count' => count($items),
        ];
    }

    /**
     * Encode a cursor value
     */
    private function encodeCursor($value, string $direction = 'next'): string {
        return base64_encode(json_encode([
            'v' => $value,
            'd' => $direction,
            'c' => $this->orderColumn,
        ]));
    }

    /**
     * Decode a cursor value
     */
    private function decodeCursor(string $cursor): ?array {
        try {
            $decoded = json_decode(base64_decode($cursor), true);
            if (is_array($decoded) && isset($decoded['v'])) {
                return [
                    'value' => $decoded['v'],
                    'direction' => $decoded['d'] ?? 'next',
                    'column' => $decoded['c'] ?? $this->orderColumn,
                ];
            }
        } catch (Exception $e) {
            // Invalid cursor
        }
        return null;
    }
}

// ========================================
// Helper Functions
// ========================================

/**
 * Create a cursor paginator for a table
 */
function cursorPaginate(string $table): CursorPagination {
    return new CursorPagination($table);
}

/**
 * Paginate models with cursor
 */
function paginateModels(array $options = []): array {
    $cursor = $options['cursor'] ?? null;
    $limit = $options['limit'] ?? 20;
    $orderBy = $options['order_by'] ?? 'created_at';
    $orderDir = $options['order_dir'] ?? 'DESC';
    $categoryId = $options['category_id'] ?? null;
    $tagId = $options['tag_id'] ?? null;

    $paginator = cursorPaginate('models')
        ->select(['id', 'name', 'description', 'category_id', 'thumbnail_path', 'created_at', 'file_size', 'download_count'])
        ->orderBy($orderBy, $orderDir)
        ->limit($limit)
        ->where('parent_id', null, 'IS');

    if ($categoryId) {
        $paginator->where('category_id', $categoryId);
    }

    if ($tagId) {
        $paginator->whereRaw(
            'id IN (SELECT model_id FROM model_tags WHERE tag_id = :tag_id)',
            [':tag_id' => $tagId]
        );
    }

    return $paginator->paginate($cursor);
}

/**
 * Generate cursor pagination links
 */
function cursorPaginationLinks(array $result, string $baseUrl): array {
    $links = [];

    if ($result['prev_cursor']) {
        $links['prev'] = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'cursor=' . urlencode($result['prev_cursor']);
    }

    if ($result['next_cursor']) {
        $links['next'] = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'cursor=' . urlencode($result['next_cursor']);
    }

    return $links;
}
