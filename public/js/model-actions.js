/**
 * Model Actions — Favorites, Archive, Tags, External Links, and Part Data
 * Handles favorite toggling, archive/unarchive, tag management, external links, and per-part calculated data.
 * Loaded on the model detail page before model-page.js.
 */

        // Favorite toggle
        async function toggleFavorite(modelId, btn) {
            try {
                const response = await fetch('/actions/favorite', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'model_id=' + modelId
                });
                const data = await response.json();
                if (data.success) {
                    btn.classList.toggle('favorited', data.favorited);
                    btn.innerHTML = data.favorited ? '&#9829;' : '&#9825;';
                    btn.title = data.favorited ? 'Remove from favorites' : 'Add to favorites';
                }
            } catch (err) {
                console.error('Failed to toggle favorite:', err);
            }
        }


        // Storage for calculated part data (persists during page session)
        const partCalculatedData = {};

        // Per-part actions
        async function calculatePartDimensions(partId, linkEl) {
            const originalText = 'Calculate Dimensions';
            linkEl.textContent = 'Calculating...';
            try {
                const formData = new FormData();
                formData.append('model_id', partId);
                const response = await fetch('/actions/calculate-dimensions', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success && data.formatted) {
                    // Store the calculated value
                    if (!partCalculatedData[partId]) partCalculatedData[partId] = {};
                    partCalculatedData[partId].dimensions = data.formatted;
                    linkEl.textContent = 'Dimensions: ' + data.formatted;
                } else {
                    showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
                    linkEl.textContent = originalText;
                }
            } catch (err) {
                console.error('Part dimensions error:', err);
                showToast('Failed to calculate dimensions', 'error');
                linkEl.textContent = originalText;
            }
        }

        async function calculatePartVolume(partId, linkEl) {
            // Volume calculation is not yet available
            showToast('This feature is not yet available', 'info');
        }

        // Restore calculated data when dropdown opens
        function restorePartCalculatedData(partId, dropdown) {
            const data = partCalculatedData[partId];
            if (!data) return;

            const dimsLink = dropdown.querySelector('[onclick*="calculatePartDimensions"]');
            const volLink = dropdown.querySelector('[onclick*="calculatePartVolume"]');

            if (dimsLink && data.dimensions) {
                dimsLink.textContent = 'Dimensions: ' + data.dimensions;
            }
            if (volLink && data.volume) {
                let volumeText = 'Volume: ' + data.volume.toFixed(1) + ' cm\u00B3';
                if (data.costEstimate) {
                    volumeText += ' (~$' + data.costEstimate.estimated_cost.toFixed(2) + ')';
                }
                volLink.textContent = volumeText;
            }
        }

        async function analyzePartMesh(partId, linkEl) {
            // Mesh analysis/repair is not yet available
            showToast('This feature is not yet available', 'info');
        }

        // Archive toggle
        async function toggleArchive(modelId, archive) {
            try {
                const response = await fetch('/actions/update-model', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'model_id=' + modelId + '&is_archived=' + (archive ? '1' : '0')
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast('Failed to update: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to toggle archive:', err);
                showToast('Failed to update model', 'error');
            }
        }

        // Tag management
        const allTags = ModelPageConfig.allTags;
        const tagInput = document.getElementById('tag-input');
        const tagSuggestions = document.getElementById('tag-suggestions');

        if (tagInput) {
            tagInput.addEventListener('input', function() {
                const value = this.value.toLowerCase().trim();
                if (value.length < 1) {
                    tagSuggestions.style.display = 'none';
                    return;
                }

                const matching = allTags.filter(t => t.name.toLowerCase().includes(value));
                if (matching.length === 0 && value.length > 0) {
                    // Show option to create new tag
                    tagSuggestions.innerHTML = `
                        <button type="button" class="tag-suggestion" onclick="addTag('${value.replace(/'/g, "\\'")}')">
                            <span class="tag-color-dot" style="background-color: var(--color-primary);"></span>
                            <span>Create "${value}"</span>
                        </button>
                    `;
                } else {
                    tagSuggestions.innerHTML = matching.map(t => {
                        const safeColor = t.color && /^(#[0-9a-fA-F]{3,8}|[a-zA-Z]+)$/.test(t.color) ? t.color : '#6b7280';
                        return `
                        <button type="button" class="tag-suggestion" onclick="addTagById(${t.id}, '${t.name.replace(/'/g, "\\'")}')">
                            <span class="tag-color-dot" style="background-color: ${safeColor};"></span>
                            <span>${t.name}</span>
                        </button>
                    `;
                    }).join('');
                }
                tagSuggestions.style.display = matching.length > 0 || value.length > 0 ? 'block' : 'none';
            });

            tagInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const value = this.value.trim();
                    if (value) {
                        addTag(value);
                    }
                }
            });

            tagInput.addEventListener('blur', function() {
                setTimeout(() => {
                    tagSuggestions.style.display = 'none';
                }, 200);
            });
        }

        async function addTag(tagName) {
            try {
                const response = await fetch('/actions/tag', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=add&model_id=${ModelPageConfig.modelId}&tag_name=` + encodeURIComponent(tagName)
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast('Failed to add tag: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to add tag:', err);
            }
        }

        async function addTagById(_tagId, tagName) {
            await addTag(tagName);
        }

        async function removeTag(modelId, tagId, element) {
            try {
                const response = await fetch('/actions/tag', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=remove&model_id=' + modelId + '&tag_id=' + tagId
                });
                const data = await response.json();
                if (data.success) {
                    element.remove();
                } else {
                    showToast('Failed to remove tag: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Failed to remove tag:', err);
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

