<?php

require_once __DIR__ . '/../includes/PdfOptimizer.php';

/**
 * Compress a PDF attachment in the background.
 *
 * Looks up the attachment row, runs PdfOptimizer with the configured mode,
 * and swaps the file in place if a smaller output was produced. The
 * pdf_compressed column is set to 1 in either case so we don't keep re-
 * processing the same file on every retroactive batch run.
 *
 * Payload: ['id' => int]   → model_attachments row id
 */
class OptimizePdf extends Job
{
    public int $maxAttempts = 2;
    public int $timeout = 600; // 10 min — large PDFs can take a while

    public function handle(array $data): void
    {
        if (!PdfOptimizer::isEnabled()) {
            return;
        }

        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) return;

        $db = getDB();
        $stmt = $db->prepare('SELECT id, file_path, file_type, pdf_compressed FROM model_attachments WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $result = $stmt->execute();
        $row = $result->fetchArray(PDO::FETCH_ASSOC);
        if (!$row) return;

        // Only PDFs, only if not already done
        if (($row['file_type'] ?? '') !== 'pdf') return;
        if ((int)($row['pdf_compressed'] ?? 0) === 1) return;
        if (empty($row['file_path'])) return;

        $relPath = $row['file_path'];
        $absPath = UPLOAD_PATH . $relPath;

        if (!file_exists($absPath)) {
            // File missing — flag as done so we don't retry forever
            $this->markCompressed($db, $id);
            return;
        }

        $mode = PdfOptimizer::getMode();
        if ($mode === null) {
            // No compression binary installed — nothing we can do.
            // Don't flag as compressed; if a binary is added later the
            // retroactive batch will still pick this row up.
            if (function_exists('logWarning')) {
                logWarning('OptimizePdf: no PDF compression binary available', ['id' => $id]);
            }
            return;
        }

        $originalSize = filesize($absPath);
        $tempOutput = PdfOptimizer::optimize($absPath, $mode);

        if ($tempOutput === null) {
            // Either the binary failed or the output wasn't smaller.
            // Mark as done — there's nothing more we can do with this file.
            $this->markCompressed($db, $id);
            return;
        }

        $newSize = filesize($tempOutput);

        // Atomic swap: write into a sibling file then rename. This avoids
        // a window where concurrent reads see a half-written file.
        $stagingPath = $absPath . '.tmp_pdfopt';
        if (!@rename($tempOutput, $stagingPath)) {
            // Fall back to copy if rename across filesystems fails (the temp
            // dir may be on a different mount than UPLOAD_PATH).
            if (!@copy($tempOutput, $stagingPath)) {
                @unlink($tempOutput);
                $this->markCompressed($db, $id);
                return;
            }
            @unlink($tempOutput);
        }

        if (!@rename($stagingPath, $absPath)) {
            @unlink($stagingPath);
            $this->markCompressed($db, $id);
            return;
        }

        // Update DB to reflect new size and mark compressed
        $upd = $db->prepare('UPDATE model_attachments SET file_size = :s, pdf_compressed = 1 WHERE id = :id');
        $upd->bindValue(':s', $newSize, PDO::PARAM_INT);
        $upd->bindValue(':id', $id, PDO::PARAM_INT);
        $upd->execute();

        if (function_exists('logInfo')) {
            $savings = round((1 - $newSize / $originalSize) * 100, 1);
            logInfo('OptimizePdf: compressed PDF', [
                'id' => $id,
                'mode' => $mode,
                'original_bytes' => $originalSize,
                'new_bytes' => $newSize,
                'savings_pct' => $savings,
            ]);
        }
    }

    private function markCompressed($db, int $id): void
    {
        try {
            $stmt = $db->prepare('UPDATE model_attachments SET pdf_compressed = 1 WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Throwable $e) {
            // Column may not exist on this install yet — ignore
        }
    }
}
