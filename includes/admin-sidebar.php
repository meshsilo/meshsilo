            <aside class="admin-sidebar">
                <h3>Admin</h3>
                <nav class="admin-nav">
                    <a href="<?= basePath('admin/license.php') ?>" <?= ($adminPage ?? '') === 'license' ? 'class="active"' : '' ?>>
                        License
                        <span class="tier-badge tier-<?= getLicenseTier() ?>"><?= getTierName(getLicenseTier()) ?></span>
                    </a>
                    <a href="<?= basePath('admin/settings.php') ?>" <?= ($adminPage ?? '') === 'settings' ? 'class="active"' : '' ?>>Site Settings</a>
                    <a href="<?= basePath('admin/users.php') ?>" <?= ($adminPage ?? '') === 'users' ? 'class="active"' : '' ?>>Users</a>
                    <a href="<?= basePath('admin/groups.php') ?>" <?= ($adminPage ?? '') === 'groups' ? 'class="active"' : '' ?>>Groups</a>
                    <a href="<?= basePath('admin/categories.php') ?>" <?= ($adminPage ?? '') === 'categories' ? 'class="active"' : '' ?>>Categories</a>
                    <a href="<?= basePath('admin/collections.php') ?>" <?= ($adminPage ?? '') === 'collections' ? 'class="active"' : '' ?>>Collections</a>
                    <a href="<?= basePath('admin/storage.php') ?>" <?= ($adminPage ?? '') === 'storage' ? 'class="active"' : '' ?>>Storage</a>
                    <a href="<?= basePath('admin/database.php') ?>" <?= ($adminPage ?? '') === 'database' ? 'class="active"' : '' ?>>Database</a>
                    <a href="<?= basePath('admin/stats.php') ?>" <?= ($adminPage ?? '') === 'stats' ? 'class="active"' : '' ?>>Statistics</a>
                    <a href="<?= basePath('admin/api-keys.php') ?>" <?= ($adminPage ?? '') === 'api-keys' ? 'class="active"' : '' ?>>API Keys</a>
                    <a href="<?= basePath('admin/webhooks.php') ?>" <?= ($adminPage ?? '') === 'webhooks' ? 'class="active"' : '' ?>>Webhooks</a>
                </nav>
            </aside>
