<?php

// Worker context has already loaded config.php + Queue.php (defines Job
// abstract) before the job is instantiated, so only the conversion helper
// needs an explicit require here.
require_once __DIR__ . '/../includes/ImageConverter.php';

/**
 * Convert an uploaded image to WebP.
 *
 * Dispatched from upload handlers (attachments, custom thumbnails) after
 * saving the original. Looks up the file from the DB, converts it, updates
 * the DB row to point at the new `.webp` file, and removes the original.
 *
 * Payload shapes:
 *   ['type' => 'attachment',  'id' => int]        → model_attachments row
 *   ['type' => 'thumbnail',   'model_id' => int]  → models.thumbnail_path
 */
class OptimizeImage extends Job
{
    public int $maxAttempts = 2;
    public int $timeout = 300;

    public function handle(array $data): void
    {
        if (!ImageConverter::isEnabled()) {
            return;
        }

        $type = $data['type'] ?? null;
        if ($type === 'attachment') {
            $this->handleAttachment((int)($data['id'] ?? 0));
        } elseif ($type === 'thumbnail') {
            $this->handleThumbnail((int)($data['model_id'] ?? 0));
        }
    }

    private function handleAttachment(int $attachmentId): void
    {
        if ($attachmentId <= 0) return;

        $db = getDB();
        $stmt = $db->prepare('SELECT id, file_path FROM model_attachments WHERE id = :id');
        $stmt->bindValue(':id', $attachmentId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $row = $result->fetchArray(PDO::FETCH_ASSOC);
        if (!$row || empty($row['file_path'])) return;

        $relPath = $row['file_path'];
        if (!ImageConverter::isConvertible($relPath)) return;

        $absPath = UPLOAD_PATH . $relPath;
        if (!file_exists($absPath)) return;

        $newAbsPath = ImageConverter::toWebp($absPath);
        if (!$newAbsPath) {
            if (function_exists('logWarning')) {
                logWarning('OptimizeImage: attachment conversion failed', ['id' => $attachmentId, 'path' => $relPath]);
            }
            return;
        }

        $newRelPath = self::pathRelativeToUploadPath($newAbsPath, $relPath);
        $newFilename = basename($newAbsPath);
        $newSize = filesize($newAbsPath);

        $upd = $db->prepare(
            'UPDATE model_attachments SET file_path = :p, mime_type = :m, filename = :f, file_size = :s WHERE id = :id'
        );
        $upd->bindValue(':p', $newRelPath, PDO::PARAM_STR);
        $upd->bindValue(':m', 'image/webp', PDO::PARAM_STR);
        $upd->bindValue(':f', $newFilename, PDO::PARAM_STR);
        $upd->bindValue(':s', $newSize, PDO::PARAM_INT);
        $upd->bindValue(':id', $attachmentId, PDO::PARAM_INT);
        $upd->execute();

        // Delete original only after DB points to new file — avoids brief
        // window where DB references a file that's already gone
        @unlink($absPath);

        if (function_exists('logInfo')) {
            logInfo('OptimizeImage: attachment converted to WebP', [
                'id' => $attachmentId,
                'old_path' => $relPath,
                'new_path' => $newRelPath,
            ]);
        }
    }

    private function handleThumbnail(int $modelId): void
    {
        if ($modelId <= 0) return;

        $db = getDB();
        $stmt = $db->prepare('SELECT id, thumbnail_path FROM models WHERE id = :id');
        $stmt->bindValue(':id', $modelId, PDO::PARAM_INT);
        $result = $stmt->execute();
        $row = $result->fetchArray(PDO::FETCH_ASSOC);
        if (!$row || empty($row['thumbnail_path'])) return;

        $relPath = $row['thumbnail_path'];
        if (!ImageConverter::isConvertible($relPath)) return;

        $absPath = UPLOAD_PATH . $relPath;
        if (!file_exists($absPath)) return;

        $newAbsPath = ImageConverter::toWebp($absPath);
        if (!$newAbsPath) {
            if (function_exists('logWarning')) {
                logWarning('OptimizeImage: thumbnail conversion failed', ['model_id' => $modelId, 'path' => $relPath]);
            }
            return;
        }

        $newRelPath = self::pathRelativeToUploadPath($newAbsPath, $relPath);

        $upd = $db->prepare('UPDATE models SET thumbnail_path = :p WHERE id = :id');
        $upd->bindValue(':p', $newRelPath, PDO::PARAM_STR);
        $upd->bindValue(':id', $modelId, PDO::PARAM_INT);
        $upd->execute();

        @unlink($absPath);

        if (function_exists('logInfo')) {
            logInfo('OptimizeImage: thumbnail converted to WebP', [
                'model_id' => $modelId,
                'old_path' => $relPath,
                'new_path' => $newRelPath,
            ]);
        }
    }

    /**
     * Given an absolute destination path produced by ImageConverter and the
     * original relative path, return the new relative path. Strips the
     * UPLOAD_PATH prefix so the DB stores portable relative paths.
     */
    private static function pathRelativeToUploadPath(string $newAbsPath, string $oldRelPath): string
    {
        $upload = rtrim(UPLOAD_PATH, '/') . '/';
        if (str_starts_with($newAbsPath, $upload)) {
            return substr($newAbsPath, strlen($upload));
        }
        // Fallback: swap the extension in the old relative path
        return preg_replace('/\.(jpe?g|png|gif)$/i', '', $oldRelPath) . '.webp';
    }
}
