<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/features.php';

// Require admin permission
requireAdminPage('isAdmin', 'You do not have permission to manage features.');

$pageTitle = 'Feature Management';
$activePage = '';
$adminPage = 'features';

$message = '';
$error = '';

// Handle form submission
// CSRF protection for all POST requests
if (($csrfError = Csrf::postError()) !== null) {
    $error = $csrfError;
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

                <form method="post" class="features-form settings-form">
                    <?= csrf_field() ?>
                    <div class="features-grid">
                        <?php foreach ($featuresByCategory as $category => $features): ?>
                        <details class="settings-section" open>
                            <summary><h2><?= htmlspecialchars($category) ?></h2></summary>
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
                        </details>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions sticky-actions">
                        <button type="submit" class="btn btn-primary">Save Feature Settings</button>
                        <button type="button" class="btn btn-secondary" data-action="reset-defaults">Reset to Defaults</button>
                    </div>
                </form>
            </div>
        </div>

        <script<?= csp_nonce_attr() ?>>
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
