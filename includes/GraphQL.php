<?php
/**
 * Simple GraphQL Implementation for Silo
 * Provides a flexible query API for models, categories, tags, etc.
 */

class GraphQL {
    private static array $types = [];
    private static array $queries = [];
    private static array $mutations = [];
    private static ?PDO $db = null;
    private const MAX_QUERY_DEPTH = 5;
    private const MAX_SELECTIONS = 50;

    /**
     * Initialize GraphQL schema
     */
    public static function init(): void {
        self::$db = getDB();
        self::registerTypes();
        self::registerQueries();
        self::registerMutations();
    }

    /**
     * Execute a GraphQL query
     */
    public static function execute(string $query, ?array $variables = null, ?int $userId = null): array {
        try {
            self::init();

            $parsed = self::parse($query);

            if (isset($parsed['errors'])) {
                return ['errors' => $parsed['errors']];
            }

            $result = self::resolveOperation($parsed, $variables, $userId);

            return ['data' => $result];
        } catch (Exception $e) {
            return [
                'errors' => [
                    ['message' => $e->getMessage()]
                ]
            ];
        }
    }

    /**
     * Parse GraphQL query (simplified parser)
     */
    private static function parse(string $query): array {
        // Remove comments
        $query = preg_replace('/\#[^\n]*/', '', $query);
        $query = trim($query);

        // Detect operation type
        $operationType = 'query';
        if (preg_match('/^mutation\s*\{/', $query)) {
            $operationType = 'mutation';
            $query = preg_replace('/^mutation\s*/', '', $query);
        } elseif (preg_match('/^query\s*\{/', $query)) {
            $query = preg_replace('/^query\s*/', '', $query);
        }

        // Extract operation name and variables from named queries
        $operationName = null;
        if (preg_match('/^(\w+)\s*(\([^)]*\))?\s*\{/', $query, $matches)) {
            $operationName = $matches[1];
            $query = preg_replace('/^(\w+)\s*(\([^)]*\))?\s*/', '', $query);
        }

        // Parse the selection set
        $selections = self::parseSelectionSet($query);

        return [
            'type' => $operationType,
            'name' => $operationName,
            'selections' => $selections,
        ];
    }

    /**
     * Parse selection set (fields to return)
     */
    private static function parseSelectionSet(string $query): array {
        $selections = [];
        $depth = 0;
        $current = '';
        $inString = false;

        // Remove outer braces
        $query = trim($query);
        if (str_starts_with($query, '{')) {
            $query = substr($query, 1);
        }
        if (str_ends_with($query, '}')) {
            $query = substr($query, 0, -1);
        }

        $chars = str_split($query);
        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];

            if ($char === '"' && ($i === 0 || $chars[$i-1] !== '\\')) {
                $inString = !$inString;
            }

            if (!$inString) {
                if ($char === '{') $depth++;
                elseif ($char === '}') $depth--;
            }

