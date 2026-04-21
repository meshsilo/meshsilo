<?php

class BrowseQuery
{
    /**
     * Execute the browse query with the given filter parameters.
     *
     * @param array $filters Parsed GET parameters:
     *   - search (string), categoryId (int), tagIds (int[]), fileType (string),
     *   - printType (string), collection (string), sort (string), page (int),
     *   - perPage (int), showArchived (bool), explicitSort (bool)
     * @return array ['models', 'totalModels', 'totalPages', 'categories', 'tags',
     *               'fileTypes', 'printTypes', 'collections', 'savedSearches',
     *               'activeCategory', 'activeTags']
     */
    public static function execute(array $filters): array
    {
        $db = getDB();

        $search = $filters['search'] ?? '';
        $categoryId = $filters['categoryId'] ?? 0;
        $tagIds = $filters['tagIds'] ?? [];
        $fileType = $filters['fileType'] ?? '';
        $printType = $filters['printType'] ?? '';
        $collection = $filters['collection'] ?? '';
        $sort = $filters['sort'] ?? 'newest';
        $page = $filters['page'] ?? 1;
        $perPage = $filters['perPage'] ?? 20;
        $showArchived = $filters['showArchived'] ?? false;
        $explicitSort = $filters['explicitSort'] ?? false;

        $offset = ($page - 1) * $perPage;

        // Detect FTS availability for ranked full-text search
        $dbType = $db->getType();
        $ftsAvailable = false;
        if ($dbType === 'sqlite') {
            $ftsAvailable = tableExists($db, 'models_fts');
        } elseif ($dbType === 'mysql') {
            try {
                $r = $db->query("SHOW INDEX FROM models WHERE Key_name = 'idx_models_fulltext'");
                $ftsAvailable = ($r !== false && $r->fetch() !== false);
            } catch (Exception $e) {
                $ftsAvailable = false;
            }
        }

        // Build query
        $where = ['m.parent_id IS NULL'];
        $params = [];
        $ftsActive = false;
        $ftsJoin = '';
        $ftsJoinParams = [];
        $ftsOrderBy = null;

        // Search filter
        if ($search !== '') {
            self::applySearchFilter(
                $search, $dbType, $ftsAvailable, $explicitSort,
                $where, $params, $ftsActive, $ftsJoin, $ftsJoinParams, $ftsOrderBy
            );
        }

        // Category filter
        if ($categoryId > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM model_categories mc WHERE mc.model_id = m.id AND mc.category_id = :category_id)';
            $params[':category_id'] = $categoryId;
        }

        // Tag filter (OR within selected tags)
        if (!empty($tagIds)) {
            $tagPlaceholders = implode(',', array_map(fn($i) => ':tag_id_' . $i, array_keys($tagIds)));
            $where[] = "EXISTS (SELECT 1 FROM model_tags mt WHERE mt.model_id = m.id AND mt.tag_id IN ($tagPlaceholders))";
            foreach ($tagIds as $i => $tid) {
                $params[':tag_id_' . $i] = $tid;
            }
        }

        // File type filter (check parent model or any of its parts)
        if ($fileType !== '') {
            $where[] = '(m.file_type = :file_type OR m.id IN (SELECT parent_id FROM models WHERE parent_id IS NOT NULL AND file_type = :file_type2))';
            $params[':file_type'] = $fileType;
            $params[':file_type2'] = $fileType;
        }

        // Print type filter
        if ($printType !== '' && in_array($printType, ['fdm', 'sla'])) {
            $where[] = '(m.print_type = :print_type OR m.id IN (SELECT parent_id FROM models WHERE parent_id IS NOT NULL AND print_type = :print_type2))';
            $params[':print_type'] = $printType;
            $params[':print_type2'] = $printType;
        }

        // Collection filter
        if ($collection !== '') {
            $where[] = 'm.collection = :collection';
            $params[':collection'] = $collection;
        }

        // Archive filter
        if (!$showArchived) {
            $where[] = '(m.is_archived = 0 OR m.is_archived IS NULL)';
        }

        $whereClause = implode(' AND ', $where);

        if (class_exists('PluginManager')) {
            $whereClause = PluginManager::applyFilter('browse_query_where', $whereClause, $params);
        }

