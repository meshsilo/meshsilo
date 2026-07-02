<?php
require_once 'includes/config.php';

if (!canEdit()) {
    header('Location: ' . route('login'));
    exit;
}

$db = getDB();
$modelId = (int)($_GET['id'] ?? 0);

if (!$modelId) {
    header('Location: ' . route('home'));
    exit;
}

// Get model
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    header('Location: ' . route('home'));
    exit;
}

// Check ownership - must be owner or admin (matches update-model.php validation)
$user = getCurrentUser();
if ($model['user_id'] !== null && (int)$model['user_id'] !== (int)$user['id'] && !$user['is_admin']) {
    $_SESSION['error'] = 'You can only edit your own models.';
    header('Location: ' . route('model.show', ['id' => $modelId]));
    exit;
}

$pageTitle = 'Edit: ' . $model['name'];
$activePage = 'browse';

// Get current tags
$modelTags = getModelTags($modelId);

// Get all categories
$categories = [];
$catResult = $db->query('SELECT * FROM categories ORDER BY name');
while ($row = $catResult->fetchArray(PDO::FETCH_ASSOC)) {
    $categories[] = $row;
}

// Get current categories
$stmt = $db->prepare('SELECT category_id FROM model_categories WHERE model_id = :model_id');
$stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
$catIdsResult = $stmt->execute();
$selectedCategories = [];
while ($row = $catIdsResult->fetchArray(PDO::FETCH_ASSOC)) {
    $selectedCategories[] = $row['category_id'];
}

// Load existing collections for the datalist (same pattern as upload.php)
$collections = [];
$collResult = $db->query('SELECT name FROM collections ORDER BY name');
while ($row = $collResult->fetchArray(PDO::FETCH_ASSOC)) {
    $collections[] = $row['name'];
}

