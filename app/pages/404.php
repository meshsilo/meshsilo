<?php
/**
 * 404 Not Found Page
 *
 * Displayed when a requested route does not exist.
 */

$pageTitle = '404 Not Found';
$activePage = '';

require_once __DIR__ . '/../../includes/header.php';
?>

        <div class="page-container" style="text-align: center; padding: 4rem 1rem;">
            <h1 style="font-size: 4rem; margin-bottom: 0.5rem; color: var(--color-text-muted);">404</h1>
            <h2 style="margin-bottom: 1rem;">Page Not Found</h2>
            <p style="color: var(--color-text-muted); margin-bottom: 2rem;">The page you are looking for does not exist or has been moved.</p>
            <div>
                <a href="<?= route('home') ?>" class="btn btn-primary">Go Home</a>
                <a href="<?= route('browse') ?>" class="btn btn-secondary" style="margin-left: 0.5rem;">Browse Models</a>
            </div>
        </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
