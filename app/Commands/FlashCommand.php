<?php

declare(strict_types=1);

namespace App\Commands;

use App\Build\Builder;
use App\Build\PortPicker;
use App\PhpbmException;
use App\Project\ConductorCheck;
use App\Project\Project;
use App\Support\FlashSize;
use PhpBaremetal\Spec\Sdk\BuildPlan;

final class FlashCommand extends PhpbmCommand
{
    protected $signature = 'flash
        {--p|port= : Serial port of the board}
        {--force : Flash even if the connected chip does not match the board or has less flash}';

    protected $description = 'Build the firmware if needed and flash it to the board';

    protected function perform(): int
    {
        $out = $this->out();
        $build = app(Builder::class)->build(Project::load((string) getcwd(), app(ConductorCheck::class)), $out);
        $port = app(PortPicker::class)->pick($build->driver, $this->option('port'), $this->input->isInteractive());

        if (!$this->option('force')) {
            $chip = $build->driver->identify($port);
            $expected = $build->plan->chip->target;
            if ($chip === null) {
                throw new PhpbmException("no {$build->plan->sdk->descriptor->name} chip answered on {$port->path} (use --force to flash anyway)");
            }
            if ($chip->target !== $expected) {
                throw new PhpbmException("board '{$build->plan->board->id}' uses {$expected}, but the chip on {$port->path} is {$chip->target} (use --force to flash anyway)");
            }
            if (!FlashSize::fits($build->plan->board->flash, $chip->flashSize)) {
                throw new PhpbmException("board '{$build->plan->board->id}' needs {$build->plan->board->flash} of flash, but the chip on {$port->path} has {$chip->flashSize} (use --force to flash anyway)");
            }
        }

        $build->driver->flash($build->artifacts, $build->plan, $port, $out);
        try {
            $build->driver->sync($build->plan, $port, $out);
        } catch (\Throwable $e) {
            throw new PhpbmException("the firmware was flashed to {$port->path}, but copying {$this->cardFiles($build->plan)} to the card failed (retry with `phpbm sync`): {$e->getMessage()}", 0, $e);
        }
        $this->info("flashed {$port->path}");

        return self::SUCCESS;
    }

    /**
     * What the sync copies to the card: the sources (code on the microSD) and/or the microSD assets. The project has
     * microSD assets with a declared microsd folder, even an empty one (its old files on the card are cleared), or a
     * prepared microsd asset: the SDK's rule.
     */
    private function cardFiles(BuildPlan $plan): string
    {
        $parts = [];
        if ($plan->storage->id === 'microsd') {
            $parts[] = 'the sources';
        }
        if ($this->hasCardAssets($plan)) {
            $parts[] = 'the microSD assets';
        }

        return $parts === [] ? 'the files' : implode(' and ', $parts);
    }

    private function hasCardAssets(BuildPlan $plan): bool
    {
        foreach ($plan->manifest->project->assets->dirs as $dir) {
            if ($dir->storage === 'microsd') {
                return true;
            }
        }
        foreach ($plan->assets as $asset) {
            if ($asset->storage === 'microsd') {
                return true;
            }
        }

        return false;
    }
}
