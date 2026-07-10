<?php

/**
 * ModelPageData
 *
 * Assembles the read-only data used by the model detail page (app/pages/model.php).
 * Extracted verbatim from that page's inline data-gathering block so the page
 * stays a thin controller + template. Behaviour is intentionally identical:
 * same SQL, same helper calls, same result shape.
 *
 * Control flow and side effects (model fetch, not-found/child redirects,
 * recordModelView(), session messages, meta tags) remain in the page. This
 * class only performs read-only queries for an already-validated parent model.
 */
class ModelPageData
{
    /**
     * Gather all supplementary data for a resolved parent model row.
     *
     * @param array $model The already-fetched model row (must contain id,
     *                      part_count, file_size, file_type, original_size, user_id).
     * @return array Associative array of template variables.
     */
    public static function gather(array $model): array
    {
        $db = getDB();
        $modelId = (int)$model['id'];

        // Get tags for this model
        $modelTags = getModelTags($modelId);

        // Check if favorited (single query for both status and count)
        $favInfo = getModelFavoriteInfo($modelId);
        $isFavorited = $favInfo['is_favorited'];
        $favoriteCount = $favInfo['count'];

        // Get categories for this model
        $categories = [];
        try {
            $stmt = $db->prepare('
                SELECT c.* FROM categories c
                JOIN model_categories mc ON c.id = mc.category_id
                WHERE mc.model_id = :model_id
                ORDER BY c.name
            ');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $categories[] = $row;
            }
        } catch (Exception $e) {
            logError('Failed to load model categories', ['model_id' => $modelId, 'error' => $e->getMessage()]);
        }

        // Get related models
        $relatedModels = getRelatedModels($modelId);

        // Get parts if this is a multi-part model
        $parts = [];
        $previewPath = null;
        $previewType = null;

        if ($model['part_count'] > 0) {
            $stmt = $db->prepare('
                SELECT id, name, filename, file_path, file_size, file_type, print_type, original_size, file_hash, dedup_path, original_path, sort_order, notes, parent_id
                FROM models
                WHERE parent_id = :parent_id
                ORDER BY sort_order ASC, original_path ASC
            ');
            $stmt->bindValue(':parent_id', $modelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $parts[] = $row;
            }
            // Use first part for preview via preview endpoint
            if (!empty($parts)) {
                $previewPath = '/preview?id=' . $parts[0]['id'];
                $previewType = $parts[0]['file_type'];
            }
        } else {
            // Single model - use preview endpoint
            $previewPath = '/preview?id=' . $model['id'];
            $previewType = $model['file_type'];
        }

        // Calculate total model size, conversion savings, and potential savings
        $totalModelSize = 0;
        $stlTotalSize = 0;
        $actualSaved = 0;

        if (!empty($parts)) {
            foreach ($parts as $part) {
                $totalModelSize += ($part['file_size'] ?? 0);
                if (($part['file_type'] ?? '') === 'stl') {
                    $stlTotalSize += ($part['file_size'] ?? 0);
                }
                // Track actual savings from already-converted parts
                if (!empty($part['original_size']) && $part['file_type'] === '3mf') {
                    $actualSaved += ($part['original_size'] - $part['file_size']);
                }
            }
        } else {
            $totalModelSize = $model['file_size'] ?? 0;
            if (($model['file_type'] ?? '') === 'stl') {
                $stlTotalSize = $model['file_size'] ?? 0;
            }
            if (!empty($model['original_size']) && $model['file_type'] === '3mf') {
                $actualSaved = $model['original_size'] - $model['file_size'];
            }
        }

        // Estimate 3MF conversion savings (~65% compression on STL data)
        $estimatedSavings = ($stlTotalSize > 0) ? (int)($stlTotalSize * 0.65) : 0;

        // Group parts by directory with relative display names
        $groupedParts = self::groupPartsByDirectory($parts);

        // Get version history
        $versions = [];
        $versionCount = 0;
        try {
            $stmt = $db->prepare('
                SELECT mv.*, u.username as created_by_name
                FROM model_versions mv
                LEFT JOIN users u ON mv.created_by = u.id
                WHERE mv.model_id = :model_id
                ORDER BY mv.version_number DESC
            ');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $versions[] = $row;
            }
            $versionCount = count($versions);
        } catch (Throwable $e) {
            // model_versions table may not exist yet
        }

        $canManageVersions = false;
        if (isLoggedIn()) {
            $vUser = getCurrentUser();
            $canManageVersions = (!empty($model['user_id']) && $model['user_id'] == $vUser['id']) || !empty($vUser['is_admin']) || canEdit();
        }

        // Get external links
        $modelLinks = [];
        try {
            $stmt = $db->prepare('SELECT id, model_id, title, url, link_type, sort_order, created_at FROM model_links WHERE model_id = :model_id ORDER BY sort_order, created_at');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $modelLinks[] = $row;
            }
        } catch (Throwable $e) {
            // model_links table may not exist yet
        }

        // Get model attachments (images, PDFs, text files)
        $attachments = ['images' => [], 'documents' => []];
        try {
            $stmt = $db->prepare('SELECT id, model_id, filename, original_filename, file_path, file_type, file_size, display_order, created_at FROM model_attachments WHERE model_id = :model_id ORDER BY display_order, created_at');
            $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                if ($row['file_type'] === 'image') {
                    $attachments['images'][] = $row;
                } else {
                    $attachments['documents'][] = $row;
                }
            }
        } catch (Throwable $e) {
            logError('Failed to load attachments', ['model_id' => $modelId, 'error' => $e->getMessage()]);
        }

