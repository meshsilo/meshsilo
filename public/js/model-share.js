/**
 * Model Share & External Links
 * Handles share modal, share link creation/deletion, copy URL, and external links.
 * Loaded on the model detail page before model-page.js.
 */

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Share Modal Functions
        function openShareModal() {
            var modal = document.getElementById('share-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
            loadShareLinks();
        }

        function closeShareModal() {
            var modal = document.getElementById('share-modal');
            releaseFocus(modal);
            modal.style.display = 'none';
        }

        document.getElementById('share-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeShareModal();
        });

        document.getElementById('share-link-form')?.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('expires_in', document.getElementById('share-expires').value);
            formData.append('max_downloads', document.getElementById('share-max-downloads').value);
            formData.append('password', document.getElementById('share-password').value);

            try {
                const response = await fetch('/actions/share-link', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Clear form
                    this.reset();
                    // Reload links
                    loadShareLinks();
                } else {
                    showToast('Failed to create share link: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Error creating share link:', err);
                showToast('Failed to create share link', 'error');
            }
        });

        async function loadShareLinks() {
            const container = document.getElementById('share-links-list');

            try {
                const response = await fetch(`/actions/share-link?action=list&model_id=${ModelPageConfig.modelId}`);
                const result = await response.json();

                if (!result.success) {
                    container.innerHTML = '<p class="text-muted">Failed to load share links</p>';
                    return;
                }

                if (result.links.length === 0) {
                    container.innerHTML = '<p class="text-muted">No active share links</p>';
                    return;
                }

                container.innerHTML = result.links.map(link => `
                    <div class="share-link-item ${link.is_expired ? 'expired' : ''}">
                        <div class="share-link-info">
                            <div class="share-link-url">
                                <input type="text" readonly value="${escapeHtml(link.share_url)}" class="share-url-input" onclick="this.select()">
                                <button type="button" class="btn btn-small" onclick="copyShareUrl(this.previousElementSibling)" title="Copy URL">Copy</button>
                            </div>
                            <div class="share-link-meta">
                                ${link.has_password ? '<span class="share-badge">Password</span>' : ''}
                                ${link.expires_at ? `<span class="share-meta-item">${link.is_expired ? 'Expired' : 'Expires: ' + new Date(link.expires_at).toLocaleDateString()}</span>` : '<span class="share-meta-item">Never expires</span>'}
                                ${link.max_downloads ? `<span class="share-meta-item">Downloads: ${link.download_count}/${link.max_downloads}</span>` : `<span class="share-meta-item">${link.download_count} downloads</span>`}
                            </div>
                        </div>
                        <button type="button" class="btn btn-danger btn-small" onclick="deleteShareLink(${link.id})">Delete</button>
                    </div>
                `).join('');
            } catch (err) {
                console.error('Error loading share links:', err);
                container.innerHTML = '<p class="text-muted">Failed to load share links</p>';
            }
        }

        function copyShareUrl(input) {
            input.select();
            navigator.clipboard.writeText(input.value).then(() => {
                showToast('Link copied to clipboard', 'success');
                const btn = input.nextElementSibling;
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => btn.textContent = originalText, 1500);
            }).catch(() => {
                document.execCommand('copy');
                showToast('Link copied to clipboard', 'success');
            });
        }

        async function deleteShareLink(linkId) {
            if (!await showConfirm('Delete this share link? Anyone with this link will no longer be able to access the model.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('link_id', linkId);

            try {
                const response = await fetch('/actions/share-link', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    loadShareLinks();
                } else {
                    showToast('Failed to delete share link: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Error deleting share link:', err);
                showToast('Failed to delete share link', 'error');
            }
        }

        // External Links
        function toggleAddLinkForm() {
            const form = document.getElementById('add-link-form');
            const btn = document.getElementById('add-link-toggle');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                btn.style.display = 'none';
                document.getElementById('link-title').focus();
            } else {
                form.style.display = 'none';
                btn.style.display = '';
                document.getElementById('link-title').value = '';
                document.getElementById('link-url').value = '';
                document.getElementById('link-type').value = 'other';
            }
        }

        async function addModelLink() {
            const title = document.getElementById('link-title').value.trim();
            const url = document.getElementById('link-url').value.trim();
            const linkType = document.getElementById('link-type').value;

            if (!title || !url) {
                showToast('Title and URL are required', 'error');
                return;
            }

            try {
                const response = await fetch('/actions/model-links', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add',
                        model_id: ModelPageConfig.modelId,
                        title: title,
                        url: url,
                        link_type: linkType
                    })
                });
                const result = await response.json();

                if (result.success) {
                    const list = document.getElementById('model-links-list');
                    const empty = document.getElementById('model-links-empty');
                    if (empty) empty.remove();

                    const link = result.link;
                    const item = document.createElement('div');
                    item.className = 'model-link-item';
                    item.dataset.linkId = link.id;
                    item.innerHTML =
                        '<span class="model-link-type type-' + escapeHtml(link.link_type) + '">' + escapeHtml(link.link_type) + '</span>' +
                        '<a href="' + escapeHtml(link.url) + '" target="_blank" rel="noopener noreferrer" class="model-link-title">' + escapeHtml(link.title) + '</a>' +
                        '<button type="button" class="model-link-delete" aria-label="Remove link" onclick="deleteModelLink(' + link.id + ')" title="Remove link">&times;</button>';
                    list.appendChild(item);

                    toggleAddLinkForm();
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function deleteModelLink(linkId) {
            if (!await showConfirm('Remove this link?')) return;

            try {
                const response = await fetch('/actions/model-links', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', link_id: linkId })
                });
                const result = await response.json();

                if (result.success) {
                    const item = document.querySelector('[data-link-id="' + linkId + '"]');
                    if (item) item.remove();

                    // Show empty state if no links remain
                    const list = document.getElementById('model-links-list');
                    if (!list.querySelector('.model-link-item')) {
                        const p = document.createElement('p');
                        p.className = 'model-links-empty';
                        p.id = 'model-links-empty';
                        p.textContent = 'No external links yet.';
                        list.appendChild(p);
                    }
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

