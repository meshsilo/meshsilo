/**
 * Model Attachments & Lightbox
 * Handles image/document attachments, lightbox with zoom/pan/navigation, and attachment upload.
 * Loaded on the model detail page before model-page.js.
 */

        // Attachments - Lightbox
        let lightboxImages = [];
        let lightboxIndex = 0;
        let lbZoom = 1, lbPanX = 0, lbPanY = 0, lbDragging = false, lbDragStart = {};

        function collectLightboxImages() {
            lightboxImages = [];
            const grid = document.getElementById('attachment-images');
            if (!grid) return;
            const imgs = grid.querySelectorAll('.attachment-image img');
            imgs.forEach(function(img) {
                lightboxImages.push({ src: img.src, caption: img.alt });
            });
        }

        function lightboxResetZoom() {
            lbZoom = 1; lbPanX = 0; lbPanY = 0;
            const lightbox = document.getElementById('image-lightbox');
            if (lightbox) {
                const img = lightbox.querySelector('.lightbox-content img');
                if (img) { img.style.transform = ''; img.style.cursor = ''; }
            }
        }

        function lightboxApplyTransform() {
            const lightbox = document.getElementById('image-lightbox');
            if (!lightbox) return;
            const img = lightbox.querySelector('.lightbox-content img');
            if (!img) return;
            if (lbZoom <= 1) {
                img.style.transform = '';
                img.style.cursor = '';
            } else {
                img.style.transform = 'scale(' + lbZoom + ') translate(' + (lbPanX / lbZoom) + 'px, ' + (lbPanY / lbZoom) + 'px)';
                img.style.cursor = 'grab';
            }
        }

        function openImageLightbox(src, caption) {
            collectLightboxImages();
            lightboxIndex = lightboxImages.findIndex(function(img) { return img.src === src || img.src.endsWith(src); });
            if (lightboxIndex < 0) lightboxIndex = 0;
            lbZoom = 1; lbPanX = 0; lbPanY = 0;

            const existing = document.getElementById('image-lightbox');
            if (existing) existing.remove();

            const hasMultiple = lightboxImages.length > 1;
            const lightbox = document.createElement('div');
            lightbox.id = 'image-lightbox';
            lightbox.className = 'modal lightbox-overlay';
            lightbox.setAttribute('role', 'dialog');
            lightbox.setAttribute('aria-modal', 'true');
            lightbox.innerHTML =
                '<div class="lightbox-content">' +
                    '<button type="button" class="lightbox-close" aria-label="Close" onclick="closeLightbox()">&times;</button>' +
                    (hasMultiple ? '<button type="button" class="lightbox-nav lightbox-prev" aria-label="Previous image" onclick="lightboxNav(-1)">&#8249;</button>' : '') +
                    '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(caption) + '" draggable="false">' +
                    (hasMultiple ? '<button type="button" class="lightbox-nav lightbox-next" aria-label="Next image" onclick="lightboxNav(1)">&#8250;</button>' : '') +
                    '<div class="lightbox-caption">' + escapeHtml(caption) +
                    (hasMultiple ? ' <span class="lightbox-counter">' + (lightboxIndex + 1) + ' / ' + lightboxImages.length + '</span>' : '') +
                    '</div>' +
                '</div>';
            document.body.appendChild(lightbox);
            lightbox.style.display = 'flex';

            const lbImg = lightbox.querySelector('.lightbox-content img');

            // Scroll to zoom
            lightbox.addEventListener('wheel', function(e) {
                e.preventDefault();
                const delta = e.deltaY > 0 ? -0.2 : 0.2;
                lbZoom = Math.min(5, Math.max(1, lbZoom + delta));
                if (lbZoom <= 1) { lbPanX = 0; lbPanY = 0; }
                lightboxApplyTransform();
            }, { passive: false });

            // Double-click to toggle zoom
            lbImg.addEventListener('dblclick', function(e) {
                e.stopPropagation();
                if (lbZoom > 1) { lightboxResetZoom(); } else { lbZoom = 2.5; lightboxApplyTransform(); }
            });

            // Drag to pan
            lbImg.addEventListener('mousedown', function(e) {
                if (lbZoom <= 1) return;
                e.preventDefault();
                lbDragging = true;
                lbDragStart = { x: e.clientX - lbPanX, y: e.clientY - lbPanY };
                lbImg.style.cursor = 'grabbing';
            });
            document.addEventListener('mousemove', lightboxMouseMove);
            document.addEventListener('mouseup', lightboxMouseUp);

            // Touch zoom/pan
            let lastTouchDist = 0;
            lightbox.addEventListener('touchstart', function(e) {
                if (e.touches.length === 2) {
                    lastTouchDist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                } else if (e.touches.length === 1 && lbZoom > 1) {
                    lbDragging = true;
                    lbDragStart = { x: e.touches[0].clientX - lbPanX, y: e.touches[0].clientY - lbPanY };
                }
            }, { passive: true });
            lightbox.addEventListener('touchmove', function(e) {
                if (e.touches.length === 2) {
                    e.preventDefault();
                    const dist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
                    if (lastTouchDist > 0) {
                        lbZoom = Math.min(5, Math.max(1, lbZoom * (dist / lastTouchDist)));
                        if (lbZoom <= 1) { lbPanX = 0; lbPanY = 0; }
                        lightboxApplyTransform();
                    }
                    lastTouchDist = dist;
                } else if (e.touches.length === 1 && lbDragging) {
                    e.preventDefault();
                    lbPanX = e.touches[0].clientX - lbDragStart.x;
                    lbPanY = e.touches[0].clientY - lbDragStart.y;
                    lightboxApplyTransform();
                }
            }, { passive: false });
            lightbox.addEventListener('touchend', function() { lbDragging = false; lastTouchDist = 0; });

            // Close on background click (only if not zoomed)
            lightbox.addEventListener('click', function(e) {
                if (e.target === this && lbZoom <= 1) closeLightbox();
            });

            document.addEventListener('keydown', lightboxKeyHandler);
        }

        function lightboxMouseMove(e) {
            if (!lbDragging) return;
            lbPanX = e.clientX - lbDragStart.x;
            lbPanY = e.clientY - lbDragStart.y;
            lightboxApplyTransform();
        }

        function lightboxMouseUp() {
            if (lbDragging) {
                lbDragging = false;
                const lightbox = document.getElementById('image-lightbox');
                if (lightbox) {
                    const img = lightbox.querySelector('.lightbox-content img');
                    if (img && lbZoom > 1) img.style.cursor = 'grab';
                }
            }
        }

        function lightboxNav(dir) {
            if (lightboxImages.length < 2) return;
            lightboxResetZoom();
            lightboxIndex = (lightboxIndex + dir + lightboxImages.length) % lightboxImages.length;
            const img = lightboxImages[lightboxIndex];
            const lightbox = document.getElementById('image-lightbox');
            if (!lightbox) return;
            lightbox.querySelector('.lightbox-content img').src = img.src;
            lightbox.querySelector('.lightbox-content img').alt = img.caption;
            const caption = lightbox.querySelector('.lightbox-caption');
            caption.innerHTML = escapeHtml(img.caption) + ' <span class="lightbox-counter">' + (lightboxIndex + 1) + ' / ' + lightboxImages.length + '</span>';
        }

        function closeLightbox() {
            const lightbox = document.getElementById('image-lightbox');
            if (lightbox) lightbox.remove();
            document.removeEventListener('keydown', lightboxKeyHandler);
            document.removeEventListener('mousemove', lightboxMouseMove);
            document.removeEventListener('mouseup', lightboxMouseUp);
            lbZoom = 1; lbPanX = 0; lbPanY = 0;
        }

        function lightboxKeyHandler(e) {
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') lightboxNav(-1);
            if (e.key === 'ArrowRight') lightboxNav(1);
        }

        // Document Preview
        function openDocumentPreview(src, type, name) {
            const existing = document.getElementById('document-preview');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.id = 'document-preview';
            overlay.className = 'modal lightbox-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            let contentHtml = '';
            if (type === 'pdf') {
                contentHtml = '<iframe src="' + escapeHtml(src) + '" class="doc-preview-iframe"></iframe>';
            } else {
                contentHtml = '<div class="doc-preview-text"><p class="text-muted">Loading...</p></div>';
            }

            overlay.innerHTML =
                '<div class="doc-preview-container">' +
                    '<div class="doc-preview-header">' +
                        '<span class="doc-preview-name">' + escapeHtml(name) + '</span>' +
                        '<div class="doc-preview-actions">' +
                            '<a href="' + escapeHtml(src) + '" target="_blank" rel="noopener noreferrer" class="btn btn-small btn-secondary" title="Open in new tab">Open</a>' +
                            '<a href="' + escapeHtml(src) + '" download class="btn btn-small btn-secondary" title="Download">Download</a>' +
                            '<button type="button" class="lightbox-close" aria-label="Close" onclick="closeDocumentPreview()">&times;</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="doc-preview-body">' + contentHtml + '</div>' +
                '</div>';

            document.body.appendChild(overlay);
            overlay.style.display = 'flex';

            // For text files, fetch and render content
            if (type !== 'pdf') {
                fetch(src)
                    .then(function(r) { return r.text(); })
                    .then(function(text) {
                        const container = overlay.querySelector('.doc-preview-text');
                        if (!container) return;
                        if (type === 'md') {
                            // Render as preformatted text (markdown source)
                            container.innerHTML = '<pre>' + escapeHtml(text) + '</pre>';
                        } else {
                            container.innerHTML = '<pre>' + escapeHtml(text) + '</pre>';
                        }
                    })
                    .catch(function(err) {
                        const container = overlay.querySelector('.doc-preview-text');
                        if (container) container.innerHTML = '<p class="text-muted">Failed to load preview.</p>';
                    });
            }

            // Close on background click
            overlay.addEventListener('click', function(e) {
                if (e.target === this) closeDocumentPreview();
            });

            document.addEventListener('keydown', docPreviewKeyHandler);
        }

        function closeDocumentPreview() {
            const preview = document.getElementById('document-preview');
            if (preview) preview.remove();
            document.removeEventListener('keydown', docPreviewKeyHandler);
        }

        function docPreviewKeyHandler(e) {
            if (e.key === 'Escape') closeDocumentPreview();
        }

        // Attachments - Upload
        async function uploadAttachments(files) {
            if (!files.length) return;

            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/markdown'];
            const allowedExts = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.txt', '.md'];

            for (const file of files) {
                const ext = '.' + file.name.split('.').pop().toLowerCase();
                if (!allowedTypes.includes(file.type) && !allowedExts.includes(ext)) {
                    showToast(`Invalid file type: ${file.name}. Allowed: Images, PDFs, TXT, MD`, 'error');
                    continue;
                }

                const formData = new FormData();
                formData.append('action', 'upload');
                formData.append('model_id', ModelPageConfig.modelId);
                formData.append('attachment', file);

                try {
                    const response = await fetch('/actions/attachments', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        // Remove empty state if present
                        const empty = document.getElementById('attachments-empty');
                        if (empty) empty.remove();

                        // Add new attachment to appropriate section
                        if (result.file_type === 'image') {
                            addImageAttachment(result);
                        } else {
                            addDocumentAttachment(result);
                        }
                    } else {
                        showToast(`Error uploading ${file.name}: ${result.error}`, 'error');
                    }
                } catch (err) {
                    showToast(`Error uploading ${file.name}: ${err.message}`, 'error');
                }
            }

            // Clear the file input
            document.getElementById('attachment-file-input').value = '';
        }

        function addImageAttachment(att) {
            let grid = document.getElementById('attachment-images');

            // Create images section if it doesn't exist
            if (!grid) {
                const section = document.createElement('div');
                section.className = 'attachment-section';
                section.innerHTML = '<h4>Images</h4><div class="attachment-grid" id="attachment-images"></div>';

                const uploadDiv = document.querySelector('.model-attachments .attachment-upload');
                uploadDiv.parentNode.insertBefore(section, uploadDiv);
                grid = document.getElementById('attachment-images');
            }

            const item = document.createElement('div');
            item.className = 'attachment-image';
            item.dataset.attachmentId = att.attachment_id;

            const img = document.createElement('img');
            img.src = '/assets/' + att.file_path;
            img.alt = att.original_filename;
            img.loading = 'lazy';
            img.onclick = function() {
                openImageLightbox('/assets/' + att.file_path, att.original_filename);
            };

            const thumbBtn = document.createElement('button');
            thumbBtn.type = 'button';
            thumbBtn.className = 'attachment-set-thumb';
            thumbBtn.title = 'Set as model thumbnail';
            thumbBtn.innerHTML = '&#128247;';
            thumbBtn.onclick = function() { setAttachmentAsThumbnail(att.attachment_id); };

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'attachment-delete';
            deleteBtn.title = 'Delete';
            deleteBtn.textContent = '×';
            deleteBtn.onclick = function() { deleteAttachment(att.attachment_id); };

            item.appendChild(img);
            item.appendChild(thumbBtn);
            item.appendChild(deleteBtn);
            grid.appendChild(item);
        }

        function addDocumentAttachment(att) {
            let list = document.getElementById('attachment-documents');

            // Create documents section if it doesn't exist
            if (!list) {
                const section = document.createElement('div');
                section.className = 'attachment-section';
                section.innerHTML = '<h4>Documents</h4><div class="attachment-documents" id="attachment-documents"></div>';

                const uploadDiv = document.querySelector('.model-attachments .attachment-upload');
                uploadDiv.parentNode.insertBefore(section, uploadDiv);
                list = document.getElementById('attachment-documents');
            }

            const item = document.createElement('div');
            item.className = 'attachment-document';
            item.dataset.attachmentId = att.attachment_id;

            const badge = document.createElement('span');
            badge.className = 'file-type-badge';
            const docExt = att.original_filename.split('.').pop().toLowerCase();
            badge.textContent = '.' + docExt;

            const link = document.createElement('a');
            link.href = '/assets/' + att.file_path;
            link.className = 'attachment-doc-name attachment-preview-trigger';
            link.dataset.previewSrc = '/assets/' + att.file_path;
            link.dataset.previewType = docExt;
            link.dataset.previewName = att.original_filename;
            link.textContent = att.original_filename;

            const sizeSpan = document.createElement('span');
            sizeSpan.className = 'attachment-doc-size';
            sizeSpan.textContent = formatFileSize(att.file_size);

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'attachment-delete';
            deleteBtn.title = 'Delete';
            deleteBtn.textContent = '×';
            deleteBtn.onclick = function() { deleteAttachment(att.attachment_id); };

            item.appendChild(badge);
            item.appendChild(link);
            item.appendChild(sizeSpan);
            item.appendChild(deleteBtn);
            list.appendChild(item);
        }

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        async function setAttachmentAsThumbnail(attachmentId) {
            const formData = new FormData();
            formData.append('action', 'set_from_attachment');
            formData.append('model_id', ModelPageConfig.modelId);
            formData.append('attachment_id', attachmentId);
            formData.append('csrf_token', ModelPageConfig.csrfToken);

            try {
                const response = await fetch('/actions/thumbnail', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    // Update the thumbnail display
                    const thumbContainer = document.querySelector('.model-detail-thumbnail');
                    if (thumbContainer) {
                        let img = thumbContainer.querySelector('.model-thumbnail-image');
                        if (!img) {
                            img = document.createElement('img');
                            img.className = 'model-thumbnail-image';
                            img.alt = ModelPageConfig.modelName;
                            thumbContainer.prepend(img);
                        }
                        img.src = '/assets/' + result.thumbnail_path + '?t=' + Date.now();
                    }
                } else {
                    showToast(result.error || 'Failed to set thumbnail', 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function deleteAttachment(attachmentId) {
            if (!await showConfirm('Delete this attachment?')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('attachment_id', attachmentId);

            try {
                const response = await fetch('/actions/attachments', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    const item = document.querySelector('[data-attachment-id="' + attachmentId + '"]');
                    if (item) {
                        const section = item.closest('.attachment-section');
                        item.remove();

                        // Remove section if empty
                        const container = section ? section.querySelector('.attachment-grid, .attachment-documents') : null;
                        if (container && container.children.length === 0) {
                            section.remove();
                        }

                        // Show empty state if no attachments remain
                        const imagesGrid = document.getElementById('attachment-images');
                        const docsList = document.getElementById('attachment-documents');
                        const hasImages = imagesGrid && imagesGrid.children.length > 0;
                        const hasDocs = docsList && docsList.children.length > 0;

                        if (!hasImages && !hasDocs) {
                            const uploadDiv = document.querySelector('.attachment-upload');
                            if (uploadDiv && !document.getElementById('attachments-empty')) {
                                const empty = document.createElement('p');
                                empty.className = 'attachments-empty';
                                empty.id = 'attachments-empty';
                                empty.textContent = 'No attachments yet.';
                                uploadDiv.parentNode.insertBefore(empty, uploadDiv);
                            }
                        }
                    }
                } else {
                    showToast(result.error, 'error');
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

