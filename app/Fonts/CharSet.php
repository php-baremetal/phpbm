<?php

declare(strict_types=1);

namespace App\Fonts;

use App\PhpbmException;

final class CharSet
{
    public const DEFAULT = [0x20, 0x7E];

    /** @return list<int> sorted, unique codepoints */
    public static function codepoints(?string $chars, ?string $range): array
    {
        $chars = $chars === '' ? null : $chars;
        $range = $range === null || trim($range) === '' ? null : $range;
        if ($chars !== null && $range !== null) {
            throw new PhpbmException('use either chars or range, not both');
        }

        if ($chars !== null) {
            if (!mb_check_encoding($chars, 'UTF-8')) {
                throw new PhpbmException('chars is not valid UTF-8');
            }
            $codepoints = array_map(mb_ord(...), mb_str_split($chars, 1, 'UTF-8'));
        } elseif ($range !== null) {
            $codepoints = [];
            foreach (explode(',', $range) as $part) {
                [$first, $last] = self::parseRun($part, $range);
                array_push($codepoints, ...range($first, $last));
            }
        } else {
            $codepoints = range(self::DEFAULT[0], self::DEFAULT[1]);
        }

        $codepoints = array_values(array_unique($codepoints));
        sort($codepoints);
        return $codepoints;
    }

    /**
     * @param  list<int>                  $codepoints sorted
     * @return list<array{0:int, 1:int}>  [first, last] of each contiguous run
     */
    public static function runs(array $codepoints): array
    {
        $runs = [];
        foreach ($codepoints as $cp) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][1] === $cp - 1) {
                $runs[$last][1] = $cp;
            } else {
                $runs[] = [$cp, $cp];
            }
        }
        return $runs;
    }

    /** @return array{0:int, 1:int} */
    private static function parseRun(string $part, string $range): array
    {
        $bounds = array_map(trim(...), explode('-', $part));
        $values = array_map(self::parseCodepoint(...), $bounds);
        if (count($values) > 2 || in_array(null, $values, true) || ($values[1] ?? $values[0]) < $values[0]) {
            throw new PhpbmException("invalid range '{$range}': use codepoints like 0x20-0x7E, separated by commas");
        }
        return [$values[0], $values[1] ?? $values[0]];
    }

    private static function parseCodepoint(string $value): ?int
    {
        $cp = match (true) {
            (bool) preg_match('/^0x[0-9a-f]{1,6}$/iD', $value) => (int) hexdec(substr($value, 2)),
            (bool) preg_match('/^\d{1,7}$/D', $value) => (int) $value,
            default => null,
        };
        return $cp !== null && $cp <= 0x10FFFF ? $cp : null;
    }
}
