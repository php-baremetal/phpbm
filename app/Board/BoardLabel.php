<?php

declare(strict_types=1);

namespace App\Board;

use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use PhpBaremetal\Spec\Sdk\Sdk;

/** How a board is named in menus, listings and extra.baremetal.board. */
final class BoardLabel
{
    /** "Name (id)", followed by " · vendor/package" for the board of a bm-boards package. */
    public static function label(Board $board): string
    {
        return "{$board->name} ({$board->id})" . ($board->fromSdk() ? '' : " · {$board->source}");
    }

    /** The id to write in extra.baremetal.board: the short id, or the long form when another source has the same id. */
    public static function key(Sdk $sdk, Board $board): string
    {
        $same = $sdk->boards[$board->id] ?? null;
        return $same !== null && $same->source === $board->source ? $board->id : $board->qualifiedId();
    }
}
