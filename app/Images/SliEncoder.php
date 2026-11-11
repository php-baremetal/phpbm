<?php

declare(strict_types=1);

namespace App\Images;

/**
 * Turns RGBA8 pixels into a .sli file, Slate's span image format. The board's PNG decoder follows the same rule,
 * so both give the same bytes from the same pixels.
 *
 * File (little-endian): a 16-byte header ("SLI1", version 1, flags: bit 0 has_alpha, bit 1 opaque; width,
 * height, 0, data size), a table of one u32 per row (the row's offset from the start of the data), then the data.
 * Each row starts at an offset that is a multiple of 4 (zero bytes between rows) and is a list of commands that
 * cover the width exactly. Command byte: bits 7..6 the kind (0 empty, 1 one repeated opaque color, 2 different
 * opaque colors, 3 semi-transparent), bits 5..0 the count 1..63, or 0 followed by a u16 count 64..32767. Then:
 * empty, nothing; repeated, the color; different, a zero byte when needed to put the colors at an even offset
 * (from the start of the data), then the colors; semi-transparent, the same padding, the premultiplied colors,
 * then one alpha byte per pixel. Colors are RGB565 (red in bits 15..11).
 *
 * Pixel (x, y): alpha 0 is empty; alpha 255 is the color pm_opaque(r, g, b, th); any other alpha a is
 * semi-transparent with color pm_opaque(div255(r * a), div255(g * a), div255(b * a), th) and alpha a, where th is
 * bayer4[y & 3][x & 3] (Slate's ordered dither, anchored at the image origin). Rows are cut into maximal spans of
 * one kind (empty, opaque, semi-transparent) of at most 32767 pixels; an opaque span becomes "repeated" commands
 * for every run of at least 4 pixels of one RGB565 color and "different" commands for the pixels in between.
 */
final class SliEncoder
{
    public const MAX_SIZE = 32767;

    public const FLAG_HAS_ALPHA = 1;
    public const FLAG_OPAQUE = 2;

    private const EMPTY = 0;
    private const REPEAT = 1;
    private const DIFFERENT = 2;
    private const SEMI = 3;

    /** Shortest run of one color written as a "repeated" command. */
    private const MIN_REPEAT = 4;

