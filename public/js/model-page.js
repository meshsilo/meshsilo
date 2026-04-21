// model-page.js — Model detail page initialization and shared utilities
// PHP data is injected by model.php via window.ModelPageConfig = {...}
// Depends on: model-parts.js, model-attachments.js, model-actions.js

        // Add click handlers to part items
        document.querySelectorAll('.part-preview-trigger').forEach(trigger => {
            trigger.addEventListener('click', function() {
                const partItem = this.closest('.part-item');
                const path = partItem.dataset.partPath;
                const type = partItem.dataset.partType;
                const name = partItem.dataset.partName;

                if (path && type) {
                    openPartPreview(path, type, name);
                }
            });
        });

        // Handle conversion button clicks
        document.querySelectorAll('.convert-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const partId = this.dataset.partId;
                const partItem = this.closest('.part-item');
                const originalText = this.textContent;

                // First, estimate the savings
                this.textContent = 'Checking...';
                this.disabled = true;

                try {
                    const estimateResponse = await fetch(`/actions/convert-part?action=estimate&part_id=${partId}`);
                    const estimate = await estimateResponse.json();

                    if (!estimate.success) {
                        showToast('Cannot estimate conversion: ' + (estimate.error || 'Unknown error'), 'error');
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    if (!estimate.worth_converting) {
                        showToast('Converting this file would not save space. Keeping original STL.', 'info');
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    // Format sizes for display
                    const formatBytes = (bytes) => {
                        if (bytes < 1024) return bytes + ' B';
                        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
                    };

                    const confirmed = await showConfirm(
                        'Convert to 3MF? Current size: ' + formatBytes(estimate.original_size) +
                        ', Estimated new size: ' + formatBytes(estimate.estimated_size) +
                        ', Estimated savings: ' + formatBytes(estimate.estimated_savings) + ' (' + estimate.estimated_savings_percent + '%). This will replace the STL file with a 3MF file.'
                    );

                    if (!confirmed) {
                        this.textContent = originalText;
                        this.disabled = false;
                        return;
                    }

                    // Queue the conversion as a background job
                    this.textContent = 'Queuing...';

                    const formData = new FormData();
                    formData.append('action', 'convert');
                    formData.append('part_id', partId);

                    const convertResponse = await fetch('/actions/convert-part', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await convertResponse.json();

                    if (result.success) {
                        this.textContent = 'Queued';
                        if (typeof refreshQueueStatus === 'function') refreshQueueStatus();
                    } else {
                        showToast('Failed to queue conversion: ' + (result.error || 'Unknown error'), 'error');
                        this.textContent = originalText;
                        this.disabled = false;
                    }
                } catch (err) {
                    console.error('Conversion error:', err);
                    showToast('Failed to queue conversion', 'error');
                    this.textContent = originalText;
                    this.disabled = false;
                }
            });
        });


        // Version upload management
        function showUploadVersionModal() {
            const modal = document.getElementById('upload-version-modal');
            modal.style.display = 'flex';
            trapFocus(modal);
        }

        function closeUploadVersionModal() {
            const modal = document.getElementById('upload-version-modal');
            releaseFocus(modal);
            modal.style.display = 'none';
            document.getElementById('upload-version-form').reset();
        }

        document.getElementById('upload-version-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeUploadVersionModal();
        });

        async function submitUploadVersion(e) {
            e.preventDefault();
            const fileInput = document.getElementById('version-file');
            const changelog = document.getElementById('version-changelog').value.trim();
            const submitBtn = document.getElementById('version-submit-btn');

            if (!fileInput.files.length) {
                showToast('Please select a file', 'error');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';

            const formData = new FormData();
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('version_file', fileInput.files[0]);
            formData.append('changelog', changelog);

            try {
                const response = await fetch('/actions/upload-version', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    closeUploadVersionModal();
                    location.reload();
                } else {
                    showToast('Failed: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error('Upload version error:', err);
                showToast('Failed to upload version', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload Version';
            }
        }

        // Position fixed dropdown menu relative to toggle button
        function positionDropdownMenu(dropdown) {
            const menu = dropdown.querySelector('.dropdown-menu');
            const btn = dropdown.querySelector('.dropdown-toggle');
            if (!menu || !btn) return;

            const btnRect = btn.getBoundingClientRect();

            // Check if dropdown is inside part-actions (needs fixed positioning)
            const isPartDropdown = dropdown.classList.contains('part-actions-dropdown');

            if (isPartDropdown) {
                // Position below the button, aligned to the right
                const right = window.innerWidth - btnRect.right;
                menu.style.top = (btnRect.bottom + 4) + 'px';
                menu.style.right = right + 'px';
                menu.style.left = 'auto';
            }
        }

        // Dropdown toggle handling
        document.querySelectorAll('.dropdown-toggle').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const dropdown = this.closest('.dropdown');
                const wasOpen = dropdown.classList.contains('open');

                // Close all other dropdowns
                document.querySelectorAll('.dropdown.open').forEach(d => {
                    d.classList.remove('open');
                    const t = d.querySelector('.dropdown-toggle');
                    if (t) t.setAttribute('aria-expanded', 'false');
                });

                // Toggle this dropdown
                if (!wasOpen) {
                    dropdown.classList.add('open');
                    this.setAttribute('aria-expanded', 'true');
                    positionDropdownMenu(dropdown);

                    // Restore calculated data for part dropdowns
                    if (dropdown.classList.contains('part-actions-dropdown')) {
                        const partItem = dropdown.closest('.part-item');
                        if (partItem) {
                            const partId = partItem.querySelector('.part-checkbox')?.value ||
                                          partItem.dataset.partId;
                            if (partId) {
                                restorePartCalculatedData(partId, dropdown);
                            }
                        }
                    }
                }
            });
        });

        // Close dropdowns when clicking outside (but not when interacting with inline controls)
        function closeAllDropdowns() {
            document.querySelectorAll('.dropdown.open').forEach(d => {
                d.classList.remove('open');
                const t = d.querySelector('.dropdown-toggle');
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        }
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                closeAllDropdowns();
            } else if (e.target.closest('.dropdown-item') && !e.target.closest('.dropdown-item-inline') && !e.target.closest('select')) {
                closeAllDropdowns();
            }
        });

        // Close fixed-position dropdowns on scroll (since they won't move with the page)
        window.addEventListener('scroll', function() {
            closeAllDropdowns();
        }, { passive: true });


