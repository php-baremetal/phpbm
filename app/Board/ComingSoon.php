<?php

declare(strict_types=1);

namespace App\Board;

use PhpBaremetal\Spec\Sdk\Output;

/** The greeting for a board marked "coming-soon" in a board catalog. */
final class ComingSoon
{
    public const MESSAGE = 'You found the first php-baremetal board. Coming soon.';

    private const ELEPHANT = [
        '     .-------.__',
        '    /  o        `\\',
        '   /  /\\          |',
        '  (  (  |  ||  || |',
        '   \\_)  |__||__||_|',
    ];

    public static function greet(Output $out): void
    {
        foreach (self::ELEPHANT as $line) {
            $out->line($line);
        }
        $out->line(self::MESSAGE);
    }
}
