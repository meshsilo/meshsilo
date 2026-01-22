            <aside class="admin-sidebar">
                <h3>Admin</h3>
                <nav class="admin-nav">
                    <a href="<?= route('admin.license') ?>" <?= ($adminPage ?? '') === 'license' ? 'class="active"' : '' ?>>
                        License
                        <span class="tier-badge tier-<?= getLicenseTier() ?>"><?= getTierName(getLicenseTier()) ?></span>
                    </a>
                    <a href="<?= route('admin.settings') ?>" <?= ($adminPage ?? '') === 'settings' ? 'class="active"' : '' ?>>Site Settings</a>
                    <a href="<?= route('admin.users') ?>" <?= ($adminPage ?? '') === 'users' ? 'class="active"' : '' ?>>Users</a>
                    <a href="<?= route('admin.groups') ?>" <?= ($adminPage ?? '') === 'groups' ? 'class="active"' : '' ?>>Groups</a>
                    <a href="<?= route('admin.categories') ?>" <?= ($adminPage ?? '') === 'categories' ? 'class="active"' : '' ?>>Categories</a>
                    <a href="<?= route('admin.collections') ?>" <?= ($adminPage ?? '') === 'collections' ? 'class="active"' : '' ?>>Collections</a>
                    <a href="<?= route('admin.storage') ?>" <?= ($adminPage ?? '') === 'storage' ? 'class="active"' : '' ?>>Storage</a>
                    <a href="<?= route('admin.database') ?>" <?= ($adminPage ?? '') === 'database' ? 'class="active"' : '' ?>>Database</a>
                    <a href="<?= route('admin.stats') ?>" <?= ($adminPage ?? '') === 'stats' ? 'class="active"' : '' ?>>Statistics</a>
                    <a href="<?= route('admin.api-keys') ?>" <?= ($adminPage ?? '') === 'api-keys' ? 'class="active"' : '' ?>>API Keys</a>
                    <a href="<?= route('admin.webhooks') ?>" <?= ($adminPage ?? '') === 'webhooks' ? 'class="active"' : '' ?>>Webhooks</a>
                    <a href="<?= route('admin.routes') ?>" <?= ($adminPage ?? '') === 'routes' ? 'class="active"' : '' ?>>Routes</a>
                </nav>
            </aside>
