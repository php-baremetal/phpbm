<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Writes PNG files in pure PHP, to build the test images of the PNG reader and the .sli encoder: every color type
 * and bit depth, palettes with tRNS, a chosen filter per row, Adam7 and IDAT split into several chunks. Only
 * zlib is used. It writes what it is told: it does not check that the samples fit the bit depth.
 */
final class PngWriter
{
    public const GRAY = 0;
    public const RGB = 2;
    public const PALETTE = 3;
    public const GRAY_ALPHA = 4;
    public const RGBA = 6;

    private const CHANNELS = [self::GRAY => 1, self::RGB => 3, self::PALETTE => 1, self::GRAY_ALPHA => 2, self::RGBA => 4];

    /** Adam7 passes: x start, y start, x step, y step. */
    private const ADAM7 = [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]];

    /**
     * @param list<list<list<int>>> $pixels $h rows of $w pixels, each pixel a list of samples at $depth (for a
     *                                      palette image, one sample: the index)
     * @param array{
     *     palette?: list<array{int, int, int}>,
     *     trns?: list<int>,
     *     filter?: int|'cycle',
     *     interlace?: bool,
     *     idat?: int,
     *     ancillary?: list<array{string, string}>,
     *     zdata?: string,
     * } $options trns: the alphas of the palette entries, or the gray sample, or the red, green and blue samples;
     *            filter: the filter of every row (0..4), or 'cycle' for row y of each pass with filter y % 5;
     *            idat: the most bytes of one IDAT chunk; ancillary: chunks (type, data) written before IDAT;
     *            zdata: the compressed image data to write instead of $pixels (for broken or huge images)
     */
    public static function encode(int $w, int $h, int $type, int $depth, array $pixels, array $options = []): string
    {
        $channels = self::CHANNELS[$type];
        $filter = $options['filter'] ?? 0;
        $interlace = $options['interlace'] ?? false;

        $raw = '';
        $passes = isset($options['zdata']) ? [] : null;
        $passes ??= $interlace ? self::ADAM7 : [[0, 0, 1, 1]];
        foreach ($passes as [$x0, $y0, $dx, $dy]) {
            $pw = $w > $x0 ? intdiv($w - $x0 + $dx - 1, $dx) : 0;
            $ph = $h > $y0 ? intdiv($h - $y0 + $dy - 1, $dy) : 0;
            if ($pw === 0 || $ph === 0) {
                continue;
            }
            $prev = str_repeat("\0", intdiv($pw * $channels * $depth + 7, 8));
            for ($i = 0; $i < $ph; $i++) {
                $row = [];
                for ($j = 0; $j < $pw; $j++) {
                    $row[] = $pixels[$y0 + $i * $dy][$x0 + $j * $dx];
                }
                $line = self::pack($row, $depth);
                $f = $filter === 'cycle' ? $i % 5 : $filter;
                $raw .= chr($f) . self::filter($f, $line, $prev, max(1, intdiv($channels * $depth, 8)));
                $prev = $line;
            }
        }

        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNCCCCC', $w, $h, $depth, $type, 0, 0, $interlace ? 1 : 0));
        if (isset($options['palette'])) {
            $plte = '';
            foreach ($options['palette'] as [$r, $g, $b]) {
                $plte .= chr($r) . chr($g) . chr($b);
            }
            $png .= self::chunk('PLTE', $plte);
        }
        if (isset($options['trns'])) {
            $png .= self::chunk('tRNS', $type === self::PALETTE
                ? implode('', array_map(chr(...), $options['trns']))
                : pack('n*', ...$options['trns']));
        }
        foreach ($options['ancillary'] ?? [] as [$chunkType, $data]) {
            $png .= self::chunk($chunkType, $data);
        }
        $z = $options['zdata'] ?? (string) gzcompress($raw, 9);
        foreach (str_split($z, max(1, $options['idat'] ?? strlen($z))) as $part) {
            $png .= self::chunk('IDAT', $part);
        }
        return $png . self::chunk('IEND', '');
    }

    /**
     * An 8-bit RGBA PNG.
     *
     * @param list<string> $rows $h rows of $w * 4 bytes, RGBA
     * @param array<string, mixed> $options as for encode()
     */
    public static function rgba(int $w, int $h, array $rows, array $options = []): string
    {
        $pixels = [];
        foreach ($rows as $row) {
            $pixels[] = array_map(fn (string $p) => array_values(unpack('C4', $p)), str_split($row, 4));
        }
        return self::encode($w, $h, self::RGBA, 8, $pixels, $options);
    }

    public static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** @param list<list<int>> $row */
    private static function pack(array $row, int $depth): string
    {
        $samples = array_merge(...$row);
        if ($depth === 8) {
            return implode('', array_map(chr(...), $samples));
        }
        if ($depth === 16) {
            return pack('n*', ...$samples);
        }
        $out = '';
        $byte = 0;
        $bits = 0;
        foreach ($samples as $s) {
            $byte = ($byte << $depth) | $s;
            $bits += $depth;
            if ($bits === 8) {
                $out .= chr($byte);
                $byte = $bits = 0;
            }
        }
        return $bits > 0 ? $out . chr($byte << (8 - $bits)) : $out;
    }

    private static function filter(int $type, string $line, string $prev, int $bpp): string
    {
        $out = '';
        $n = strlen($line);
        for ($i = 0; $i < $n; $i++) {
            $x = ord($line[$i]);
            $a = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
            $out .= chr(($x - match ($type) {
                0 => 0,
                1 => $a,
                2 => $b,
                3 => intdiv($a + $b, 2),
                4 => self::paeth($a, $b, $c),
            }) & 0xFF);
        }
        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }
}
