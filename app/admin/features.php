<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

// Require admin permission
if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = 'You do not have permission to manage features.';
    header('Location: ' . route('home'));
    exit;
}

$pageTitle = 'Feature Management';
$activePage = '';
$adminPage = 'features';

$message = '';
$error = '';

// Handle form submission
// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if applying a preset
    if (isset($_POST['apply_preset'])) {
        $presetName = $_POST['apply_preset'];
        if (applyFeaturePreset($presetName)) {
            $presets = getFeaturePresets();
            logInfo('Feature preset applied', [
                'preset' => $presetName,
                'by' => getCurrentUser()['username']
            ]);
            $message = "Applied '{$presets[$presetName]['name']}' preset successfully.";
        } else {
            $error = 'Invalid preset selected.';
        }
    } else {
        // Normal feature save
        $features = getAvailableFeatures();
        $enabledFeatures = $_POST['features'] ?? [];

        foreach ($features as $key => $meta) {
            if (in_array($key, $enabledFeatures)) {
                enableFeature($key);
            } else {
                disableFeature($key);
            }
        }

        logInfo('Features updated', [
            'enabled' => $enabledFeatures,
            'by' => getCurrentUser()['username']
        ]);

        $message = 'Feature settings saved successfully.';
    }
}

// Get features grouped by category
$featuresByCategory = getFeaturesByCategory();

