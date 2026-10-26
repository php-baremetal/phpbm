<?php

declare(strict_types=1);

namespace App\Commands;

use App\Build\Builder;
use App\Project\ConductorCheck;
use App\Project\Project;

final class BuildCommand extends PhpbmCommand
{
    protected $signature = 'build {--clean : Rebuild from scratch}';

    protected $description = 'Build the firmware of the project in the current directory';

    protected function perform(): int
    {
        $build = app(Builder::class)->build(Project::load((string) getcwd(), app(ConductorCheck::class)), $this->out(), (bool) $this->option('clean'));
        $this->info(sprintf('built %d image(s) in %s', count($build->artifacts->images), $build->artifacts->buildDir));

        return self::SUCCESS;
    }
}
