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

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $creator = trim($_POST['creator'] ?? '');
    $sourceUrl = trim($_POST['source_url'] ?? '');
    $license = trim($_POST['license'] ?? '');
    $categoryIds = $_POST['categories'] ?? [];

    if (!Csrf::validate()) {
        $message = 'Security validation failed. Please try again.';
        $messageType = 'error';
    } elseif (empty($name)) {
        $message = 'Name is required';
        $messageType = 'error';
    } else {
        // Update model
        $stmt = $db->prepare('UPDATE models SET name = :name, description = :description, creator = :creator, source_url = :source_url, license = :license WHERE id = :id');
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':creator', $creator);
        $stmt->bindValue(':source_url', $sourceUrl);
        $stmt->bindValue(':license', $license);
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $stmt->execute();

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

require_once 'includes/header.php';
?>

        <div class="page-container">
            <div class="page-header">
                <h1>Edit Model</h1>
                <p><a href="<?= route('model.show', ['id' => $modelId]) ?>">&larr; Back to model</a></p>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
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
                    <script>
                    (function() {
                        var ta = document.getElementById('description');
                        var preview = document.getElementById('md-preview');
                        var timer = null;
                        function updatePreview() {
                            var text = ta.value;
                            if (!text.trim()) { preview.innerHTML = '<em style="color:var(--color-text-muted)">Nothing to preview</em>'; return; }
                            // Simple Markdown rendering (client-side)
                            var html = text
                                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                                .replace(/^### (.+)$/gm, '<h4>$1</h4>')
                                .replace(/^## (.+)$/gm, '<h3>$1</h3>')
                                .replace(/^# (.+)$/gm, '<h2>$1</h2>')
                                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                                .replace(/`(.+?)`/g, '<code>$1</code>')
                                .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>')
                                .replace(/^- (.+)$/gm, '<li>$1</li>')
                                .replace(/\n/g, '<br>');
                            preview.innerHTML = html;
                        }
                        ta.addEventListener('input', function() {
                            clearTimeout(timer);
                            timer = setTimeout(updatePreview, 200);
                        });
                        document.querySelector('.markdown-preview-toggle').addEventListener('toggle', updatePreview);
                    })();
                    </script>
                </div>

                <div class="form-group">
                    <label for="creator">Creator</label>
                    <input type="text" id="creator" name="creator" class="form-input" value="<?= htmlspecialchars($model['creator'] ?? '') ?>">
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
                <div class="form-group">
                    <label>Categories</label>
                    <div class="checkbox-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.5rem;">
                        <?php foreach ($categories as $cat): ?>
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" <?= in_array($cat['id'], $selectedCategories) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Tags</label>
                    <div class="model-tags" id="current-tags">
                        <?php foreach ($modelTags as $tag): ?>
                        <span class="model-tag" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>;" data-tag-id="<?= $tag['id'] ?>">
                            <?= htmlspecialchars($tag['name']) ?>
                            <button type="button" class="model-tag-remove" onclick="removeTag(<?= $modelId ?>, <?= $tag['id'] ?>, this.parentElement)">&times;</button>
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
        const allTags = <?= json_encode(getAllTags()) ?>;
        const tagInput = document.getElementById('tag-input');
        const tagSuggestions = document.getElementById('tag-suggestions');
        const modelId = <?= $modelId ?>;

        if (tagInput) {
            tagInput.addEventListener('input', function() {
                const value = this.value.toLowerCase().trim();
                if (value.length < 1) {
                    tagSuggestions.style.display = 'none';
                    return;
                }

                const matching = allTags.filter(t => t.name.toLowerCase().includes(value));
                if (matching.length === 0 && value.length > 0) {
                    var safeValue = value.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
                    tagSuggestions.innerHTML =
                        '<div class="tag-suggestion" onclick="addTag(this.dataset.name)" data-name="' + safeValue + '">' +
                            '<span class="tag-color-dot" style="background-color: var(--color-primary);"></span>' +
                            '<span>Create "' + safeValue + '"</span>' +
                        '</div>';
                } else {
                    tagSuggestions.innerHTML = matching.map(function(t) {
                        var safeName = t.name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
                        return '<div class="tag-suggestion" onclick="addTag(this.dataset.name)" data-name="' + safeName + '">' +
                            '<span class="tag-color-dot" style="background-color:' + t.color + ';"></span>' +
                            '<span>' + safeName + '</span>' +
                        '</div>';
                    }).join('');
                }
                tagSuggestions.style.display = matching.length > 0 || value.length > 0 ? 'block' : 'none';
            });

            tagInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const value = this.value.trim();
                    if (value) addTag(value);
                }
            });

            tagInput.addEventListener('blur', function() {
                setTimeout(() => { tagSuggestions.style.display = 'none'; }, 200);
            });
        }

        async function addTag(tagName) {
            try {
                const response = await fetch('/actions/tag', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=add&model_id=' + modelId + '&tag_name=' + encodeURIComponent(tagName)
                });
                const data = await response.json();
                if (data.success && data.tag) {
                    const tagsContainer = document.getElementById('current-tags');
                    const tagEl = document.createElement('span');
                    tagEl.className = 'model-tag';
                    tagEl.style.setProperty('--tag-color', data.tag.color);
                    tagEl.dataset.tagId = data.tag.id;
                    tagEl.textContent = data.tag.name + ' ';
                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'model-tag-remove';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.onclick = function() { removeTag(modelId, data.tag.id, tagEl); };
                    tagEl.appendChild(removeBtn);
                    tagsContainer.appendChild(tagEl);
                    tagInput.value = '';
                    tagSuggestions.style.display = 'none';
                } else {
                    showToast('Failed to add tag: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to add tag:', err);
            }
        }

        async function removeTag(modelId, tagId, element) {
            try {
                const response = await fetch('/actions/tag', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=remove&model_id=' + modelId + '&tag_id=' + tagId
                });
                const data = await response.json();
                if (data.success) {
                    element.remove();
                } else {
                    showToast('Failed to remove tag: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to remove tag:', err);
            }
        }
        </script>

<?php require_once 'includes/footer.php'; ?>
