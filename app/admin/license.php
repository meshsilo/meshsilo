<?php
require_once __DIR__ . '/../../includes/config.php';
// Set baseDir based on how we're accessed (router vs direct)
// Router loads from root context, direct access needs ../
$baseDir = isset($_SERVER['ROUTE_NAME']) ? '' : '../';

// Require admin access
if (!isLoggedIn() || !isAdmin()) {
    header('Location: /login');
    exit;
}

$pageTitle = 'License Management';
$activePage = '';
$adminPage = 'license';

$message = '';
$messageType = 'success';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'activate':
                $licenseKey = trim($_POST['license_key'] ?? '');
                if (empty($licenseKey)) {
                    $message = 'Please enter a license key.';
                    $messageType = 'error';
                } else {
                    $result = saveLicenseKey($licenseKey);
                    if ($result['success']) {
                        $message = 'License activated successfully! You are now on the ' . getTierName($result['tier']) . ' plan.';
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to activate license: ' . $result['error'];
                        $messageType = 'error';
                    }
                }
                break;

            case 'deactivate':
                removeLicenseKey();
                $message = 'License deactivated. You are now on the Community (free) plan.';
                $messageType = 'info';
                break;
        }
    }
}

// Get current license info
$licenseResult = getCurrentLicense();
$currentTier = $licenseResult['tier'];
$license = $licenseResult['license'];
$usage = getLicenseUsage();
$featureInfo = getFeatureInfo();
$tierFeatures = getFeaturesByTier();

