<?php
/**
 * Upgrade Prompt Component
 *
 * Displays a modal or inline prompt when a user tries to access a Pro/Business feature.
 * Include this at the bottom of pages that have gated features.
 */

/**
 * Render upgrade prompt modal (include once per page)
 */
function renderUpgradePromptModal() {
?>
<div id="upgrade-prompt-overlay" class="upgrade-prompt-overlay" onclick="if(event.target === this) hideUpgradePrompt()">
    <div class="upgrade-prompt">
        <div class="upgrade-prompt-icon">&#128274;</div>
        <h3 id="upgrade-prompt-title">Upgrade Required</h3>
        <p id="upgrade-prompt-message">This feature requires a Pro license.</p>
        <div class="upgrade-prompt-tier" id="upgrade-prompt-tier">Pro</div>
        <div class="upgrade-prompt-actions">
            <a href="<?= basePath('admin/license.php') ?>" class="btn btn-primary">View Plans</a>
            <button type="button" class="btn btn-secondary" onclick="hideUpgradePrompt()">Close</button>
        </div>
    </div>
</div>
<script>
function showUpgradePrompt(featureName, requiredTier) {
    const overlay = document.getElementById('upgrade-prompt-overlay');
    const title = document.getElementById('upgrade-prompt-title');
    const message = document.getElementById('upgrade-prompt-message');
    const tier = document.getElementById('upgrade-prompt-tier');

    title.textContent = featureName + ' - Upgrade Required';
    message.textContent = 'This feature requires a ' + requiredTier + ' license.';
    tier.textContent = requiredTier;

    overlay.classList.add('visible');
}

function hideUpgradePrompt() {
    document.getElementById('upgrade-prompt-overlay').classList.remove('visible');
}

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideUpgradePrompt();
});
</script>
<?php
}

/**
 * Check feature and redirect with upgrade message if not available
 *
 * @param string $feature Feature constant
 * @param string $redirectUrl URL to redirect to (default: back to previous page)
 * @return bool True if feature is available, false if redirected
 */
function requireFeatureOrRedirect($feature, $redirectUrl = null) {
    if (hasFeature($feature)) {
        return true;
    }

    $info = getFeatureInfo();
    $featureInfo = $info[$feature] ?? ['name' => $feature, 'tier' => LICENSE_TIER_PRO];

    $_SESSION['upgrade_message'] = [
        'feature' => $featureInfo['name'],
        'tier' => getTierName($featureInfo['tier'])
    ];

    $redirect = $redirectUrl ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php');
    header('Location: ' . $redirect);
    exit;
}

/**
 * Display upgrade message if set in session
 */
function displayUpgradeMessage() {
    if (isset($_SESSION['upgrade_message'])) {
        $msg = $_SESSION['upgrade_message'];
        unset($_SESSION['upgrade_message']);
        ?>
        <div class="alert alert-warning">
            <strong><?= htmlspecialchars($msg['feature']) ?></strong> requires a <?= htmlspecialchars($msg['tier']) ?> license.
            <a href="<?= basePath('admin/license.php') ?>">View upgrade options</a>
        </div>
        <?php
    }
}

/**
 * Render inline upgrade prompt for a feature section
 */
function renderInlineUpgradePrompt($feature) {
    $info = getFeatureInfo();
    $featureInfo = $info[$feature] ?? ['name' => $feature, 'tier' => LICENSE_TIER_PRO, 'description' => ''];
    ?>
    <div class="inline-upgrade-prompt">
        <div class="inline-upgrade-icon">&#128274;</div>
        <div class="inline-upgrade-content">
            <h4><?= htmlspecialchars($featureInfo['name']) ?></h4>
            <p><?= htmlspecialchars($featureInfo['description']) ?></p>
            <span class="tier-badge tier-<?= $featureInfo['tier'] ?>"><?= getTierName($featureInfo['tier']) ?></span>
        </div>
        <a href="<?= basePath('admin/license.php') ?>" class="btn btn-primary">Upgrade</a>
    </div>
    <?php
}

/**
 * Wrap content that requires a feature - shows upgrade prompt if not available
 *
 * @param string $feature Feature constant
 * @param callable $content Function that renders the content
 */
function featureGate($feature, $content) {
    if (hasFeature($feature)) {
        $content();
    } else {
        renderInlineUpgradePrompt($feature);
    }
}
