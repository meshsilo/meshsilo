/**
 * Model Parts - Upload & Progress
 * Split from model-parts.js (behaviour unchanged; code moved verbatim).
 * Handles adding parts to an existing model via TUS chunked resumable uploads,
 * with an inline progress bar.
 *
 * uploadParts() is global and invoked by model-page.js (add-part-file change /
 * .trigger-add-parts click). Load order (set in includes/header.php): after
 * window.ModelPageConfig and the model-parts.js core, before model-page.js.
 * Relies on the global tus client (tus-js-client) and showToast from ui-common.js.
 */

        // Upload parts function - uses TUS chunked resumable uploads
        async function uploadParts(files) {
            if (!files || files.length === 0) return;
            if (typeof tus === 'undefined') {
                showToast('Upload library not loaded. Please refresh and try again.', 'error');
                return;
            }

            const modelId = ModelPageConfig.modelId;
            const totalFiles = files.length;
            let completedFiles = 0;
            let errorCount = 0;

            // Disable add-parts buttons
            document.querySelectorAll('.trigger-add-parts').forEach(function(btn) {
                btn.disabled = true;
                btn._originalText = btn.textContent;
                btn.textContent = 'Uploading...';
            });

            // Create or show progress container
            let progressContainer = document.getElementById('add-parts-progress');
            if (!progressContainer) {
                progressContainer = document.createElement('div');
                progressContainer.id = 'add-parts-progress';
                progressContainer.className = 'add-parts-progress';
                progressContainer.innerHTML =
                    '<div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="Upload progress">' +
                        '<div class="progress-bar-fill" style="width: 0%"></div>' +
                    '</div>' +
                    '<div class="add-parts-progress-text"></div>';
                // Insert before the parts actions area
                var partsActions = document.querySelector('.parts-actions');
                if (partsActions) {
                    partsActions.parentNode.insertBefore(progressContainer, partsActions);
                } else {
                    document.querySelector('.model-parts')?.appendChild(progressContainer);
                }
            }
            progressContainer.style.display = 'block';
            var progressFill = progressContainer.querySelector('.progress-bar-fill');
            var progressText = progressContainer.querySelector('.add-parts-progress-text');

            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / 1048576).toFixed(1) + ' MB';
            }

            function updateOverallProgress(fileIndex, filePercent) {
                var overall = Math.round(((fileIndex + filePercent / 100) / totalFiles) * 100);
                progressFill.style.width = overall + '%';
                progressFill.parentElement.setAttribute('aria-valuenow', overall);
            }

            // Upload files sequentially using TUS
            for (let i = 0; i < totalFiles; i++) {
                const file = files[i];
                progressText.textContent = 'Uploading ' + (i + 1) + ' of ' + totalFiles + ': ' + file.name;
                updateOverallProgress(i, 0);

                try {
                    await new Promise(function(resolve, reject) {
                        var upload = new tus.Upload(file, {
                            endpoint: '/actions/tus',
                            chunkSize: 5 * 1024 * 1024,
                            retryDelays: [0, 1000, 3000, 5000],
                            metadata: {
                                filename: file.name,
                                filetype: file.type || file.name.split('.').pop(),
                                parent_id: String(modelId)
                            },
                            onProgress: function(bytesUploaded, bytesTotal) {
                                var percent = Math.round((bytesUploaded / bytesTotal) * 100);
                                updateOverallProgress(i, percent);
                                progressText.textContent = 'Uploading ' + (i + 1) + '/' + totalFiles + ': ' + file.name + ' — ' + percent + '% (' + formatSize(bytesUploaded) + ' / ' + formatSize(bytesTotal) + ')';
                            },
                            onSuccess: function() {
                                completedFiles++;
                                resolve();
                            },
                            onError: function(error) {
                                errorCount++;
                                console.error('TUS upload failed for', file.name, ':', error);
                                resolve(); // continue with next file
                            }
                        });
                        upload.start();
                    });
                } catch (err) {
                    errorCount++;
                    console.error('Upload error for', file.name, ':', err);
                }
            }

            // Reset file input
            document.getElementById('add-part-file').value = '';

            // Show completion
            if (completedFiles > 0) {
                progressText.textContent = 'Processing ' + completedFiles + ' file(s)...';
                progressFill.style.width = '100%';
                if (errorCount > 0) {
                    showToast('Uploaded ' + completedFiles + ' files. ' + errorCount + ' failed.', 'error');
                }
                // Reload after a brief delay to let queue process
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                progressContainer.style.display = 'none';
                document.querySelectorAll('.trigger-add-parts').forEach(function(btn) {
                    btn.textContent = btn._originalText || 'Add Parts';
                    btn.disabled = false;
                });
                showToast('Upload failed. ' + errorCount + ' file(s) could not be uploaded.', 'error');
            }
        }
