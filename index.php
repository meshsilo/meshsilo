<?php
require_once 'includes/config.php';
$pageTitle = 'Home';
$activePage = 'browse';
require_once 'includes/header.php';
?>

        <section class="hero">
            <div class="hero-content">
                <h1>Your 3D Model Library</h1>
                <p>Store, organize, and share your 3D print files in one place.</p>
            </div>
        </section>

        <section class="models-section">
            <div class="section-header">
                <h2>Recent Models</h2>
                <a href="#" class="view-all">View All</a>
            </div>
            <div class="models-grid">
                <article class="model-card">
                    <div class="model-thumbnail"></div>
                    <div class="model-info">
                        <h3 class="model-title">Benchy</h3>
                        <p class="model-author">by demo_user</p>
                    </div>
                </article>
                <article class="model-card">
                    <div class="model-thumbnail"></div>
                    <div class="model-info">
                        <h3 class="model-title">Phone Stand</h3>
                        <p class="model-author">by demo_user</p>
                    </div>
                </article>
                <article class="model-card">
                    <div class="model-thumbnail"></div>
                    <div class="model-info">
                        <h3 class="model-title">Cable Organizer</h3>
                        <p class="model-author">by demo_user</p>
                    </div>
                </article>
                <article class="model-card">
                    <div class="model-thumbnail"></div>
                    <div class="model-info">
                        <h3 class="model-title">Desk Hook</h3>
                        <p class="model-author">by demo_user</p>
                    </div>
                </article>
            </div>
        </section>

        <section class="categories-section">
            <div class="section-header">
                <h2>Categories</h2>
            </div>
            <div class="categories-grid">
                <a href="category.php?cat=functional" class="category-card">
                    <span class="category-name">Functional</span>
                </a>
                <a href="category.php?cat=decorative" class="category-card">
                    <span class="category-name">Decorative</span>
                </a>
                <a href="category.php?cat=tools" class="category-card">
                    <span class="category-name">Tools</span>
                </a>
                <a href="category.php?cat=gaming" class="category-card">
                    <span class="category-name">Gaming</span>
                </a>
                <a href="category.php?cat=art" class="category-card">
                    <span class="category-name">Art</span>
                </a>
                <a href="category.php?cat=mechanical" class="category-card">
                    <span class="category-name">Mechanical</span>
                </a>
            </div>
        </section>

<?php require_once 'includes/footer.php'; ?>
