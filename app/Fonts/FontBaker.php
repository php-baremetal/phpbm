<?php

declare(strict_types=1);

namespace App\Fonts;

use App\PhpbmException;

/** Turns a TTF (TrueType outlines) or BDF font into a GFX-extended header, deterministically. */
final class FontBaker
{
    public const VERSION = 1;

    /** @param list<int> $missing */
    public static function missingWarning(string $name, array $missing): string
    {
        $shown = array_map(fn (int $cp) => sprintf('U+%04X', $cp), array_slice($missing, 0, 10));
        $more = count($missing) > 10 ? sprintf(' and %d more', count($missing) - 10) : '';
        return sprintf("font '%s': %d characters not in the source (%s%s)", $name, count($missing), implode(', ', $shown), $more);
    }

    public function bake(string $source, string $name, ?int $size, int $bpp, ?string $chars, ?string $range): BakedFont
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $name)) {
            throw new PhpbmException("font name '{$name}' is not valid: use lowercase letters, digits and '-'");
        }
        $fail = fn (string $message) => new PhpbmException("font '{$name}': {$message}");
        if (!in_array($bpp, [1, 2, 4], true)) {
            throw $fail('bpp must be 1, 2 or 4');
        }
        if ($size !== null && $size <= 0) {
            throw $fail('size must be a positive number of pixels');
        }
        if (!is_file($source)) {
            throw $fail("source '{$source}' not found");
        }

        try {
            $codepoints = CharSet::codepoints($chars, $range);
            $isBdf = str_starts_with((string) file_get_contents($source, length: 9), 'STARTFONT');
            if (!$isBdf && $size === null) {
                throw new PhpbmException('size is required for a TTF source');
            }
            [$glyphs, $missing, $lineHeight, $ascent] = $isBdf
                ? $this->fromBdf(BdfFont::fromFile($source), $codepoints, $bpp, $name)
                : $this->fromTtf(TrueTypeFont::fromFile($source), $codepoints, (int) $size, $bpp, $name);
        } catch (PhpbmException $e) {
            throw str_starts_with($e->getMessage(), "font '{$name}'") ? $e : $fail($e->getMessage());
        }

        if ($glyphs === []) {
            throw $fail('none of the characters is in the source');
        }
        $header = FontHeaderWriter::write($name, 'font_' . str_replace('-', '_', $name), $bpp, $lineHeight, $ascent, $glyphs);
        $bytes = array_sum(array_map(fn (Glyph $g) => FontHeaderWriter::glyphBytes($g, $bpp), $glyphs));
        return new BakedFont($header, $missing, $glyphs, $bytes);
    }

    /**
     * @param  list<int> $codepoints
     * @return array{0:list<Glyph>, 1:list<int>, 2:int, 3:int}
     */
    private function fromTtf(TrueTypeFont $font, array $codepoints, int $size, int $bpp, string $name): array
    {
        $scale = $size / $font->unitsPerEm();
        $glyphs = $missing = [];
        $bytes = 0;
        foreach ($codepoints as $cp) {
            $index = $font->glyphIndex($cp);
            if ($index === 0) {
                $missing[] = $cp;
                continue;
            }
            try {
                $outline = $font->outline($index);
            } catch (PhpbmException $e) {
                throw in_array($e->getMessage(), [TrueTypeFont::SCALED_COMPOSITE, TrueTypeFont::POINT_MATCHED_COMPOSITE], true)
                    ? new PhpbmException(sprintf("font '%s': glyph U+%04X %s", $name, $cp, $e->getMessage()))
                    : $e;
            }
            $glyph = Rasterizer::render($outline, $scale, $bpp)
                ->with($cp, (int) round($font->advance($index) * $scale))
                ->cropped($bpp);
            $glyphs[] = $glyph;
            $bytes += FontHeaderWriter::glyphBytes($glyph, $bpp);
            if ($bytes > FontHeaderWriter::MAX_BITMAP) {
                throw FontHeaderWriter::tooLarge($name);
            }
        }
        $lineHeight = (int) round(($font->ascent() - $font->descent() + $font->lineGap()) * $scale);
        return [$glyphs, $missing, $lineHeight, (int) round($font->ascent() * $scale)];
    }

    /**
     * @param  list<int> $codepoints
     * @return array{0:list<Glyph>, 1:list<int>, 2:int, 3:int}
     */
    private function fromBdf(BdfFont $font, array $codepoints, int $bpp, string $name): array
    {
        $glyphs = $missing = [];
        foreach ($codepoints as $cp) {
            $glyph = $font->glyph($cp);
            if ($glyph === null) {
                $missing[] = $cp;
            } else {
                $glyphs[] = $glyph->cropped($bpp);
            }
        }
        return [$glyphs, $missing, $font->ascent() + $font->descent(), $font->ascent()];
    }
}