// Get usage statistics
$usageStats = getFeatureUsageStats();

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="admin-layout">
<?php require_once __DIR__ . '/../../includes/admin-sidebar.php'; ?>

            <div class="admin-content">
                <div class="page-header">
                    <h1>Feature Management</h1>
                    <p>Enable or disable optional features to customize your installation</p>
                </div>

                <?php if ($message): ?>
                <div role="status" class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div role="alert" class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="presets-section">
                    <h3>Quick Presets</h3>
                    <div class="presets-grid">
                        <?php foreach (getFeaturePresets() as $key => $preset): ?>
                        <form method="post" class="preset-card">
                            <?= csrf_field() ?>
                            <input type="hidden" name="apply_preset" value="<?= htmlspecialchars($key) ?>">
                            <div class="preset-header">
                                <span class="preset-name"><?= htmlspecialchars($preset['name']) ?></span>
                                <span class="preset-count"><?= count(array_filter($preset['features'])) ?>/<?= count($preset['features']) ?> features</span>
                            </div>
                            <p class="preset-description"><?= htmlspecialchars($preset['description']) ?></p>
                            <button type="submit" class="btn btn-sm btn-secondary">Apply Preset</button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                </div>

                <form method="post" class="features-form">
                    <?= csrf_field() ?>
                    <div class="features-grid">
                        <?php foreach ($featuresByCategory as $category => $features): ?>
                        <section class="feature-category">
                            <h2><?= htmlspecialchars($category) ?></h2>
                            <div class="feature-list">
                                <?php foreach ($features as $feature):
                                    $missingDeps = getMissingDependencies($feature['key']);
                                    $dependents = getDependentFeatures($feature['key']);
                                    $enabledDependents = array_filter($dependents, fn($d) => isFeatureEnabled($d));
                                ?>
                                <label class="feature-item <?= $feature['enabled'] ? 'enabled' : 'disabled' ?><?= !empty($missingDeps) ? ' has-warning' : '' ?>"
                                       data-feature="<?= htmlspecialchars($feature['key']) ?>"
                                       data-dependents="<?= htmlspecialchars(json_encode($dependents)) ?>"
                                       data-usage="<?= $usageStats[$feature['key']] ?? 0 ?>">
                                    <div class="feature-toggle">
                                        <input type="checkbox"
                                               name="features[]"
                                               value="<?= htmlspecialchars($feature['key']) ?>"
                                               <?= $feature['enabled'] ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </div>
                                    <div class="feature-info">
                                        <div class="feature-header">
                                            <span class="feature-icon" data-icon="<?= htmlspecialchars($feature['icon']) ?>" aria-hidden="true">
                                                <?= getFeatureIcon($feature['icon']) ?>
                                            </span>
                                            <span class="feature-name"><?= htmlspecialchars($feature['name']) ?></span>
                                            <?php if ($feature['default']): ?>
                                            <span class="feature-badge default">Default</span>
                                            <?php endif; ?>
                                            <?php if (isset($usageStats[$feature['key']]) && $usageStats[$feature['key']] > 0): ?>
                                            <span class="feature-badge usage"><?= number_format($usageStats[$feature['key']]) ?> items</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="feature-description"><?= htmlspecialchars($feature['description']) ?></p>
                                        <?php if (!empty($missingDeps)): ?>
                                        <p class="feature-warning">Requires: <?= htmlspecialchars(implode(', ', $missingDeps)) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($enabledDependents)): ?>
                                        <?php
                                            $depNames = array_map(function($d) {
                                                $all = getAvailableFeatures();
                                                return $all[$d]['name'] ?? $d;
                                            }, $enabledDependents);
                                        ?>
                                        <p class="feature-dependents">Used by: <?= htmlspecialchars(implode(', ', $depNames)) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions sticky-actions">
                        <button type="submit" class="btn btn-primary">Save Feature Settings</button>
                        <button type="button" class="btn btn-secondary" data-action="reset-defaults">Reset to Defaults</button>
                    </div>
                </form>
            </div>
        </div>

        <style>
        .presets-section {
            margin-bottom: 2rem;
        }

        .presets-section h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--color-text);
        }

        .presets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .preset-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 1rem;
            transition: border-color 0.2s ease;
        }

        .preset-card:hover {
            border-color: var(--color-primary);
        }

        .preset-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .preset-name {
            font-weight: 600;
            color: var(--color-text);
        }

        .preset-count {
            font-size: 0.75rem;
            color: var(--color-text-muted);
            background: var(--color-border);
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
        }

        .preset-description {
            font-size: 0.85rem;
            color: var(--color-text-muted);
            margin: 0 0 1rem;
            line-height: 1.4;
        }

        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
        }

        .features-grid {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .feature-category h2 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--color-border);
            color: var(--color-text);
        }

        .feature-list {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .feature-item:hover {
            border-color: var(--color-primary);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .feature-item.enabled {
            border-color: var(--color-success);
            background: color-mix(in srgb, var(--color-success) 10%, transparent);
        }

        .feature-toggle {
            position: relative;
            flex-shrink: 0;
        }

        .feature-toggle input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            display: block;
            width: 44px;
            height: 24px;
            background: var(--color-text-muted);
            border-radius: 12px;
            transition: background 0.2s ease;
            position: relative;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            transition: transform 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .feature-toggle input:checked + .toggle-slider {
            background: var(--color-success);
        }

        .feature-toggle input:checked + .toggle-slider::before {
            transform: translateX(20px);
        }

        .feature-info {
            flex: 1;
            min-width: 0;
        }

        .feature-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
            flex-wrap: wrap;
        }

        .feature-icon {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }

        .feature-name {
            font-weight: 600;
            color: var(--color-text);
        }

        .feature-badge {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 500;
        }

        .feature-badge.default {
            background: var(--color-primary);
            color: white;
        }

        .feature-badge.usage {
            background: #6366f1;
            color: white;
        }

        .feature-description {
            font-size: 0.85rem;
            color: var(--color-text-muted);
            margin: 0;
            line-height: 1.4;
        }

        .feature-warning {
            font-size: 0.75rem;
            color: var(--color-warning);
            margin: 0.25rem 0 0;
            padding: 0.25rem 0.5rem;
            background: color-mix(in srgb, var(--color-warning) 10%, transparent);
            border-radius: 4px;
        }

        .feature-dependents {
            font-size: 0.75rem;
            color: var(--color-primary);
            margin: 0.25rem 0 0;
            padding: 0.25rem 0.5rem;
            background: color-mix(in srgb, var(--color-primary) 10%, transparent);
            border-radius: 4px;
        }

        .feature-item.has-warning {
            border-color: var(--color-warning);
        }

        .sticky-actions {
            position: sticky;
            bottom: 0;
            background: var(--color-bg);
            padding: 1rem;
            margin: 2rem -1.5rem -1.5rem;
            border-top: 1px solid var(--color-border);
            display: flex;
            gap: 1rem;
            justify-content: flex-start;
        }

        @media (max-width: 768px) {
            .feature-list {
                grid-template-columns: 1fr;
            }
        }

        /* Saving indicator */
        .feature-toggle.saving .toggle-slider {
            opacity: 0.6;
        }

        .feature-toggle.saving .toggle-slider::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 12px;
            height: 12px;
            border: 2px solid transparent;
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: translate(-50%, -50%) rotate(360deg); }
        }

        /* Success feedback */
        .feature-item.just-saved {
            animation: save-flash 0.5s ease;
        }

        @keyframes save-flash {
            0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-success) 0%, transparent); }
            50% { box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-success) 40%, transparent); }
        }
        </style>

        <script>
        async function resetToDefaults() {
            if (!await showConfirm('Reset all features to their default settings?')) {
                return;
            }

            const defaults = <?= json_encode(array_map(function($f) { return $f['default']; }, getAvailableFeatures())) ?>;

            document.querySelectorAll('.feature-item input[type="checkbox"]').forEach(checkbox => {
                const key = checkbox.value;
                const newState = defaults[key] === true;
                if (checkbox.checked !== newState) {
                    checkbox.checked = newState;
                    updateFeatureItemState(checkbox);
                    saveFeatureState(key, newState);
                }
            });
        }

        function updateFeatureItemState(checkbox) {
            const item = checkbox.closest('.feature-item');
            if (checkbox.checked) {
                item.classList.add('enabled');
                item.classList.remove('disabled');
            } else {
                item.classList.remove('enabled');
                item.classList.add('disabled');
            }
        }

        // Save feature state via AJAX
        async function saveFeatureState(feature, enabled) {
            const item = document.querySelector(`[data-feature="${feature}"]`);
            const toggle = item?.querySelector('.feature-toggle');

            // Add saving indicator
            if (toggle) {
                toggle.classList.add('saving');
            }

            try {
                const formData = new FormData();
                formData.append('action', 'toggle');
                formData.append('feature', feature);
                formData.append('enabled', enabled ? '1' : '0');

                const response = await fetch('<?= route('actions.features') ?>', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // Show brief success feedback
                    if (item) {
                        item.classList.add('just-saved');
                        setTimeout(() => item.classList.remove('just-saved'), 1000);
                    }
                } else {
                    showToast('Failed to save: ' + (data.error || 'Unknown error'), 'error');
                    // Revert the checkbox state
                    const checkbox = item?.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = !enabled;
                        updateFeatureItemState(checkbox);
                    }
                }
            } catch (error) {
                console.error('Error saving feature:', error);
                showToast('Failed to save feature state', 'error');
                // Revert the checkbox state
                const checkbox = item?.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !enabled;
                    updateFeatureItemState(checkbox);
                }
            } finally {
                if (toggle) {
                    toggle.classList.remove('saving');
                }
            }
        }

        // Update visual state when checkbox changes and save via AJAX
        document.querySelectorAll('.feature-item input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', async function() {
                const item = this.closest('.feature-item');
                const feature = item.dataset.feature;
                const enabled = this.checked;

                // Check dependencies first (for disabling)
                if (!enabled && !await checkDependencies(this)) {
                    return; // User cancelled
                }

                updateFeatureItemState(this);
                await saveFeatureState(feature, enabled);
            });
        });

        // Check if disabling a feature breaks dependencies or has existing data
        // Returns true if user confirms or no warnings, false if user cancels
        async function checkDependencies(checkbox) {
            if (checkbox.checked) return true; // Only check when disabling

            const item = checkbox.closest('.feature-item');
            const featureName = item.querySelector('.feature-name').textContent;
            const usage = parseInt(item.dataset.usage, 10) || 0;
            const dependents = JSON.parse(item.dataset.dependents || '[]');

            let warnings = [];

            // Check for existing data
            if (usage > 0) {
                warnings.push(`This feature has ${usage.toLocaleString()} existing item(s). Disabling will hide this data but not delete it.`);
            }

            // Check if any dependents are still enabled
            const enabledDependents = [];
            dependents.forEach(dep => {
                const depItem = document.querySelector(`[data-feature="${dep}"]`);
                if (depItem) {
                    const depCheckbox = depItem.querySelector('input[type="checkbox"]');
                    if (depCheckbox && depCheckbox.checked) {
                        const name = depItem.querySelector('.feature-name').textContent;
                        enabledDependents.push(name);
                    }
                }
            });

            if (enabledDependents.length > 0) {
                warnings.push(`${enabledDependents.length} feature(s) depend on this: ${enabledDependents.join(', ')}`);
            }

            if (warnings.length > 0) {
                const message = `Warning for "${featureName}":\n\n` + warnings.join('\n\n') + '\n\nContinue?';
                if (!await showConfirm(message)) {
                    checkbox.checked = true;
                    updateFeatureItemState(checkbox);
                    return false;
                }
            }
            return true;
        }
        </script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
