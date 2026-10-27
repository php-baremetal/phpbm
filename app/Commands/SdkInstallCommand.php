<?php

declare(strict_types=1);

namespace App\Commands;

use App\PhpbmException;
use App\Sdk\SdkIndex;
use App\Sdk\SdkResolver;
use App\Sdk\SdkStore;

final class SdkInstallCommand extends PhpbmCommand
{
    protected $signature = 'sdk:install {sdk : SDK name, optionally with a constraint (esp32@^2.0)}';

    protected $description = 'Install an SDK';

    protected function perform(): int
    {
        [$name, $constraint] = array_pad(explode('@', (string) $this->argument('sdk'), 2), 2, '*');
        SdkStore::assertName($name);
        $best = app(SdkIndex::class)->best($name, $constraint)
            ?? throw new PhpbmException("no {$name} sdk release satisfies {$constraint}");

        if (app(SdkStore::class)->has($name, $best['version'])) {
            $this->info("{$name} {$best['version']} is already installed");
            return self::SUCCESS;
        }
        app(SdkResolver::class)->ensure($name, $best['version'], $best['sha256'], $this->out(), $best['url']);
        $this->info("installed {$name} {$best['version']}");

        return self::SUCCESS;
    }
}
