<?php

declare(strict_types=1);

namespace App\Fonts;

use App\PhpbmException;

/** A BDF bitmap font, read as it is: lit pixels have full coverage. */
final class BdfFont
{
    /** @param array<int, Glyph> $glyphs */
    private function __construct(private readonly int $ascent, private readonly int $descent, private readonly array $glyphs)
    {
    }

    public static function fromFile(string $file): self
    {
        $data = @file_get_contents($file);
        $invalid = new PhpbmException("'{$file}' is not a TTF or BDF font");
        if ($data === false || !str_starts_with($data, 'STARTFONT')) {
            throw $invalid;
        }

        $properties = [];
        $glyphs = [];
        $char = null;
        $rows = null;
        foreach (preg_split('/\r?\n/', $data) as $line) {
            $line = trim($line);
            $words = preg_split('/\s+/', $line);
            if ($rows !== null) {
                if ($words[0] === 'ENDCHAR') {
                    self::addGlyph($glyphs, $char, $rows, $invalid);
                    [$char, $rows] = [null, null];
                } elseif (preg_match('/^[0-9A-Fa-f]+$/D', $line)) {
                    $rows[] = $line;
                } else {
                    throw $invalid;
                }
                continue;
            }
            match ($words[0]) {
                'FONT_ASCENT', 'FONT_DESCENT', 'FONTBOUNDINGBOX' => $properties[$words[0]] = array_map(intval(...), array_slice($words, 1)),
                'STARTCHAR' => $char = [],
                'ENCODING', 'DWIDTH', 'BBX' => $char === null ? throw $invalid : $char[$words[0]] = array_map(intval(...), array_slice($words, 1)),
                'BITMAP' => $char === null ? throw $invalid : $rows = [],
                default => null,
            };
        }

        $box = $properties['FONTBOUNDINGBOX'] ?? null;
        $ascent = $properties['FONT_ASCENT'][0] ?? (isset($box[3]) ? $box[1] + $box[3] : null);
        $descent = $properties['FONT_DESCENT'][0] ?? (isset($box[3]) ? -$box[3] : null);
        if ($ascent === null || $descent === null || $rows !== null) {
            throw $invalid;
        }
        ksort($glyphs);
        return new self($ascent, $descent, $glyphs);
    }

    public function ascent(): int
    {
        return $this->ascent;
    }

    public function descent(): int
    {
        return $this->descent;
    }

    public function glyph(int $codepoint): ?Glyph
    {
        return $this->glyphs[$codepoint] ?? null;
    }

    /**
     * @param array<int, Glyph>    $glyphs
     * @param array<string, list<int>> $char
     * @param list<string>         $rows
     */
    private static function addGlyph(array &$glyphs, array $char, array $rows, PhpbmException $invalid): void
    {
        $encoding = $char['ENCODING'][0] ?? -1;
        [$width, $height, $xOffset, $yOffset] = ($char['BBX'] ?? []) + [null, null, null, null];
        if ($width === null || $yOffset === null || $width < 0 || $height < 0 || count($rows) !== $height) {
            throw $invalid;
        }
        $coverage = [];
        foreach ($rows as $row) {
            if (strlen($row) < 2 * intdiv($width + 7, 8)) {
                throw $invalid;
            }
            $bits = '';
            foreach (str_split($row, 2) as $byte) {
                $bits .= str_pad(decbin((int) hexdec($byte)), 8, '0', STR_PAD_LEFT);
            }
            for ($x = 0; $x < $width; $x++) {
                $coverage[] = $bits[$x] === '1' ? 255 : 0;
            }
        }
        if ($encoding >= 0 && $encoding <= 0x10FFFF) {
            $advance = $char['DWIDTH'][0] ?? $width;
            $glyphs[$encoding] = new Glyph($encoding, $width, $height, $advance, $xOffset, -($yOffset + $height), $coverage);
        }
    }
}
