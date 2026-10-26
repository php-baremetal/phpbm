<?php

declare(strict_types=1);

use App\Project\ConductorCheck;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\Testing\FakeProcess;

const SHOW = 'composer global show php-baremetal/conductor --format=json';
const ALLOWED = 'composer global config allow-plugins.php-baremetal/conductor';

it('is happy with conductor 1 installed and allowed', function () {
    $process = (new FakeProcess())
        ->on(SHOW, new ProcessResult(0, '{"versions":["1.0.0"]}'))
        ->on(ALLOWED, new ProcessResult(0, "true\n"));
    expect((new ConductorCheck($process))->problem())->toBeNull();
});

it('asks to install conductor 1', function (ProcessResult $show) {
    $process = (new FakeProcess())->on(SHOW, $show)->on(ALLOWED, new ProcessResult(0, "true\n"));
    expect((new ConductorCheck($process))->problem())
        ->toBe('the php-baremetal conductor 1 is not installed globally: run `composer global require php-baremetal/conductor:^1.0`');
})->with([
    'missing' => [new ProcessResult(1, '', 'not found')],
    'old' => [new ProcessResult(0, '{"versions":["0.9.0"]}')],
]);

it('asks to allow the plugin', function () {
    $process = (new FakeProcess())
        ->on(SHOW, new ProcessResult(0, '{"versions":["1.0.0"]}'))
        ->on(ALLOWED, new ProcessResult(1, '', 'not set'));
    expect((new ConductorCheck($process))->problem())
        ->toBe('the conductor plugin is not allowed globally: run `composer global config allow-plugins.php-baremetal/conductor true`');
});
