<?php

declare(strict_types=1);

namespace App\Commands;

use App\PhpbmException;
use App\Sdk\SdkStore;

final class SdkRemoveCommand extends PhpbmCommand
{
    protected $signature = 'sdk:remove {sdk : name@version, or name@dev for a link}';

    protected $description = 'Remove an installed SDK version or a link';

    protected function perform(): int
    {
        $value = (string) $this->argument('sdk');
        if (!str_contains($value, '@')) {
            throw new PhpbmException("expected <name>@<version>, got {$value}");
        }
        [$name, $version] = explode('@', $value, 2);
        app(SdkStore::class)->remove($name, $version);
        $this->info("removed {$name} {$version}");

        return self::SUCCESS;
    }
}
