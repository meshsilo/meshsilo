<?php
// Favorite/bookmark helper functions
// =====================
// Favorites Functions
// =====================

// Check if model is favorited by user
function isModelFavorited($modelId, $userId = null)
{
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) {
        return false;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM favorites WHERE model_id = :model_id AND user_id = :user_id');
        $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Toggle favorite status
function toggleFavorite($modelId, $userId = null)
{
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) {
        return ['success' => false, 'error' => 'Not logged in'];
    }

    try {
        $db = getDB();

        if (isModelFavorited($modelId, $userId)) {
            $stmt = $db->prepare('DELETE FROM favorites WHERE model_id = :model_id AND user_id = :user_id');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
            return ['success' => true, 'favorited' => false];
        } else {
            $stmt = $db->prepare('INSERT INTO favorites (model_id, user_id) VALUES (:model_id, :user_id)');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
            return ['success' => true, 'favorited' => true];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Get user's favorites
function getUserFavorites($userId = null, $limit = 50)
{
    if (!$userId) {
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
    }
    if (!$userId) {
        return [];
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('
            SELECT m.* FROM models m
            JOIN favorites f ON m.id = f.model_id
            WHERE f.user_id = :user_id AND m.parent_id IS NULL AND m.is_archived = 0
            ORDER BY f.created_at DESC
            LIMIT :limit
        ');
        $stmt->execute([':user_id' => $userId, ':limit' => $limit]);
        $favorites = [];
        while ($row = $stmt->fetch()) {
            $favorites[] = $row;
        }
        return $favorites;
    } catch (Exception $e) {
        return [];
    }
}

// Get favorite count for a model
function getModelFavoriteCount($modelId)
{
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) FROM favorites WHERE model_id = :model_id');
        $stmt->execute([':model_id' => $modelId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
