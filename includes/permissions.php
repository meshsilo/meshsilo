<?php
// Permission constants - Basic
define('PERM_UPLOAD', 'upload');
define('PERM_DELETE', 'delete');
define('PERM_EDIT', 'edit');
define('PERM_VIEW_STATS', 'view_stats');

// Permission constants - Features
define('PERM_CONVERT', 'convert');          // Convert STL to 3MF

// Permission constants - Admin functions
define('PERM_MANAGE_USERS', 'manage_users');
define('PERM_MANAGE_GROUPS', 'manage_groups');
define('PERM_MANAGE_CATEGORIES', 'manage_categories');
define('PERM_MANAGE_COLLECTIONS', 'manage_collections');
define('PERM_MANAGE_SETTINGS', 'manage_settings');
define('PERM_VIEW_LOGS', 'view_logs');
define('PERM_ADMIN', 'admin');              // Full admin (all permissions)

// Default permissions for regular users
define('DEFAULT_USER_PERMISSIONS', [PERM_UPLOAD, PERM_VIEW_STATS, PERM_CONVERT]);

// Admin has all permissions
define('ADMIN_PERMISSIONS', [
    PERM_UPLOAD, PERM_DELETE, PERM_EDIT, PERM_VIEW_STATS, PERM_CONVERT,
    PERM_MANAGE_USERS, PERM_MANAGE_GROUPS, PERM_MANAGE_CATEGORIES,
    PERM_MANAGE_COLLECTIONS, PERM_MANAGE_SETTINGS, PERM_VIEW_LOGS, PERM_ADMIN
]);

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

    // Check user's permissions (includes group permissions)
    $userPermissions = getUserPermissions($user['id']);
    return in_array($permission, $userPermissions);
}

/**
 * Get all permissions for a user (combining direct and group permissions)
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

    $permissions = [];

    // Get permissions from user's groups
    $stmt = $db->prepare('
        SELECT g.permissions
        FROM groups g
        JOIN user_groups ug ON g.id = ug.group_id
        WHERE ug.user_id = :user_id
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['permissions']) {
            $groupPerms = json_decode($row['permissions'], true);
            if (is_array($groupPerms)) {
                $permissions = array_merge($permissions, $groupPerms);
            }
        }
    }

    // Add direct user permissions if set
    if (!empty($user['permissions'])) {
        $userPerms = json_decode($user['permissions'], true);
        if (is_array($userPerms)) {
            $permissions = array_merge($permissions, $userPerms);
        }
    }

    // If no permissions from groups or user, use defaults
    if (empty($permissions)) {
        return DEFAULT_USER_PERMISSIONS;
    }

    return array_unique($permissions);
}

/**
 * Get user's groups
 */
function getUserGroups($userId) {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT g.*
        FROM groups g
        JOIN user_groups ug ON g.id = ug.group_id
        WHERE ug.user_id = :user_id
        ORDER BY g.name
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $groups = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $groups[] = $row;
    }
    return $groups;
}

/**
 * Add user to a group
 */
function addUserToGroup($userId, $groupId) {
    $db = getDB();
    try {
        $stmt = $db->prepare('INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
        return $stmt->execute();
    } catch (Exception $e) {
        logException($e, ['action' => 'add_user_to_group', 'user_id' => $userId, 'group_id' => $groupId]);
        return false;
    }
}

/**
 * Remove user from a group
 */
function removeUserFromGroup($userId, $groupId) {
    $db = getDB();
    try {
        $stmt = $db->prepare('DELETE FROM user_groups WHERE user_id = :user_id AND group_id = :group_id');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
        return $stmt->execute();
    } catch (Exception $e) {
        logException($e, ['action' => 'remove_user_from_group', 'user_id' => $userId, 'group_id' => $groupId]);
        return false;
    }
}

/**
 * Get all groups
 */
function getAllGroups() {
    $db = getDB();
    $result = $db->query('SELECT * FROM groups ORDER BY is_system DESC, name ASC');
    $groups = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['permissions_array'] = $row['permissions'] ? json_decode($row['permissions'], true) : [];
        $groups[] = $row;
    }
    return $groups;
}

/**
 * Get a single group by ID
 */
function getGroup($groupId) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM groups WHERE id = :id');
    $stmt->bindValue(':id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $group = $result->fetchArray(SQLITE3_ASSOC);
    if ($group) {
        $group['permissions_array'] = $group['permissions'] ? json_decode($group['permissions'], true) : [];
    }
    return $group;
}

/**
 * Create a new group
 */
function createGroup($name, $description, $permissions) {
    $db = getDB();
    try {
        $stmt = $db->prepare('INSERT INTO groups (name, description, permissions) VALUES (:name, :description, :permissions)');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':description', $description, SQLITE3_TEXT);
        $stmt->bindValue(':permissions', json_encode($permissions), SQLITE3_TEXT);
        $stmt->execute();
        return $db->lastInsertRowID();
    } catch (Exception $e) {
        logException($e, ['action' => 'create_group', 'name' => $name]);
        return false;
    }
}

/**
 * Update a group
 */