        return [
            'modelTags' => $modelTags,
            'isFavorited' => $isFavorited,
            'favoriteCount' => $favoriteCount,
            'categories' => $categories,
            'relatedModels' => $relatedModels,
            'parts' => $parts,
            'previewPath' => $previewPath,
            'previewType' => $previewType,
            'totalModelSize' => $totalModelSize,
            'stlTotalSize' => $stlTotalSize,
            'actualSaved' => $actualSaved,
            'estimatedSavings' => $estimatedSavings,
            'groupedParts' => $groupedParts,
            'versions' => $versions,
            'versionCount' => $versionCount,
            'canManageVersions' => $canManageVersions,
            'modelLinks' => $modelLinks,
            'attachments' => $attachments,
        ];
    }

    /**
     * Group parts by directory with relative display names.
     * Moved verbatim from app/pages/model.php.
     */
    private static function groupPartsByDirectory(array $partsArray): array
    {
        $grouped = [];
        $dirs = [];

        // First pass: collect all directories
        foreach ($partsArray as $part) {
            $path = $part['original_path'] ?? $part['name'];
            // Normalize path separators
            $path = str_replace('\\', '/', $path);
            $dir = dirname($path);
            if ($dir === '.') {
                $dir = 'Root';
            }
            $dirs[$dir] = true;
        }

        // Find common base path (excluding 'Root')
        $realDirs = [];
        foreach (array_keys($dirs) as $d) {
            if ($d !== 'Root') {
                $realDirs[] = $d;
            }
        }

        $basePath = '';
        if (count($realDirs) > 0) {
            $pathSegments = [];
            foreach ($realDirs as $d) {
                $pathSegments[] = explode('/', $d);
            }
            $baseParts = [];
            if (count($pathSegments) > 0) {
                $lengths = array_map('count', $pathSegments);
                $minLen = min($lengths);
                for ($i = 0; $i < $minLen; $i++) {
                    $segment = $pathSegments[0][$i];
                    $allMatch = true;
                    foreach ($pathSegments as $ps) {
                        if ($ps[$i] !== $segment) {
                            $allMatch = false;
                            break;
                        }
                    }
                    if ($allMatch) {
                        $baseParts[] = $segment;
                    } else {
                        break;
                    }
                }
            }
            $basePath = implode('/', $baseParts);
        }

        // Second pass: group with relative names
        foreach ($partsArray as $part) {
            $path = $part['original_path'] ?? $part['name'];
            $path = str_replace('\\', '/', $path);
            $dir = dirname($path);
            if ($dir === '.') {
                $dir = 'Root';
                $displayDir = 'Root';
            } else {
                // Strip common base path for display
                $displayDir = $dir;
                if ($basePath && strpos($dir, $basePath) === 0) {
                    $displayDir = substr($dir, strlen($basePath));
                    $displayDir = ltrim($displayDir, '/');
                    if ($displayDir === '') {
                        $displayDir = basename($dir);
                    }
                }
            }

            if (!isset($grouped[$dir])) {
                $grouped[$dir] = ['display' => $displayDir, 'parts' => []];
            }
            $grouped[$dir]['parts'][] = $part;
        }

        return $grouped;
    }
}
