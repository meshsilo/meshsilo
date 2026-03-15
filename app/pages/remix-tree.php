<?php
/**
 * Remix Tree Visualization
 *
 * Displays the remix/fork tree for a model showing original and derivatives
 */
require_once 'includes/config.php';

$db = getDB();

// Get model ID from URL
$modelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$modelId) {
    header('Location: ' . route('browse'));
    exit;
}

// Get model details
$stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
$stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
$result = $stmt->execute();
$model = $result->fetchArray(PDO::FETCH_ASSOC);

if (!$model) {
    header('Location: ' . route('browse'));
    exit;
}

// Function to build the remix tree (upward - find originals)
function getOriginalChain($db, $modelId, $visited = []) {
    if (in_array($modelId, $visited)) return null; // Prevent infinite loops
    $visited[] = $modelId;

    $stmt = $db->prepare('SELECT * FROM models WHERE id = :id AND parent_id IS NULL');
    $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
    $model = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

    if (!$model) return null;

    $node = [
        'id' => $model['id'],
        'name' => $model['name'],
        'thumbnail' => $model['thumbnail'],
        'created_at' => $model['created_at'],
        'user_id' => $model['user_id'],
        'external_source' => $model['external_source_url'] ?? null,
        'parent' => null
    ];

    // Check if this is a remix of another model
    if (!empty($model['remix_of'])) {
        $node['parent'] = getOriginalChain($db, $model['remix_of'], $visited);
    }

    return $node;
}

// Function to find all remixes (downward - find derivatives)
function getRemixes($db, $modelId, $depth = 0, $maxDepth = 5) {
    if ($depth > $maxDepth) return [];

    $stmt = $db->prepare('
        SELECT m.*, u.username
        FROM models m
        LEFT JOIN users u ON m.user_id = u.id
        WHERE m.remix_of = :model_id AND m.parent_id IS NULL
        ORDER BY m.created_at DESC
    ');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();

    $remixes = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $remixes[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'thumbnail' => $row['thumbnail'],
            'created_at' => $row['created_at'],
            'user_id' => $row['user_id'],
            'username' => $row['username'],
            'children' => getRemixes($db, $row['id'], $depth + 1, $maxDepth)
        ];
    }

    return $remixes;
}

// Get related models marked as remixes (manual relationships)
function getRelatedRemixes($db, $modelId) {
    $stmt = $db->prepare('
        SELECT rm.*, m.name, m.thumbnail, m.created_at, u.username,
               rm.remix_notes, rm.is_remix
        FROM related_models rm
        JOIN models m ON rm.related_model_id = m.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE rm.model_id = :model_id AND rm.is_remix = 1 AND m.parent_id IS NULL
        ORDER BY m.created_at DESC
    ');
    $stmt->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $result = $stmt->execute();

    $remixes = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $remixes[] = $row;
    }

    return $remixes;
}

// Build the tree
$originalChain = getOriginalChain($db, $modelId);
$remixes = getRemixes($db, $modelId);
$relatedRemixes = getRelatedRemixes($db, $modelId);

// Find the root of the tree
$root = $originalChain;
while ($root && $root['parent']) {
    $root = $root['parent'];
}

// Get user for display
$stmt = $db->prepare('SELECT username FROM users WHERE id = :id');
$stmt->bindValue(':id', $model['user_id'], PDO::PARAM_INT);
$modelUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

$pageTitle = 'Remix Tree: ' . $model['name'];
$activePage = 'browse';

require_once 'includes/header.php';
?>

