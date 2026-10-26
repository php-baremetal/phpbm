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

final readonly class Builder
{
    public function __construct(
        private SdkResolver $resolver,
        private ProjectValidator $validator,
        private SourceTree $sources,
        private DriverFactory $drivers,
    ) {
    }

    public function build(Project $project, Output $out, bool $clean = false): Build
    {
        $target = $this->plan($project, $out, $clean);
        $plan = $this->withSources($target->plan, $out);

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
        );

        return new Target($driver, $plan);
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
        );
    }
}
