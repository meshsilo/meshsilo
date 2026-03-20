/**
 * Remix Tree — Model remix/fork tree interaction
 * Handles remix modal, search, and tree management.
 * Expects global variable: modelId
 */

function closeRemixModal() {
    document.getElementById('remix-modal').style.display = 'none';
}
document.querySelectorAll('#remix-modal .modal-close, #remix-modal [data-action="close-remix-modal"]').forEach(function(btn) {
    btn.addEventListener('click', closeRemixModal);
});

document.getElementById('remix-source-type').addEventListener('change', function() {
    const isInternal = this.value === 'internal';
    document.getElementById('internal-source').style.display = isInternal ? 'block' : 'none';
    document.getElementById('external-source').style.display = isInternal ? 'none' : 'block';
});

// Model search
document.getElementById('remix-model-search').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();

    if (query.length < 2) {
        document.getElementById('model-search-results').classList.remove('active');
        return;
    }

    searchTimeout = setTimeout(async function() {
        try {
            const response = await fetch('<?= basePath('api/models') ?>?q=' + encodeURIComponent(query) + '&limit=10');
            const data = await response.json();

            const results = document.getElementById('model-search-results');
            results.innerHTML = '';

            if (data.data && data.data.length > 0) {
                data.data.forEach(model => {
                    if (model.id === modelId) return; // Skip current model

                    const item = document.createElement('div');
                    item.className = 'search-result-item';
                    item.dataset.id = model.id;
                    item.innerHTML = `
                        <img src="${model.thumbnail ? '<?= basePath('assets/') ?>' + model.thumbnail : '<?= basePath('images/placeholder.png') ?>'}" alt="${escapeHtml(model.name)}">
                        <span>${escapeHtml(model.name)}</span>
                    `;
                    item.addEventListener('click', function() {
                        selectModel(model.id, model.name);
                    });
                    results.appendChild(item);
                });
                results.classList.add('active');
            } else {
                results.classList.remove('active');
            }
        } catch (err) {
            console.error('Search error:', err);
        }
    }, 300);
});

function selectModel(id, name) {
    document.getElementById('selected-model-id').value = id;
    document.getElementById('remix-model-search').value = name;
    document.getElementById('model-search-results').classList.remove('active');
}

document.getElementById('save-remix-btn').addEventListener('click', async function() {
    const sourceType = document.getElementById('remix-source-type').value;
    const notes = document.getElementById('remix-notes').value;

    let payload = {
        model_id: modelId,
        notes: notes
    };

    if (sourceType === 'internal') {
        const selectedId = document.getElementById('selected-model-id').value;
        if (!selectedId) {
            showToast('Please select a model', 'error');
            return;
        }
        payload.remix_of = parseInt(selectedId, 10);
    } else {
        const externalUrl = document.getElementById('remix-external-url').value.trim();
        if (!externalUrl) {
            showToast('Please enter an external URL', 'error');
            return;
        }
        payload.external_url = externalUrl;
    }

    this.disabled = true;
    this.textContent = 'Saving...';

    try {
        const response = await fetch('/actions/related-models', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'set_remix_source',
                ...payload
            })
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            showToast(result.error, 'error');
            this.disabled = false;
            this.textContent = 'Save';
        }
    } catch (err) {
        showToast(err.message, 'error');
        this.disabled = false;
        this.textContent = 'Save';
    }
});

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRemixModal();
    }
});

// Close modal on backdrop click
document.getElementById('remix-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeRemixModal();
    }
});
