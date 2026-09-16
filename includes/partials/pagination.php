<?php
/**
 * Pagination navigation partial.
 *
 * Renders the windowed pager (first/prev, a +/-2 window around the current
 * page, ellipses, next/last) shared by browse, category, collection and the
 * admin list pages. Those pages previously each carried their own copy of this
 * markup; they differed only in how a page URL is built, which is why the URL
 * builder is injected.
 *
 * Required before include:
 *   $page          int       Current page (1-based).
 *   $totalPages    int       Total number of pages. Nothing renders when < 2.
 *   $paginationUrl callable  fn(int $page): string - returns the href for a page.
 *
 * Optional:
 *   $paginationAttrs string  Extra attributes for the <nav> (e.g. an inline style).
 *
 * Example:
 *   $paginationUrl = fn($p) => BrowseQuery::buildUrl(['page' => $p]);
 *   include __DIR__ . '/../../includes/partials/pagination.php';
 */

if (!isset($page, $totalPages, $paginationUrl) || !is_callable($paginationUrl)) {
    return;
}

$totalPages = (int)$totalPages;
$page = (int)$page;

if ($totalPages < 2) {
    return;
}

$startPage = max(1, $page - 2);
$endPage = min($totalPages, $page + 2);
$navAttrs = $paginationAttrs ?? '';
?>
<nav class="pagination" aria-label="Pagination"<?= $navAttrs ? ' ' . $navAttrs : '' ?>>
    <?php if ($page > 1): ?>
    <a href="<?= $paginationUrl($page - 1) ?>" class="pagination-btn" aria-label="Previous page">&laquo; Prev</a>
    <?php endif; ?>

    <?php if ($startPage > 1): ?>
    <a href="<?= $paginationUrl(1) ?>" class="pagination-btn" aria-label="Page 1">1</a>
    <?php if ($startPage > 2): ?>
    <span class="pagination-ellipsis">...</span>
    <?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
    <a href="<?= $paginationUrl($i) ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>" aria-label="Page <?= $i ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($endPage < $totalPages): ?>
    <?php if ($endPage < $totalPages - 1): ?>
    <span class="pagination-ellipsis">...</span>
    <?php endif; ?>
    <a href="<?= $paginationUrl($totalPages) ?>" class="pagination-btn" aria-label="Page <?= $totalPages ?>"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($page < $totalPages): ?>
    <a href="<?= $paginationUrl($page + 1) ?>" class="pagination-btn" aria-label="Next page">Next &raquo;</a>
    <?php endif; ?>
</nav>
<?php
// Prevent the builder leaking into the including page's later includes.
unset($paginationAttrs);
