    </main>

    <footer class="site-footer">
        <div class="footer-content">
            <div class="footer-brand">
                <?php $footerLogoPath = getSetting('logo_path', ''); if ($footerLogoPath) : ?>
                <img src="<?= rtrim(defined('SITE_URL') ? SITE_URL : '', '/') ?>/assets/<?= htmlspecialchars($footerLogoPath) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="logo-img">
                <?php endif; ?>
                <span class="logo-text"><?= htmlspecialchars(SITE_NAME) ?></span>
                <p><?= htmlspecialchars(SITE_DESCRIPTION) ?></p>
                <p class="footer-copyright">&copy; <?= date('Y') ?> <?= htmlspecialchars(SITE_NAME) ?></p>
            </div>
            <?php
            $footerAboutUrl = getSetting('footer_about_url', '');
            $footerContactUrl = getSetting('footer_contact_url', '');
            $footerPrivacyUrl = getSetting('footer_privacy_url', '');
            $footerTermsUrl = getSetting('footer_terms_url', '');
            if ($footerAboutUrl || $footerContactUrl || $footerPrivacyUrl || $footerTermsUrl) :
                ?>
            <nav class="footer-nav" aria-label="Footer navigation">
                <?php if ($footerAboutUrl) :
                    ?><a href="<?= htmlspecialchars($footerAboutUrl) ?>">About</a><?php
                endif; ?>
                <?php if ($footerContactUrl) :
                    ?><a href="<?= htmlspecialchars($footerContactUrl) ?>">Contact</a><?php
                endif; ?>
                <?php if ($footerPrivacyUrl) :
                    ?><a href="<?= htmlspecialchars($footerPrivacyUrl) ?>">Privacy</a><?php
                endif; ?>
                <?php if ($footerTermsUrl) :
                    ?><a href="<?= htmlspecialchars($footerTermsUrl) ?>">Terms</a><?php
                endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <?php if (getenv('MESHSILO_ENABLE_QUERY_STATS') === 'true') : ?>
        <div class="query-stats" style="padding: 10px; margin-top: 10px; background: var(--background-secondary); border-top: 1px solid var(--border-color); font-size: 0.85em; color: var(--text-secondary); text-align: center;">
            <strong>Query Stats:</strong>
            <?= Database::getQueryCount() ?> queries in <?= number_format(Database::getQueryTime() * 1000, 2) ?>ms |
            Peak Memory: <?= number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) ?>MB |
            Current: <?= number_format(memory_get_usage(true) / 1024 / 1024, 2) ?>MB
        </div>
        <?php endif; ?>
    </footer>

<?php if (class_exists('PluginManager')) : ?>
    <?= PluginManager::applyFilter('footer_content', '') ?>
<?php endif; ?>

    <?php if (class_exists('PluginManager')) : ?>
        <?= PluginManager::getInstance()->renderScripts() ?>
    <?php endif; ?>

    <script>
    // Collapsible sections
    document.addEventListener('DOMContentLoaded', function() {
        // Wrap non-h2 children in a .settings-section-content div so CSS can collapse them
        document.querySelectorAll('.settings-section').forEach(section => {
            if (section.querySelector('.settings-section-content')) return;
            const h2 = section.querySelector('h2');
            if (!h2) return;
            const wrapper = document.createElement('div');
            wrapper.className = 'settings-section-content';
            while (h2.nextSibling) {
                wrapper.appendChild(h2.nextSibling);
            }
            section.appendChild(wrapper);
        });

        document.querySelectorAll('.settings-section h2').forEach(header => {
            header.addEventListener('click', function() {
                const section = this.closest('.settings-section');
                section.classList.toggle('collapsed');

                // Save state to localStorage
                const sectionId = section.id || this.textContent.trim();
                const collapsedSections = JSON.parse(localStorage.getItem('collapsedSections') || '{}');
                collapsedSections[sectionId] = section.classList.contains('collapsed');
                localStorage.setItem('collapsedSections', JSON.stringify(collapsedSections));
            });
        });

        // Restore collapsed state from localStorage
        const collapsedSections = JSON.parse(localStorage.getItem('collapsedSections') || '{}');
        document.querySelectorAll('.settings-section').forEach(section => {
            const sectionId = section.id || section.querySelector('h2')?.textContent.trim();
            if (sectionId && collapsedSections[sectionId]) {
                section.classList.add('collapsed');
            }
        });
    });
    </script>
</body>
</html>
