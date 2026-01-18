<?php
/**
 * AJAX endpoint for mass actions on models and parts
 */
require_once 'includes/config.php';
require_once 'includes/dedup.php';

header('Content-Type: application/json');

// Require appropriate permissions
if (!canEdit() && !canDelete()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$action = $_POST['action'] ?? '';
$ids = $_POST['ids'] ?? [];

if (empty($ids) || !is_array($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No items selected']);
    exit;
}

// Sanitize IDs
$ids = array_map('intval', $ids);
$ids = array_filter($ids, function($id) { return $id > 0; });

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid item IDs']);
    exit;
}

$db = getDB();

try {
    switch ($action) {
        case 'set_print_type':
            if (!canEdit()) {
                throw new Exception('Permission denied');
            }
            $printType = $_POST['print_type'] ?? '';
            if (!in_array($printType, ['fdm', 'sla', ''])) {
                throw new Exception('Invalid print type');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE models SET print_type = ? WHERE id IN ($placeholders)");
            $stmt->bindValue(1, $printType ?: null, $printType ? SQLITE3_TEXT : SQLITE3_NULL);
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, SQLITE3_INTEGER);
            }
            $stmt->execute();

            $affected = $db->changes();
            logInfo('Mass set print type', ['count' => $affected, 'print_type' => $printType]);
            echo json_encode(['success' => true, 'affected' => $affected, 'message' => "Updated $affected parts"]);
            break;

        case 'delete_parts':
            if (!canDelete()) {
                throw new Exception('Permission denied');
            }

            // Get parent IDs before deletion to update part counts
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT DISTINCT parent_id FROM models WHERE id IN ($placeholders) AND parent_id IS NOT NULL");
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $result = $stmt->execute();
            $parentIds = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $parentIds[] = $row['parent_id'];
            }

            // Delete the parts and their files
            foreach ($ids as $id) {
                $stmt = $db->prepare('SELECT file_path, dedup_path, parent_id FROM models WHERE id = ?');
                $stmt->bindValue(1, $id, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $part = $result->fetchArray(SQLITE3_ASSOC);

                if ($part) {
                    // Check if file can be deleted before removing from DB
                    $canDeleteDedup = !empty($part['dedup_path']) && canDeleteDedupFile($part['dedup_path']);

                    // Delete from database first
                    $stmt = $db->prepare('DELETE FROM models WHERE id = ?');
                    $stmt->bindValue(1, $id, SQLITE3_INTEGER);
                    $stmt->execute();

                    // Now delete the file
                    if (!empty($part['dedup_path'])) {
                        // Deduplicated file - only delete if no other references
                        if ($canDeleteDedup) {
                            $dedupPath = __DIR__ . '/' . $part['dedup_path'];
                            if (file_exists($dedupPath)) {
                                unlink($dedupPath);
                            }
                        }
                    } elseif ($part['file_path']) {
                        // Regular file
                        $filePath = __DIR__ . '/' . $part['file_path'];
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                }
            }

            // Update parent model part counts
            foreach ($parentIds as $parentId) {
                $stmt = $db->prepare('SELECT COUNT(*) as count FROM models WHERE parent_id = ?');
                $stmt->bindValue(1, $parentId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $count = $result->fetchArray(SQLITE3_ASSOC)['count'];

                $stmt = $db->prepare('UPDATE models SET part_count = ? WHERE id = ?');
                $stmt->bindValue(1, $count, SQLITE3_INTEGER);
                $stmt->bindValue(2, $parentId, SQLITE3_INTEGER);
                $stmt->execute();
            }

            $affected = count($ids);
            logInfo('Mass deleted parts', ['count' => $affected]);
            echo json_encode(['success' => true, 'affected' => $affected, 'message' => "Deleted $affected parts"]);
            break;

        case 'delete_models':
            if (!canDelete()) {
                throw new Exception('Permission denied');
            }

            foreach ($ids as $id) {
                // Get model info
                $stmt = $db->prepare('SELECT file_path, dedup_path, part_count FROM models WHERE id = ? AND parent_id IS NULL');
                $stmt->bindValue(1, $id, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $model = $result->fetchArray(SQLITE3_ASSOC);

                if (!$model) continue;

                $filesToDelete = [];
                $dedupFilesToCheck = [];

                // Collect files to delete from child parts
                $stmt = $db->prepare('SELECT file_path, dedup_path FROM models WHERE parent_id = ?');
                $stmt->bindValue(1, $id, SQLITE3_INTEGER);
                $result = $stmt->execute();
                while ($part = $result->fetchArray(SQLITE3_ASSOC)) {
                    if (!empty($part['dedup_path'])) {
                        $dedupFilesToCheck[$part['dedup_path']] = true;
                    } elseif ($part['file_path']) {
                        $filesToDelete[] = __DIR__ . '/' . $part['file_path'];
                    }
                }

                // Collect model's own file
                if (!empty($model['dedup_path'])) {
                    $dedupFilesToCheck[$model['dedup_path']] = true;
                } elseif ($model['file_path']) {
                    $filesToDelete[] = __DIR__ . '/' . $model['file_path'];
                }

                // Delete from database (cascade will handle children)
                $stmt = $db->prepare('DELETE FROM models WHERE id = ? OR parent_id = ?');
                $stmt->bindValue(1, $id, SQLITE3_INTEGER);
                $stmt->bindValue(2, $id, SQLITE3_INTEGER);
                $stmt->execute();

                // Delete category associations
                $stmt = $db->prepare('DELETE FROM model_categories WHERE model_id = ?');
                $stmt->bindValue(1, $id, SQLITE3_INTEGER);
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

                // Delete dedup files only if no other parts reference them
                foreach (array_keys($dedupFilesToCheck) as $dedupPath) {
                    if (canDeleteDedupFile($dedupPath)) {
                        $fullPath = __DIR__ . '/' . $dedupPath;
                        if (file_exists($fullPath)) {
                            unlink($fullPath);
                        }
                    }
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
            echo json_encode(['success' => true, 'affected' => $affected, 'message' => "Deleted $affected models"]);
            break;

        case 'set_collection':
            if (!canEdit()) {
                throw new Exception('Permission denied');
            }
            $collection = trim($_POST['collection'] ?? '');

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE models SET collection = ? WHERE id IN ($placeholders) AND parent_id IS NULL");
            $stmt->bindValue(1, $collection ?: null, $collection ? SQLITE3_TEXT : SQLITE3_NULL);
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, SQLITE3_INTEGER);
            }
            $stmt->execute();

            // Add to collections table if new
            if ($collection) {
                $stmt = $db->prepare('INSERT OR IGNORE INTO collections (name) VALUES (?)');
                $stmt->bindValue(1, $collection, SQLITE3_TEXT);
                $stmt->execute();
            }

            $affected = $db->changes();
            logInfo('Mass set collection', ['count' => $affected, 'collection' => $collection]);
            echo json_encode(['success' => true, 'affected' => $affected, 'message' => "Updated $affected models"]);
            break;

        case 'set_creator':
            if (!canEdit()) {
                throw new Exception('Permission denied');
            }
            $creator = trim($_POST['creator'] ?? '');

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE models SET creator = ? WHERE id IN ($placeholders) AND parent_id IS NULL");
            $stmt->bindValue(1, $creator ?: null, $creator ? SQLITE3_TEXT : SQLITE3_NULL);
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, SQLITE3_INTEGER);
            }
            $stmt->execute();

            $affected = $db->changes();
            logInfo('Mass set creator', ['count' => $affected, 'creator' => $creator]);
            echo json_encode(['success' => true, 'affected' => $affected, 'message' => "Updated $affected models"]);
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
                $stmt->bindValue(1, $id, SQLITE3_INTEGER);
                $stmt->bindValue(2, $categoryId, SQLITE3_INTEGER);
                $stmt->execute();
                $added += $db->changes();
            }

            logInfo('Mass added category', ['count' => $added, 'category_id' => $categoryId]);
            echo json_encode(['success' => true, 'affected' => $added, 'message' => "Added category to $added models"]);
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
            $stmt->bindValue(1, $categoryId, SQLITE3_INTEGER);
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 2, $id, SQLITE3_INTEGER);
            }
            $stmt->execute();

            $affected = $db->changes();
            logInfo('Mass removed category', ['count' => $affected, 'category_id' => $categoryId]);
            echo json_encode(['success' => true, 'affected' => $affected, 'message' => "Removed category from $affected models"]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    logException($e, ['action' => 'mass_action', 'attempted_action' => $action]);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
