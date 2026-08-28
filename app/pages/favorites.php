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
    header('Location: ' . route('login'));
    exit;
}

$user = getCurrentUser();

// Get user's favorites
$favorites = getUserFavorites($user['id'], 100);

// Resolve viewer previews (a multi-part model previews its first part); the
// helper batches every multi-part lookup into one query instead of running one
// per favorited model.
attachPreviewData($favorites);

$needsViewer = true;
require_once 'includes/header.php';
?>

        <div class="page-container-wide">
            <div class="page-header">
                <h1>My Favorites</h1>
                <p><?= count($favorites) ?> favorited model<?= count($favorites) !== 1 ? 's' : '' ?></p>
            </div>

            <?php if (empty($favorites)): ?>
                <p class="text-muted empty-state-msg">
                    You haven't favorited any models yet.<br>
                    Click the heart icon on any model to add it to your favorites.
                </p>
            <?php else: ?>
                <div class="models-grid">
                    <?php foreach ($favorites as $model): ?>
                    <?php $cardOptions = ['favoriteButton' => true, 'fileSizeLimit' => true]; include 'includes/partials/model-card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <script<?= csp_nonce_attr() ?>>
        // The request itself is window.toggleFavorite in public/js/ui-common.js,
        // reached through that file's delegated .model-card-favorite handler.
        // Only the list-specific reaction lives here: an unfavorited model no
        // longer belongs on this page, so drop its card and restate the count.
        document.addEventListener('favorite:toggled', function(e) {
            const card = e.detail.button.closest('.model-card');
            if (card) card.remove();
            const remaining = document.querySelectorAll('.model-card').length;
            const countEl = document.querySelector('.page-header p');
            if (countEl) {
                countEl.textContent = remaining + ' favorited model' + (remaining !== 1 ? 's' : '');
            }
            if (remaining === 0) {
                location.reload();
            }
        });
        </script>

<?php require_once 'includes/footer.php'; ?>
