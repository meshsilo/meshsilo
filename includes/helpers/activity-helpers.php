<?php
// Activity log and recently viewed helper functions
// =====================
// Activity Log Functions
// =====================

// Log an activity
function logActivity($action, $entityType, $entityId = null, $entityName = null, $details = null)
{
    if (getSetting('enable_activity_log', '1') !== '1') {
        return true;
    }

    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $db->prepare('
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, entity_name, details, ip_address)
            VALUES (:user_id, :action, :entity_type, :entity_id, :entity_name, :details, :ip_address)
        ');
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':entity_name' => $entityName,
            ':details' => is_array($details) ? json_encode($details) : $details,
            ':ip_address' => $ipAddress
        ]);

        // Fire plugin action hook for every activity event
        if (class_exists('PluginManager')) {
            PluginManager::doAction('activity:' . $action, [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_name' => $entityName,
                'details' => $details,
                'user_id' => $userId,
            ]);
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get activity log entries
function getActivityLog($limit = 50, $offset = 0, $filters = [])
{
    try {
        $db = getDB();
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'al.action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $params[':entity_type'] = $filters['entity_type'];
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT al.*, u.username
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE $whereClause
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);

        $activities = [];
        while ($row = $stmt->fetch()) {
            $activities[] = $row;
        }
        return $activities;
    } catch (Exception $e) {
        return [];
    }
}

// Clean old activity log entries
function cleanActivityLog()
{
    $retentionDays = (int)getSetting('activity_log_retention_days', '90');
    if ($retentionDays <= 0) {
        return true;
    }

    try {
        $db = getDB();
        $type = $db->getType();

        if ($type === 'mysql') {
            $stmt = $db->prepare('DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        } else {
            $stmt = $db->prepare("DELETE FROM activity_log WHERE created_at < datetime('now', '-' || :days || ' days')");
        }
        $stmt->execute([':days' => $retentionDays]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// =====================
// Recently Viewed Functions
// =====================

// Record a model view
function recordModelView($modelId)
{
    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $sessionId = session_id();

        // Delete existing entry for this model (user or session based)
        if ($userId) {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE model_id = :model_id AND user_id = :user_id');
            $stmt->execute([':model_id' => $modelId, ':user_id' => $userId]);
        } else {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE model_id = :model_id AND session_id = :session_id');
            $stmt->execute([':model_id' => $modelId, ':session_id' => $sessionId]);
        }

        // Insert new entry
        $stmt = $db->prepare('
            INSERT INTO recently_viewed (user_id, session_id, model_id)
            VALUES (:user_id, :session_id, :model_id)
        ');
        $stmt->execute([
            ':user_id' => $userId,
            ':session_id' => $sessionId,
            ':model_id' => $modelId
        ]);

        // Limit to 50 most recent per user/session using parameterized queries
        if ($userId) {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE user_id = :user_id AND id NOT IN (SELECT id FROM (SELECT id FROM recently_viewed WHERE user_id = :user_id2 ORDER BY viewed_at DESC LIMIT 50) AS keep)');
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id2', $userId, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('DELETE FROM recently_viewed WHERE session_id = :session_id AND id NOT IN (SELECT id FROM (SELECT id FROM recently_viewed WHERE session_id = :session_id2 ORDER BY viewed_at DESC LIMIT 50) AS keep)');
            $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_STR);
            $stmt->bindValue(':session_id2', $sessionId, PDO::PARAM_STR);
            $stmt->execute();
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get recently viewed models
function getRecentlyViewed($limit = 10)
{
    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user ? $user['id'] : null;
        $sessionId = session_id();

        if ($userId) {
            $stmt = $db->prepare('
                SELECT m.* FROM models m
                JOIN recently_viewed rv ON m.id = rv.model_id
                WHERE rv.user_id = :user_id AND m.parent_id IS NULL AND m.is_archived = 0
                ORDER BY rv.viewed_at DESC
                LIMIT :limit
            ');
            $stmt->execute([':user_id' => $userId, ':limit' => $limit]);
        } else {
            $stmt = $db->prepare('
                SELECT m.* FROM models m
                JOIN recently_viewed rv ON m.id = rv.model_id
                WHERE rv.session_id = :session_id AND m.parent_id IS NULL AND m.is_archived = 0
                ORDER BY rv.viewed_at DESC
                LIMIT :limit
            ');
            $stmt->execute([':session_id' => $sessionId, ':limit' => $limit]);
        }

        $models = [];
        while ($row = $stmt->fetch()) {
            $models[] = $row;
        }
        return $models;
    } catch (Exception $e) {
        return [];
    }
}