include '../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
    <div class="admin-header">
        <h1>License Management</h1>
        <p class="admin-subtitle">Manage your Silo license and view feature availability</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Current License Status -->
    <div class="license-status-card">
        <div class="license-tier-badge" style="--tier-color: <?= getTierColor($currentTier) ?>">
            <?= getTierName($currentTier) ?>
        </div>
        <div class="license-details">
            <?php if ($currentTier !== LICENSE_TIER_FREE): ?>
            <div class="license-info-row">
                <span class="label">License ID:</span>
                <span class="value"><?= htmlspecialchars($license['license_id'] ?? 'N/A') ?></span>
            </div>
            <?php if (!empty($license['email'])): ?>
            <div class="license-info-row">
                <span class="label">Licensed to:</span>
                <span class="value"><?= htmlspecialchars($license['email']) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($license['expires_at'])): ?>
            <div class="license-info-row">
                <span class="label">Expires:</span>
                <span class="value"><?= date('F j, Y', strtotime($license['expires_at'])) ?></span>
            </div>
            <?php else: ?>
            <div class="license-info-row">
                <span class="label">Expires:</span>
                <span class="value" style="color: var(--color-success)">Never (Lifetime)</span>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <p class="license-free-note">You're using the free Community edition. Upgrade to unlock more features!</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Usage Statistics -->
    <div class="admin-section">
        <h2>Usage</h2>
        <div class="usage-stats">
            <div class="usage-stat">
                <div class="usage-header">
                    <span class="usage-label">Users</span>
                    <span class="usage-value">
                        <?= $usage['users']['current'] ?> / <?= $usage['users']['unlimited'] ? '∞' : $usage['users']['max'] ?>
                    </span>
                </div>
                <?php if (!$usage['users']['unlimited']): ?>
                <div class="progress-bar">
                    <div class="progress-bar-fill <?= $usage['users']['percentage'] >= 90 ? 'warning' : '' ?>"
                         style="width: <?= min(100, $usage['users']['percentage']) ?>%"></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="usage-stat">
                <div class="usage-header">
                    <span class="usage-label">Models</span>
                    <span class="usage-value">
                        <?= number_format($usage['models']['current']) ?> / <?= $usage['models']['unlimited'] ? '∞' : number_format($usage['models']['max']) ?>
                    </span>
                </div>
                <?php if (!$usage['models']['unlimited']): ?>
                <div class="progress-bar">
                    <div class="progress-bar-fill <?= $usage['models']['percentage'] >= 90 ? 'warning' : '' ?>"
                         style="width: <?= min(100, $usage['models']['percentage']) ?>%"></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="usage-stat">
                <div class="usage-header">
                    <span class="usage-label">Storage</span>
                    <span class="usage-value">
                        <?= $usage['storage']['current_formatted'] ?> / <?= $usage['storage']['max_formatted'] ?>
                    </span>
                </div>
                <?php if (!$usage['storage']['unlimited']): ?>
                <div class="progress-bar">
                    <div class="progress-bar-fill <?= $usage['storage']['percentage'] >= 90 ? 'warning' : '' ?>"
                         style="width: <?= min(100, $usage['storage']['percentage']) ?>%"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- License Key Input -->
    <div class="admin-section">
        <h2><?= $currentTier === LICENSE_TIER_FREE ? 'Activate License' : 'Update License' ?></h2>
        <form method="post" class="license-form">
            <input type="hidden" name="action" value="activate">
            <div class="form-group">
                <label for="license_key">License Key</label>
                <textarea name="license_key" id="license_key" class="form-input" rows="4"
                    placeholder="Paste your license key here..."><?= $currentTier !== LICENSE_TIER_FREE ? getSetting('license_key', '') : '' ?></textarea>
                <p class="form-help">Enter the license key you received after purchase.</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Activate License</button>
                <?php if ($currentTier !== LICENSE_TIER_FREE): ?>
                <button type="submit" name="action" value="deactivate" class="btn btn-secondary"
                    onclick="return confirm('Are you sure you want to deactivate your license? You will lose access to paid features.')">
                    Deactivate License
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Feature Comparison -->
    <div class="admin-section">
        <h2>Feature Comparison</h2>
        <div class="feature-comparison">
            <table class="admin-table feature-table">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th class="tier-col <?= $currentTier === LICENSE_TIER_FREE ? 'current' : '' ?>">
                            Community
                            <?php if ($currentTier === LICENSE_TIER_FREE): ?><span class="current-badge">Current</span><?php endif; ?>
                        </th>
                        <th class="tier-col <?= $currentTier === LICENSE_TIER_PRO ? 'current' : '' ?>">
                            Pro
                            <?php if ($currentTier === LICENSE_TIER_PRO): ?><span class="current-badge">Current</span><?php endif; ?>
                        </th>
                        <th class="tier-col <?= $currentTier === LICENSE_TIER_BUSINESS ? 'current' : '' ?>">
                            Business
                            <?php if ($currentTier === LICENSE_TIER_BUSINESS): ?><span class="current-badge">Current</span><?php endif; ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Limits -->
                    <tr class="section-header">
                        <td colspan="4">Limits</td>
                    </tr>
                    <tr>
                        <td>Users</td>
                        <td>1</td>
                        <td>5</td>
                        <td>Unlimited</td>
                    </tr>
                    <tr>
                        <td>Models</td>
                        <td>100</td>
                        <td>Unlimited</td>
                        <td>Unlimited</td>
                    </tr>
                    <tr>
                        <td>Storage</td>
                        <td>5 GB</td>
                        <td>100 GB</td>
                        <td>Unlimited</td>
                    </tr>

                    <!-- Core Features -->
                    <tr class="section-header">
                        <td colspan="4">Core Features</td>
                    </tr>
                    <tr>
                        <td>Upload & Download</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                    </tr>
                    <tr>
                        <td>3D Preview</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                    </tr>
                    <tr>
                        <td>Categories</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                    </tr>
                    <tr>
                        <td>Search</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                    </tr>

                    <!-- Pro Features -->
                    <tr class="section-header">
                        <td colspan="4">Pro Features</td>
                    </tr>
                    <?php
                    $proFeatures = [
                        FEATURE_MULTI_USER, FEATURE_TAGS, FEATURE_FAVORITES,
                        FEATURE_BATCH_OPERATIONS, FEATURE_PRINT_QUEUE, FEATURE_THEMES,
                        FEATURE_KEYBOARD_SHORTCUTS, FEATURE_GCODE_PREVIEW, FEATURE_DIMENSIONS,
                        FEATURE_VERSION_HISTORY, FEATURE_RELATED_MODELS, FEATURE_ACTIVITY_LOG,
                        FEATURE_STORAGE_ANALYTICS, FEATURE_DUPLICATE_DETECTION, FEATURE_BULK_UPLOAD
                    ];
                    foreach ($proFeatures as $feature):
                        $info = $featureInfo[$feature] ?? ['name' => $feature];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($info['name']) ?></td>
                        <td class="cross">✗</td>
                        <td class="check">✓</td>
                        <td class="check">✓</td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Business Features -->
                    <tr class="section-header">
                        <td colspan="4">Business Features</td>
                    </tr>
                    <?php
                    $businessOnlyFeatures = [
                        FEATURE_API_ACCESS, FEATURE_SSO_OIDC, FEATURE_WEBHOOKS,
                        FEATURE_CUSTOM_BRANDING, FEATURE_PRIORITY_SUPPORT, FEATURE_S3_STORAGE
                    ];
                    foreach ($businessOnlyFeatures as $feature):
                        $info = $featureInfo[$feature] ?? ['name' => $feature];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($info['name']) ?></td>
                        <td class="cross">✗</td>
                        <td class="cross">✗</td>
                        <td class="check">✓</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Purchase Links -->
    <?php if ($currentTier !== LICENSE_TIER_BUSINESS): ?>
    <div class="admin-section upgrade-cta">
        <h2>Upgrade Your Plan</h2>
        <p>Unlock more features and remove limits by upgrading your license.</p>
        <div class="upgrade-buttons">
            <?php if ($currentTier === LICENSE_TIER_FREE): ?>
            <a href="https://silo3d.com/pricing" target="_blank" class="btn btn-primary btn-large">
                Upgrade to Pro
            </a>
            <?php endif; ?>
            <a href="https://silo3d.com/pricing" target="_blank" class="btn btn-secondary btn-large">
                View All Plans
            </a>
        </div>
    </div>
    <?php endif; ?>
            </div>
        </div>

