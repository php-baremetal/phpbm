<?php

declare(strict_types=1);

use Tests\Support\Fixtures;

beforeEach(function () {
    $this->cwd = getcwd();
    $this->home = Fixtures::bootstrap();
    chdir(Fixtures::project(Fixtures::tempDir(), ['storage' => 'microsd']));
});

afterEach(fn () => chdir($this->cwd));

it('syncs without building or flashing', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('sync')->expectsOutputToContain('synced /dev/ttyDEMO')->assertExitCode(0);
    expect(Fixtures::calls($this->home))->toBe(['sync microsd /dev/ttyDEMO']);
});

it('does not prepare the PHP sources', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('sync')->doesntExpectOutputToContain('preparing')->assertExitCode(0);
    expect(glob($this->home->trees() . '/php-*'))->toBe([]);
});

it('says when the storage has nothing to sync', function () {
    chdir(Fixtures::project(Fixtures::tempDir()));
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('sync')
        ->expectsOutputToContain("storage 'embedded' has nothing to sync")
        ->doesntExpectOutputToContain('synced')
        ->assertExitCode(0);
});

it('uses the given port', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/a', '/dev/b']]);

    $this->artisan('sync', ['-p' => '/dev/b'])->assertExitCode(0);
    expect(Fixtures::calls($this->home))->toBe(['sync microsd /dev/b']);
});

it('asks for a port when several are connected', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/a', '/dev/b']]);

    $this->artisan('sync', ['--no-interaction' => true])
        ->expectsOutputToContain('several serial ports found (/dev/a, /dev/b): pass --port')
        ->assertExitCode(1);
    expect(Fixtures::calls($this->home))->toBe([]);
});

it('needs the toolchain', function () {
    $this->artisan('sync', ['--port' => '/dev/x'])
        ->expectsOutputToContain('the demo toolchain is not installed')
        ->assertExitCode(1);
});

it('reports a failed sync', function () {
    Fixtures::toolchain($this->home, ['sync' => 'fail']);

    $this->artisan('sync', ['--port' => '/dev/x'])
        ->expectsOutputToContain('the board did not answer the sync')
        ->assertExitCode(1);
});
