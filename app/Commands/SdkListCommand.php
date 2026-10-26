<?php

declare(strict_types=1);

namespace App\Commands;

use App\Sdk\SdkStore;

final class SdkListCommand extends PhpbmCommand
{
    protected $signature = 'sdk:list';

    protected $description = 'List the installed and linked SDKs';

    protected function perform(): int
    {
        $store = app(SdkStore::class);
        $rows = [];
        foreach ($store->names() as $name) {
            foreach ($store->versions($name) as $version) {
                $rows[] = [$name, $version, $store->dir($name, $version)];
            }
            $linked = $store->linked($name);
            if ($linked !== null) {
                $rows[] = [$name, 'dev', $linked];
            }
        }

        $rows === [] ? $this->line('no SDK installed') : $this->table(['SDK', 'Version', 'Location'], $rows);

        return self::SUCCESS;
    }
}
