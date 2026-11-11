<?php

declare(strict_types=1);

namespace App\Build;

use App\PhpbmException;
use App\Project\EnvFile;
use App\Project\Project;
use App\Project\ProjectValidator;
use App\Sdk\DriverFactory;
use App\Sdk\SdkResolver;
use PhpBaremetal\Spec\Sdk\BuildPlan;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\PreparedAsset;

final readonly class Builder
{
    public function __construct(
        private SdkResolver $resolver,
        private ProjectValidator $validator,
        private SourceTree $sources,
        private DriverFactory $drivers,
        private FontResolver $fonts,
        private AssetResolver $assets,
    ) {
    }

    public function build(Project $project, Output $out, bool $clean = false): Build
    {
        $target = $this->plan($project, $out, $clean);
        $plan = $this->withSources($this->withAssets($target->plan, $target->assets), $out);

        return new Build($target->driver, $plan, $target->driver->build($plan, $out));
    }

    /** The driver and the build plan, without preparing any source tree: phpSourceDir and sourceDirs stay empty. */
    public function plan(Project $project, Output $out, bool $clean = false): Target
    {
        $config = $project->manifest->project;
        $sdk = $this->resolver->resolve($project->dir, $config->sdk, $out);
        $target = $this->validator->validate($project, $sdk);
        $driver = $this->drivers->for($sdk);

        $status = $driver->toolchain()->status();
        if (!$status->installed) {
            throw new PhpbmException(sprintf(
                'the %s toolchain is not installed%s: run `phpbm toolchain:install`',
                $sdk->descriptor->name,
                $status->problem === null ? '' : " ({$status->problem})",
            ));
        }

        $fonts = $this->fonts->resolve($project, $out);
        $assets = $this->assets->plan($config, $project->dir, $out);
        $prepared = array_map(
            fn (array $entry): PreparedAsset => new PreparedAsset($entry['storage'], $entry['board'], $this->assets->prepare($entry)),
            $assets,
        );

        $plan = new BuildPlan(
            $project->dir,
            $project->manifest,
            $sdk,
            $target->board,
            $target->chip,
            $target->runtime,
            $target->storage,
            $target->php,
            '',
            [],
            $config->env === null ? [] : EnvFile::parse($project->path($config->env)),
            $project->buildDir(),
            $clean,
            $target->options,
            $target->phpExtensions,
            fonts: $fonts,
            assets: $prepared,
        );

        return new Target($driver, $plan, $assets);
    }

    /** Where the image of the embedded storage is put together when the project has embedded assets. */
    public static function stagingDir(BuildPlan $plan): string
    {
        return $plan->buildDir . '/app';
    }

    /**
     * With embedded assets, the image of the embedded storage holds the sources and the converted assets: they
     * are staged in one folder that the plan names as its image folder (the SDK images and sizes imageDir()).
     *
     * @param list<array{source:string, board:string, storage:string}> $assets
     */
    private function withAssets(BuildPlan $plan, array $assets): BuildPlan
    {
        if (AssetResolver::embedded($assets) === []) {
            return $plan;
        }
        $staging = self::stagingDir($plan);
        $this->assets->stage($plan->srcDir(), $assets, $staging);

        return new BuildPlan(
            $plan->projectDir,
            $plan->manifest,
            $plan->sdk,
            $plan->board,
            $plan->chip,
            $plan->runtime,
            $plan->storage,
            $plan->php,
            $plan->phpSourceDir,
            $plan->sourceDirs,
            $plan->env,
            $plan->buildDir,
            $plan->clean,
            $plan->options,
            $plan->phpExtensions,
            fonts: $plan->fonts,
            assets: $plan->assets,
            imageDir: $staging,
        );
    }

    /** The plan with the PHP source tree and the extensions' external sources prepared (only a build needs them). */
    private function withSources(BuildPlan $plan, Output $out): BuildPlan
    {
        $sources = [];
        foreach ($plan->phpExtensions as $extension) {
            $sources = [...$sources, ...$extension->usedSources($plan->options)];
        }

        return new BuildPlan(
            $plan->projectDir,
            $plan->manifest,
            $plan->sdk,
            $plan->board,
            $plan->chip,
            $plan->runtime,
            $plan->storage,
            $plan->php,
            $this->sources->php($plan->sdk, $plan->php, $out),
            $this->sources->sources($plan->sdk, $sources, $out),
            $plan->env,
            $plan->buildDir,
            $plan->clean,
            $plan->options,
            $plan->phpExtensions,
            fonts: $plan->fonts,
            assets: $plan->assets,
            imageDir: $plan->imageDir,
        );
    }
}