            if ($depth === 0 && !$inString && ($char === "\n" || $char === ',')) {
                $current = trim($current);
                if ($current) {
                    $selections[] = self::parseField($current);
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $current = trim($current);
        if ($current) {
            $selections[] = self::parseField($current);
        }

        return array_filter($selections);
    }

    /**
     * Parse a single field
     */
    private static function parseField(string $field): ?array {
        $field = trim($field);
        if (empty($field)) return null;

        $result = [
            'name' => '',
            'alias' => null,
            'arguments' => [],
            'selections' => [],
        ];

        // Check for alias
        if (preg_match('/^(\w+)\s*:\s*/', $field, $matches)) {
            $result['alias'] = $matches[1];
            $field = substr($field, strlen($matches[0]));
        }

        // Check for nested selection
        $bracePos = strpos($field, '{');
        if ($bracePos !== false) {
            $nested = substr($field, $bracePos);
            $field = substr($field, 0, $bracePos);
            $result['selections'] = self::parseSelectionSet($nested);
        }

        // Parse field name and arguments
        if (preg_match('/^(\w+)\s*(?:\(([^)]*)\))?/', $field, $matches)) {
            $result['name'] = $matches[1];
            if (isset($matches[2]) && $matches[2]) {
                $result['arguments'] = self::parseArguments($matches[2]);
            }
        }

        return $result;
    }

    /**
     * Parse arguments from a field
     */
    private static function parseArguments(string $args): array {
        $result = [];
        $pairs = preg_split('/\s*,\s*/', $args);

        foreach ($pairs as $pair) {
            if (preg_match('/(\w+)\s*:\s*(.+)/', trim($pair), $m)) {
                $key = $m[1];
                $value = trim($m[2]);

                // Parse value
                if ($value === 'true') $value = true;
                elseif ($value === 'false') $value = false;
                elseif ($value === 'null') $value = null;
                elseif (is_numeric($value)) $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                elseif (preg_match('/^"(.*)"$/', $value, $vm)) $value = stripcslashes($vm[1]);
                elseif (preg_match('/^\$(\w+)$/', $value, $vm)) $value = ['$var' => $vm[1]];

                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check query depth and selection count to prevent DoS
     */
    private static function validateQueryComplexity(array $selections, int $depth = 1, int &$count = 0): void {
        if ($depth > self::MAX_QUERY_DEPTH) {
            throw new Exception('Query exceeds maximum depth of ' . self::MAX_QUERY_DEPTH);
        }

        foreach ($selections as $selection) {
            $count++;
            if ($count > self::MAX_SELECTIONS) {
                throw new Exception('Query exceeds maximum of ' . self::MAX_SELECTIONS . ' fields');
            }
            if (!empty($selection['selections'])) {
                self::validateQueryComplexity($selection['selections'], $depth + 1, $count);
            }
        }
    }

    /**
     * Resolve the parsed operation
     */
    private static function resolveOperation(array $parsed, ?array $variables, ?int $userId): array {
        self::validateQueryComplexity($parsed['selections']);

        $result = [];

        foreach ($parsed['selections'] as $selection) {
            $fieldName = $selection['name'];
            $alias = $selection['alias'] ?? $fieldName;

            // Resolve variables in arguments
            $args = self::resolveVariables($selection['arguments'], $variables);

            if ($parsed['type'] === 'mutation') {
                if (isset(self::$mutations[$fieldName])) {
                    $result[$alias] = self::$mutations[$fieldName]($args, $selection['selections'], $userId);
                } else {
                    throw new Exception("Unknown mutation: $fieldName");
                }
            } else {
                if (isset(self::$queries[$fieldName])) {
                    $result[$alias] = self::$queries[$fieldName]($args, $selection['selections'], $userId);
                } else {
                    throw new Exception("Unknown query: $fieldName");
                }
            }
        }

        return $result;
    }

    /**
     * Resolve variable references in arguments
     */
    private static function resolveVariables(array $args, ?array $variables): array {
        if (!$variables) return $args;

        foreach ($args as $key => $value) {
            if (is_array($value) && isset($value['$var'])) {
                $varName = $value['$var'];
                $args[$key] = $variables[$varName] ?? null;
            }
        }

        return $args;
    }

    /**
     * Register GraphQL types
     */
    private static function registerTypes(): void {
        self::$types = [
            'Model' => [
                'id' => 'Int',
                'name' => 'String',
                'description' => 'String',
                'file_path' => 'String',
                'thumbnail' => 'String',
                'category_id' => 'Int',
                'user_id' => 'Int',
                'download_count' => 'Int',
                'license' => 'String',
                'is_archived' => 'Boolean',
                'created_at' => 'String',
                'updated_at' => 'String',
                'category' => 'Category',
                'tags' => '[Tag]',
                'parts' => '[Model]',
                'owner' => 'User',
            ],
            'Category' => [
                'id' => 'Int',
                'name' => 'String',
                'description' => 'String',
                'parent_id' => 'Int',
                'model_count' => 'Int',
            ],
            'Tag' => [
                'id' => 'Int',
                'name' => 'String',
                'color' => 'String',
            ],
            'User' => [
                'id' => 'Int',
                'username' => 'String',
                'is_admin' => 'Boolean',
            ],
            'Collection' => [
                'id' => 'Int',
                'name' => 'String',
                'description' => 'String',
                'is_public' => 'Boolean',
                'user_id' => 'Int',
                'model_count' => 'Int',
            ],
        ];
    }

    /**
     * Register query resolvers
     */
    private static function registerQueries(): void {
        // Model query
        self::$queries['model'] = function($args, $selections, $userId) {
            $db = self::$db;
            $id = $args['id'] ?? null;

            if (!$id) throw new Exception('Model id is required');

            $stmt = $db->prepare("SELECT * FROM models WHERE id = :id AND parent_id IS NULL");
            $stmt->execute([':id' => $id]);
            $model = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$model) return null;

            return self::resolveModel($model, $selections, $userId);
        };

        // Models query (list)
        self::$queries['models'] = function($args, $selections, $userId) {
            $db = self::$db;
            $limit = min($args['limit'] ?? 20, 100);
            $offset = $args['offset'] ?? 0;
            $category = $args['category'] ?? null;
            $tag = $args['tag'] ?? null;
            $search = $args['search'] ?? null;
            $sort = $args['sort'] ?? 'created_at';
            $order = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            $where = ['m.parent_id IS NULL', 'm.is_archived = 0'];
            $params = [];

            if ($category) {
                $where[] = 'm.category_id = :category';
                $params[':category'] = $category;
            }

            if ($tag) {
                $where[] = 'EXISTS (SELECT 1 FROM model_tags mt JOIN tags t ON mt.tag_id = t.id WHERE mt.model_id = m.id AND t.name = :tag)';
                $params[':tag'] = $tag;
            }

            if ($search) {
                $where[] = '(m.name LIKE :search OR m.description LIKE :search)';
                $params[':search'] = '%' . $search . '%';
            }

            $allowedSort = ['created_at', 'updated_at', 'name', 'download_count'];
            if (!in_array($sort, $allowedSort)) $sort = 'created_at';

            $sql = "SELECT m.* FROM models m WHERE " . implode(' AND ', $where) .
                   " ORDER BY m.$sort $order LIMIT :limit OFFSET :offset";

            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $models = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $models[] = self::resolveModel($row, $selections, $userId);
            }

            return $models;
        };

        // Categories query
        self::$queries['categories'] = function($args, $selections, $userId) {
            $db = self::$db;
            $parent = $args['parent'] ?? null;

            $where = $parent === null ? 'parent_id IS NULL' : 'parent_id = :parent';
            $params = $parent === null ? [] : [':parent' => $parent];

            $stmt = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM models WHERE category_id = c.id AND parent_id IS NULL) as model_count FROM categories c WHERE $where ORDER BY name");
            if ($params) $stmt->execute($params);
            else $stmt->execute();

            $categories = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $categories[] = $row;
            }

            return $categories;
        };

        // Tags query
        self::$queries['tags'] = function($args, $selections, $userId) {
            $db = self::$db;
            $limit = min($args['limit'] ?? 50, 200);

            $stmt = $db->prepare("SELECT t.*, COUNT(mt.model_id) as usage_count FROM tags t LEFT JOIN model_tags mt ON t.id = mt.tag_id GROUP BY t.id ORDER BY usage_count DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $tags = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tags[] = $row;
            }

            return $tags;
        };

        // Collections query
        self::$queries['collections'] = function($args, $selections, $userId) {
            $db = self::$db;
            $public = $args['public'] ?? true;
            $owner = $args['owner'] ?? null;

            $where = [];
            $params = [];

            if ($public) {
                $where[] = 'is_public = 1';
            }

            if ($owner) {
                $where[] = 'user_id = :owner';
                $params[':owner'] = $owner;
            } elseif (!$public && $userId) {
                $where[] = 'user_id = :user_id';
                $params[':user_id'] = $userId;
            }

            if (empty($where)) {
                $where[] = '1=1';
            }

            $sql = "SELECT c.*, (SELECT COUNT(*) FROM collection_models WHERE collection_id = c.id) as model_count FROM collections c WHERE " . implode(' AND ', $where) . " ORDER BY name";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $collections = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $collections[] = $row;
            }

            return $collections;
        };

