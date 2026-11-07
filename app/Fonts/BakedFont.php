<?php

declare(strict_types=1);

namespace App\Fonts;

final class BakedFont
{
    /**
     * @param list<int>   $missing codepoints asked for that the source does not have
     * @param list<Glyph> $glyphs
     */
    public function __construct(
        public readonly string $header,
        public readonly array $missing,
        public readonly array $glyphs,
        public readonly int $bitmapBytes,
    ) {
    }
}
