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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

// Get features grouped by category
$featuresByCategory = getFeaturesByCategory();

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
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" class="features-form">
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
                                       data-dependents="<?= htmlspecialchars(json_encode($dependents)) ?>">
                                    <div class="feature-toggle">
                                        <input type="checkbox"
                                               name="features[]"
                                               value="<?= htmlspecialchars($feature['key']) ?>"
                                               <?= $feature['enabled'] ? 'checked' : '' ?>>
                                        <span class="toggle-slider"></span>
                                    </div>
                                    <div class="feature-info">
                                        <div class="feature-header">
                                            <span class="feature-icon" data-icon="<?= htmlspecialchars($feature['icon']) ?>">
                                                <?= getFeatureIcon($feature['icon']) ?>
                                            </span>
                                            <span class="feature-name"><?= htmlspecialchars($feature['name']) ?></span>
                                            <?php if ($feature['default']): ?>
                                            <span class="feature-badge default">Default</span>
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
                        <button type="button" class="btn btn-secondary" onclick="resetToDefaults()">Reset to Defaults</button>
                    </div>
                </form>
            </div>
        </div>

        <style>
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
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
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
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .feature-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .feature-item.enabled {
            border-color: var(--success-color);
            background: rgba(34, 197, 94, 0.05);
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
            background: var(--border-color);
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
            background: var(--success-color);
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
            color: var(--text-primary);
        }

        .feature-badge {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 500;
        }

        .feature-badge.default {
            background: var(--primary-color);
            color: white;
        }

        .feature-description {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin: 0;
            line-height: 1.4;
        }

        .sticky-actions {
            position: sticky;
            bottom: 0;
            background: var(--bg-primary);
            padding: 1rem;
            margin: 2rem -1.5rem -1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            justify-content: flex-start;
        }

        @media (max-width: 768px) {
            .feature-list {
                grid-template-columns: 1fr;
            }
        }
        </style>

        <script>
        function resetToDefaults() {
            if (!confirm('Reset all features to their default settings?')) {
                return;
            }

            const defaults = <?= json_encode(array_map(function($f) { return $f['default']; }, getAvailableFeatures())) ?>;

            document.querySelectorAll('.feature-item input[type="checkbox"]').forEach(checkbox => {
                const key = checkbox.value;
                checkbox.checked = defaults[key] === true;
                updateFeatureItemState(checkbox);
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

        // Update visual state when checkbox changes
        document.querySelectorAll('.feature-item input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateFeatureItemState(this);
            });
        });
        </script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