<div class="container">
    <div class="breadcrumb">
        <a href="<?= route('browse') ?>">Models</a> &raquo;
        <a href="<?= route('model.show', ['id' => $modelId]) ?>"><?= htmlspecialchars($model['name']) ?></a> &raquo;
        <span>Remix Tree</span>
    </div>

    <div class="page-header">
        <h1>Remix Tree</h1>
        <p class="subtitle"><?= htmlspecialchars($model['name']) ?></p>
    </div>

    <?php if (isLoggedIn()): ?>
    <div class="tree-actions">
        <button type="button" class="btn btn-primary" id="mark-remix-btn">
            Mark as Remix of...
        </button>
        <?php if ($model['remix_of'] || $model['external_source_url']): ?>
        <span class="remix-info">
            <?php if ($model['remix_of']): ?>
            This is a remix of
            <a href="<?= route('model.show', ['id' => $model['remix_of']]) ?>">another model</a>
            <?php elseif ($model['external_source_url']): ?>
            Based on <a href="<?= htmlspecialchars($model['external_source_url']) ?>" target="_blank" rel="noopener">external source</a>
            <?php endif; ?>
        </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Tree Visualization -->
    <div class="tree-container">
        <?php if ($root && ($root['id'] != $modelId || !empty($remixes))): ?>
        <div class="tree-section">
            <h3>Model Lineage</h3>
            <div class="remix-tree" id="remix-tree">
                <?php
                // Render the tree starting from root
                function renderTreeNode($node, $currentId, $isRemix = false) {
                    $isCurrent = $node['id'] == $currentId;
                    ?>
                    <div class="tree-node <?= $isCurrent ? 'current' : '' ?> <?= $isRemix ? 'remix' : 'original' ?>">
                        <a href="<?= route('model.show', ['id' => $node['id']]) ?>" class="node-link">
                            <?php if ($node['thumbnail']): ?>
                            <img src="<?= basePath('assets/' . $node['thumbnail']) ?>" alt="<?= htmlspecialchars($node['name']) ?>" class="node-thumbnail" loading="lazy" decoding="async">
                            <?php else: ?>
                            <div class="node-thumbnail placeholder">&#9653;</div>
                            <?php endif; ?>
                            <div class="node-info">
                                <span class="node-name"><?= htmlspecialchars($node['name']) ?></span>
                                <?php if (isset($node['username'])): ?>
                                <span class="node-author">by <?= htmlspecialchars($node['username']) ?></span>
                                <?php endif; ?>
                                <?php if ($node['external_source']): ?>
                                <span class="node-external">External source</span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php if ($isCurrent): ?>
                        <span class="current-badge">Current</span>
                        <?php endif; ?>
                    </div>
                    <?php
                }

                function renderTree($node, $currentId, $remixes = []) {
                    if (!$node) return;
                    ?>
                    <div class="tree-level">
                        <?php renderTreeNode($node, $currentId, false); ?>

                        <?php if (!empty($remixes) || ($node['id'] == $currentId)): ?>
                        <div class="tree-children">
                            <?php
                            // If this is the current node, show its remixes
                            if ($node['id'] == $currentId && !empty($remixes)) {
                                foreach ($remixes as $remix) {
                                    ?>
                                    <div class="tree-branch">
                                        <div class="branch-connector"></div>
                                        <?php renderRemixBranch($remix, $currentId); ?>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php
                }

                function renderRemixBranch($remix, $currentId) {
                    renderTreeNode($remix, $currentId, true);
                    if (!empty($remix['children'])) {
                        ?>
                        <div class="tree-children">
                            <?php foreach ($remix['children'] as $child): ?>
                            <div class="tree-branch">
                                <div class="branch-connector"></div>
                                <?php renderRemixBranch($child, $currentId); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php
                    }
                }

                // Render the chain from root to current
                $chain = [];
                $current = $originalChain;
                while ($current) {
                    array_unshift($chain, $current);
                    $current = $current['parent'];
                }

                foreach ($chain as $index => $node) {
                    if ($index > 0) {
                        echo '<div class="tree-connector vertical"></div>';
                    }
                    if ($node['id'] == $modelId) {
                        renderTree($node, $modelId, $remixes);
                    } else {
                        renderTreeNode($node, $modelId, false);
                    }
                }
                ?>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-tree">
            <p>This model has no known remixes or originals.</p>
            <?php if (isLoggedIn()): ?>
            <p>You can mark this model as a remix of another model using the button above.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($relatedRemixes)): ?>
        <div class="tree-section">
            <h3>Related Remixes (Manual Links)</h3>
            <div class="related-remixes">
                <?php foreach ($relatedRemixes as $related): ?>
                <div class="related-item">
                    <a href="<?= route('model.show', ['id' => $related['related_model_id']]) ?>">
                        <?php if ($related['thumbnail']): ?>
                        <img src="<?= basePath('assets/' . $related['thumbnail']) ?>" alt="<?= htmlspecialchars($related['name']) ?>" class="related-thumbnail" loading="lazy" decoding="async">
                        <?php else: ?>
                        <div class="related-thumbnail placeholder">&#9653;</div>
                        <?php endif; ?>
                        <div class="related-info">
                            <span class="related-name"><?= htmlspecialchars($related['name']) ?></span>
                            <?php if ($related['username']): ?>
                            <span class="related-author">by <?= htmlspecialchars($related['username']) ?></span>
                            <?php endif; ?>
                            <?php if ($related['remix_notes']): ?>
                            <span class="related-notes"><?= htmlspecialchars($related['remix_notes']) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Mark as Remix Modal -->
<div id="remix-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="remix-modal-title" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="remix-modal-title">Mark as Remix</h3>
            <button type="button" class="modal-close" aria-label="Close" onclick="closeRemixModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="remix-source-type">Source Type</label>
                <select id="remix-source-type" class="form-control">
                    <option value="internal">Another model in Silo</option>
                    <option value="external">External URL (Thingiverse, Printables, etc.)</option>
                </select>
            </div>

            <div id="internal-source" class="source-input">
                <div class="form-group">
                    <label for="remix-model-search">Search for original model</label>
                    <input type="text" id="remix-model-search" class="form-control" placeholder="Search models...">
                    <div id="model-search-results" class="search-results"></div>
                </div>
                <input type="hidden" id="selected-model-id">
            </div>

            <div id="external-source" class="source-input" style="display: none;">
                <div class="form-group">
                    <label for="remix-external-url">External URL</label>
                    <input type="url" id="remix-external-url" class="form-control" placeholder="https://www.thingiverse.com/thing:12345">
                </div>
            </div>

            <div class="form-group">
                <label for="remix-notes">Notes (optional)</label>
                <textarea id="remix-notes" class="form-control" rows="2" placeholder="What modifications did you make?"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeRemixModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="save-remix-btn">Save</button>
        </div>
    </div>
</div>

<style>
.tree-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
}

