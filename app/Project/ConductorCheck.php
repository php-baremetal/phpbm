<?php

declare(strict_types=1);

namespace App\Project;

use Composer\Semver\Semver;
use PhpBaremetal\Spec\Sdk\Process;

final readonly class ConductorCheck
{
    public function __construct(private Process $process)
    {
    }

    public function problem(): ?string
    {
        $show = $this->process->run(['composer', 'global', 'show', 'php-baremetal/conductor', '--format=json', '--no-interaction']);
        $version = $show->successful() ? (json_decode($show->output, true)['versions'][0] ?? null) : null;
        if (!is_string($version) || !Semver::satisfies(ltrim($version, 'v'), '^1.0')) {
            return 'the php-baremetal conductor 1 is not installed globally: run `composer global require php-baremetal/conductor:^1.0`';
        }

        $allowed = $this->process->run(['composer', 'global', 'config', 'allow-plugins.php-baremetal/conductor', '--no-interaction']);
        if (trim($allowed->output) !== 'true') {
            return 'the conductor plugin is not allowed globally: run `composer global config allow-plugins.php-baremetal/conductor true`';
        }

        return null;
    }
}