<style>
.license-status-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: 2rem;
    display: flex;
    align-items: flex-start;
    gap: 2rem;
    margin-bottom: 2rem;
}

.license-tier-badge {
    background: var(--tier-color);
    color: white;
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius);
    font-size: 1.25rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.license-details {
    flex: 1;
}

.license-info-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.license-info-row .label {
    color: var(--color-text-muted);
    min-width: 120px;
}

.license-info-row .value {
    font-weight: 500;
}

.license-free-note {
    color: var(--color-text-muted);
    margin: 0;
}

.usage-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.usage-stat {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: 1rem;
}

.usage-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
}

.usage-label {
    font-weight: 500;
}

.usage-value {
    color: var(--color-text-muted);
}

.usage-stat .progress-bar {
    margin-top: 0.5rem;
}

.progress-bar-fill.warning {
    background: var(--color-warning, #f59e0b);
}

.license-form {
    max-width: 600px;
}

.license-form textarea {
    font-family: monospace;
    font-size: 0.875rem;
}

.feature-comparison {
    overflow-x: auto;
}

.feature-table {
    width: 100%;
}

.feature-table th,
.feature-table td {
    text-align: left;
    padding: 0.75rem 1rem;
}

.feature-table .tier-col {
    text-align: center;
    width: 120px;
}

.feature-table .tier-col.current {
    background: var(--color-primary-alpha, rgba(59, 130, 246, 0.1));
}

.current-badge {
    display: block;
    font-size: 0.625rem;
    font-weight: normal;
    color: var(--color-primary);
    text-transform: uppercase;
}

.feature-table .section-header td {
    background: var(--color-surface-elevated);
    font-weight: 600;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-muted);
}

.feature-table .check {
    color: var(--color-success, #10b981);
    font-weight: bold;
    text-align: center;
}

.feature-table .cross {
    color: var(--color-text-muted);
    text-align: center;
}

.upgrade-cta {
    background: linear-gradient(135deg, var(--color-primary-alpha, rgba(59, 130, 246, 0.1)), var(--color-surface));
    border: 1px solid var(--color-primary);
    border-radius: var(--radius-lg);
    padding: 2rem;
    text-align: center;
}

.upgrade-cta h2 {
    margin-top: 0;
}

.upgrade-buttons {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1.5rem;
}
</style>

<?php include '../includes/footer.php'; ?>
