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
        'thumbnail' => $model['thumbnail_path'],
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
            'thumbnail' => $row['thumbnail_path'],
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
        SELECT rm.*, m.name, m.thumbnail_path, m.created_at, u.username,
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

$needsViewer = true;
require_once 'includes/header.php';
?>

<div class="container page-remix-tree">
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
            Based on <a href="<?= htmlspecialchars($model['external_source_url']) ?>" target="_blank" rel="noopener noreferrer">external source</a>
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
                            <div class="node-thumbnail placeholder"><i class="fa-solid fa-cube"></i></div>
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
                        <?php if ($related['thumbnail_path']): ?>
                        <img src="<?= basePath('assets/' . $related['thumbnail_path']) ?>" alt="<?= htmlspecialchars($related['name']) ?>" class="related-thumbnail" loading="lazy" decoding="async">
                        <?php else: ?>
                        <div class="related-thumbnail placeholder"><i class="fa-solid fa-cube"></i></div>
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
<div id="remix-modal" class="modal page-remix-tree" role="dialog" aria-modal="true" aria-labelledby="remix-modal-title" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="remix-modal-title">Mark as Remix</h3>
            <button type="button" class="modal-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
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
                    <input type="search" id="remix-model-search" class="form-control" placeholder="Search models..." enterkeyhint="search">
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
            <button type="button" class="btn btn-secondary" data-action="close-remix-modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="save-remix-btn">Save</button>
        </div>
    </div>
</div>

<script<?= csp_nonce_attr() ?>>
window.RemixTreeConfig = {
    apiModelsUrl: '<?= basePath('api/models') ?>',
    assetsPath: '<?= basePath('assets/') ?>',
    placeholderImage: '<?= basePath('images/placeholder.png') ?>'
};
const modelId = <?= $modelId ?>;
let searchTimeout = null;

document.getElementById('mark-remix-btn')?.addEventListener('click', function() {
    document.getElementById('remix-modal').style.display = 'flex';
});

</script>
<script src="<?= basePath('js/remix-tree.js') ?>?v=<?= filemtime(__DIR__ . '/../../public/js/remix-tree.js') ?>" defer></script>

<?php require_once 'includes/footer.php'; ?>
