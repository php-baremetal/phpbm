<?php

declare(strict_types=1);

namespace App\Fonts;

/** One glyph: coverage is width*height values 0..255, rows top to bottom; offsets are from the cursor on the baseline. */
final class Glyph
{
    /** @param list<int> $coverage */
    public function __construct(
        public readonly int $codepoint,
        public readonly int $width,
        public readonly int $height,
        public readonly int $xAdvance,
        public readonly int $xOffset,
        public readonly int $yOffset,
        public readonly array $coverage,
    ) {
    }

    public static function level(int $coverage, int $bpp): int
    {
        return (int) round($coverage * ((1 << $bpp) - 1) / 255);
    }

    public function with(int $codepoint, int $xAdvance): self
    {
        return new self($codepoint, $this->width, $this->height, $xAdvance, $this->xOffset, $this->yOffset, $this->coverage);
    }

    /** Crops to the pixels that stay lit at this bpp; a glyph with none becomes 0x0. */
    public function cropped(int $bpp): self
    {
        $top = $left = PHP_INT_MAX;
        $bottom = $right = -1;
        foreach ($this->coverage as $i => $c) {
            if (self::level($c, $bpp) > 0) {
                $y = intdiv($i, $this->width);
                $x = $i % $this->width;
                $top = min($top, $y);
                $bottom = max($bottom, $y);
                $left = min($left, $x);
                $right = max($right, $x);
            }
        }
        if ($bottom < 0) {
            return new self($this->codepoint, 0, 0, $this->xAdvance, 0, 0, []);
        }
        $width = $right - $left + 1;
        $coverage = [];
        for ($y = $top; $y <= $bottom; $y++) {
            array_push($coverage, ...array_slice($this->coverage, $y * $this->width + $left, $width));
        }
        return new self($this->codepoint, $width, $bottom - $top + 1, $this->xAdvance, $this->xOffset + $left, $this->yOffset + $top, $coverage);
    }
}
