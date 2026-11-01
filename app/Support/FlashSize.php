<?php

declare(strict_types=1);

namespace App\Support;

final class FlashSize
{
    /** "4MB", "512KB" as bytes; null when the size cannot be read (checks on it are then skipped). */
    public static function bytes(?string $size): ?int
    {
        if ($size === null || !preg_match('/^\s*(\d+)\s*([KM])B\s*$/i', $size, $m)) {
            return null;
        }
        return (int) $m[1] * (strtoupper($m[2]) === 'M' ? 1024 * 1024 : 1024);
    }

    /** False only when both sizes are known and the chip's is smaller than the board's. */
    public static function fits(string $board, ?string $chip): bool
    {
        $needed = self::bytes($board);
        $found = self::bytes($chip);
        return $needed === null || $found === null || $found >= $needed;
    }
}
