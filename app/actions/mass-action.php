<?php
/**
 * AJAX endpoint for mass actions on models and parts
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dedup.php';

header('Content-Type: application/json');

// CSRF validation
requireCsrfJson();

// Require appropriate permissions
if (!canEdit() && !canDelete()) {
    jsonError('Permission denied', 403);
}

$action = $_POST['action'] ?? '';
$ids = $_POST['ids'] ?? [];

if (empty($ids) || !is_array($ids)) {
    jsonError('No items selected');
}

// Sanitize IDs
$ids = array_map('intval', $ids);
$ids = array_filter($ids, function($id) { return $id > 0; });

if (empty($ids)) {
    jsonError('Invalid item IDs', 400);
}

$db = getDB();

// For non-admin users, filter IDs to only models they own
$user = getCurrentUser();
if (!$user['is_admin']) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $filterStmt = $db->prepare("SELECT id FROM models WHERE id IN ($placeholders) AND (user_id = ? OR user_id IS NULL)");
    $filterParams = array_merge($ids, [(int)$user['id']]);
    foreach ($filterParams as $i => $v) {
        $filterStmt->bindValue($i + 1, $v);
    }
    $filterStmt->execute();
    $ids = array_column($filterStmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    if (empty($ids)) {
        echo json_encode(['success' => false, 'error' => 'No authorized models found']);
        exit;
    }
}

try {
    switch ($action) {
        case 'delete_parts':
            if (!canDelete()) {
                throw new Exception('Permission denied');
            }

            // Get parent IDs before deletion to update part counts
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT DISTINCT parent_id FROM models WHERE id IN ($placeholders) AND parent_id IS NOT NULL");
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
            }
            $result = $stmt->execute();
            $parentIds = [];
            while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $parentIds[] = $row['parent_id'];
            }

            // Delete the parts and their files
            foreach ($ids as $id) {
                $stmt = $db->prepare('SELECT file_path, dedup_path, parent_id FROM models WHERE id = ?');
                $stmt->bindValue(1, $id, PDO::PARAM_INT);
                $result = $stmt->execute();
                $part = $result->fetchArray(PDO::FETCH_ASSOC);

                if ($part) {
                    // Delete from database first
                    $stmt = $db->prepare('DELETE FROM models WHERE id = ?');
                    $stmt->bindValue(1, $id, PDO::PARAM_INT);
                    $stmt->execute();

                    // Now delete the file
                    if (!empty($part['dedup_path'])) {
                        // Deduplicated file - atomically check reference count and delete
                        deleteIfOrphaned($part['dedup_path']);
                    } elseif ($part['file_path']) {
                        // Regular file
                        $absPath = getAbsoluteFilePath($part);
                        if (file_exists($absPath)) {
                            unlink($absPath);
                        }
                    }
                }
            }

            // Update parent model part counts
            foreach ($parentIds as $parentId) {
                $stmt = $db->prepare('SELECT COUNT(*) as count FROM models WHERE parent_id = ?');
                $stmt->bindValue(1, $parentId, PDO::PARAM_INT);
                $result = $stmt->execute();
                $count = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

                $stmt = $db->prepare('UPDATE models SET part_count = ? WHERE id = ?');
                $stmt->bindValue(1, $count, PDO::PARAM_INT);
                $stmt->bindValue(2, $parentId, PDO::PARAM_INT);
                $stmt->execute();
            }

            $affected = count($ids);
            logInfo('Mass deleted parts', ['count' => $affected]);
            jsonSuccess(['affected' => $affected, 'message' => "Deleted $affected parts"]);
            break;

        case 'delete_models':
            if (!canDelete()) {
                throw new Exception('Permission denied');
            }

            foreach ($ids as $id) {
                // Get model info
                $stmt = $db->prepare('SELECT file_path, dedup_path, part_count FROM models WHERE id = ? AND parent_id IS NULL');
                $stmt->bindValue(1, $id, PDO::PARAM_INT);
                $result = $stmt->execute();
                $model = $result->fetchArray(PDO::FETCH_ASSOC);

                if (!$model) continue;

                $filesToDelete = [];
                $dedupFilesToCheck = [];

                // Collect files to delete from child parts
                $stmt = $db->prepare('SELECT file_path, dedup_path FROM models WHERE parent_id = ?');
                $stmt->bindValue(1, $id, PDO::PARAM_INT);
                $result = $stmt->execute();
                while ($part = $result->fetchArray(PDO::FETCH_ASSOC)) {
                    if (!empty($part['dedup_path'])) {
                        $dedupFilesToCheck[$part['dedup_path']] = true;
                    } elseif ($part['file_path']) {
                        $filesToDelete[] = getAbsoluteFilePath($part);
                    }
                }

                // Collect model's own file
                if (!empty($model['dedup_path'])) {
                    $dedupFilesToCheck[$model['dedup_path']] = true;
                } elseif ($model['file_path']) {
                    $filesToDelete[] = getAbsoluteFilePath($model);
                }

                // Delete from database (cascade will handle children)
                $stmt = $db->prepare('DELETE FROM models WHERE id = ? OR parent_id = ?');
                $stmt->bindValue(1, $id, PDO::PARAM_INT);
                $stmt->bindValue(2, $id, PDO::PARAM_INT);
                $stmt->execute();

                // Delete category associations
                $stmt = $db->prepare('DELETE FROM model_categories WHERE model_id = ?');
                $stmt->bindValue(1, $id, PDO::PARAM_INT);
                $stmt->execute();

                // Now delete regular files
                $foldersToCheck = [];
                foreach ($filesToDelete as $filePath) {
                    if (file_exists($filePath)) {
                        unlink($filePath);
                        $folder = dirname($filePath);
                        if (!in_array($folder, $foldersToCheck)) {
                            $foldersToCheck[] = $folder;
                        }
                    }
                }

                // Delete dedup files only if no other parts reference them (atomic check+delete)
                foreach (array_keys($dedupFilesToCheck) as $dedupPath) {
                    deleteIfOrphaned($dedupPath);
                }

                // Clean up empty folders
                foreach ($foldersToCheck as $folder) {
                    if (is_dir($folder) && count(scandir($folder)) === 2) {
                        @rmdir($folder);
                    }
                }
            }

            $affected = count($ids);
            logInfo('Mass deleted models', ['count' => $affected]);
            jsonSuccess(['affected' => $affected, 'message' => "Deleted $affected models"]);
            break;

        case 'set_collection':
            if (!canEdit()) {
                throw new Exception('Permission denied');
            }
            $collection = trim($_POST['collection'] ?? '');

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE models SET collection = ? WHERE id IN ($placeholders) AND parent_id IS NULL");
            $stmt->bindValue(1, $collection ?: null, $collection ? PDO::PARAM_STR : PDO::PARAM_NULL);
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, PDO::PARAM_INT);
            }
            $stmt->execute();

            // Add to collections table if new
            if ($collection) {
                $stmt = $db->prepare('INSERT OR IGNORE INTO collections (name) VALUES (?)');
                $stmt->bindValue(1, $collection, PDO::PARAM_STR);
                $stmt->execute();
            }

            $affected = $db->changes();
            logInfo('Mass set collection', ['count' => $affected, 'collection' => $collection]);
            jsonSuccess(['affected' => $affected, 'message' => "Updated $affected models"]);
            break;

        case 'set_creator':
            if (!canEdit()) {
                throw new Exception('Permission denied');
            }
            $creator = trim($_POST['creator'] ?? '');

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE models SET creator = ? WHERE id IN ($placeholders) AND parent_id IS NULL");
            $stmt->bindValue(1, $creator ?: null, $creator ? PDO::PARAM_STR : PDO::PARAM_NULL);
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, PDO::PARAM_INT);
            }
            $stmt->execute();

            $affected = $db->changes();
            logInfo('Mass set creator', ['count' => $affected, 'creator' => $creator]);
            jsonSuccess(['affected' => $affected, 'message' => "Updated $affected models"]);
            break;

        case 'add_category':
            if (!canEdit()) {
                throw new Exception('Permission denied');
            }
            $categoryId = (int)($_POST['category_id'] ?? 0);
            if (!$categoryId) {
                throw new Exception('Invalid category');
            }

            $added = 0;
            foreach ($ids as $id) {
                $stmt = $db->prepare('INSERT OR IGNORE INTO model_categories (model_id, category_id) VALUES (?, ?)');
                $stmt->bindValue(1, $id, PDO::PARAM_INT);
                $stmt->bindValue(2, $categoryId, PDO::PARAM_INT);
                $stmt->execute();
                $added += $db->changes();
            }

            logInfo('Mass added category', ['count' => $added, 'category_id' => $categoryId]);
            jsonSuccess(['affected' => $added, 'message' => "Added category to $added models"]);
            break;

        case 'remove_category':
            if (!canEdit()) {
                throw new Exception('Permission denied');
            }
            $categoryId = (int)($_POST['category_id'] ?? 0);
            if (!$categoryId) {
                throw new Exception('Invalid category');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM model_categories WHERE category_id = ? AND model_id IN ($placeholders)");
            $stmt->bindValue(1, $categoryId, PDO::PARAM_INT);
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, PDO::PARAM_INT);
            }
            $stmt->execute();

            $affected = $db->changes();
            logInfo('Mass removed category', ['count' => $affected, 'category_id' => $categoryId]);
            jsonSuccess(['affected' => $affected, 'message' => "Removed category from $affected models"]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    logException($e, ['action' => 'mass_action', 'attempted_action' => $action]);
    http_response_code(400);
    jsonError($e->getMessage());
}
