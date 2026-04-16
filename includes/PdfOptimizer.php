<?php

declare(strict_types=1);

/**
 * PDF Optimizer
 *
 * Shells out to Ghostscript or qpdf to compress uploaded PDFs. Dispatched
 * from the background OptimizePdf job.
 *
 * Modes:
 *   gs-ebook    Ghostscript /ebook preset — 150 DPI images, typical 40-70% savings
 *   gs-printer  Ghostscript /printer preset — 300 DPI, print quality, ~20-30%
 *   gs-screen   Ghostscript /screen preset — 72 DPI, largest savings, can blur
 *   qpdf        Lossless object-stream compression — ~10-30%, no quality risk
 *
 * Safety: every run is checked against the source size. If the output isn't
 * smaller by at least MIN_SAVINGS_PERCENT, we return null and the caller
 * keeps the original — no point swapping an equally-sized or larger file.
 */
class PdfOptimizer
{
    private const MIN_SAVINGS_PERCENT = 5;

    private const GS_PRESETS = [
        'gs-ebook' => '/ebook',
        'gs-printer' => '/printer',
        'gs-screen' => '/screen',
    ];

    /**
     * All mode strings the optimizer understands, regardless of whether the
     * underlying binary is installed.
     */
    public static function validModes(): array
    {
        return array_merge(array_keys(self::GS_PRESETS), ['qpdf']);
    }

    public static function isValidMode(string $mode): bool
    {
        return in_array($mode, self::validModes(), true);
    }

    /**
     * Modes that can actually run right now — filtered by installed binaries.
     */
    public static function availableModes(): array
    {
        $modes = [];
        if (self::commandAvailable('gs')) {
            foreach (array_keys(self::GS_PRESETS) as $m) {
                $modes[] = $m;
            }
        }
        if (self::commandAvailable('qpdf')) {
            $modes[] = 'qpdf';
        }
        return $modes;
    }

    /**
     * Whether PDF optimization is enabled in admin settings.
     */
    public static function isEnabled(): bool
    {
        if (!function_exists('getSetting')) {
            return false;
        }
        return getSetting('compress_pdfs', '0') === '1';
    }

    /**
     * The configured compression mode, or the first available mode if the
     * configured one isn't runnable. Returns null if no mode is available.
     */
    public static function getMode(): ?string
    {
        $configured = function_exists('getSetting')
            ? (string)getSetting('compress_pdfs_mode', 'gs-ebook')
            : 'gs-ebook';

        $available = self::availableModes();
        if (in_array($configured, $available, true)) {
            return $configured;
        }

        return $available[0] ?? null;
    }

    /**
     * Compress a PDF file. Writes the result to a temp path and returns
     * that path on success; returns null if the result wasn't at least
     * MIN_SAVINGS_PERCENT smaller than the source, or on any error.
     *
     * The caller is responsible for swapping the file into place and
     * deleting the original (so DB rows can be updated atomically with
     * the file swap).
     */
    public static function optimize(string $sourcePath, string $mode): ?string
    {
        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            return null;
        }
        if (!self::isValidMode($mode)) {
            return null;
        }

        $originalSize = filesize($sourcePath);
        if ($originalSize === false || $originalSize === 0) {
            return null;
        }

        $destPath = sys_get_temp_dir() . '/pdfopt_' . uniqid() . '.pdf';

        $ok = false;
        if (isset(self::GS_PRESETS[$mode])) {
            $ok = self::runGhostscript($sourcePath, $destPath, self::GS_PRESETS[$mode]);
        } elseif ($mode === 'qpdf') {
            $ok = self::runQpdf($sourcePath, $destPath);
        }

        if (!$ok || !file_exists($destPath)) {
            @unlink($destPath);
            return null;
        }

        $newSize = filesize($destPath);
        if ($newSize === false || $newSize <= 0) {
            @unlink($destPath);
            return null;
        }

        $savings = 1 - ($newSize / $originalSize);
        if ($savings < (self::MIN_SAVINGS_PERCENT / 100)) {
            // Not worth swapping — output is equal size or bigger
            @unlink($destPath);
            return null;
        }

        return $destPath;
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private static function runGhostscript(string $source, string $dest, string $preset): bool
    {
        if (!self::commandAvailable('gs')) {
            return false;
        }

        // -dNOPAUSE -dBATCH: non-interactive
        // -dQUIET: suppress progress messages
        // -sDEVICE=pdfwrite: produce a PDF
        // -dCompatibilityLevel=1.4: broad reader compatibility
        // -dPDFSETTINGS=$preset: select compression preset
        $cmd = sprintf(
            'gs -dNOPAUSE -dBATCH -dQUIET -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=%s -sOutputFile=%s %s 2>&1',
            escapeshellarg($preset),
            escapeshellarg($dest),
            escapeshellarg($source)
        );

        $output = [];
        $exit = 1;
        @exec($cmd, $output, $exit);

        if ($exit !== 0 && function_exists('logWarning')) {
            logWarning('PdfOptimizer: gs returned non-zero', [
                'exit' => $exit,
                'output' => implode("\n", array_slice($output, 0, 10)),
            ]);
        }

        return $exit === 0 && file_exists($dest);
    }

    private static function runQpdf(string $source, string $dest): bool
    {
        if (!self::commandAvailable('qpdf')) {
            return false;
        }

        // --object-streams=generate: compress object streams (smaller output)
        // --compress-streams=y: ensure streams are compressed
        // --recompress-flate: re-encode flate streams at higher compression
        $cmd = sprintf(
            'qpdf --object-streams=generate --compress-streams=y --recompress-flate -- %s %s 2>&1',
            escapeshellarg($source),
            escapeshellarg($dest)
        );

        $output = [];
        $exit = 1;
        @exec($cmd, $output, $exit);

        // qpdf exit codes: 0 = success, 3 = warnings (output still usable),
        // anything else = real error
        $success = ($exit === 0 || $exit === 3);

        if (!$success && function_exists('logWarning')) {
            logWarning('PdfOptimizer: qpdf returned error', [
                'exit' => $exit,
                'output' => implode("\n", array_slice($output, 0, 10)),
            ]);
        }

        return $success && file_exists($dest);
    }

    private static array $commandCache = [];
    private static function commandAvailable(string $cmd): bool
    {
        if (!isset(self::$commandCache[$cmd])) {
            $output = [];
            $exit = 1;
            @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $output, $exit);
            self::$commandCache[$cmd] = ($exit === 0 && !empty($output));
        }
        return self::$commandCache[$cmd];
    }
}