    /** The 4x4 ordered dither thresholds of Slate's software renderer (gfx_sw.c). */
    private const BAYER4 = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];

    /**
     * @param list<string> $rows $h rows of $w * 4 bytes, RGBA8, not premultiplied
     * @throws ImageException
     */
    public static function encode(int $w, int $h, array $rows): string
    {
        if ($w < 1 || $h < 1 || $w > self::MAX_SIZE || $h > self::MAX_SIZE) {
            throw new ImageException(sprintf('size %dx%d is out of 1..%d', $w, $h, self::MAX_SIZE));
        }
        if (count($rows) !== $h) {
            throw new ImageException(sprintf('%d rows expected, %d given', $h, count($rows)));
        }

        $data = '';
        $table = '';
        $opaque = true;
        foreach (array_values($rows) as $y => $row) {
            if (strlen($row) !== $w * 4) {
                throw new ImageException(sprintf('row %d has %d bytes, %d expected', $y, strlen($row), $w * 4));
            }
            if ($opaque && !self::allOpaque($row)) {
                $opaque = false;
            }
            if (strlen($data) % 4 !== 0) {
                $data .= str_repeat("\0", 4 - strlen($data) % 4);
            }
            $table .= pack('V', strlen($data));
            $data .= self::encodeRow($row, $y);
        }

        $flags = $opaque ? self::FLAG_OPAQUE : self::FLAG_HAS_ALPHA;
        return 'SLI1' . pack('CCvvvV', 1, $flags, $w, $h, 0, strlen($data)) . $table . $data;
    }

    /**
     * The commands of row $y, as they are written at an offset that is a multiple of 4. The row may be of any
     * length (spans are cut at 32767 pixels).
     */
    public static function encodeRow(string $row, int $y): string
    {
        $bay = self::BAYER4[$y & 3];
        $n = intdiv(strlen($row), 4);
        $kinds = [];
        $colors = [];
        $alphas = [];
        foreach (array_chunk(array_values(unpack('C*', $row)), 4) as $x => [$r, $g, $b, $a]) {
            $th = $bay[$x & 3];
            if ($a === 0) {
                $kinds[] = self::EMPTY;
                $colors[] = 0;
            } elseif ($a === 255) {
                $kinds[] = self::REPEAT;
                $colors[] = self::pmOpaque($r, $g, $b, $th);
            } else {
                $kinds[] = self::SEMI;
                $colors[] = self::pmOpaque(self::div255($r * $a), self::div255($g * $a), self::div255($b * $a), $th);
            }
            $alphas[] = $a;
        }

        $out = '';
        for ($s = 0; $s < $n;) {
            $e = $s;
            while ($e < $n && $e - $s < self::MAX_SIZE && $kinds[$e] === $kinds[$s]) {
                $e++;
            }
            switch ($kinds[$s]) {
                case self::EMPTY:
                    $out .= self::command(self::EMPTY, $e - $s);
                    break;
                case self::SEMI:
                    $out .= self::command(self::SEMI, $e - $s);
                    $out .= self::align2($out) . self::colors($colors, $s, $e);
                    for ($i = $s; $i < $e; $i++) {
                        $out .= chr($alphas[$i]);
                    }
                    break;
                default:
                    $out = self::opaqueSpan($out, $colors, $s, $e);
            }
            $s = $e;
        }
        return $out;
    }

    /** $out followed by the commands of the opaque pixels $s..$e - 1. */
    private static function opaqueSpan(string $out, array $colors, int $s, int $e): string
    {
        $pending = $s;   // first pixel not written yet
        for ($i = $s; $i < $e;) {
            $j = $i + 1;
            while ($j < $e && $colors[$j] === $colors[$i]) {
                $j++;
            }
            if ($j - $i >= self::MIN_REPEAT) {
                $out = self::different($out, $colors, $pending, $i);
                $out .= self::command(self::REPEAT, $j - $i) . pack('v', $colors[$i]);
                $pending = $j;
            }
            $i = $j;
        }
        return self::different($out, $colors, $pending, $e);
    }

    /** $out followed by a "different" command for pixels $s..$e - 1, if any. */
    private static function different(string $out, array $colors, int $s, int $e): string
    {
        if ($s === $e) {
            return $out;
        }
        $out .= self::command(self::DIFFERENT, $e - $s);
        return $out . self::align2($out) . self::colors($colors, $s, $e);
    }

    private static function command(int $kind, int $n): string
    {
        return $n <= 63 ? chr($kind << 6 | $n) : chr($kind << 6) . pack('v', $n);
    }

    /** The padding byte that puts what follows $out at an even offset. */
    private static function align2(string $out): string
    {
        return strlen($out) % 2 === 1 ? "\0" : '';
    }

    /** @param list<int> $colors */
    private static function colors(array $colors, int $s, int $e): string
    {
        return pack('v*', ...array_slice($colors, $s, $e - $s));
    }

    private static function allOpaque(string $row): bool
    {
        for ($i = 3, $n = strlen($row); $i < $n; $i += 4) {
            if ($row[$i] !== "\xFF") {
                return false;
            }
        }
        return true;
    }

    /** x / 255 rounded to nearest, for 0 <= x <= 65535 (slate_gfx.h). */
    private static function div255(int $v): int
    {
        return ($v + 128 + (($v + 128) >> 8)) >> 8;
    }

    /** An opaque premultiplied color reduced to RGB565 with dither threshold $th (0..15), as pm_opaque in gfx_sw.c. */
    private static function pmOpaque(int $r, int $g, int $b, int $th): int
    {
        $r5 = min(($r + ($th >> 1)) >> 3, 31);
        $g6 = min(($g + ($th >> 2)) >> 2, 63);
        $b5 = min(($b + ($th >> 1)) >> 3, 31);
        return ($r5 << 11) | ($g6 << 5) | $b5;
    }
}
