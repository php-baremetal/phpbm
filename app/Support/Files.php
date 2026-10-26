<?php

declare(strict_types=1);

namespace App\Support;

final class Files
{
    public static function rename(string $from, string $to): bool
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }
            if ($attempt < 5) {
                usleep(200000);
            }
        }
        return false;
    }
}