        // Sort
        $orderBy = $ftsOrderBy ?? match($sort) {
            'oldest' => 'm.created_at ASC',
            'updated' => 'm.updated_at DESC',
            'name' => 'm.name ASC',
            'name_desc' => 'm.name DESC',
            'size' => 'm.file_size DESC',
            'size_asc' => 'm.file_size ASC',
            'parts' => 'm.part_count DESC',
            'downloads' => 'm.download_count DESC',
            default => 'm.created_at DESC'
        };

        // Count query
        $countSql = "SELECT COUNT(*) FROM models m WHERE $whereClause";
        $countStmt = $db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalModels = (int)$countStmt->fetchColumn();
        $totalPages = (int)ceil($totalModels / $perPage);

        // Main query
        $sql = "SELECT m.id, m.name, m.description, m.creator, m.file_path, m.file_size, m.file_type,
                       m.dedup_path, m.part_count, m.download_count, m.created_at, m.is_archived, m.thumbnail_path
                FROM models m
                $ftsJoin
                WHERE $whereClause
                ORDER BY $orderBy
                LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        foreach ($ftsJoinParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $result = $stmt->execute();

        $models = [];
        $modelIds = [];
        $multiPartModelIds = [];

        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $row['tags'] = [];
            $row['preview_path'] = '/preview?id=' . $row['id'];
            $row['preview_type'] = $row['file_type'];
            $row['preview_file_size'] = $row['file_size'] ?? 0;

            if ($row['part_count'] > 0) {
                $multiPartModelIds[] = $row['id'];
            }

            $modelIds[] = $row['id'];
            $models[$row['id']] = $row;
        }

        // Batch load first parts for multi-part models
        if (!empty($multiPartModelIds)) {
            $placeholders = implode(',', array_fill(0, count($multiPartModelIds), '?'));
            $firstPartsSql = "SELECT m.parent_id, m.id, m.file_path, m.file_type, m.file_size, m.dedup_path
                              FROM models m
                              INNER JOIN (
                                  SELECT parent_id, MIN(original_path) as min_path
                                  FROM models
                                  WHERE parent_id IN ($placeholders)
                                  GROUP BY parent_id
                              ) first ON m.parent_id = first.parent_id AND m.original_path = first.min_path";
            $firstPartsStmt = $db->prepare($firstPartsSql);
            foreach ($multiPartModelIds as $index => $parentId) {
                $firstPartsStmt->bindValue($index + 1, $parentId, PDO::PARAM_INT);
            }
            $firstPartsResult = $firstPartsStmt->execute();

            while ($firstPart = $firstPartsResult->fetchArray(PDO::FETCH_ASSOC)) {
                $parentId = $firstPart['parent_id'];
                if (isset($models[$parentId])) {
                    $models[$parentId]['preview_path'] = '/preview?id=' . $firstPart['id'];
                    $models[$parentId]['preview_type'] = $firstPart['file_type'];
                    $models[$parentId]['preview_file_size'] = $firstPart['file_size'] ?? 0;
                }
            }
        }

        $models = array_values($models);

        // Fetch tags for all models
        if (!empty($modelIds)) {
            $tagsByModel = getTagsForModels($modelIds);
            foreach ($models as &$model) {
                if (isset($tagsByModel[$model['id']])) {
                    $model['tags'] = $tagsByModel[$model['id']];
                }
            }
            unset($model);
        }

        // Filter dropdown data
        $categories = Cache::getInstance()->remember('browse_categories', 300, function() use ($db) {
            $cats = [];
            $catResult = $db->query('SELECT c.id, c.name, COUNT(mc.model_id) as model_count FROM categories c LEFT JOIN model_categories mc ON c.id = mc.category_id GROUP BY c.id ORDER BY c.name');
            while ($row = $catResult->fetchArray(PDO::FETCH_ASSOC)) {
                $cats[] = $row;
            }
            return $cats;
        });

        $tags = getAllTags();

        $fileTypes = [];
        $ftResult = $db->query("SELECT DISTINCT file_type FROM models WHERE file_type IS NOT NULL AND file_type != '' AND file_type != 'parent' ORDER BY file_type");
        if ($ftResult) {
            while ($ftRow = $ftResult->fetchArray()) {
                $fileTypes[] = $ftRow['file_type'];
            }
        }