function updateGroup($groupId, $name, $description, $permissions) {
    $db = getDB();
    try {
        $stmt = $db->prepare('UPDATE groups SET name = :name, description = :description, permissions = :permissions WHERE id = :id AND is_system = 0');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':description', $description, SQLITE3_TEXT);
        $stmt->bindValue(':permissions', json_encode($permissions), SQLITE3_TEXT);
        $stmt->bindValue(':id', $groupId, SQLITE3_INTEGER);
        return $stmt->execute();
    } catch (Exception $e) {
        logException($e, ['action' => 'update_group', 'group_id' => $groupId]);
        return false;
    }
}

/**
 * Update system group permissions only
 */
function updateSystemGroupPermissions($groupId, $permissions) {
    $db = getDB();
    try {
        $stmt = $db->prepare('UPDATE groups SET permissions = :permissions WHERE id = :id');
        $stmt->bindValue(':permissions', json_encode($permissions), SQLITE3_TEXT);
        $stmt->bindValue(':id', $groupId, SQLITE3_INTEGER);
        return $stmt->execute();
    } catch (Exception $e) {
        logException($e, ['action' => 'update_system_group', 'group_id' => $groupId]);
        return false;
    }
}

/**
 * Delete a group (only non-system groups)
 */
function deleteGroup($groupId) {
    $db = getDB();
    try {
        // Check if it's a system group
        $stmt = $db->prepare('SELECT is_system FROM groups WHERE id = :id');
        $stmt->bindValue(':id', $groupId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $group = $result->fetchArray(SQLITE3_ASSOC);

        if (!$group || $group['is_system']) {
            return false; // Cannot delete system groups
        }

        $stmt = $db->prepare('DELETE FROM groups WHERE id = :id AND is_system = 0');
        $stmt->bindValue(':id', $groupId, SQLITE3_INTEGER);
        return $stmt->execute();
    } catch (Exception $e) {
        logException($e, ['action' => 'delete_group', 'group_id' => $groupId]);
        return false;
    }
}

/**
 * Get group members
 */
function getGroupMembers($groupId) {
    $db = getDB();
    $stmt = $db->prepare('
        SELECT u.*
        FROM users u
        JOIN user_groups ug ON u.id = ug.user_id
        WHERE ug.group_id = :group_id
        ORDER BY u.username
    ');
    $stmt->bindValue(':group_id', $groupId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $members = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $members[] = $row;
    }
    return $members;
}

/**
 * Set permissions for a user (direct permissions)
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
 * Check if user can convert files
 */
function canConvert() {
    return hasPermission(PERM_CONVERT);
}

/**
 * Check if user can manage users
 */
function canManageUsers() {
    return hasPermission(PERM_MANAGE_USERS) || hasPermission(PERM_ADMIN);
}

/**
 * Check if user can manage groups
 */
function canManageGroups() {
    return hasPermission(PERM_MANAGE_GROUPS) || hasPermission(PERM_ADMIN);
}

/**
 * Check if user can manage categories
 */
function canManageCategories() {
    return hasPermission(PERM_MANAGE_CATEGORIES) || hasPermission(PERM_ADMIN);
}

/**
 * Check if user can manage collections
 */
function canManageCollections() {
    return hasPermission(PERM_MANAGE_COLLECTIONS) || hasPermission(PERM_ADMIN);
}

/**
 * Check if user can manage settings
 */
function canManageSettings() {
    return hasPermission(PERM_MANAGE_SETTINGS) || hasPermission(PERM_ADMIN);
}

/**
 * Check if user can view logs
 */
function canViewLogs() {
    return hasPermission(PERM_VIEW_LOGS) || hasPermission(PERM_ADMIN);
}

/**
 * Get list of all available permissions with descriptions
 */
function getAllPermissions() {
    return [
        // Basic permissions
        PERM_UPLOAD => 'Upload new models',
        PERM_DELETE => 'Delete models',
        PERM_EDIT => 'Edit model details',
        PERM_VIEW_STATS => 'View statistics',
        PERM_CONVERT => 'Convert STL to 3MF',
        // Admin permissions
        PERM_MANAGE_USERS => 'Manage users',
        PERM_MANAGE_GROUPS => 'Manage groups',
        PERM_MANAGE_CATEGORIES => 'Manage categories',
        PERM_MANAGE_COLLECTIONS => 'Manage collections',
        PERM_MANAGE_SETTINGS => 'Manage site settings',
        PERM_VIEW_LOGS => 'View system logs',
        PERM_ADMIN => 'Full admin access (all permissions)'
    ];
}

/**
 * Get permissions grouped by category
 */
function getPermissionsByCategory() {
    return [
        'Basic' => [
            PERM_UPLOAD => 'Upload new models',
            PERM_DELETE => 'Delete models',
            PERM_EDIT => 'Edit model details',
            PERM_VIEW_STATS => 'View statistics',
            PERM_CONVERT => 'Convert STL to 3MF',
        ],
        'Administration' => [
            PERM_MANAGE_USERS => 'Manage users',
            PERM_MANAGE_GROUPS => 'Manage groups',
            PERM_MANAGE_CATEGORIES => 'Manage categories',
            PERM_MANAGE_COLLECTIONS => 'Manage collections',
            PERM_MANAGE_SETTINGS => 'Manage site settings',
            PERM_VIEW_LOGS => 'View system logs',
            PERM_ADMIN => 'Full admin access (all permissions)'
        ]
    ];
}
