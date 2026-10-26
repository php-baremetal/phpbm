<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Home;
use Illuminate\Filesystem\Filesystem;

final class CacheClearCommand extends PhpbmCommand
{
    protected $signature = 'cache:clear';

    protected $description = 'Delete downloaded archives and prepared source trees';

    protected function perform(): int
    {
        $cache = app(Home::class)->cache();
        (new Filesystem())->deleteDirectory($cache);
        $this->info("cleared {$cache}");

        return self::SUCCESS;
    }
}
