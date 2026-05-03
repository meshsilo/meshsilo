<?php

/**
 * Minimal QR Code generator — pure PHP, no dependencies.
 *
 * Supports byte-mode encoding up to Version 10 (57×57 modules).
 * Error correction level M (~15% recovery).
 * Outputs SVG markup or a data:image/svg+xml URI.
 */
class QRCode
{
    // Error correction level M (15% recovery) is hardcoded in the encode logic

    // Version capacities for EC level M, byte mode
    private const VERSION_CAPACITY = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
    ];

    // EC codewords per block for EC level M
    private const EC_CODEWORDS = [
        1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24,
        6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26,
    ];

    // Number of EC blocks for EC level M
    private const EC_BLOCKS = [
        1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2,
        6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 4,
    ];

    // Total codewords per version
    private const TOTAL_CODEWORDS = [
        1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
        6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
    ];

    // Alignment pattern positions per version
    private const ALIGNMENT = [
        1 => [], 2 => [6,18], 3 => [6,22], 4 => [6,26], 5 => [6,30],
        6 => [6,34], 7 => [6,22,38], 8 => [6,24,42], 9 => [6,26,46], 10 => [6,28,50],
    ];

    // Format info bits for mask 0-7 with EC level M
    private const FORMAT_BITS = [
        0 => 0x5412, 1 => 0x5125, 2 => 0x5E7C, 3 => 0x5B4B,
        4 => 0x45F9, 5 => 0x40CE, 6 => 0x4F97, 7 => 0x4AA0,
    ];

    // Version info bits (versions 7+)
    private const VERSION_BITS = [
        7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3,
    ];

    /**
     * Generate QR code as SVG string.
     */
    public static function toSVG(string $data, int $moduleSize = 4, int $margin = 4): string
    {
        $matrix = self::encode($data);
        $size = count($matrix);
        $imgSize = ($size + $margin * 2) * $moduleSize;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $imgSize . ' ' . $imgSize . '" width="' . $imgSize . '" height="' . $imgSize . '">';
        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';
        $svg .= '<path d="';

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x] & 1) {
                    $px = ($x + $margin) * $moduleSize;
                    $py = ($y + $margin) * $moduleSize;
                    $svg .= 'M' . $px . ',' . $py . 'h' . $moduleSize . 'v' . $moduleSize . 'h-' . $moduleSize . 'z';
                }
            }
        }

        $svg .= '" fill="#000000"/></svg>';
        return $svg;
    }

    /**
     * Generate QR code as data URI (for use in <img src="...">).
     */
    public static function toDataURI(string $data, int $moduleSize = 4, int $margin = 4): string
    {
        $svg = self::toSVG($data, $moduleSize, $margin);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Encode data into a QR code matrix.
     * Returns 2D array where 1 = dark module, 0 = light module.
     */
    public static function encode(string $data): array
    {
        $version = self::selectVersion($data);
        $size = 17 + $version * 4;

        // Encode data into codewords
        $dataCodewords = self::encodeData($data, $version);

        // Generate error correction
        $ecCodewords = self::generateEC($dataCodewords, $version);

        // Interleave data and EC codewords
        $finalBits = self::interleave($dataCodewords, $ecCodewords, $version);

        // Create matrix and place patterns
        $matrix = array_fill(0, $size, array_fill(0, $size, -1));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinderPatterns($matrix, $reserved, $size);
        self::placeAlignmentPatterns($matrix, $reserved, $version, $size);
        self::placeTimingPatterns($matrix, $reserved, $size);
        self::placeDarkModule($matrix, $reserved, $version);
        self::reserveFormatArea($reserved, $size);
        if ($version >= 7) {
            self::reserveVersionArea($reserved, $size);
        }

        // Place data bits
        self::placeDataBits($matrix, $reserved, $finalBits, $size);

        // Apply best mask
        $bestMask = self::selectBestMask($matrix, $reserved, $size);
        self::applyMask($matrix, $reserved, $bestMask, $size);

        // Place format info
        self::placeFormatInfo($matrix, $bestMask, $size);
        if ($version >= 7) {
            self::placeVersionInfo($matrix, $version, $size);
        }

        return $matrix;
    }

    private static function selectVersion(string $data): int
    {
        $len = strlen($data);
        foreach (self::VERSION_CAPACITY as $v => $cap) {
            if ($len <= $cap) {
                return $v;
            }
        }
        throw new \RuntimeException('Data too long for QR code (max ' . self::VERSION_CAPACITY[10] . ' bytes)');
    }

    private static function encodeData(string $data, int $version): array
    {
        $totalDataCodewords = self::TOTAL_CODEWORDS[$version] -
            (self::EC_CODEWORDS[$version] * self::EC_BLOCKS[$version]);

        // Byte mode indicator (0100) + character count
        $bits = '0100';
        $countBits = $version <= 9 ? 8 : 16;
        $bits .= str_pad(decbin(strlen($data)), $countBits, '0', STR_PAD_LEFT);

        // Data
        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Terminator (up to 4 bits of 0)
        $bits .= str_repeat('0', min(4, $totalDataCodewords * 8 - strlen($bits)));

        // Pad to byte boundary
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        // Convert to bytes
        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        // Pad with alternating 0xEC, 0x11
        $padBytes = [0xEC, 0x11];
        $padIdx = 0;
        while (count($codewords) < $totalDataCodewords) {
            $codewords[] = $padBytes[$padIdx % 2];
            $padIdx++;
        }

        return $codewords;
    }

    private static function generateEC(array $dataCodewords, int $version): array
    {
        $ecPerBlock = self::EC_CODEWORDS[$version];
        $numBlocks = self::EC_BLOCKS[$version];
        $totalData = count($dataCodewords);
        $dataPerBlock = intdiv($totalData, $numBlocks);
        $extraBlocks = $totalData % $numBlocks;

        $generator = self::rsGeneratorPoly($ecPerBlock);
        $ecBlocks = [];
        $offset = 0;

        for ($b = 0; $b < $numBlocks; $b++) {
            $blockSize = $dataPerBlock + ($b >= $numBlocks - $extraBlocks ? 1 : 0);
            $block = array_slice($dataCodewords, $offset, $blockSize);
            $offset += $blockSize;
            $ecBlocks[] = self::rsEncode($block, $generator, $ecPerBlock);
        }

        return $ecBlocks;
    }

    private static function interleave(array $dataCodewords, array $ecBlocks, int $version): string
    {
        $numBlocks = self::EC_BLOCKS[$version];
        $totalData = count($dataCodewords);
        $dataPerBlock = intdiv($totalData, $numBlocks);
        $extraBlocks = $totalData % $numBlocks;

        // Split data into blocks
        $dataBlocks = [];
        $offset = 0;
        for ($b = 0; $b < $numBlocks; $b++) {
            $blockSize = $dataPerBlock + ($b >= $numBlocks - $extraBlocks ? 1 : 0);
            $dataBlocks[] = array_slice($dataCodewords, $offset, $blockSize);
            $offset += $blockSize;
        }

        // Interleave data
        $result = [];
        $maxDataLen = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }

        // Interleave EC
        $ecPerBlock = self::EC_CODEWORDS[$version];
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }

        // Convert to bit string
        $bits = '';
        foreach ($result as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        // Remainder bits
        $remainderBits = [0,0,7,7,7,7,7,0,0,0]; // versions 1-10
        if (isset($remainderBits[$version - 1])) {
            $bits .= str_repeat('0', $remainderBits[$version - 1]);
        }

        return $bits;
    }

    // ===== Reed-Solomon =====

    private static $gfExp = [];
    private static $gfLog = [];
    private static $gfInitialized = false;

    private static function initGF(): void
    {
        if (self::$gfInitialized) return;
        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x >= 256) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
        self::$gfInitialized = true;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        self::initGF();
        return self::$gfExp[self::$gfLog[$a] + self::$gfLog[$b]];
    }

    private static function rsGeneratorPoly(int $degree): array
    {
        self::initGF();
        $gen = [1];
        for ($i = 0; $i < $degree; $i++) {
            $newGen = array_fill(0, count($gen) + 1, 0);
            $factor = self::$gfExp[$i];
            for ($j = 0; $j < count($gen); $j++) {
                $newGen[$j] ^= $gen[$j];
                $newGen[$j + 1] ^= self::gfMul($gen[$j], $factor);
            }
            $gen = $newGen;
        }
        return $gen;
    }

    private static function rsEncode(array $data, array $generator, int $ecLen): array
    {
        self::initGF();
        $result = array_merge($data, array_fill(0, $ecLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $coef = $result[$i];
            if ($coef !== 0) {
                for ($j = 0; $j < count($generator); $j++) {
                    $result[$i + $j] ^= self::gfMul($generator[$j], $coef);
                }
            }
        }
        return array_slice($result, count($data));
    }

    // ===== Matrix placement =====

    private static function placeFinderPatterns(array &$m, array &$r, int $s): void
    {
        $positions = [[0, 0], [0, $s - 7], [$s - 7, 0]];
        foreach ($positions as [$oy, $ox]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $y = $oy + $dy;
                    $x = $ox + $dx;
                    if ($y < 0 || $y >= $s || $x < 0 || $x >= $s) continue;
                    if ($dy === -1 || $dy === 7 || $dx === -1 || $dx === 7) {
                        $m[$y][$x] = 0;
                    } elseif ($dy === 0 || $dy === 6 || $dx === 0 || $dx === 6) {
                        $m[$y][$x] = 1;
                    } elseif ($dy >= 2 && $dy <= 4 && $dx >= 2 && $dx <= 4) {
                        $m[$y][$x] = 1;
                    } else {
                        $m[$y][$x] = 0;
                    }
                    $r[$y][$x] = true;
                }
            }
        }
    }

    private static function placeAlignmentPatterns(array &$m, array &$r, int $v, int $s): void
    {
        $positions = self::ALIGNMENT[$v] ?? [];
        if (empty($positions)) return;
        $combos = [];
        foreach ($positions as $a) {
            foreach ($positions as $b) {
                $combos[] = [$a, $b];
            }
        }
        foreach ($combos as [$cy, $cx]) {
            // Skip if overlapping finder pattern
            if ($r[$cy][$cx]) continue;
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $y = $cy + $dy;
                    $x = $cx + $dx;
                    if ($y < 0 || $y >= $s || $x < 0 || $x >= $s) continue; /** @phpstan-ignore-line */
                    if (abs($dy) === 2 || abs($dx) === 2 || ($dy === 0 && $dx === 0)) {
                        $m[$y][$x] = 1;
                    } else {
                        $m[$y][$x] = 0;
                    }
                    $r[$y][$x] = true;
                }
            }
        }
    }

    private static function placeTimingPatterns(array &$m, array &$r, int $s): void
    {
        for ($i = 8; $i < $s - 8; $i++) {
            if (!$r[6][$i]) {
                $m[6][$i] = ($i % 2 === 0) ? 1 : 0;
                $r[6][$i] = true;
            }
            if (!$r[$i][6]) {
                $m[$i][6] = ($i % 2 === 0) ? 1 : 0;
                $r[$i][6] = true;
            }
        }
    }

    private static function placeDarkModule(array &$m, array &$r, int $v): void
    {
        $y = 4 * $v + 9;
        $m[$y][8] = 1;
        $r[$y][8] = true;
    }

    private static function reserveFormatArea(array &$r, int $s): void
    {
        for ($i = 0; $i <= 8; $i++) {
            $r[8][$i] = true;
            $r[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $r[8][$s - 1 - $i] = true;
            $r[$s - 1 - $i][8] = true;
        }
    }

    private static function reserveVersionArea(array &$r, int $s): void
    {
        for ($i = 0; $i < 6; $i++) {
            for ($j = $s - 11; $j < $s - 8; $j++) {
                $r[$i][$j] = true;
                $r[$j][$i] = true;
            }
        }
    }

    private static function placeDataBits(array &$m, array &$r, string $bits, int $s): void
    {
        $bitIdx = 0;
        $upward = true;
        for ($col = $s - 1; $col >= 1; $col -= 2) {
            if ($col === 6) $col = 5; // skip timing column
            $rows = $upward ? range($s - 1, 0, -1) : range(0, $s - 1);
            foreach ($rows as $row) {
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    if ($x < 0 || $r[$row][$x]) continue; /** @phpstan-ignore-line */
                    $m[$row][$x] = ($bitIdx < strlen($bits) && $bits[$bitIdx] === '1') ? 1 : 0;
                    $bitIdx++;
                }
            }
            $upward = !$upward;
        }
    }

    private static function selectBestMask(array $matrix, array $reserved, int $s): int
    {
        $bestScore = PHP_INT_MAX;
        $bestMask = 0;
        for ($mask = 0; $mask < 8; $mask++) {
            $test = $matrix;
            self::applyMask($test, $reserved, $mask, $s);
            $score = self::evaluatePenalty($test, $s);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
            }
        }
        return $bestMask;
    }

    private static function applyMask(array &$m, array $r, int $mask, int $s): void
    {
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if ($r[$y][$x]) continue;
                $flip = match ($mask) {
                    0 => ($y + $x) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($y + $x) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($y * $x) % 2 + ($y * $x) % 3 === 0,
                    6 => (($y * $x) % 2 + ($y * $x) % 3) % 2 === 0,
                    7 => (($y + $x) % 2 + ($y * $x) % 3) % 2 === 0,
                    default => false,
                };
                if ($flip) {
                    $m[$y][$x] ^= 1;
                }
            }
        }
    }

    private static function evaluatePenalty(array $m, int $s): int
    {
        $penalty = 0;
        // Rule 1: consecutive same-color modules in row/col
        for ($y = 0; $y < $s; $y++) {
            $count = 1;
            for ($x = 1; $x < $s; $x++) {
                if (($m[$y][$x] & 1) === ($m[$y][$x - 1] & 1)) {
                    $count++;
                    if ($count === 5) $penalty += 3;
                    elseif ($count > 5) $penalty++;
                } else {
                    $count = 1;
                }
            }
        }
        for ($x = 0; $x < $s; $x++) {
            $count = 1;
            for ($y = 1; $y < $s; $y++) {
                if (($m[$y][$x] & 1) === ($m[$y - 1][$x] & 1)) {
                    $count++;
                    if ($count === 5) $penalty += 3;
                    elseif ($count > 5) $penalty++;
                } else {
                    $count = 1;
                }
            }
        }
        return $penalty;
    }

    private static function placeFormatInfo(array &$m, int $mask, int $s): void
    {
        $bits = self::FORMAT_BITS[$mask];
        // Around top-left finder
        $positions1 = [[0,8],[1,8],[2,8],[3,8],[4,8],[5,8],[7,8],[8,8],[8,7],[8,5],[8,4],[8,3],[8,2],[8,1],[8,0]];
        // Around other finders
        $positions2 = [[$s-1,8],[$s-2,8],[$s-3,8],[$s-4,8],[$s-5,8],[$s-6,8],[$s-7,8],[8,$s-8],[8,$s-7],[8,$s-6],[8,$s-5],[8,$s-4],[8,$s-3],[8,$s-2],[8,$s-1]];

        for ($i = 0; $i < 15; $i++) {
            $bit = ($bits >> (14 - $i)) & 1;
            [$y1, $x1] = $positions1[$i];
            [$y2, $x2] = $positions2[$i];
            $m[$y1][$x1] = $bit;
            $m[$y2][$x2] = $bit;
        }
    }

    private static function placeVersionInfo(array &$m, int $v, int $s): void
    {
        if ($v < 7) return;
        $bits = self::VERSION_BITS[$v] ?? 0;
        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $row = intdiv($i, 3);
            $col = $s - 11 + ($i % 3);
            $m[$row][$col] = $bit;
            $m[$col][$row] = $bit;
        }
    }
}
