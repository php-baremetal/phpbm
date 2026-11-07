<?php

declare(strict_types=1);

namespace App\Fonts;

use App\PhpbmException;

/** The tables of a TrueType-outline font that the baker needs: head, hhea, maxp, cmap, loca, glyf, hmtx. */
final class TrueTypeFont
{
    public const SCALED_COMPOSITE = 'uses a scaled composite, not supported';
    public const POINT_MATCHED_COMPOSITE = 'uses a point-matched composite, not supported';

    private const CFF = 'CFF outlines (OTF) are not supported, use a TTF or BDF';

    /** @var array<string, array{0:int, 1:int}> tag => [offset, length] */
    private array $tables = [];
    private int $unitsPerEm;
    private bool $longLoca;
    private int $numGlyphs;
    private int $numHMetrics;
    private int $ascent;
    private int $descent;
    private int $lineGap;
    /** @var \Closure(int):int */
    private \Closure $cmap;

    private function __construct(private readonly string $data, private readonly string $file)
    {
        $version = $this->u32(0);
        if (substr($data, 0, 4) === 'OTTO') {
            throw new PhpbmException(self::CFF);
        }
        if ($version !== 0x00010000 && substr($data, 0, 4) !== 'true') {
            throw $this->invalid();
        }
        $count = $this->u16(4);
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + 16 * $i;
            $this->tables[$this->bytes($record, 4)] = [$this->u32($record + 8), $this->u32($record + 12)];
        }
        if (isset($this->tables['CFF ']) || isset($this->tables['CFF2'])) {
            throw new PhpbmException(self::CFF);
        }

