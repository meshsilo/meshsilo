<?php
require_once 'includes/config.php';
require_once 'includes/dedup.php';
require_once 'includes/features.php';

// Require feature to be enabled
requireFeature('favorites');

$pageTitle = 'My Favorites';
$activePage = 'favorites';

// Require login
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = '/favorites';
    header('Location: /login');
    exit;
}

$db = getDB();
$user = getCurrentUser();

// Get user's favorites
$favorites = getUserFavorites($user['id'], 100);

// Enhance models with preview data using preview endpoint
foreach ($favorites as &$model) {
    if ($model['part_count'] > 0) {
        $partStmt = $db->prepare('SELECT id, file_type FROM models WHERE parent_id = :parent_id ORDER BY original_path ASC LIMIT 1');
        $partStmt->bindValue(':parent_id', $model['id'], PDO::PARAM_INT);
        $partResult = $partStmt->execute();
        $firstPart = $partResult->fetchArray(PDO::FETCH_ASSOC);
        if ($firstPart) {
            $model['preview_path'] = '/actions/preview?id=' . $firstPart['id'];
            $model['preview_type'] = $firstPart['file_type'];
        }
    } else {
        $model['preview_path'] = '/actions/preview?id=' . $model['id'];
        $model['preview_type'] = $model['file_type'];
    }
}
unset($model);

require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>My Favorites</h1>
                <p><?= count($favorites) ?> favorited model<?= count($favorites) !== 1 ? 's' : '' ?></p>
            </div>

            <?php if (empty($favorites)): ?>
                <p class="text-muted" style="text-align: center; padding: 3rem;">
                    You haven't favorited any models yet.<br>
                    Click the heart icon on any model to add it to your favorites.
                </p>
            <?php else: ?>
                <div class="models-grid">
                    <?php foreach ($favorites as $model): ?>
                    <article class="model-card" onclick="window.location='model.php?id=<?= $model['id'] ?>'">
                        <div class="model-thumbnail"
                            <?php if (!empty($model['preview_path'])): ?>
                            data-model-url="<?= htmlspecialchars($model['preview_path']) ?>"
                            data-file-type="<?= htmlspecialchars($model['preview_type']) ?>"
                            <?php endif; ?>>
                            <?php if ($model['part_count'] > 0): ?>
                            <span class="part-count-badge"><?= $model['part_count'] ?> <?= $model['part_count'] === 1 ? 'part' : 'parts' ?></span>
                            <?php endif; ?>
                            <button type="button" class="model-card-favorite favorite-btn favorited" onclick="event.stopPropagation(); toggleFavorite(<?= $model['id'] ?>, this)" title="Remove from favorites">&#9829;</button>
                        </div>
                        <div class="model-info">
                            <h3 class="model-title"><?= htmlspecialchars($model['name']) ?></h3>
                            <p class="model-creator"><?= $model['creator'] ? 'by ' . htmlspecialchars($model['creator']) : '' ?></p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <script>
        async function toggleFavorite(modelId, btn) {
            try {
                const response = await fetch('actions/favorite.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'model_id=' + modelId
                });
                const data = await response.json();
                if (data.success) {
                    // Remove the card from the page
                    btn.closest('.model-card').remove();
                    // Update count in header
                    const countEl = document.querySelector('.page-header p');
                    const remaining = document.querySelectorAll('.model-card').length;
                    countEl.textContent = remaining + ' favorited model' + (remaining !== 1 ? 's' : '');
                    if (remaining === 0) {
                        location.reload();
                    }
                }
            } catch (err) {
                console.error('Failed to toggle favorite:', err);
            }
        }
        </script>

<?php require_once 'includes/footer.php'; ?>
