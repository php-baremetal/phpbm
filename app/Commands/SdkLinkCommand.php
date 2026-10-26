<?php

declare(strict_types=1);

namespace App\Commands;

use App\Sdk\SdkStore;

final class SdkLinkCommand extends PhpbmCommand
{
    protected $signature = 'sdk:link {name : SDK name} {dir : Directory holding the SDK}';

    protected $description = 'Use a local SDK directory instead of a release';

    protected function perform(): int
    {
        $sdk = app(SdkStore::class)->link((string) $this->argument('name'), (string) $this->argument('dir'));
        $this->info("linked {$sdk->descriptor->name} -> {$sdk->dir}");

        return self::SUCCESS;
    }
}
