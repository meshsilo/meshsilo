<?php
require_once __DIR__ . '/../../includes/config.php';

// Require group management permission
if (!isLoggedIn() || !canManageGroups()) {
    $_SESSION['error'] = 'You do not have permission to manage groups.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Manage Groups';
$activePage = 'admin';
$adminPage = 'groups';

$db = getDB();
$message = '';
$error = '';

// Handle form submissions
// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $permissions = $_POST['permissions'] ?? [];

        if (empty($name)) {
            $error = 'Group name is required.';
        } else {
            $groupId = createGroup($name, $description, $permissions);
            if ($groupId) {
                logInfo('Group created', ['group_id' => $groupId, 'name' => $name]);
                $message = 'Group "' . $name . '" created successfully.';
            } else {
                $error = 'Failed to create group. Name may already exist.';
            }
        }
    } elseif ($action === 'update') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $permissions = $_POST['permissions'] ?? [];

        $group = getGroup($groupId);
        if (!$group) {
            $error = 'Group not found.';
        } elseif (empty($name)) {
            $error = 'Group name is required.';
        } else {
            if ($group['is_system']) {
                // For system groups, only update permissions
                if (updateSystemGroupPermissions($groupId, $permissions)) {
                    logInfo('System group permissions updated', ['group_id' => $groupId]);
                    $message = 'Group permissions updated successfully.';
                } else {
                    $error = 'Failed to update group permissions.';
                }
            } else {
                if (updateGroup($groupId, $name, $description, $permissions)) {
                    logInfo('Group updated', ['group_id' => $groupId, 'name' => $name]);
                    $message = 'Group updated successfully.';
                } else {
                    $error = 'Failed to update group.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $group = getGroup($groupId);

        if (!$group) {
            $error = 'Group not found.';
        } elseif ($group['is_system']) {
            $error = 'Cannot delete system groups.';
        } else {
            if (deleteGroup($groupId)) {
                logInfo('Group deleted', ['group_id' => $groupId, 'name' => $group['name']]);
                $message = 'Group "' . $group['name'] . '" deleted successfully.';
            } else {
                $error = 'Failed to delete group.';
            }
        }
    } elseif ($action === 'add_member') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($groupId && $userId) {
            if (addUserToGroup($userId, $groupId)) {
                logInfo('User added to group', ['user_id' => $userId, 'group_id' => $groupId]);
                $message = 'User added to group successfully.';
            } else {
                $error = 'Failed to add user to group.';
            }
        }
    } elseif ($action === 'remove_member') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($groupId && $userId) {
            if (removeUserFromGroup($userId, $groupId)) {
                logInfo('User removed from group', ['user_id' => $userId, 'group_id' => $groupId]);
                $message = 'User removed from group successfully.';
            } else {
                $error = 'Failed to remove user from group.';
            }
        }
    }
}

// Get all groups with member counts
$groups = getAllGroups();
foreach ($groups as &$group) {
    $group['members'] = getGroupMembers($group['id']);
    $group['member_count'] = count($group['members']);
}
unset($group);

// Get all users for adding to groups
$usersResult = $db->query('SELECT id, username, email FROM users ORDER BY username');
$allUsers = [];
while ($row = $usersResult->fetchArray(PDO::FETCH_ASSOC)) {
    $allUsers[] = $row;
}

// Get all available permissions
$allPermissions = getAllPermissions();

// Check if editing a specific group
$editGroup = null;
if (isset($_GET['edit'])) {
    $editGroupId = (int)$_GET['edit'];
    $editGroup = getGroup($editGroupId);
    if ($editGroup) {
        $editGroup['members'] = getGroupMembers($editGroupId);
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Manage Groups</h1>
                    <p>Create and manage permission groups</p>
                </div>

                <?php if ($message): ?>
                <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="admin-grid">
                <!-- Group List -->
                <section class="admin-section">
                    <h2>Groups</h2>
                    <div class="admin-table-container">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Members</th>
                                    <th scope="col">Permissions</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups as $group): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($group['name']) ?></strong>
                                        <?php if ($group['is_system']): ?>
                                        <span class="badge badge-system">System</span>
                                        <?php endif; ?>
                                        <?php if ($group['description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($group['description']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $group['member_count'] ?></td>
                                    <td>
                                        <?php foreach ($group['permissions_array'] as $perm): ?>
                                        <span class="permission-badge"><?= htmlspecialchars($perm) ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <a href="?edit=<?= $group['id'] ?>" class="btn btn-small btn-secondary">Edit</a>
                                        <?php if (!$group['is_system']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this group?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                            <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Create/Edit Group Form -->
                <section class="admin-section">
                    <?php if ($editGroup): ?>
                    <h2>Edit Group: <?= htmlspecialchars($editGroup['name']) ?></h2>
                    <form method="POST" class="admin-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">

                        <div class="form-group">
                            <label for="name">Group Name</label>
                            <input type="text" id="name" name="name" class="form-input" value="<?= htmlspecialchars($editGroup['name']) ?>" <?= $editGroup['is_system'] ? 'disabled' : 'required' ?>>
                            <?php if ($editGroup['is_system']): ?>
                            <small class="form-help">System group names cannot be changed.</small>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-input form-textarea" rows="2" <?= $editGroup['is_system'] ? 'disabled' : '' ?>><?= htmlspecialchars($editGroup['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Permissions</label>
                            <div class="checkbox-group">
                                <?php foreach ($allPermissions as $perm => $desc): ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="permissions[]" value="<?= $perm ?>" <?= in_array($perm, $editGroup['permissions_array']) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($desc) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="<?= route('admin.groups') ?>" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Group</button>
                        </div>
                    </form>

                    <!-- Group Members -->
                    <h3 style="margin-top: 2rem;">Group Members</h3>
                    <?php if (!empty($editGroup['members'])): ?>
                    <div class="members-list">
                        <?php foreach ($editGroup['members'] as $member): ?>
                        <div class="member-item">
                            <span><?= htmlspecialchars($member['username']) ?></span>
                            <form method="POST" style="display: inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
                                <input type="hidden" name="user_id" value="<?= $member['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Remove</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">No members in this group.</p>
                    <?php endif; ?>

                    <!-- Add Member -->
                    <form method="POST" class="add-member-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_member">
                        <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
                        <div class="form-row">
                            <select name="user_id" class="form-input" aria-label="Select user" required>
                                <option value="">Select user to add...</option>
                                <?php
                                $existingMemberIds = array_column($editGroup['members'], 'id');
                                foreach ($allUsers as $user):
                                    if (!in_array($user['id'], $existingMemberIds)):
                                ?>
                                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['email']) ?>)</option>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </select>
                            <button type="submit" class="btn btn-primary">Add Member</button>
                        </div>
                    </form>

                    <?php else: ?>
                    <h2>Create New Group</h2>
                    <form method="POST" class="admin-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">

                        <div class="form-group">
                            <label for="name">Group Name <span class="required">*</span></label>
                            <input type="text" id="name" name="name" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-input form-textarea" rows="2"></textarea>
                        </div>

                        <div class="form-group">
                            <label>Permissions</label>
                            <div class="checkbox-group">
                                <?php foreach ($allPermissions as $perm => $desc): ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="permissions[]" value="<?= $perm ?>">
                                    <span><?= htmlspecialchars($desc) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Create Group</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </section>
                </div>
            </div>
        </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
