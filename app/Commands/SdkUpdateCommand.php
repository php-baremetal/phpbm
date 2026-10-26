<?php

declare(strict_types=1);

namespace App\Commands;

use App\Project\ConductorCheck;
use App\Project\Project;
use App\Sdk\SdkResolver;

final class SdkUpdateCommand extends PhpbmCommand
{
    protected $signature = 'sdk:update';

    protected $description = 'Move the project to the newest SDK its constraint allows';

    protected function perform(): int
    {
        $project = Project::load((string) getcwd(), app(ConductorCheck::class));
        $sdk = app(SdkResolver::class)->resolve($project->dir, $project->manifest->project->sdk, $this->out(), true);
        $this->info("using {$sdk->descriptor->name} {$sdk->descriptor->version}");

        return self::SUCCESS;
    }
}