        $head = $this->table('head');
        $this->unitsPerEm = $this->u16($head + 18);
        $this->longLoca = $this->s16($head + 50) === 1;
        $this->numGlyphs = $this->u16($this->table('maxp') + 4);
        $hhea = $this->table('hhea');
        $this->ascent = $this->s16($hhea + 4);
        $this->descent = $this->s16($hhea + 6);
        $this->lineGap = $this->s16($hhea + 8);
        $this->numHMetrics = max(1, $this->u16($hhea + 34));
        $this->table('loca');
        $this->table('glyf');
        $this->table('hmtx');
        $this->cmap = $this->readCmap();
        if ($this->unitsPerEm === 0) {
            throw $this->invalid();
        }
    }

    public static function fromFile(string $file): self
    {
        $data = @file_get_contents($file);
        if ($data === false) {
            throw new PhpbmException("'{$file}' is not a TTF or BDF font");
        }
        return new self($data, $file);
    }

    public function unitsPerEm(): int
    {
        return $this->unitsPerEm;
    }

    public function ascent(): int
    {
        return $this->ascent;
    }

    public function descent(): int
    {
        return $this->descent;
    }

    public function lineGap(): int
    {
        return $this->lineGap;
    }

    /** 0 (the .notdef glyph) when the font has no glyph for the codepoint. */
    public function glyphIndex(int $codepoint): int
    {
        $glyph = ($this->cmap)($codepoint);
        return $glyph < $this->numGlyphs ? $glyph : 0;
    }

    public function advance(int $glyph): int
    {
        return $this->u16($this->table('hmtx') + 4 * min($glyph, $this->numHMetrics - 1));
    }

    public function outline(int $glyph): Outline
    {
        return Outline::fromContours($this->contours($glyph, 0));
    }

    /** @return list<list<array{x:int, y:int, on:bool}>> */
    private function contours(int $glyph, int $depth): array
    {
        if ($glyph >= $this->numGlyphs || $depth > 8) {
            throw $this->invalid();
        }
        $loca = $this->table('loca');
        [$start, $end] = $this->longLoca
            ? [$this->u32($loca + 4 * $glyph), $this->u32($loca + 4 * $glyph + 4)]
            : [2 * $this->u16($loca + 2 * $glyph), 2 * $this->u16($loca + 2 * $glyph + 2)];
        if ($end <= $start) {
            return [];
        }
        $at = $this->table('glyf') + $start;
        $contourCount = $this->s16($at);
        return $contourCount >= 0 ? $this->simple($at, $contourCount) : $this->composite($at, $depth);
    }

    /** @return list<list<array{x:int, y:int, on:bool}>> */
    private function simple(int $at, int $contourCount): array
    {
        $ends = [];
        for ($i = 0; $i < $contourCount; $i++) {
            $ends[] = $this->u16($at + 10 + 2 * $i);
        }
        $pointCount = $ends === [] ? 0 : $ends[$contourCount - 1] + 1;
        $p = $at + 10 + 2 * $contourCount;
        $p += 2 + $this->u16($p);

        $flags = [];
        while (count($flags) < $pointCount) {
            $flag = $this->u8($p++);
            $flags[] = $flag;
            if ($flag & 0x08) {
                $repeat = $this->u8($p++);
                for ($r = 0; $r < $repeat; $r++) {
                    $flags[] = $flag;
                }
            }
        }
        $flags = array_slice($flags, 0, $pointCount);
        $xs = $this->coordinates($flags, $p, 0x02, 0x10);
        $ys = $this->coordinates($flags, $p, 0x04, 0x20);

        $contours = [];
        $first = 0;
        foreach ($ends as $last) {
            if ($last < $first || $last >= $pointCount) {
                throw $this->invalid();
            }
            $contour = [];
            for ($i = $first; $i <= $last; $i++) {
                $contour[] = ['x' => $xs[$i], 'y' => $ys[$i], 'on' => (bool) ($flags[$i] & 0x01)];
            }
            $contours[] = $contour;
            $first = $last + 1;
        }
        return $contours;
    }

    /**
     * @param  list<int> $flags
     * @return list<int>
     */
    private function coordinates(array $flags, int &$p, int $short, int $same): array
    {
        $values = [];
        $value = 0;
        foreach ($flags as $flag) {
            if ($flag & $short) {
                $delta = $this->u8($p++);
                $value += ($flag & $same) ? $delta : -$delta;
            } elseif (!($flag & $same)) {
                $value += $this->s16($p);
                $p += 2;
            }
            $values[] = $value;
        }
        return $values;
    }

    /** @return list<list<array{x:int, y:int, on:bool}>> */
    private function composite(int $at, int $depth): array
    {
        $contours = [];
        $p = $at + 10;
        do {
            $flags = $this->u16($p);
            $component = $this->u16($p + 2);
            $p += 4;
            if ($flags & 0x0001) {
                [$dx, $dy] = [$this->s16($p), $this->s16($p + 2)];
                $p += 4;
            } else {
                [$dx, $dy] = [$this->s8($p), $this->s8($p + 1)];
                $p += 2;
            }
            if (!($flags & 0x0002)) {
                throw new PhpbmException(self::POINT_MATCHED_COMPOSITE);
            }
            if ($flags & (0x0008 | 0x0040 | 0x0080)) {
                throw new PhpbmException(self::SCALED_COMPOSITE);
            }
            foreach ($this->contours($component, $depth + 1) as $contour) {
                $contours[] = array_map(fn (array $pt) => ['x' => $pt['x'] + $dx, 'y' => $pt['y'] + $dy, 'on' => $pt['on']], $contour);
            }
        } while ($flags & 0x0020);
        return $contours;
    }

    /** @return \Closure(int):int */
    private function readCmap(): \Closure
    {
        $cmap = $this->table('cmap');
        $found = [];
        $count = $this->u16($cmap + 2);
        for ($i = 0; $i < $count; $i++) {
            $record = $cmap + 4 + 8 * $i;
            $offset = $cmap + $this->u32($record + 4);
            $format = $this->u16($offset);
            $key = sprintf('%d,%d,%d', $this->u16($record), $this->u16($record + 2), $format);
            $found[$key] ??= $offset;
        }
        $preferred = ['3,10,12', '0,6,12', '0,4,12', '3,1,4', '0,3,4', '0,2,4', '0,1,4', '0,0,4'];
        foreach ($preferred as $key) {
            if (isset($found[$key])) {
                return str_ends_with($key, ',12') ? $this->format12($found[$key]) : $this->format4($found[$key]);
            }
        }
        throw $this->invalid();
    }

    /** @return \Closure(int):int */
    private function format4(int $at): \Closure
    {
        $segments = intdiv($this->u16($at + 6), 2);
        $ends = $at + 14;
        $starts = $ends + 2 * $segments + 2;
        $deltas = $starts + 2 * $segments;
        $rangeOffsets = $deltas + 2 * $segments;
        $this->bytes($rangeOffsets, 2 * $segments);

        return function (int $cp) use ($segments, $ends, $starts, $deltas, $rangeOffsets): int {
            if ($cp > 0xFFFF) {
                return 0;
            }
            for ($i = 0; $i < $segments; $i++) {
                if ($cp > $this->u16($ends + 2 * $i)) {
                    continue;
                }
                $start = $this->u16($starts + 2 * $i);
                if ($cp < $start) {
                    return 0;
                }
                $delta = $this->u16($deltas + 2 * $i);
                $rangeOffset = $this->u16($rangeOffsets + 2 * $i);
                if ($rangeOffset === 0) {
                    return ($cp + $delta) & 0xFFFF;
                }
                $glyph = $this->u16($rangeOffsets + 2 * $i + $rangeOffset + 2 * ($cp - $start));
                return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
            }
            return 0;
        };
    }

    /** @return \Closure(int):int */
    private function format12(int $at): \Closure
    {
        $groups = $this->u32($at + 12);
        $this->bytes($at + 16, 12 * $groups);

        return function (int $cp) use ($at, $groups): int {
            for ($i = 0; $i < $groups; $i++) {
                $group = $at + 16 + 12 * $i;
                $first = $this->u32($group);
                if ($cp >= $first && $cp <= $this->u32($group + 4)) {
                    return $this->u32($group + 8) + $cp - $first;
                }
            }
            return 0;
        };
    }

    private function table(string $tag): int
    {
        if (!isset($this->tables[$tag])) {
            throw $this->invalid();
        }
        [$offset, $length] = $this->tables[$tag];
        $this->bytes($offset, $length);
        return $offset;
    }

    private function bytes(int $at, int $length): string
    {
        if ($at < 0 || $length < 0 || $at + $length > strlen($this->data)) {
            throw $this->invalid();
        }
        return substr($this->data, $at, $length);
    }

    private function u8(int $at): int
    {
        return ord($this->bytes($at, 1));
    }

    private function s8(int $at): int
    {
        $v = $this->u8($at);
        return $v >= 0x80 ? $v - 0x100 : $v;
    }

    private function u16(int $at): int
    {
        return unpack('n', $this->bytes($at, 2))[1];
    }

    private function s16(int $at): int
    {
        $v = $this->u16($at);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $at): int
    {
        return unpack('N', $this->bytes($at, 4))[1];
    }

    private function invalid(): PhpbmException
    {
        return new PhpbmException("'{$this->file}' is not a TTF or BDF font");
    }
}
