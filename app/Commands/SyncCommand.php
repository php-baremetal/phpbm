<?php

declare(strict_types=1);

namespace App\Commands;

use App\Build\Builder;
use App\Build\PortPicker;
use App\Project\ConductorCheck;
use App\Project\Project;

final class SyncCommand extends PhpbmCommand
{
    protected $signature = 'sync {--p|port= : Serial port of the board}';

    protected $description = 'Copy the project sources to the board storage (microSD) without flashing';

    protected function perform(): int
    {
        $out = $this->out();
        $target = app(Builder::class)->plan(Project::load((string) getcwd(), app(ConductorCheck::class)), $out);
        $port = app(PortPicker::class)->pick($target->driver, $this->option('port'), $this->input->isInteractive());

        if ($target->driver->sync($target->plan, $port, $out)) {
            $this->info("synced {$port->path}");
        } else {
            $this->info("storage '{$target->plan->storage->id}' has nothing to sync");
        }

        return self::SUCCESS;
    }
}
