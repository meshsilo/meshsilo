<?php
// Permission constants - Basic
define('PERM_UPLOAD', 'upload');
define('PERM_DELETE', 'delete');
define('PERM_EDIT', 'edit');
define('PERM_VIEW_STATS', 'view_stats');

// Permission constants - Features
define('PERM_CONVERT', 'convert');          // Convert STL to 3MF
define('PERM_SAVE_SEARCHES', 'save_searches');  // Save and share searches

// Permission constants - Admin functions
define('PERM_MANAGE_USERS', 'manage_users');
define('PERM_MANAGE_GROUPS', 'manage_groups');
define('PERM_MANAGE_CATEGORIES', 'manage_categories');
define('PERM_MANAGE_COLLECTIONS', 'manage_collections');
define('PERM_MANAGE_SETTINGS', 'manage_settings');
define('PERM_VIEW_LOGS', 'view_logs');
define('PERM_ADMIN', 'admin');              // Full admin (all permissions)

// Permission constants - Security & Compliance
define('PERM_MANAGE_SESSIONS', 'manage_sessions');       // View/revoke user sessions
define('PERM_MANAGE_SECURITY', 'manage_security');       // Security headers, encryption
define('PERM_VIEW_AUDIT_LOG', 'view_audit_log');         // View audit trail
define('PERM_MANAGE_RETENTION', 'manage_retention');     // Data retention policies

// Permission constants - Integration
define('PERM_MANAGE_API_KEYS', 'manage_api_keys');       // API key management
define('PERM_MANAGE_WEBHOOKS', 'manage_webhooks');       // Webhook configuration
define('PERM_MANAGE_OAUTH', 'manage_oauth');             // OAuth2 client management
define('PERM_MANAGE_LDAP', 'manage_ldap');               // LDAP/AD configuration
define('PERM_MANAGE_SCIM', 'manage_scim');               // SCIM provisioning

// Permission constants - System Operations
define('PERM_MANAGE_BACKUPS', 'manage_backups');         // Backup/restore operations
define('PERM_MANAGE_SCHEDULER', 'manage_scheduler');     // Scheduled task management
define('PERM_MANAGE_STORAGE', 'manage_storage');         // Storage configuration

// Default permissions for regular users
define('DEFAULT_USER_PERMISSIONS', [PERM_UPLOAD, PERM_VIEW_STATS, PERM_CONVERT, PERM_SAVE_SEARCHES]);