        $printTypes = [];
        $ptResult = $db->query("SELECT DISTINCT print_type FROM models WHERE print_type IS NOT NULL AND print_type != '' ORDER BY print_type");
        if ($ptResult) {
            while ($ptRow = $ptResult->fetchArray()) {
                $printTypes[] = $ptRow['print_type'];
            }
        }

        $collections = [];
        $collResult = $db->query("SELECT DISTINCT collection FROM models WHERE parent_id IS NULL AND collection IS NOT NULL AND collection != '' ORDER BY collection");
        if ($collResult) {
            while ($collRow = $collResult->fetchArray()) {
                $collections[] = $collRow['collection'];
            }
        }

        $savedSearches = [];
        if (isLoggedIn()) {
            $user = getCurrentUser();
            $savedSearches = SavedSearches::getUserSearches($user['id'], 20);
        }

        // Active filter names
        $activeCategory = null;
        if ($categoryId > 0) {
            $catStmt = $db->prepare('SELECT name FROM categories WHERE id = :id');
            $catStmt->bindValue(':id', $categoryId, PDO::PARAM_INT);
            $catStmt->execute();
            $activeCategory = $catStmt->fetchColumn();
        }

        $activeTags = [];
        if (!empty($tagIds)) {
            $tagPlaceholders2 = implode(',', array_fill(0, count($tagIds), '?'));
            $activeTagStmt = $db->prepare("SELECT id, name, color FROM tags WHERE id IN ($tagPlaceholders2) ORDER BY name");
            foreach ($tagIds as $i => $tid) {
                $activeTagStmt->bindValue($i + 1, $tid, PDO::PARAM_INT);
            }
            $activeTagStmt->execute();
            while ($tagRow = $activeTagStmt->fetch()) {
                $activeTags[] = $tagRow;
            }
        }

