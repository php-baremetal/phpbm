<?php

declare(strict_types=1);

use App\Commands\PhpbmCommand;
use Illuminate\Contracts\Console\Kernel;

function boomCommand(): PhpbmCommand
{
    return new class extends PhpbmCommand {
        protected $signature = 'boom';

        protected function perform(): int
        {
            throw new RuntimeException('boom');
        }
    };
}

it('turns unexpected runtime errors into a message', function () {
    app(Kernel::class)->registerCommand(boomCommand());
    $this->artisan('boom')->expectsOutputToContain('boom (run with -vv for details)')->assertExitCode(1);
});

it('rethrows them when very verbose', function () {
    app(Kernel::class)->registerCommand(boomCommand());
    $this->artisan('boom', ['-vv' => true])->run();
})->throws(RuntimeException::class, 'boom');