.remix-info {
    color: var(--color-text-muted);
}

.tree-container {
    margin-top: 1rem;
}

.tree-section {
    background: var(--card-bg);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.tree-section h3 {
    margin: 0 0 1.5rem 0;
    font-size: 1.1rem;
}

.remix-tree {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.tree-level {
    display: flex;
    flex-direction: column;
}

.tree-node {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: var(--color-bg);
    border: 2px solid var(--color-border);
    border-radius: 8px;
    margin: 0.25rem 0;
    position: relative;
}

.tree-node.current {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 20%, transparent);
}

.tree-node.remix {
    border-color: var(--color-success);
}

.node-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
    color: inherit;
}

.node-thumbnail {
    width: 48px;
    height: 48px;
    border-radius: 4px;
    object-fit: cover;
}

.node-thumbnail.placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-border);
    font-size: 1.5rem;
    color: var(--color-text-muted);
}

.node-info {
    display: flex;
    flex-direction: column;
}

.node-name {
    font-weight: 500;
}

.node-author {
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.node-external {
    font-size: 0.75rem;
    color: var(--color-warning);
}

.current-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: var(--color-primary);
    color: white;
    font-size: 0.7rem;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
}

.tree-connector {
    width: 2px;
    height: 20px;
    background: var(--color-border);
    margin-left: 24px;
}

.tree-children {
    margin-left: 2rem;
    padding-left: 1rem;
    border-left: 2px solid var(--color-border);
}

.tree-branch {
    position: relative;
    padding-left: 1rem;
}

.branch-connector {
    position: absolute;
    left: 0;
    top: 50%;
    width: 1rem;
    height: 2px;
    background: var(--color-border);
}

.empty-tree {
    text-align: center;
    padding: 3rem;
    color: var(--color-text-muted);
}

.related-remixes {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.related-item {
    background: var(--color-bg);
    border-radius: 8px;
    overflow: hidden;
}

.related-item a {
    display: block;
    text-decoration: none;
    color: inherit;
}

.related-thumbnail {
    width: 100%;
    aspect-ratio: 4/3;
    object-fit: cover;
}

.related-thumbnail.placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-border);
    font-size: 2rem;
    color: var(--color-text-muted);
}

