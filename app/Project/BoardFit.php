<?php

declare(strict_types=1);

namespace App\Project;

use App\PhpbmException;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use PhpBaremetal\Spec\Sdk\Descriptor\Module;

/** Whether a runtime or a storage fits a board: shared by `phpbm init` and `phpbm board`. */
final class BoardFit
{
    /** The first capability the module needs that the board does not have, or null when it fits. */
    public static function missing(Module $module, Board $board): ?string
    {
        foreach ($module->requires->capabilities as $capability) {
            if (!$board->has($capability)) {
                return $capability;
            }
        }
        return null;
    }

    /**
     * @param string $kind 'runtime' or 'storage'
     * @throws PhpbmException when the module needs a capability the board does not have
     */
    public static function check(string $kind, string $id, Module $module, Board $board): void
    {
        $missing = self::missing($module, $board);
        if ($missing !== null) {
            throw new PhpbmException("{$kind} '{$id}' needs {$missing}, but board '{$board->id}' has none");
        }
    }
}