// Admin has all permissions
define('ADMIN_PERMISSIONS', [
    // Basic
    PERM_UPLOAD, PERM_DELETE, PERM_EDIT, PERM_VIEW_STATS, PERM_CONVERT, PERM_SAVE_SEARCHES,
    // Administration
    PERM_MANAGE_USERS, PERM_MANAGE_GROUPS, PERM_MANAGE_CATEGORIES,
    PERM_MANAGE_COLLECTIONS, PERM_MANAGE_SETTINGS, PERM_VIEW_LOGS,
    // Security & Compliance
    PERM_MANAGE_SESSIONS, PERM_MANAGE_SECURITY, PERM_VIEW_AUDIT_LOG, PERM_MANAGE_RETENTION,
    // Integration
    PERM_MANAGE_API_KEYS, PERM_MANAGE_WEBHOOKS, PERM_MANAGE_OAUTH, PERM_MANAGE_LDAP, PERM_MANAGE_SCIM,
    // System Operations
    PERM_MANAGE_BACKUPS, PERM_MANAGE_SCHEDULER, PERM_MANAGE_STORAGE,
    // Full admin
    PERM_ADMIN
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
 * Results are cached per-request to avoid redundant DB queries.
 */
function getUserPermissions($userId) {
    // Per-request cache to avoid N+1 queries
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $db = getDB();

    // Check if user is admin
    $stmt = $db->prepare('SELECT is_admin, permissions FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $user = $result->fetchArray(PDO::FETCH_ASSOC);

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
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $result = $stmt->execute();

    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
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
        $cache[$userId] = DEFAULT_USER_PERMISSIONS;
        return DEFAULT_USER_PERMISSIONS;
    }

    $result = array_unique($permissions);
    $cache[$userId] = $result;
    return $result;
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
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $result = $stmt->execute();

    $groups = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
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
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
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
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
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
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
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
    $stmt->bindValue(':id', $groupId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $group = $result->fetchArray(PDO::FETCH_ASSOC);
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
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':description', $description, PDO::PARAM_STR);
        $stmt->bindValue(':permissions', json_encode($permissions), PDO::PARAM_STR);
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
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':description', $description, PDO::PARAM_STR);
        $stmt->bindValue(':permissions', json_encode($permissions), PDO::PARAM_STR);
        $stmt->bindValue(':id', $groupId, PDO::PARAM_INT);
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
        $stmt->bindValue(':permissions', json_encode($permissions), PDO::PARAM_STR);
        $stmt->bindValue(':id', $groupId, PDO::PARAM_INT);
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
        $stmt->bindValue(':id', $groupId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $group = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$group || $group['is_system']) {
            return false; // Cannot delete system groups
        }

        $stmt = $db->prepare('DELETE FROM groups WHERE id = :id AND is_system = 0');
        $stmt->bindValue(':id', $groupId, PDO::PARAM_INT);
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
    $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
    $result = $stmt->execute();

    $members = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
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
    $stmt->bindValue(':permissions', json_encode($permissions), PDO::PARAM_STR);
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
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
    return hasPermission(PERM_MANAGE_USERS);
}

/**
 * Check if user can manage groups
 */
function canManageGroups() {
    return hasPermission(PERM_MANAGE_GROUPS);
}

/**
 * Check if user can manage categories
 */
function canManageCategories() {
    return hasPermission(PERM_MANAGE_CATEGORIES);
}

/**
 * Check if user can manage collections
 */
function canManageCollections() {
    return hasPermission(PERM_MANAGE_COLLECTIONS);
}

/**
 * Check if user can manage settings
 */
function canManageSettings() {
    return hasPermission(PERM_MANAGE_SETTINGS);
}

/**
 * Check if user can view logs
 */
function canViewLogs() {
    return hasPermission(PERM_VIEW_LOGS);
}

/**
 * Check if user can save searches
 */
function canSaveSearches() {
    return hasPermission(PERM_SAVE_SEARCHES);
}

/**
 * Check if user can manage sessions
 */
function canManageSessions() {
    return hasPermission(PERM_MANAGE_SESSIONS);
}

/**
 * Check if user can manage security settings
 */
function canManageSecurity() {
    return hasPermission(PERM_MANAGE_SECURITY);
}

/**
 * Check if user can view audit log
 */
function canViewAuditLog() {
    return hasPermission(PERM_VIEW_AUDIT_LOG);
}

/**
 * Check if user can manage data retention
 */
function canManageRetention() {
    return hasPermission(PERM_MANAGE_RETENTION);
}

/**
 * Check if user can manage API keys
 */
function canManageApiKeys() {
    return hasPermission(PERM_MANAGE_API_KEYS);
}

/**
 * Check if user can manage webhooks
 */
function canManageWebhooks() {
    return hasPermission(PERM_MANAGE_WEBHOOKS);
}

/**
 * Check if user can manage OAuth clients
 */
function canManageOAuth() {
    return hasPermission(PERM_MANAGE_OAUTH);
}

/**
 * Check if user can manage LDAP/AD
 */
function canManageLdap() {
    return hasPermission(PERM_MANAGE_LDAP);
}

/**
 * Check if user can manage SCIM provisioning
 */
function canManageScim() {
    return hasPermission(PERM_MANAGE_SCIM);
}

/**
 * Check if user can manage backups
 */
function canManageBackups() {
    return hasPermission(PERM_MANAGE_BACKUPS);
}

/**
 * Check if user can manage scheduled tasks
 */
function canManageScheduler() {
    return hasPermission(PERM_MANAGE_SCHEDULER);
}

/**
 * Check if user can manage storage
 */
function canManageStorage() {
    return hasPermission(PERM_MANAGE_STORAGE);
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
        PERM_SAVE_SEARCHES => 'Save and share searches',
        // Admin permissions
        PERM_MANAGE_USERS => 'Manage users',
        PERM_MANAGE_GROUPS => 'Manage groups',
        PERM_MANAGE_CATEGORIES => 'Manage categories',
        PERM_MANAGE_COLLECTIONS => 'Manage collections',
        PERM_MANAGE_SETTINGS => 'Manage site settings',
        PERM_VIEW_LOGS => 'View system logs',
        // Security & Compliance
        PERM_MANAGE_SESSIONS => 'Manage user sessions',
        PERM_MANAGE_SECURITY => 'Manage security settings',
        PERM_VIEW_AUDIT_LOG => 'View audit log',
        PERM_MANAGE_RETENTION => 'Manage data retention',
        // Integration
        PERM_MANAGE_API_KEYS => 'Manage API keys',
        PERM_MANAGE_WEBHOOKS => 'Manage webhooks',
        PERM_MANAGE_OAUTH => 'Manage OAuth2 clients',
        PERM_MANAGE_LDAP => 'Manage LDAP/AD integration',
        PERM_MANAGE_SCIM => 'Manage SCIM provisioning',
        // System Operations
        PERM_MANAGE_BACKUPS => 'Manage backups & recovery',
        PERM_MANAGE_SCHEDULER => 'Manage scheduled tasks',
        PERM_MANAGE_STORAGE => 'Manage storage settings',
        // Full admin
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
            PERM_SAVE_SEARCHES => 'Save and share searches',
        ],
        'Content Management' => [
            PERM_MANAGE_CATEGORIES => 'Manage categories',
            PERM_MANAGE_COLLECTIONS => 'Manage collections',
        ],
        'User Management' => [
            PERM_MANAGE_USERS => 'Manage users',
            PERM_MANAGE_GROUPS => 'Manage groups',
            PERM_MANAGE_SESSIONS => 'Manage user sessions',
        ],
        'Security & Compliance' => [
            PERM_MANAGE_SECURITY => 'Manage security settings (headers, encryption)',
            PERM_VIEW_AUDIT_LOG => 'View audit log',
            PERM_MANAGE_RETENTION => 'Manage data retention policies',
        ],
        'Integration' => [
            PERM_MANAGE_API_KEYS => 'Manage API keys',
            PERM_MANAGE_WEBHOOKS => 'Manage webhooks',
            PERM_MANAGE_OAUTH => 'Manage OAuth2 clients',
            PERM_MANAGE_LDAP => 'Manage LDAP/AD integration',
            PERM_MANAGE_SCIM => 'Manage SCIM user provisioning',
        ],
        'System Operations' => [
            PERM_MANAGE_SETTINGS => 'Manage site settings',
            PERM_VIEW_LOGS => 'View system logs',
            PERM_MANAGE_BACKUPS => 'Manage backups & recovery',
            PERM_MANAGE_SCHEDULER => 'Manage scheduled tasks',
            PERM_MANAGE_STORAGE => 'Manage storage settings',
        ],
        'Super Admin' => [
            PERM_ADMIN => 'Full admin access (all permissions)'
        ]
    ];
}