        return [
            'models' => $models,
            'totalModels' => $totalModels,
            'totalPages' => $totalPages,
            'categories' => $categories,
            'tags' => $tags,
            'fileTypes' => $fileTypes,
            'printTypes' => $printTypes,
            'collections' => $collections,
            'savedSearches' => $savedSearches,
            'activeCategory' => $activeCategory,
            'activeTags' => $activeTags,
        ];
    }

    /**
     * Build a URL by merging params into the current GET parameters.
     * Pass null as a value to remove that parameter.
     */
    public static function buildUrl(array $params = []): string
    {
        $current = $_GET;
        foreach ($params as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }
        return '?' . http_build_query($current);
    }

    /**
     * Remove a single tag ID from the tags[] parameter while keeping all other filters.
     */
    public static function buildUrlWithoutTag(int $tagId): string
    {
        $current = $_GET;
        $current['tags'] = array_values(array_filter(
            array_map('intval', (array)($current['tags'] ?? [])),
            fn($id) => $id !== $tagId
        ));
        unset($current['tag']);
        $current['page'] = 1;
        if (empty($current['tags'])) {
            unset($current['tags']);
        }
        return '?' . http_build_query($current);
    }

    private static function applySearchFilter(
        string $search,
        string $dbType,
        bool $ftsAvailable,
        bool $explicitSort,
        array &$where,
        array &$params,
        bool &$ftsActive,
        string &$ftsJoin,
        array &$ftsJoinParams,
        ?string &$ftsOrderBy
    ): void {
        if ($ftsAvailable && $dbType === 'sqlite') {
            $ftsActive = true;
            $ftsWords = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);
            $ftsWords = array_values(array_filter(array_map(fn($w) => preg_replace('/[^\w\x80-\xff]/u', '', $w), $ftsWords)));
            $ftsQuery = implode(' ', array_map(fn($w) => $w . '*', $ftsWords));
            if (empty($ftsWords)) {
                $ftsActive = false;
            } else {
                $params[':fts_query'] = $ftsQuery;
                $params[':fts_query_parts'] = $ftsQuery;
                $ftsSearchTerm = '%' . $search . '%';
                $params[':fts_tag_search'] = $ftsSearchTerm;
                $params[':fts_cat_search'] = $ftsSearchTerm;
                $where[] = "(m.id IN (SELECT rowid FROM models_fts WHERE models_fts MATCH :fts_query)
                    OR m.id IN (SELECT p.parent_id FROM models p WHERE p.parent_id IS NOT NULL AND p.id IN (SELECT rowid FROM models_fts WHERE models_fts MATCH :fts_query_parts))
                    OR EXISTS (SELECT 1 FROM model_tags mt2 JOIN tags t2 ON mt2.tag_id = t2.id WHERE mt2.model_id = m.id AND t2.name LIKE :fts_tag_search)
                    OR EXISTS (SELECT 1 FROM model_categories mc2 JOIN categories c2 ON mc2.category_id = c2.id WHERE mc2.model_id = m.id AND c2.name LIKE :fts_cat_search))";
                $ftsJoin = "LEFT JOIN (SELECT rowid, rank FROM models_fts WHERE models_fts MATCH :fts_query2) fts_r ON fts_r.rowid = m.id";
                $ftsJoinParams = [':fts_query2' => $ftsQuery];
                if (!$explicitSort) {
                    $ftsOrderBy = 'COALESCE(fts_r.rank, 1) ASC, m.created_at DESC';
                }
            }
        } elseif ($ftsAvailable && $dbType === 'mysql') {
            $ftsActive = true;
            $params[':fts_query'] = $search;
            $mysqlFtsExpr = 'MATCH(m.name, m.description, m.creator) AGAINST(:fts_query IN NATURAL LANGUAGE MODE)';
            $partSubquery = 'EXISTS (SELECT 1 FROM models p WHERE p.parent_id = m.id AND MATCH(p.name, p.description, p.creator) AGAINST(:fts_query_part IN NATURAL LANGUAGE MODE))';
            $params[':fts_query_part'] = $search;
            $mysqlSearchTerm = '%' . $search . '%';
            $tagSubquery = 'EXISTS (SELECT 1 FROM model_tags mt2 JOIN tags t2 ON mt2.tag_id = t2.id WHERE mt2.model_id = m.id AND t2.name LIKE :mysql_tag_search)';
            $catSubquery = 'EXISTS (SELECT 1 FROM model_categories mc2 JOIN categories c2 ON mc2.category_id = c2.id WHERE mc2.model_id = m.id AND c2.name LIKE :mysql_cat_search)';
            $params[':mysql_tag_search'] = $mysqlSearchTerm;
            $params[':mysql_cat_search'] = $mysqlSearchTerm;
            $where[] = "($mysqlFtsExpr OR $partSubquery OR $tagSubquery OR $catSubquery)";
            if (!$explicitSort) {
                $params[':fts_sort'] = $search;
                $ftsOrderBy = 'MATCH(m.name, m.description, m.creator) AGAINST(:fts_sort IN NATURAL LANGUAGE MODE) DESC';
            }
        }

        if (!$ftsActive) {
            $searchTerm = '%' . $search . '%';
            $partSubquery = 'EXISTS (SELECT 1 FROM models p WHERE p.parent_id = m.id AND (p.name LIKE :part_search1 OR p.notes LIKE :part_search2))';
            $tagSubquery = 'EXISTS (SELECT 1 FROM model_tags mt2 JOIN tags t2 ON mt2.tag_id = t2.id WHERE mt2.model_id = m.id AND t2.name LIKE :tag_search)';
            $catSubquery = 'EXISTS (SELECT 1 FROM model_categories mc2 JOIN categories c2 ON mc2.category_id = c2.id WHERE mc2.model_id = m.id AND c2.name LIKE :cat_search)';
            $where[] = "(m.name LIKE :search1 OR m.description LIKE :search2 OR m.creator LIKE :search3 OR m.notes LIKE :search4 OR $partSubquery OR $tagSubquery OR $catSubquery)";
            $params[':search1'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
            $params[':search4'] = $searchTerm;
            $params[':part_search1'] = $searchTerm;
            $params[':part_search2'] = $searchTerm;
            $params[':tag_search'] = $searchTerm;
            $params[':cat_search'] = $searchTerm;
        }
    }
}
