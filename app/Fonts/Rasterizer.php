<?php

declare(strict_types=1);

namespace App\Fonts;

/** Scanline coverage with the non-zero rule: one sample per pixel at 1 bpp, 4x4 above. */
final class Rasterizer
{
    public static function render(Outline $outline, float $scale, int $bpp): Glyph
    {
        $edges = [];
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($outline->flatten($scale) as $polygon) {
            $count = count($polygon);
            for ($i = 0; $i < $count; $i++) {
                [$x0, $y0] = $polygon[$i];
                [$x1, $y1] = $polygon[($i + 1) % $count];
                $minX = min($minX, $x0);
                $maxX = max($maxX, $x0);
                $minY = min($minY, $y0);
                $maxY = max($maxY, $y0);
                if ($y0 !== $y1) {
                    $edges[] = $y0 < $y1 ? [$x0, $y0, $x1, $y1, 1] : [$x1, $y1, $x0, $y0, -1];
                }
            }
        }
        if ($edges === []) {
            return new Glyph(0, 0, 0, 0, 0, 0, []);
        }

        $left = (int) floor($minX);
        $top = (int) floor($minY);
        $width = (int) ceil($maxX) - $left;
        $height = (int) ceil($maxY) - $top;
        $n = $bpp > 1 ? 4 : 1;
        $counts = array_fill(0, $width * $height, 0);

        for ($row = 0; $row < $height * $n; $row++) {
            $y = $top + ($row + 0.5) / $n;
            $crossings = [];
            foreach ($edges as [$x0, $y0, $x1, $y1, $dir]) {
                if ($y >= $y0 && $y < $y1) {
                    $crossings[] = [$x0 + ($y - $y0) * ($x1 - $x0) / ($y1 - $y0), $dir];
                }
            }
            usort($crossings, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            $base = intdiv($row, $n) * $width;
            $winding = 0;
            foreach ($crossings as $i => [$x, $dir]) {
                $winding += $dir;
                if ($winding === 0 || !isset($crossings[$i + 1])) {
                    continue;
                }
                $from = max(0, (int) ceil(($x - $left) * $n - 0.5));
                $to = min($width * $n, (int) ceil(($crossings[$i + 1][0] - $left) * $n - 0.5));
                for ($s = $from; $s < $to; $s++) {
                    $counts[$base + intdiv($s, $n)]++;
                }
            }
        }

        $full = $n * $n;
        $coverage = array_map(fn (int $c) => intdiv($c * 255 + intdiv($full, 2), $full), $counts);
        return new Glyph(0, $width, $height, 0, $left, $top, $coverage);
    }
}
