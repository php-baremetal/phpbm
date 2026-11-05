<?php

declare(strict_types=1);

namespace App\Commands;

use App\Build\Builder;
use App\Build\MergedImage;
use App\Project\ConductorCheck;
use App\Project\Project;

final class BuildCommand extends PhpbmCommand
{
    public const MERGED = 'merged.bin';

    protected $signature = 'build
        {--clean : Rebuild from scratch}
        {--merged : Also write every image into one file, to write at offset 0}';

    protected $description = 'Build the firmware of the project in the current directory';

    protected function perform(): int
    {
        $build = app(Builder::class)->build(Project::load((string) getcwd(), app(ConductorCheck::class)), $this->out(), (bool) $this->option('clean'));
        $this->info(sprintf('built %d image(s) in %s', count($build->artifacts->images), $build->artifacts->buildDir));

        if ($this->option('merged')) {
            $target = $build->artifacts->buildDir . '/' . self::MERGED;
            $size = MergedImage::write($build->artifacts->images, $target);
            $this->info(sprintf('merged image: %s (%d bytes, write it at offset 0x0)', $target, $size));
        }

        return self::SUCCESS;
    }
}
