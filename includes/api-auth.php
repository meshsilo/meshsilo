<?php

/**
 * API Authentication System
 */

// Permission constants for API keys
define('API_PERM_READ', 'read');
define('API_PERM_WRITE', 'write');
define('API_PERM_DELETE', 'delete');
define('API_PERM_ADMIN', 'admin');

/**
 * Authenticate an API request
 * Returns the API key record with user info, or null if invalid
 */
function authenticateApiRequest()
{
    // Get API key from header only (never query params - they leak in logs/referrer)
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    // Strip "Bearer " prefix if using Authorization header
    if ($apiKey && str_starts_with($apiKey, 'Bearer ')) {
        $apiKey = substr($apiKey, 7);
    }

    if (!$apiKey) {
        return null;
    }

    // Validate the API key
    $keyRecord = validateApiKey($apiKey);
    if (!$keyRecord) {
        return null;
    }

    // Update last used timestamp
    updateApiKeyLastUsed($keyRecord['id']);

    return $keyRecord;
}

/**
 * Validate an API key and return its record
 */
function validateApiKey($key)
{
    $db = getDB();

    // Hash the key for lookup
    $keyHash = hash('sha256', $key);

    $stmt = $db->prepare('
        SELECT ak.*, u.username, u.email, u.is_admin
        FROM api_keys ak
        JOIN users u ON ak.user_id = u.id
        WHERE ak.key_hash = :key_hash
        AND ak.is_active = 1
        AND (ak.expires_at IS NULL OR ak.expires_at > CURRENT_TIMESTAMP)
    ');
    $stmt->execute([':key_hash' => $keyHash]);
    $record = $stmt->fetch();

    if ($record) {
        $record['permissions_array'] = json_decode($record['permissions'], true) ?: [];
    }

    return $record ?: null;
}

/**
 * Check if API key has a specific permission
 */
function apiHasPermission($apiUser, $permission)
{
    // Admin API keys have all permissions
    if (in_array(API_PERM_ADMIN, $apiUser['permissions_array'])) {
        return true;
    }

    // User admins also have all permissions
    if ($apiUser['is_admin']) {
        return true;
    }

    return in_array($permission, $apiUser['permissions_array']);
}

/**
 * Require an API permission or return error
 */
function requireApiPermission($apiUser, $permission)
{
    if (!apiHasPermission($apiUser, $permission)) {
        apiError('Permission denied. This API key does not have ' . $permission . ' access.', 403);
    }
}

/**
 * Generate a new API key
 */
function generateApiKey($userId, $name, $permissions = [API_PERM_READ], $expiresAt = null)
{
    $db = getDB();

    // Generate a secure random key
    $key = 'silo_' . bin2hex(random_bytes(32));
    $keyHash = hash('sha256', $key);
    $keyPrefix = substr($key, 0, 12);

    $stmt = $db->prepare('
        INSERT INTO api_keys (user_id, name, key_hash, key_prefix, permissions, expires_at)
        VALUES (:user_id, :name, :key_hash, :key_prefix, :permissions, :expires_at)
    ');
    $stmt->execute([
        ':user_id' => $userId,
        ':name' => $name,
        ':key_hash' => $keyHash,
        ':key_prefix' => $keyPrefix,
        ':permissions' => json_encode($permissions),
        ':expires_at' => $expiresAt
    ]);

    $keyId = $db->lastInsertId();

    logActivity('api_key_created', 'api_key', $keyId, $name);

    // Return the full key only once - it cannot be retrieved later
    return [
        'id' => $keyId,
        'key' => $key,
        'prefix' => $keyPrefix,
        'name' => $name,
        'permissions' => $permissions,
        'expires_at' => $expiresAt
    ];
}

/**
 * Revoke an API key
 */
function revokeApiKey($keyId, $userId = null)
{
    $db = getDB();

    $sql = 'UPDATE api_keys SET is_active = 0 WHERE id = :id';
    $params = [':id' => $keyId];

    // If user ID provided, ensure they own the key
    if ($userId !== null) {
        $sql .= ' AND user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    logActivity('api_key_revoked', 'api_key', $keyId);

    return $db->changes() > 0;
}

/**
 * Update API key last used timestamp
 */
function updateApiKeyLastUsed($keyId)
{
    $db = getDB();
    $type = $db->getType();

    if ($type === 'mysql') {
        $stmt = $db->prepare('UPDATE api_keys SET last_used_at = NOW(), request_count = request_count + 1 WHERE id = :id');
    } else {
        $stmt = $db->prepare('UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP, request_count = request_count + 1 WHERE id = :id');
    }
    $stmt->execute([':id' => $keyId]);
}

/**
 * Get all API keys for a user
 */
function getUserApiKeys($userId)
{
    $db = getDB();
    $stmt = $db->prepare('
        SELECT id, name, key_prefix, permissions, is_active, expires_at, last_used_at, request_count, created_at
        FROM api_keys
        WHERE user_id = :user_id
        ORDER BY created_at DESC
    ');
    $stmt->execute([':user_id' => $userId]);

    $keys = [];
    while ($row = $stmt->fetch()) {
        $row['permissions_array'] = json_decode($row['permissions'], true) ?: [];
        $keys[] = $row;
    }
    return $keys;
}

/**
 * Get all API keys (admin)
 */
function getAllApiKeys()
{
    $db = getDB();
    $result = $db->query('
        SELECT ak.id, ak.name, ak.key_prefix, ak.permissions, ak.is_active,
               ak.expires_at, ak.last_used_at, ak.request_count, ak.created_at,
               u.username, u.email
        FROM api_keys ak
        JOIN users u ON ak.user_id = u.id
        ORDER BY ak.created_at DESC
    ');

    $keys = [];
    while ($row = $result->fetch()) {
        $row['permissions_array'] = json_decode($row['permissions'], true) ?: [];
        $keys[] = $row;
    }
    return $keys;
}

/**
 * Log an API request
 */
function logApiRequest($apiKeyId, $method, $endpoint)
{
    $db = getDB();

    $stmt = $db->prepare('
        INSERT INTO api_request_log (api_key_id, method, endpoint, ip_address, user_agent)
        VALUES (:api_key_id, :method, :endpoint, :ip_address, :user_agent)
    ');
    $stmt->execute([
        ':api_key_id' => $apiKeyId,
        ':method' => $method,
        ':endpoint' => $endpoint,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

/**
 * Get API request statistics
 */
function getApiRequestStats($apiKeyId = null, $days = 30)
{
    $db = getDB();
    $type = $db->getType();

    $where = $apiKeyId ? 'WHERE api_key_id = :api_key_id' : '';
    $params = $apiKeyId ? [':api_key_id' => $apiKeyId] : [];

    if ($type === 'mysql') {
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM api_request_log
            $where
            AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $params[':days'] = $days;
    } else {
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM api_request_log
            $where
            AND created_at > datetime('now', '-' || :days || ' days')
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $params[':days'] = $days;
    }
    $stmt->execute($params);

    $stats = [];
    while ($row = $stmt->fetch()) {
        $stats[] = $row;
    }
    return $stats;
}