        // Me query (current user)
        self::$queries['me'] = function($args, $selections, $userId) {
            if (!$userId) return null;

            $db = self::$db;
            $stmt = $db->prepare("SELECT id, username, email, is_admin, created_at FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        };

        // Stats query
        self::$queries['stats'] = function($args, $selections, $userId) {
            $db = self::$db;

            return [
                'totalModels' => (int)$db->query("SELECT COUNT(*) FROM models WHERE parent_id IS NULL")->fetchColumn(),
                'totalCategories' => (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn(),
                'totalTags' => (int)$db->query("SELECT COUNT(*) FROM tags")->fetchColumn(),
                'totalUsers' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
                'totalDownloads' => (int)$db->query("SELECT SUM(download_count) FROM models")->fetchColumn() ?: 0,
            ];
        };
    }

    /**
     * Register mutation resolvers
     */
    private static function registerMutations(): void {
        // Toggle favorite
        self::$mutations['toggleFavorite'] = function($args, $selections, $userId) {
            if (!$userId) throw new Exception('Authentication required');

            $modelId = $args['modelId'] ?? null;
            if (!$modelId) throw new Exception('modelId is required');

            $db = self::$db;

            // Check if already favorited
            $stmt = $db->prepare("SELECT id FROM favorites WHERE user_id = :user AND model_id = :model");
            $stmt->execute([':user' => $userId, ':model' => $modelId]);

            if ($stmt->fetch()) {
                // Remove favorite
                $stmt = $db->prepare("DELETE FROM favorites WHERE user_id = :user AND model_id = :model");
                $stmt->execute([':user' => $userId, ':model' => $modelId]);
                return ['isFavorite' => false, 'modelId' => $modelId];
            } else {
                // Add favorite
                $stmt = $db->prepare("INSERT INTO favorites (user_id, model_id) VALUES (:user, :model)");
                $stmt->execute([':user' => $userId, ':model' => $modelId]);
                return ['isFavorite' => true, 'modelId' => $modelId];
            }
        };

        // Add tag to model
        self::$mutations['addTag'] = function($args, $selections, $userId) {
            if (!$userId) throw new Exception('Authentication required');

            $modelId = $args['modelId'] ?? null;
            $tagName = $args['tag'] ?? null;

            if (!$modelId || !$tagName) throw new Exception('modelId and tag are required');

            $db = self::$db;

            // Get or create tag
            $stmt = $db->prepare("SELECT id FROM tags WHERE name = :name");
            $stmt->execute([':name' => $tagName]);
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tag) {
                $stmt = $db->prepare("INSERT INTO tags (name) VALUES (:name)");
                $stmt->execute([':name' => $tagName]);
                $tagId = $db->lastInsertId();
            } else {
                $tagId = $tag['id'];
            }

            // Add to model
            try {
                $stmt = $db->prepare("INSERT INTO model_tags (model_id, tag_id) VALUES (:model, :tag)");
                $stmt->execute([':model' => $modelId, ':tag' => $tagId]);
            } catch (Exception $e) {
                // Already exists, ignore
            }

            return ['success' => true, 'modelId' => $modelId, 'tag' => $tagName];
        };

        // Increment download count
        self::$mutations['trackDownload'] = function($args, $selections, $userId) {
            $modelId = $args['modelId'] ?? null;
            if (!$modelId) throw new Exception('modelId is required');

            $db = self::$db;
            $stmt = $db->prepare("UPDATE models SET download_count = download_count + 1 WHERE id = :id");
            $stmt->execute([':id' => $modelId]);

            return ['success' => true, 'modelId' => $modelId];
        };
    }

    /**
     * Resolve a model with requested fields
     */
    private static function resolveModel(array $model, array $selections, ?int $userId): array {
        $db = self::$db;
        $result = $model;

        foreach ($selections as $field) {
            $name = $field['name'];

            switch ($name) {
                case 'category':
                    if ($model['category_id']) {
                        $stmt = $db->prepare("SELECT * FROM categories WHERE id = :id");
                        $stmt->execute([':id' => $model['category_id']]);
                        $result['category'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    } else {
                        $result['category'] = null;
                    }
                    break;

                case 'tags':
                    $stmt = $db->prepare("SELECT t.* FROM tags t JOIN model_tags mt ON t.id = mt.tag_id WHERE mt.model_id = :id");
                    $stmt->execute([':id' => $model['id']]);
                    $result['tags'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                case 'parts':
                    $stmt = $db->prepare("SELECT * FROM models WHERE parent_id = :id ORDER BY sort_order, name");
                    $stmt->execute([':id' => $model['id']]);
                    $parts = [];
                    while ($part = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $parts[] = self::resolveModel($part, $field['selections'] ?? [], $userId);
                    }
                    $result['parts'] = $parts;
                    break;

                case 'owner':
                    if ($model['user_id']) {
                        $stmt = $db->prepare("SELECT id, username, is_admin FROM users WHERE id = :id");
                        $stmt->execute([':id' => $model['user_id']]);
                        $result['owner'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    } else {
                        $result['owner'] = null;
                    }
                    break;

                case 'isFavorite':
                    if ($userId) {
                        $stmt = $db->prepare("SELECT 1 FROM favorites WHERE user_id = :user AND model_id = :model");
                        $stmt->execute([':user' => $userId, ':model' => $model['id']]);
                        $result['isFavorite'] = (bool)$stmt->fetch();
                    } else {
                        $result['isFavorite'] = false;
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * Get GraphQL schema for introspection
     */
    public static function getSchema(): array {
        self::init();

        return [
            'types' => self::$types,
            'queries' => array_keys(self::$queries),
            'mutations' => array_keys(self::$mutations),
        ];
    }
}
