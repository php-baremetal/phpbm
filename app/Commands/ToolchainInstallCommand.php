<?php

declare(strict_types=1);

namespace App\Commands;

use App\PhpbmException;
use App\Project\ConductorCheck;
use App\Project\Project;
use App\Sdk\DriverFactory;
use App\Sdk\SdkResolver;

final class ToolchainInstallCommand extends PhpbmCommand
{
    protected $signature = 'toolchain:install';

    protected $description = 'Install the toolchain of the project SDK';

    protected function perform(): int
    {
        $project = Project::load((string) getcwd(), app(ConductorCheck::class));
        $sdk = app(SdkResolver::class)->resolve($project->dir, $project->manifest->project->sdk, $this->out());
        $toolchain = app(DriverFactory::class)->for($sdk)->toolchain();
        $name = $sdk->descriptor->name;

        $status = $toolchain->status();
        if ($status->installed) {
            $this->info("{$name} toolchain already installed ({$status->version})");
            return self::SUCCESS;
        }

        $toolchain->install($this->out());
        $status = $toolchain->status();
        if (!$status->installed) {
            throw new PhpbmException("the {$name} toolchain is still not usable after installing: {$status->problem}");
        }
        $this->info("{$name} toolchain installed ({$status->version})");

        return self::SUCCESS;
    }
}
