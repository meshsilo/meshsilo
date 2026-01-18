<?php
// Permission constants
define('PERM_UPLOAD', 'upload');
define('PERM_DELETE', 'delete');
define('PERM_EDIT', 'edit');
define('PERM_ADMIN', 'admin');
define('PERM_VIEW_STATS', 'view_stats');

// Default permissions for regular users
define('DEFAULT_USER_PERMISSIONS', [PERM_UPLOAD, PERM_VIEW_STATS]);

// Admin has all permissions
define('ADMIN_PERMISSIONS', [PERM_UPLOAD, PERM_DELETE, PERM_EDIT, PERM_ADMIN, PERM_VIEW_STATS]);

/**
 * Check if current user has a specific permission
 */
function hasPermission($permission) {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }

    // Admins have all permissions
    if ($user['is_admin']) {
        return true;
    }

    // Check user's specific permissions
    $userPermissions = getUserPermissions($user['id']);
    return in_array($permission, $userPermissions);
}

/**
 * Get all permissions for a user
 */
function getUserPermissions($userId) {
    $db = getDB();

    // Check if user is admin
    $stmt = $db->prepare('SELECT is_admin, permissions FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    if (!$user) {
        return [];
    }

    // Admins get all permissions
    if ($user['is_admin']) {
        return ADMIN_PERMISSIONS;
    }

    // Parse stored permissions or return defaults
    if (!empty($user['permissions'])) {
        return json_decode($user['permissions'], true) ?: DEFAULT_USER_PERMISSIONS;
    }

    return DEFAULT_USER_PERMISSIONS;
}

/**
 * Set permissions for a user
 */
function setUserPermissions($userId, $permissions) {
    $db = getDB();
    $stmt = $db->prepare('UPDATE users SET permissions = :permissions WHERE id = :id');
    $stmt->bindValue(':permissions', json_encode($permissions), SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    return $stmt->execute();
}

/**
 * Require a permission - redirect if not authorized
 */
function requirePermission($permission, $redirectUrl = 'index.php') {
    if (!hasPermission($permission)) {
        logWarning('Permission denied', [
            'user_id' => $_SESSION['user_id'] ?? null,
            'permission' => $permission,
            'page' => $_SERVER['PHP_SELF']
        ]);
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: ' . $redirectUrl);
        exit;
    }
}

/**
 * Check if user can upload models
 */
function canUpload() {
    return hasPermission(PERM_UPLOAD);
}

/**
 * Check if user can delete models
 */
function canDelete() {
    return hasPermission(PERM_DELETE);
}

/**
 * Check if user can edit models
 */
function canEdit() {
    return hasPermission(PERM_EDIT);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return hasPermission(PERM_ADMIN);
}

/**
 * Check if user can view stats
 */
function canViewStats() {
    return hasPermission(PERM_VIEW_STATS);
}

/**
 * Get list of all available permissions with descriptions
 */
function getAllPermissions() {
    return [
        PERM_UPLOAD => 'Upload new models',
        PERM_DELETE => 'Delete models',
        PERM_EDIT => 'Edit model details',
        PERM_VIEW_STATS => 'View statistics',
        PERM_ADMIN => 'Full admin access'
    ];
}
