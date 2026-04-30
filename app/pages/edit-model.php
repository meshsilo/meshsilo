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

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $creator = trim($_POST['creator'] ?? '');
    $sourceUrl = trim($_POST['source_url'] ?? '');
    $license = trim($_POST['license'] ?? '');
    $collection = trim($_POST['collection'] ?? '');
    $categoryIds = $_POST['categories'] ?? [];
    $nestFolders = isset($_POST['nest_folders']) ? 1 : 0;

    if (!Csrf::validate()) {
        $message = 'Security validation failed. Please try again.';
        $messageType = 'error';
    } elseif (empty($name)) {
        $message = 'Name is required';
        $messageType = 'error';
    } else {
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

        // Auto-create new collection (matches upload.php pattern)
        if (!empty($collection)) {
            try {
                $insColl = $db->prepare('INSERT OR IGNORE INTO collections (name) VALUES (:name)');
                $insColl->bindValue(':name', $collection, PDO::PARAM_STR);
                $insColl->execute();
            } catch (\Exception $e) {
                // Ignore — already exists
            }
        }

        // Update categories using prepared statements
        $deleteStmt = $db->prepare('DELETE FROM model_categories WHERE model_id = :model_id');
        $deleteStmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
        $deleteStmt->execute();

        $insertStmt = $db->prepare('INSERT INTO model_categories (model_id, category_id) VALUES (:model_id, :category_id)');
        foreach ($categoryIds as $catId) {
            $insertStmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
            $insertStmt->bindValue(':category_id', (int)$catId, PDO::PARAM_INT);
            $insertStmt->execute();
        }

        logActivity('edit', 'model', $modelId, $name);

        $message = 'Model updated successfully';
        $messageType = 'success';

        // Refresh model data
        $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $model = $result->fetchArray(PDO::FETCH_ASSOC);

        // Refresh categories
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
                <p><a href="<?= route('model.show', ['id' => $modelId]) ?>">&larr; Back to model</a></p>
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
                    <input type="text" id="creator" name="creator" class="form-input" value="<?= htmlspecialchars($model['creator'] ?? '') ?>">
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

                <?php if (!empty($categories)): ?>
                <fieldset class="form-group">
                    <legend>Categories</legend>
                    <div class="checkbox-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.5rem;">
                        <?php foreach ($categories as $cat): ?>
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $selectedCategories) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

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
                            <button type="button" class="model-tag-remove" aria-label="Remove tag <?= htmlspecialchars($tag['name']) ?>">&times;</button>
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
