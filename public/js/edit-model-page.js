/**
 * Edit model page JavaScript
 * Handles Markdown preview and tag management.
 * PHP data is passed via window.EditModelPageConfig (set in edit-model.php).
 */

document.addEventListener('DOMContentLoaded', function() {
    var config = window.EditModelPageConfig || {};
    var allTags = config.allTags || [];
    var modelId = config.modelId || 0;

    // --- Markdown preview ---
    var ta = document.getElementById('description');
    var preview = document.getElementById('md-preview');
    var previewToggle = document.querySelector('.markdown-preview-toggle');

    if (ta && preview) {
        var mdTimer = null;
        function updatePreview() {
            var text = ta.value;
            if (!text.trim()) {
                preview.innerHTML = '<em style="color:var(--color-text-muted)">Nothing to preview</em>';
                return;
            }
            var html = text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/^### (.+)$/gm, '<h4>$1</h4>')
                .replace(/^## (.+)$/gm, '<h3>$1</h3>')
                .replace(/^# (.+)$/gm, '<h2>$1</h2>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/`(.+?)`/g, '<code>$1</code>')
                .replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, text, url) {
                    if (/^(https?:\/\/|\/)/i.test(url)) {
                        return '<a href="' + url + '">' + text + '</a>';
                    }
                    return text; // Strip the link if not http/https/relative
                })
                .replace(/^- (.+)$/gm, '<li>$1</li>')
                .replace(/\n/g, '<br>');
            preview.innerHTML = html;
        }
        ta.addEventListener('input', function() {
            clearTimeout(mdTimer);
            mdTimer = setTimeout(updatePreview, 200);
        });
        if (previewToggle) {
            previewToggle.addEventListener('toggle', updatePreview);
        }
    }

    // --- Tag management ---
    var tagInput = document.getElementById('tag-input');
    var tagSuggestions = document.getElementById('tag-suggestions');
    var tagsContainer = document.getElementById('current-tags');

    if (tagInput) {
        tagInput.addEventListener('input', function() {
            var value = this.value.toLowerCase().trim();
            if (value.length < 1) {
                tagSuggestions.style.display = 'none';
                return;
            }

            var matching = allTags.filter(function(t) {
                return t.name.toLowerCase().includes(value);
            });
            if (matching.length === 0 && value.length > 0) {
                var safeValue = escapeHtml(value);
                tagSuggestions.innerHTML =
                    '<button type="button" class="tag-suggestion" data-name="' + safeValue + '">' +
                        '<span class="tag-color-dot" style="background-color: var(--color-primary);"></span>' +
                        '<span>Create "' + safeValue + '"</span>' +
                    '</button>';
            } else {
                tagSuggestions.innerHTML = matching.map(function(t) {
                    var safeName = escapeHtml(t.name);
                    var safeColor = t.color && /^(#[0-9a-fA-F]{3,8}|[a-zA-Z]+)$/.test(t.color) ? t.color : '#6b7280';
                    return '<button type="button" class="tag-suggestion" data-name="' + safeName + '">' +
                        '<span class="tag-color-dot" style="background-color:' + safeColor + ';"></span>' +
                        '<span>' + safeName + '</span>' +
                    '</button>';
                }).join('');
            }
            tagSuggestions.style.display = matching.length > 0 || value.length > 0 ? 'block' : 'none';
        });

        tagInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var value = this.value.trim();
                if (value) addTag(value);
            }
        });

        tagInput.addEventListener('blur', function() {
            setTimeout(function() { tagSuggestions.style.display = 'none'; }, 200);
        });
    }

    async function addTag(tagName) {
        try {
            var response = await fetch('/actions/tag', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=add&model_id=' + modelId + '&tag_name=' + encodeURIComponent(tagName)
            });
            var data = await response.json();
            if (data.success && data.tag) {
                var tagEl = document.createElement('span');
                tagEl.className = 'model-tag';
                tagEl.style.setProperty('--tag-color', data.tag.color);
                tagEl.dataset.tagId = data.tag.id;
                tagEl.textContent = data.tag.name + ' ';
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'model-tag-remove';
                removeBtn.innerHTML = '&times;';
                removeBtn.setAttribute('aria-label', 'Remove tag ' + data.tag.name);
                tagEl.appendChild(removeBtn);
                tagsContainer.appendChild(tagEl);
                tagInput.value = '';
                tagSuggestions.style.display = 'none';
            } else {
                showToast('Failed to add tag: ' + (data.error || 'Unknown error'), 'error');
            }
        } catch (err) {
            console.error('Failed to add tag:', err);
        }
    }

    async function removeTag(modelId, tagId, element) {
        try {
            var response = await fetch('/actions/tag', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=remove&model_id=' + modelId + '&tag_id=' + tagId
            });
            var data = await response.json();
            if (data.success) {
                element.remove();
            } else {
                showToast('Failed to remove tag: ' + (data.error || 'Unknown error'), 'error');
            }
        } catch (err) {
            console.error('Failed to remove tag:', err);
        }
    }

    // Delegated: tag suggestion clicks
    if (tagSuggestions) {
        tagSuggestions.addEventListener('click', function(e) {
            var btn = e.target.closest('.tag-suggestion');
            if (btn) addTag(btn.dataset.name);
        });
    }

    // Delegated: tag remove clicks
    if (tagsContainer) {
        tagsContainer.addEventListener('click', function(e) {
            var btn = e.target.closest('.model-tag-remove');
            if (!btn) return;
            var tagEl = btn.closest('.model-tag');
            if (tagEl && tagEl.dataset.tagId) {
                removeTag(modelId, tagEl.dataset.tagId, tagEl);
            }
        });
    }
});