// Load distinct creators for autocomplete
$creators = [];
$creatorsResult = $db->query("SELECT DISTINCT creator FROM models WHERE creator != '' ORDER BY creator LIMIT 200");
while ($row = $creatorsResult->fetchArray(PDO::FETCH_ASSOC)) {
    $creators[] = $row['creator'];
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $creator = trim($_POST['creator'] ?? '');
    $sourceUrl = trim($_POST['source_url'] ?? '');
    // Reject control-character obfuscation (e.g. "java\tscript:") before the scheme
    // test: parse_url() returns an empty scheme for these, but browsers strip the
    // control chars and execute the underlying javascript: URL. A valid URL has none.
    if ($sourceUrl !== '' && preg_match('/[\x00-\x1F\x7F]/', $sourceUrl)) {
        $sourceUrl = '';
    }
    // Reject non-http(s) schemes (e.g. javascript:) to prevent stored XSS; allow empty and relative URLs.
    $sourceScheme = strtolower((string)parse_url($sourceUrl, PHP_URL_SCHEME));
    if ($sourceUrl !== '' && $sourceScheme !== '' && !in_array($sourceScheme, ['http', 'https'], true)) {
        $sourceUrl = '';
    }
    $license = trim($_POST['license'] ?? '');
    $collection = trim($_POST['collection'] ?? '');
    $categoryIds = $_POST['categories'] ?? [];
    $newCategoryNames = array_filter(array_map('trim', explode(',', $_POST['new_categories'] ?? '')));
    $nestFolders = isset($_POST['nest_folders']) ? 1 : 0;

    if (!Csrf::validate()) {
        $message = 'Security validation failed. Please try again.';
        $messageType = 'error';
    } elseif (empty($name)) {
        $message = 'Name is required';
        $messageType = 'error';
    } else {
        // Normalize collection: resolve to canonical name or insert as new
        if (!empty($collection)) {
            $colCheck = $db->prepare('SELECT name FROM collections WHERE LOWER(name) = LOWER(:name)');
            $colCheck->bindValue(':name', $collection, PDO::PARAM_STR);
            $colResult = $colCheck->execute();
            $existingCol = $colResult ? $colResult->fetchArray(PDO::FETCH_ASSOC) : null;
            if ($existingCol) {
                $collection = $existingCol['name'];
            } else {
                try {
                    $insColl = $db->prepare('INSERT INTO collections (name) VALUES (:name)');
                    $insColl->bindValue(':name', $collection, PDO::PARAM_STR);
                    $insColl->execute();
                } catch (\Exception $e) {
                    // ignore race condition
                }
            }
        }

        // Update model
        $stmt = $db->prepare('UPDATE models SET name = :name, description = :description, creator = :creator, source_url = :source_url, license = :license, collection = :collection, nest_folders = :nest_folders WHERE id = :id');
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':creator', $creator);
        $stmt->bindValue(':source_url', $sourceUrl);
        $stmt->bindValue(':license', $license);
        $stmt->bindValue(':collection', $collection);
        $stmt->bindValue(':nest_folders', $nestFolders, PDO::PARAM_INT);
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $stmt->execute();

        // Update categories using prepared statements
        $deleteStmt = $db->prepare('DELETE FROM model_categories WHERE model_id = :model_id');
        $deleteStmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $deleteStmt->execute();

        $insertStmt = $db->prepare('INSERT OR IGNORE INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
        foreach ($categoryIds as $catId) {
            $insertStmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $insertStmt->bindValue(':category_id', (int)$catId, PDO::PARAM_INT);
            $insertStmt->execute();
        }

        // Create and link new categories typed by the user
        if (!empty($newCategoryNames)) {
            foreach ($newCategoryNames as $catName) {
                $catCheck = $db->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name)');
                $catCheck->bindValue(':name', $catName, PDO::PARAM_STR);
                $catResult = $catCheck->execute();
                $existingCat = $catResult ? $catResult->fetchArray(PDO::FETCH_ASSOC) : null;
                if ($existingCat) {
                    $catId = (int)$existingCat['id'];
                } else {
                    $catInsert = $db->prepare('INSERT INTO categories (name) VALUES (:name)');
                    $catInsert->bindValue(':name', $catName, PDO::PARAM_STR);
                    $catInsert->execute();
                    $catId = (int)$db->lastInsertRowID();
                    if (function_exists('invalidateCategoriesCache')) {
                        invalidateCategoriesCache();
                    }
                }
                // Add to categories list so checkboxes reflect it on the refresh below
                if (!in_array($catId, $categoryIds)) {
                    $catInsertLink = $db->prepare('INSERT OR IGNORE INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
                    $catInsertLink->bindValue(':model_id', $modelId, PDO::PARAM_INT);
                    $catInsertLink->bindValue(':category_id', $catId, PDO::PARAM_INT);
                    $catInsertLink->execute();
                }
            }
        }

        logActivity('edit', 'model', $modelId, $name);

        $message = 'Model updated successfully';
        $messageType = 'success';

        // Refresh model data
        $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $model = $result->fetchArray(PDO::FETCH_ASSOC);

        // Refresh all categories (new ones may have been created above)
        $categories = [];
        $catListResult = $db->query('SELECT * FROM categories ORDER BY name');
        while ($row = $catListResult->fetchArray(PDO::FETCH_ASSOC)) {
            $categories[] = $row;
        }

        // Refresh selected categories for this model
        $stmt = $db->prepare('SELECT category_id FROM model_categories WHERE model_id = :model_id');
        $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $catIdsResult = $stmt->execute();
        $selectedCategories = [];
        while ($row = $catIdsResult->fetchArray(PDO::FETCH_ASSOC)) {
            $selectedCategories[] = $row['category_id'];
        }
    }
}

$licenseOptions = getLicenseOptions();
$needsEditModelJs = true;