.related-info {
    padding: 0.75rem;
}

.related-name {
    display: block;
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.related-author {
    display: block;
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.related-notes {
    display: block;
    font-size: 0.8rem;
    color: var(--color-text-muted);
    font-style: italic;
    margin-top: 0.25rem;
}

/* Modal */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: var(--card-bg);
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--color-border);
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--color-text-muted);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--color-border);
}

.search-results {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--color-border);
    border-radius: 4px;
    margin-top: 0.5rem;
    display: none;
}

.search-results.active {
    display: block;
}

.search-result-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem;
    cursor: pointer;
    border-bottom: 1px solid var(--color-border);
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-item:hover {
    background: var(--color-bg);
}

.search-result-item.selected {
    background: var(--color-primary);
    color: white;
}

.search-result-item img {
    width: 40px;
    height: 40px;
    border-radius: 4px;
    object-fit: cover;
}

@media (max-width: 768px) {
    .tree-actions {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<script>
const modelId = <?= $modelId ?>;
let searchTimeout = null;

document.getElementById('mark-remix-btn')?.addEventListener('click', function() {
    document.getElementById('remix-modal').style.display = 'flex';
});

function closeRemixModal() {
    document.getElementById('remix-modal').style.display = 'none';
}

document.getElementById('remix-source-type').addEventListener('change', function() {
    const isInternal = this.value === 'internal';
    document.getElementById('internal-source').style.display = isInternal ? 'block' : 'none';
    document.getElementById('external-source').style.display = isInternal ? 'none' : 'block';
});

// Model search
document.getElementById('remix-model-search').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();

    if (query.length < 2) {
        document.getElementById('model-search-results').classList.remove('active');
        return;
    }

    searchTimeout = setTimeout(async function() {
        try {
            const response = await fetch('<?= basePath('api/models') ?>?q=' + encodeURIComponent(query) + '&limit=10');
            const data = await response.json();

            const results = document.getElementById('model-search-results');
            results.innerHTML = '';

            if (data.data && data.data.length > 0) {
                data.data.forEach(model => {
                    if (model.id === modelId) return; // Skip current model

                    const item = document.createElement('div');
                    item.className = 'search-result-item';
                    item.dataset.id = model.id;
                    item.innerHTML = `
                        <img src="${model.thumbnail ? '<?= basePath('assets/') ?>' + model.thumbnail : '<?= basePath('images/placeholder.png') ?>'}" alt="${escapeHtml(model.name)}">
                        <span>${escapeHtml(model.name)}</span>
                    `;
                    item.addEventListener('click', function() {
                        selectModel(model.id, model.name);
                    });
                    results.appendChild(item);
                });
                results.classList.add('active');
            } else {
                results.classList.remove('active');
            }
        } catch (err) {
            console.error('Search error:', err);
        }
    }, 300);
});

function selectModel(id, name) {
    document.getElementById('selected-model-id').value = id;
    document.getElementById('remix-model-search').value = name;
    document.getElementById('model-search-results').classList.remove('active');
}

document.getElementById('save-remix-btn').addEventListener('click', async function() {
    const sourceType = document.getElementById('remix-source-type').value;
    const notes = document.getElementById('remix-notes').value;

    let payload = {
        model_id: modelId,
        notes: notes
    };

    if (sourceType === 'internal') {
        const selectedId = document.getElementById('selected-model-id').value;
        if (!selectedId) {
            showToast('Please select a model', 'error');
            return;
        }
        payload.remix_of = parseInt(selectedId);
    } else {
        const externalUrl = document.getElementById('remix-external-url').value.trim();
        if (!externalUrl) {
            showToast('Please enter an external URL', 'error');
            return;
        }
        payload.external_url = externalUrl;
    }

    this.disabled = true;
    this.textContent = 'Saving...';

    try {
        const response = await fetch('/actions/related-models', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'set_remix_source',
                ...payload
            })
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            showToast(result.error, 'error');
            this.disabled = false;
            this.textContent = 'Save';
        }
    } catch (err) {
        showToast(err.message, 'error');
        this.disabled = false;
        this.textContent = 'Save';
    }
});

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRemixModal();
    }
});

// Close modal on backdrop click
document.getElementById('remix-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeRemixModal();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
