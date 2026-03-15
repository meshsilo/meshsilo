<?php
/**
 * Session Management
 *
 * View, manage, and revoke user sessions
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';

// Require session management permission
if (!isLoggedIn() || !canManageSessions()) {
    $_SESSION['error'] = 'You do not have permission to manage sessions.';
    header('Location: ' . route('admin.health'));
    exit;
}

$pageTitle = 'Session Management';
$activePage = 'admin';
$adminPage = 'sessions';

$db = getDB();
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Security validation failed. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'revoke':
            $sessionId = $_POST['session_id'] ?? '';
            if ($sessionId) {
                $stmt = $db->prepare('DELETE FROM sessions WHERE id = :id');
                $stmt->bindValue(':id', $sessionId, PDO::PARAM_STR);
                $stmt->execute();
                $message = 'Session revoked successfully.';
                logActivity('session_revoked', 'session', null, "Admin revoked session: $sessionId");
            }
            break;

        case 'revoke_user':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId) {
                $stmt = $db->prepare('DELETE FROM sessions WHERE user_id = :user_id');
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->execute();
                $message = 'All sessions for user revoked.';
                logActivity('sessions_revoked', 'user', $userId, "Admin revoked all sessions for user");
            }
            break;

        case 'revoke_all':
            // Revoke all sessions except current admin session
            $currentSession = session_id();
            $stmt = $db->prepare('DELETE FROM sessions WHERE id != :current');
            $stmt->bindValue(':current', $currentSession, PDO::PARAM_STR);
            $stmt->execute();
            $message = 'All other sessions revoked.';
            logActivity('sessions_revoked_all', 'system', null, "Admin revoked all sessions");
            break;

        case 'cleanup_expired':
            $stmt = $db->prepare('DELETE FROM sessions WHERE expires_at > 0 AND expires_at < :now');
            $stmt->bindValue(':now', time(), PDO::PARAM_INT);
            $stmt->execute();
            $deleted = $db->changes();
            $message = "Cleaned up $deleted expired sessions.";
            break;

        case 'update_settings':
            setSetting('session_timeout', (int)($_POST['session_timeout'] ?? 3600));
            setSetting('max_sessions_per_user', (int)($_POST['max_sessions'] ?? 5));
            setSetting('session_ip_lock', isset($_POST['ip_lock']) ? '1' : '0');
            setSetting('session_remember_days', (int)($_POST['remember_days'] ?? 30));
            $message = 'Session settings updated.';
            break;
    }
}

// Check if database sessions are enabled
$useDbSessions = getenv('DB_SESSIONS') === 'true' ||
    (defined('DB_SESSIONS') && DB_SESSIONS === true) ||
    getSetting('db_sessions', '0') === '1';

// Get session statistics
$stats = ['active' => 0, 'expired' => 0, 'unique_users' => 0, 'today' => 0];
$sessions = [];
$multiSessionUsers = [];
$now = time();

if ($useDbSessions) {
    // Active sessions count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM sessions WHERE expires_at > :now");
    $stmt->bindValue(':now', $now, PDO::PARAM_INT);
    $result = $stmt->execute();
    $stats['active'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

    // Expired sessions count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM sessions WHERE expires_at > 0 AND expires_at <= :now");
    $stmt->bindValue(':now', $now, PDO::PARAM_INT);
    $result = $stmt->execute();
    $stats['expired'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

    // Unique users with sessions
    $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) as count FROM sessions WHERE expires_at > :now");
    $stmt->bindValue(':now', $now, PDO::PARAM_INT);
    $result = $stmt->execute();
    $stats['unique_users'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

    // Sessions created today
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM sessions WHERE last_activity >= :today");
    $stmt->bindValue(':today', strtotime('today'), PDO::PARAM_INT);
    $result = $stmt->execute();
    $stats['today'] = $result ? ($result->fetchArray(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;

    // Get active sessions with user info
    $stmt = $db->prepare("
        SELECT s.*, u.username, u.email, u.is_admin
        FROM sessions s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.expires_at > :now
        ORDER BY s.last_activity DESC
        LIMIT 100
    ");
    $stmt->bindValue(':now', $now, PDO::PARAM_INT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $sessions[] = $row;
    }

    // Get users with multiple sessions
    $stmt = $db->prepare("
        SELECT u.id, u.username, COUNT(s.id) as session_count
        FROM users u
        JOIN sessions s ON u.id = s.user_id
        WHERE s.expires_at > :now
        GROUP BY u.id
        HAVING session_count > 1
        ORDER BY session_count DESC
        LIMIT 20
    ");
    $stmt->bindValue(':now', $now, PDO::PARAM_INT);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $multiSessionUsers[] = $row;
    }
}

// Get session settings
$sessionTimeout = getSetting('session_timeout', '3600');
$maxSessions = getSetting('max_sessions_per_user', '5');
$ipLock = getSetting('session_ip_lock', '0') === '1';
$rememberDays = getSetting('session_remember_days', '30');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h1>Session Management</h1>
        <div class="header-actions">
            <form method="POST" style="display: inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cleanup_expired">
                <button type="submit" class="btn btn-secondary">Clean Up Expired</button>
            </form>
            <form method="POST" style="display: inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke_all">
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Revoke ALL sessions except yours? All users will be logged out.')">
                    Revoke All Sessions
                </button>
            </form>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$useDbSessions): ?>
    <div class="alert alert-warning">
        <strong>File-based sessions active.</strong>
        Database session tracking is not enabled. To track and manage all user sessions here,
        set <code>DB_SESSIONS=true</code> as an environment variable or enable it in settings.
    </div>

    <div class="admin-section" style="margin-bottom: 1.5rem;">
        <h2>Current Session</h2>
        <div class="table-responsive">
            <table class="data-table" style="width: 100%; table-layout: fixed;">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Session ID</th>
                        <th>IP Address</th>
                        <th>User Agent</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="current-session">
                        <td>
                            <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Unknown') ?></strong>
                            <?php if (isAdmin()): ?>
                            <span class="badge badge-admin">Admin</span>
                            <?php endif; ?>
                            <span class="badge badge-current">Current</span>
                        </td>
                        <td class="ip-address"><?= htmlspecialchars(substr(session_id(), 0, 12)) ?>...</td>
                        <td class="ip-address"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '-') ?></td>
                        <td class="user-agent"><?= htmlspecialchars(parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '')) ?></td>
                        <td><span class="badge badge-current">Active</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['active']) ?></div>
            <div class="stat-label">Active Sessions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['unique_users']) ?></div>
            <div class="stat-label">Active Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['today']) ?></div>
            <div class="stat-label">Sessions Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['expired']) ?></div>
            <div class="stat-label">Expired (Pending Cleanup)</div>
        </div>
    </div>

    <div class="admin-grid">
        <!-- Active Sessions -->
        <div class="admin-section">
            <h2>Active Sessions</h2>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>IP Address</th>
                            <th>User Agent</th>
                            <th>Created</th>
                            <th>Last Activity</th>
                            <th>Expires</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): ?>
                        <?php $isCurrent = $session['id'] === session_id(); ?>
                        <tr class="<?= $isCurrent ? 'current-session' : '' ?>">
                            <td>
                                <strong><?= htmlspecialchars($session['username'] ?? 'Unknown') ?></strong>
                                <?php if ($session['is_admin']): ?>
                                <span class="badge badge-admin">Admin</span>
                                <?php endif; ?>
                                <?php if ($isCurrent): ?>
                                <span class="badge badge-current">Current</span>
                                <?php endif; ?>
                            </td>
                            <td class="ip-address"><?= htmlspecialchars($session['ip_address'] ?? '-') ?></td>
                            <td class="user-agent" title="<?= htmlspecialchars($session['user_agent'] ?? '') ?>">
                                <?= htmlspecialchars(parseUserAgent($session['user_agent'] ?? '')) ?>
                            </td>
                            <td class="timestamp"><?= formatSessionTime($session['created_at'] ?? '') ?></td>
                            <td class="timestamp"><?= formatSessionTime($session['last_activity'] ?? '') ?></td>
                            <td class="timestamp"><?= formatSessionTime($session['expires_at'] ?? '') ?></td>
                            <td>
                                <?php if (!$isCurrent): ?>
                                <form method="POST" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="session_id" value="<?= htmlspecialchars($session['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sessions)): ?>
                        <tr>
                            <td colspan="7" class="empty-row">No active sessions</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Users with Multiple Sessions -->
        <?php if (!empty($multiSessionUsers)): ?>
        <div class="admin-section">
            <h2>Users with Multiple Sessions</h2>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Session Count</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($multiSessionUsers as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= $user['session_count'] ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="revoke_user">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning"
                                            onclick="return confirm('Revoke all sessions for this user?')">
                                        Revoke All
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Session Settings -->
        <div class="admin-section">
            <h2>Session Settings</h2>
            <form method="POST" class="settings-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_settings">

                <div class="form-group">
                    <label for="session_timeout">Session Timeout (seconds)</label>
                    <input type="number" id="session_timeout" name="session_timeout"
                           class="form-control" value="<?= $sessionTimeout ?>" min="300" max="86400">
                    <small class="form-help">How long until inactive sessions expire (default: 3600 = 1 hour)</small>
                </div>

                <div class="form-group">
                    <label for="max_sessions">Max Sessions Per User</label>
                    <input type="number" id="max_sessions" name="max_sessions"
                           class="form-control" value="<?= $maxSessions ?>" min="1" max="100">
                    <small class="form-help">Maximum concurrent sessions allowed per user</small>
                </div>

                <div class="form-group">
                    <label for="remember_days">"Remember Me" Duration (days)</label>
                    <input type="number" id="remember_days" name="remember_days"
                           class="form-control" value="<?= $rememberDays ?>" min="1" max="365">
                    <small class="form-help">How long persistent sessions last</small>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="ip_lock" <?= $ipLock ? 'checked' : '' ?>>
                        Lock sessions to IP address
                    </label>
                    <small class="form-help">If enabled, sessions become invalid if user's IP changes</small>
                </div>

                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>
</div>
</div>

<?php
function formatSessionTime($value) {
    if (empty($value) || $value === '0') return '-';
    // If it's a large number, treat as epoch timestamp
    if (is_numeric($value) && (int)$value > 1000000000) {
        return date('M j, H:i', (int)$value);
    }
    // Otherwise treat as datetime string
    $ts = strtotime($value);
    return $ts ? date('M j, H:i', $ts) : '-';
}

function parseUserAgent($ua) {
    if (empty($ua)) return 'Unknown';

    $browser = 'Unknown';
    $os = 'Unknown';

    // Detect browser
    if (preg_match('/Firefox\/(\d+)/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Chrome\/(\d+)/i', $ua) && !preg_match('/Edg/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Safari\/(\d+)/i', $ua) && !preg_match('/Chrome/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/Edg\/(\d+)/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/MSIE|Trident/i', $ua)) $browser = 'IE';

    // Detect OS
    if (preg_match('/Windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
    elseif (preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iPhone|iPad/i', $ua)) $os = 'iOS';

    return "$browser on $os";
}
?>

<style>
.admin-grid {
    display: grid;
    gap: 1.5rem;
}

.admin-section {
    background: var(--card-bg);
    padding: 1.5rem;
    border-radius: 8px;
}

.admin-section h2 {
    margin-top: 0;
    margin-bottom: 1rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--card-bg);
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: var(--primary-color);
}

.stat-label {
    color: var(--text-muted);
    font-size: 0.875rem;
}

.ip-address, .timestamp {
    font-family: monospace;
    font-size: 0.85rem;
}

.user-agent {
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 500;
    margin-left: 0.25rem;
}

.badge-admin {
    background: rgba(239, 68, 68, 0.2);
    color: var(--color-danger);
}

.badge-current {
    background: rgba(34, 197, 94, 0.2);
    color: var(--color-success);
}

.current-session {
    background: rgba(59, 130, 246, 0.1);
}

.settings-form {
    max-width: 500px;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: var(--bg-color);
    color: var(--text-color);
}

.form-help {
    display: block;
    margin-top: 0.25rem;
    color: var(--text-muted);
    font-size: 0.8rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.empty-row {
    text-align: center;
    color: var(--text-muted);
    padding: 2rem;
}

</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