require_once 'includes/header.php';
?>

        <div class="page-container">
            <div class="page-header">
                <h1>Edit Model</h1>
                <p><a href="<?= route('model.show', ['id' => $modelId]) ?>"><i class="fa-solid fa-arrow-left"></i> Back to model</a></p>
            </div>

            <?php if ($message): ?>
            <div role="<?= $messageType === 'success' ? 'status' : 'alert' ?>" class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form method="post" class="upload-form" style="max-width: 800px;">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" class="form-input" value="<?= htmlspecialchars($model['name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-input" rows="6"><?= htmlspecialchars($model['description'] ?? '') ?></textarea>
                    <small class="form-hint">Supports Markdown: **bold**, *italic*, `code`, [links](url), lists, headings (##), and more.</small>
                    <details class="markdown-preview-toggle" style="margin-top:0.5rem">
                        <summary style="cursor:pointer;color:var(--color-text-muted);font-size:0.85rem">Preview</summary>
                        <div id="md-preview" class="markdown-content" style="padding:1rem;border:1px solid var(--color-border);border-radius:var(--radius);margin-top:0.5rem;min-height:3rem;background:var(--color-surface)"></div>
                    </details>
                </div>

                <div class="form-group">
                    <label for="creator">Creator</label>
                    <input type="text" id="creator" name="creator" class="form-input" autocomplete="off" list="creators-list" value="<?= htmlspecialchars($model['creator'] ?? '') ?>">
                    <datalist id="creators-list">
                        <?php foreach ($creators as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label for="collection">Collection</label>
                    <input type="text" id="collection" name="collection" class="form-input" placeholder="Collection name (e.g., Gridfinity, Voron)" list="collections-list" value="<?= htmlspecialchars($model['collection'] ?? '') ?>">
                    <datalist id="collections-list">
                        <?php foreach ($collections as $col): ?>
                        <option value="<?= htmlspecialchars($col) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label for="source_url">Source URL</label>
                    <input type="url" id="source_url" name="source_url" class="form-input" value="<?= htmlspecialchars($model['source_url'] ?? '') ?>" placeholder="https://...">
                </div>

                <div class="form-group">
                    <label for="license">License</label>
                    <select id="license" name="license" class="form-input">
                        <?php foreach ($licenseOptions as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($model['license'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <fieldset class="form-group">
                    <legend>Categories</legend>
                    <?php if (!empty($categories)): ?>
                    <div class="checkbox-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.5rem;">
                        <?php foreach ($categories as $cat): ?>
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $selectedCategories) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="form-group" style="margin-top:0.75rem;margin-bottom:0">
                        <input type="text" name="new_categories" class="form-input" placeholder="Add new categories (comma-separated, e.g. Minis, Terrain)">
                        <small class="form-help">New categories will be created automatically. Existing names are matched without regard to capitalization.</small>
                    </div>
                </fieldset>

                <div class="form-group">
                    <label class="checkbox-label" style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="checkbox" name="nest_folders" value="1" <?= !empty($model['nest_folders']) ? 'checked' : '' ?>>
                        Nest subfolders inside parent folders
                    </label>
                    <small class="form-hint">When enabled, folders with paths like "Parent/Child" will be displayed as nested hierarchies on the model page.</small>
                </div>

                <div class="form-group">
                    <label>Tags</label>
                    <div class="model-tags" id="current-tags">
                        <?php foreach ($modelTags as $tag): ?>
                        <span class="model-tag" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>;" data-tag-id="<?= $tag['id'] ?>">
                            <?= htmlspecialchars($tag['name']) ?>
                            <button type="button" class="model-tag-remove" aria-label="Remove tag <?= htmlspecialchars($tag['name']) ?>"><i class="fa-solid fa-xmark"></i></button>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="tag-input-wrapper" style="position: relative; margin-top: 0.5rem;">
                        <input type="text" id="tag-input" class="form-input" placeholder="Add tag..." style="width: 200px;">
                        <div id="tag-suggestions" class="tag-suggestions" style="display: none;"></div>
                    </div>
                </div>

                <div class="form-actions" style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="<?= route('model.show', ['id' => $modelId]) ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <script>
        window.EditModelPageConfig = {
            allTags: <?= json_encode(getAllTags()) ?>,
            modelId: <?= $modelId ?>
        };
        </script>

<?php require_once 'includes/footer.php'; ?>
