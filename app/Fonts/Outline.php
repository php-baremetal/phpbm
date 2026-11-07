<?php

declare(strict_types=1);

namespace App\Fonts;

/** TrueType contours in font units (y up), with on- and off-curve (quadratic control) points. */
final class Outline
{
    /** @param list<list<array{x:int|float, y:int|float, on:bool}>> $contours */
    private function __construct(public readonly array $contours)
    {
    }

    /** @param list<list<array{x:int|float, y:int|float, on:bool}>> $contours */
    public static function fromContours(array $contours): self
    {
        return new self(array_values(array_filter($contours, fn (array $c) => $c !== [])));
    }

    public function isEmpty(): bool
    {
        return $this->contours === [];
    }

    /**
     * Closed polygons in pixel coordinates (y down), quadratics split so no point strays more than 1/8 px.
     *
     * @return list<list<array{0:float, 1:float}>>
     */
    public function flatten(float $scale): array
    {
        $polygons = [];
        foreach ($this->contours as $contour) {
            $points = array_map(fn (array $p) => [$p['x'] * $scale, -$p['y'] * $scale, $p['on']], $contour);
            $polygons[] = self::flattenContour($points);
        }
        return $polygons;
    }

    /**
     * @param  list<array{0:float, 1:float, 2:bool}> $points
     * @return list<array{0:float, 1:float}>
     */
    private static function flattenContour(array $points): array
    {
        $n = count($points);
        $start = 0;
        while ($start < $n && !$points[$start][2]) {
            $start++;
        }
        if ($start === $n) {
            $first = self::mid($points[$n - 1], $points[0]);
            $start = 0;
            $ordered = $points;
        } else {
            $first = [$points[$start][0], $points[$start][1]];
            $ordered = [...array_slice($points, $start + 1), ...array_slice($points, 0, $start)];
        }

        $polygon = [$first];
        $current = $first;
        $control = null;
        foreach ([...$ordered, [$first[0], $first[1], true]] as $point) {
            if ($point[2]) {
                $end = [$point[0], $point[1]];
                if ($control === null) {
                    $polygon[] = $end;
                } else {
                    self::quadratic($polygon, $current, $control, $end);
                }
                $current = $end;
                $control = null;
            } elseif ($control === null) {
                $control = [$point[0], $point[1]];
            } else {
                $end = self::mid($control, $point);
                self::quadratic($polygon, $current, $control, $end);
                $current = $end;
                $control = [$point[0], $point[1]];
            }
        }
        array_pop($polygon);
        return $polygon;
    }

    /**
     * @param list<array{0:float, 1:float}> $polygon
     * @param array{0:float, 1:float}       $p0
     * @param array{0:float, 1:float}       $p1
     * @param array{0:float, 1:float}       $p2
     */
    private static function quadratic(array &$polygon, array $p0, array $p1, array $p2): void
    {
        $deviation = hypot($p0[0] - 2 * $p1[0] + $p2[0], $p0[1] - 2 * $p1[1] + $p2[1]);
        $segments = max(1, (int) ceil(sqrt(2 * $deviation)) + 1);
        for ($i = 1; $i <= $segments; $i++) {
            $t = $i / $segments;
            $u = 1 - $t;
            $polygon[] = [
                $u * $u * $p0[0] + 2 * $u * $t * $p1[0] + $t * $t * $p2[0],
                $u * $u * $p0[1] + 2 * $u * $t * $p1[1] + $t * $t * $p2[1],
            ];
        }
    }

    /** @return array{0:float, 1:float} */
    private static function mid(array $a, array $b): array
    {
        return [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
    }
}
