    </main>

    <footer class="site-footer">
        <div class="footer-content">
            <div class="footer-brand">
                <span class="logo-icon">&#9653;</span>
                <span class="logo-text"><?= SITE_NAME ?></span>
                <p><?= SITE_DESCRIPTION ?></p>
            </div>
            <nav class="footer-nav">
                <a href="#">About</a>
                <a href="#">Contact</a>
                <a href="#">Privacy</a>
                <a href="#">Terms</a>
            </nav>
        </div>
        <?php if (getenv('MESHSILO_ENABLE_QUERY_STATS') === 'true'): ?>
        <div class="query-stats" style="padding: 10px; margin-top: 10px; background: var(--background-secondary); border-top: 1px solid var(--border-color); font-size: 0.85em; color: var(--text-secondary); text-align: center;">
            <strong>Query Stats:</strong>
            <?= Database::getQueryCount() ?> queries in <?= number_format(Database::getQueryTime() * 1000, 2) ?>ms |
            Peak Memory: <?= number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) ?>MB |
            Current: <?= number_format(memory_get_usage(true) / 1024 / 1024, 2) ?>MB
        </div>
        <?php endif; ?>
    </footer>

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
