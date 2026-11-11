<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Images\SliEncoder;

/**
 * The PNG corpus of tests/fixtures/images: for each case the PNG (written by PngWriter) and the RGBA8 pixels it
 * holds, worked out here without the PNG reader. regenerate() writes the PNGs and their .sli files; see the
 * README.md in that folder.
 */
final class ImageFixtures
{
    public const DIR = __DIR__ . '/../fixtures/images';

    /** Cases with no .sli: the PNG is valid but phpbm refuses it. name => the error message */
    public const REJECTED = ['wide-40000' => 'size 40000x1 is out of 1..32767'];

    /** @return array<string, array{w: int, h: int, rows: list<string>, png: string}> name => case */
    public static function cases(): array
    {
        $cases = [];

        // 1x1, as RGB: red, 0xF800 whatever the dither.
        $cases['1x1-opaque'] = self::fromSamples(1, 1, PngWriter::RGB, 8, [[[255, 0, 0]]]);
        $cases['1x1-transparent'] = self::fromRgba(1, 1, [self::p(0, 0, 0, 0)]);

        // Every kind of span; the channels are 0 or 255 (or white at alpha 128) so the dither does not change
        // them, except row 7: gray 100, which the dither turns into two alternating colors.
        $T = self::p(0, 0, 0, 0);
        $S = self::p(255, 255, 255, 128);
        $R = self::p(255, 0, 0);
        $G = self::p(0, 255, 0);
        $B = self::p(0, 0, 255);
        $W = self::p(255, 255, 255);
        $K = self::p(0, 0, 0);
        $cases['8x8-spans'] = self::fromRgba(8, 8, [
            str_repeat($T, 8),
            str_repeat($R, 8),
            $R . $G . $B . $W . $K . $R . $G . $B,
            $T . $T . $S . $S . $R . $R . $R . $R,
            $R . $G . $G . $G . $G . $B . $T . $S,
            str_repeat($S, 8),
            $R . $R . $R . $G . $G . $G . $B . $B,
            str_repeat(self::p(100, 100, 100), 8),
        ], ['filter' => 'cycle']);

        // Runs of one color: a 2-byte length from 64 pixels.
        foreach ([63, 64, 70] as $n) {
            $cases["run-{$n}"] = self::fromRgba($n, 1, [str_repeat($R, $n)]);
        }

        // Rows that do not end on a 4-byte boundary, and odd offsets before the colors.
        $cases['odd-width'] = self::fromRgba(5, 3, [
            $R . $G . $B . $W . $K,
            $T . $S . $S . $R . $G,
            $S . $T . $R . $R . $R,
        ]);

        $cases['icon-48'] = self::fromRgba(48, 48, self::disk(48, 20.0, [30, 144, 255]), ['filter' => 4]);

        $rows = [];
        for ($y = 0; $y < 16; $y++) {
            $row = '';
            for ($x = 0; $x < 256; $x++) {
                $row .= self::p($x, $x, 255 - $x);
            }
            $rows[] = $row;
        }
        $cases['gradient-256x16'] = self::fromRgba(256, 16, $rows, ['filter' => 'cycle']);

        $cases['palette-1bit-trns'] = self::palette(9, 3, 1, [[0, 0, 0], [255, 200, 40]], [0]);
        $cases['palette-4bit-trns'] = self::palette(10, 4, 4, self::ramp(16), [0, 64, 128, 192, 255]);
        $cases['palette-8bit-trns'] = self::palette(20, 6, 8, self::ramp(200), array_map(fn ($i) => ($i * 37) & 255, range(0, 99)));

        $pixels = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $pixels[$y][$x] = [($x * 16 + $y * 5) & 255, [0, 255, 90, 200][$x & 3]];
            }
        }
        $cases['gray-alpha'] = self::fromSamples(16, 4, PngWriter::GRAY_ALPHA, 8, $pixels, ['filter' => 'cycle']);

        $pixels = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 12; $x++) {
                // Different low bytes: only the high byte counts.
                $pixels[$y][$x] = [$x * 5000 + 17, 65535 - $x * 4000, $y * 16000 + 255, [65535, 0, 32896, 1234][$x & 3]];
            }
        }
        $cases['rgba-16bit'] = self::fromSamples(12, 4, PngWriter::RGBA, 16, $pixels, ['filter' => 'cycle']);

        $rows = [];
        for ($y = 0; $y < 11; $y++) {
            $row = '';
            for ($x = 0; $x < 13; $x++) {
                $row .= self::p($x * 19, $y * 23, ($x * $y * 7) & 255, [255, 255, 0, 160][($x + $y) & 3]);
            }
            $rows[] = $row;
        }
        $cases['adam7'] = self::fromRgba(13, 11, $rows, ['interlace' => true, 'filter' => 'cycle', 'idat' => 50]);

        return $cases;
    }

    /** @return array<string, string> name => PNG of the cases in REJECTED */
    public static function rejected(): array
    {
        return ['wide-40000' => PngWriter::encode(40000, 1, PngWriter::GRAY, 1, [array_fill(0, 40000, [1])])];
    }

    /** Writes every case as <name>.png and <name>.sli, and the rejected PNGs, into $dir. */
    public static function regenerate(string $dir = self::DIR): void
    {
        foreach (self::cases() as $name => $case) {
            file_put_contents("{$dir}/{$name}.png", $case['png']);
            file_put_contents("{$dir}/{$name}.sli", SliEncoder::encode($case['w'], $case['h'], $case['rows']));
        }
        foreach (self::rejected() as $name => $png) {
            file_put_contents("{$dir}/{$name}.png", $png);
        }
    }

    /** One RGBA8 pixel. */
    public static function p(int $r, int $g, int $b, int $a = 255): string
    {
        return chr($r) . chr($g) . chr($b) . chr($a);
    }

    /**
     * @param list<string> $rows
     * @param array<string, mixed> $options
     * @return array{w: int, h: int, rows: list<string>, png: string}
     */
    private static function fromRgba(int $w, int $h, array $rows, array $options = []): array
    {
        return ['w' => $w, 'h' => $h, 'rows' => $rows, 'png' => PngWriter::rgba($w, $h, $rows, $options)];
    }

    /**
     * 8- or 16-bit samples of a gray, gray+alpha, RGB or RGBA image.
     *
     * @param list<list<list<int>>> $pixels
     * @param array<string, mixed> $options
     * @return array{w: int, h: int, rows: list<string>, png: string}
     */
    private static function fromSamples(int $w, int $h, int $type, int $depth, array $pixels, array $options = []): array
    {
        $rows = [];
        foreach ($pixels as $row) {
            $out = '';
            foreach ($row as $s) {
                $s = array_map(fn (int $v) => $depth === 16 ? $v >> 8 : $v, $s);
                $out .= match ($type) {
                    PngWriter::RGB => self::p($s[0], $s[1], $s[2]),
                    PngWriter::GRAY_ALPHA => self::p($s[0], $s[0], $s[0], $s[1]),
                    PngWriter::RGBA => self::p($s[0], $s[1], $s[2], $s[3]),
                };
            }
            $rows[] = $out;
        }
        return ['w' => $w, 'h' => $h, 'rows' => $rows, 'png' => PngWriter::encode($w, $h, $type, $depth, $pixels, $options)];
    }

    /**
     * @param list<array{int, int, int}> $palette
     * @param list<int> $trns
     * @return array{w: int, h: int, rows: list<string>, png: string}
     */
    private static function palette(int $w, int $h, int $depth, array $palette, array $trns): array
    {
        $pixels = [];
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $row = '';
            for ($x = 0; $x < $w; $x++) {
                $i = ($x * 7 + $y * 3) % count($palette);
                $pixels[$y][$x] = [$i];
                $row .= self::p(...$palette[$i], a: $trns[$i] ?? 255);
            }
            $rows[] = $row;
        }
        return ['w' => $w, 'h' => $h, 'rows' => $rows, 'png' => PngWriter::encode($w, $h, PngWriter::PALETTE, $depth, $pixels, [
            'palette' => $palette,
            'trns' => $trns,
            'filter' => 'cycle',
        ])];
    }

    /** @return list<array{int, int, int}> */
    private static function ramp(int $n): array
    {
        return array_map(fn ($i) => [($i * 251) % 256, ($i * 13) % 256, 255 - intdiv($i * 255, max(1, $n - 1))],range(0, $n - 1));
    }

    /**
     * A disk of one color centered in a $size square, with antialiased edges (4x4 samples per pixel).
     *
     * @param array{int, int, int} $rgb
     * @return list<string>
     */
    private static function disk(int $size, float $radius, array $rgb): array
    {
        $rows = [];
        $c = $size / 2;
        for ($y = 0; $y < $size; $y++) {
            $row = '';
            for ($x = 0; $x < $size; $x++) {
                $in = 0;
                for ($sy = 0; $sy < 4; $sy++) {
                    for ($sx = 0; $sx < 4; $sx++) {
                        $dx = $x + ($sx + 0.5) / 4 - $c;
                        $dy = $y + ($sy + 0.5) / 4 - $c;
                        $in += $dx * $dx + $dy * $dy <= $radius * $radius ? 1 : 0;
                    }
                }
                $row .= self::p(...$rgb, a: intdiv($in * 255 + 8, 16));
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
