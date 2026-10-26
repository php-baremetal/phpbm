<?php

declare(strict_types=1);

namespace App\Commands;

use App\Build\PortPicker;
use App\Project\ConductorCheck;
use App\Project\Project;
use App\Sdk\DriverFactory;
use App\Sdk\SdkResolver;

final class MonitorCommand extends PhpbmCommand
{
    protected $signature = 'monitor {--p|port= : Serial port of the board}';

    protected $description = 'Open the serial console of the board';

    protected function perform(): int
    {
        $project = Project::load((string) getcwd(), app(ConductorCheck::class));
        $sdk = app(SdkResolver::class)->resolve($project->dir, $project->manifest->project->sdk, $this->out());
        $driver = app(DriverFactory::class)->for($sdk);

        return $driver->monitor(app(PortPicker::class)->pick($driver, $this->option('port'), $this->input->isInteractive()));
    }
}