document.addEventListener('DOMContentLoaded', function() {
    // ── Favorite button ──────────────────────────────────────────────
    document.querySelector('.favorite-btn')?.addEventListener('click', function() {
        toggleFavorite(ModelPageConfig.modelId, this);
    });

    // ── Password visibility toggle ───────────────────────────────────
    document.querySelector('.password-toggle')?.addEventListener('click', function() {
        togglePasswordVisibility(this);
    });

    // ── Add link form toggle ─────────────────────────────────────────
    document.getElementById('add-link-toggle')?.addEventListener('click', toggleAddLinkForm);
    document.querySelector('.cancel-link-form')?.addEventListener('click', toggleAddLinkForm);
    document.querySelector('.submit-link-form')?.addEventListener('click', addModelLink);

    // ── Attachment upload ─────────────────────────────────────────────
    document.getElementById('attachment-file-input')?.addEventListener('change', function() {
        uploadAttachments(this.files);
    });
    document.querySelector('.trigger-attachment-upload')?.addEventListener('click', function() {
        document.getElementById('attachment-file-input').click();
    });

    // ── Add parts upload ─────────────────────────────────────────────
    document.getElementById('add-part-file')?.addEventListener('change', function() {
        uploadParts(this.files);
    });
    document.querySelectorAll('.trigger-add-parts').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('add-part-file')?.click();
        });
    });

    // ── Upload version modal ─────────────────────────────────────────
    document.querySelector('.show-upload-version')?.addEventListener('click', showUploadVersionModal);
    document.getElementById('upload-version-form')?.addEventListener('submit', submitUploadVersion);

    // ── Create folder modal ──────────────────────────────────────────
    document.querySelector('.show-create-folder')?.addEventListener('click', showCreateFolderModal);
    document.getElementById('create-folder-form')?.addEventListener('submit', submitCreateFolder);

    // ── Move folder modal ─────────────────────────────────────────────
    document.querySelector('.submit-move-folder')?.addEventListener('click', submitMoveToFolder);

    // ── Modal close buttons — map parent modal ID to close function ───
    const modalClosers = {
        'part-preview-modal': closePartPreview,
        'create-folder-modal': closeCreateFolderModal,
        'move-folder-modal': closeMoveFolderModal,
        'upload-version-modal': closeUploadVersionModal,
        'batch-rename-modal': closeBatchRenameModal,
    };
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('[id]');
            const fn = modal && modalClosers[modal.id];
            if (fn) fn();
        });
    });

    // ── Batch rename modal actions ────────────────────────────────────
    document.querySelector('.cancel-batch-rename')?.addEventListener('click', closeBatchRenameModal);
    document.querySelector('.apply-batch-rename')?.addEventListener('click', applyBatchRename);
    document.getElementById('rename-pattern')?.addEventListener('input', updateRenamePreview);
    document.getElementById('rename-prefix')?.addEventListener('input', updateRenamePreview);
    document.getElementById('rename-suffix')?.addEventListener('input', updateRenamePreview);

    // ── Select all parts ─────────────────────────────────────────────
    document.getElementById('select-all-parts')?.addEventListener('click', function() {
        toggleSelectAllParts(this);
    });

    // ── Collapse all groups ───────────────────────────────────────────
    document.querySelector('.collapse-all-toggle')?.addEventListener('click', function() {
        toggleCollapseAllGroups(this);
    });

    // ── Mass print type select ───────────────────────────────────────
    document.getElementById('mass-print-type')?.addEventListener('change', function() {
        massUpdatePrintType(this);
    });

    // ── Edit model (navigate to edit page) ───────────────────────────
    document.querySelector('.navigate-edit-model')?.addEventListener('click', function() {
        window.location.href = this.dataset.href;
    });

    // ── Archive / unarchive ───────────────────────────────────────────
    document.querySelectorAll('.toggle-archive').forEach(btn => {
        btn.addEventListener('click', function() {
            const archive = this.dataset.archiveValue === 'true';
            toggleArchive(ModelPageConfig.modelId, archive);
        });
    });

    // ── Event delegation for dynamically-generated and loop-rendered elements ──

    document.addEventListener('click', function(e) {
        // Tag remove button
        if (e.target.matches('.model-tag-remove')) {
            e.preventDefault();
            e.stopPropagation();
            const tagId = parseInt(e.target.dataset.tagId);
            removeTag(ModelPageConfig.modelId, tagId, e.target.parentElement);
            return;
        }

        // Model link delete button
        if (e.target.matches('.model-link-delete')) {
            const linkId = parseInt(e.target.closest('.model-link-item')?.dataset.linkId);
            if (linkId) deleteModelLink(linkId);
            return;
        }

        // Attachment set as thumbnail
        if (e.target.matches('.attachment-set-thumb')) {
            const attachmentId = parseInt(e.target.closest('[data-attachment-id]')?.dataset.attachmentId);
            if (attachmentId) setAttachmentAsThumbnail(attachmentId);
            return;
        }

        // Attachment delete
        if (e.target.matches('.attachment-delete')) {
            const attachmentId = parseInt(e.target.closest('[data-attachment-id]')?.dataset.attachmentId);
            if (attachmentId) deleteAttachment(attachmentId);
            return;
        }

        // Attachment image lightbox
        if (e.target.matches('.attachment-image-trigger')) {
            openImageLightbox(e.target.dataset.lightboxSrc, e.target.dataset.lightboxAlt);
            return;
        }

        // Document preview trigger
        const previewTrigger = e.target.matches('.attachment-preview-trigger') ? e.target : e.target.closest('.attachment-preview-trigger');
        if (previewTrigger) {
            e.preventDefault();
            openDocumentPreview(previewTrigger.dataset.previewSrc, previewTrigger.dataset.previewType, previewTrigger.dataset.previewName);
            return;
        }

        // Show more parts
        if (e.target.matches('.show-more-parts')) {
            showMoreParts(e.target);
            return;
        }

        // Folder actions — stop propagation
        if (e.target.closest('.folder-actions')) {
            e.stopPropagation();
        }

        // Folder checkbox — stop propagation + select parts
        if (e.target.matches('.folder-checkbox')) {
            e.stopPropagation();
            selectFolderParts(e.target);
            return;
        }

        // Folder header collapse toggle
        if (e.target.matches('.parts-group-header') || e.target.closest('.parts-group-header')) {
            const header = e.target.matches('.parts-group-header') ? e.target : e.target.closest('.parts-group-header');
            if (!e.target.matches('.folder-checkbox') && !e.target.closest('.folder-actions')) {
                toggleFolder(header.parentElement);
            }
            return;
        }

        // Collapsible section toggle (sidebar)
        if (e.target.matches('.collapsible-header') || e.target.closest('.collapsible-header')) {
            const header = e.target.matches('.collapsible-header') ? e.target : e.target.closest('.collapsible-header');
            const section = header.closest('.collapsible-section');
            section.classList.toggle('collapsed');
            header.setAttribute('aria-expanded', !section.classList.contains('collapsed'));
            return;
        }

        // Rename folder button
        if (e.target.matches('.rename-folder-btn')) {
            renameFolder(e.target.dataset.folder);
            return;
        }

        // Delete folder button
        if (e.target.matches('.delete-folder-btn')) {
            deleteFolder(e.target.dataset.folder);
            return;
        }

        // Calculate dimensions
        if (e.target.matches('.calc-dimensions-btn')) {
            calculatePartDimensions(parseInt(e.target.dataset.partId), e.target);
            return;
        }

        // Calculate volume
        if (e.target.matches('.calc-volume-btn')) {
            calculatePartVolume(parseInt(e.target.dataset.partId), e.target);
            return;
        }

        // Analyze mesh
        if (e.target.matches('.analyze-mesh-btn')) {
            analyzePartMesh(parseInt(e.target.dataset.partId), e.target);
            return;
        }

        // Move single part to folder
        if (e.target.matches('.show-move-folder-single')) {
            showMoveFolderModal([parseInt(e.target.dataset.partId)]);
            return;
        }

        // Move selected parts to folder
        if (e.target.matches('.show-move-folder-modal')) {
            showMoveFolderModal(getSelectedPartIds());
            return;
        }

        // Show batch rename modal
        if (e.target.matches('.show-batch-rename-modal')) {
            showBatchRenameModal(getSelectedPartIds());
            return;
        }
    });

    // ── Keyboard delegation for focusable elements with role=button ──
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            // Attachment image keyboard activation
            if (e.target.matches('.attachment-image-trigger')) {
                e.preventDefault();
                openImageLightbox(e.target.dataset.lightboxSrc, e.target.dataset.lightboxAlt);
                return;
            }
            // Folder header keyboard activation
            if (e.target.matches('.parts-group-header')) {
                e.preventDefault();
                toggleFolder(e.target.parentElement);
                return;
            }
        }
    });

    // ── Change delegation ─────────────────────────────────────────────
    document.addEventListener('change', function(e) {
        if (e.target.matches('.print-type-select.folder-print-type')) {
            updateFolderPrintType(e.target, e.target.dataset.folder);
            return;
        }
        if (e.target.matches('.print-type-select:not(.folder-print-type):not(#mass-print-type)')) {
            updatePrintType(e.target);
            return;
        }
    });

    // ── Upload processing banner polling ──────────────────────────────
    var banner = document.getElementById('upload-processing-banner');
    if (banner) {
        var modelId = banner.dataset.modelId;
        var pollInterval = setInterval(function() {
            fetch('/actions/queue-status?model_id=' + modelId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.upload_status === 'ready') {
                        clearInterval(pollInterval);
                        window.location.reload();
                    } else if (data.upload_status === 'failed') {
                        clearInterval(pollInterval);
                        banner.className = 'alert alert-error mb-4';
                        banner.setAttribute('role', 'alert');
                        banner.innerHTML = 'Upload processing failed. Please try uploading again.';
                    }
                })
                .catch(function() { /* silently retry next interval */ });
        }, 5000);
    }
});

