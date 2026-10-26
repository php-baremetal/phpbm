<?php

declare(strict_types=1);

use Tests\Support\Fixtures;

beforeEach(function () {
    $this->cwd = getcwd();
    $this->home = Fixtures::bootstrap();
    chdir(Fixtures::project(Fixtures::tempDir()));
});

afterEach(fn () => chdir($this->cwd));

it('builds, checks the chip, flashes and syncs', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO'], 'chips' => ['/dev/ttyDEMO' => 'demo']]);

    $this->artisan('flash')->expectsOutputToContain('flashed /dev/ttyDEMO')->assertExitCode(0);
    expect(array_slice(Fixtures::calls($this->home), 1))->toBe(['identify /dev/ttyDEMO', 'flash /dev/ttyDEMO', 'sync embedded /dev/ttyDEMO']);
});

it('hands a microSD project to the sync after flashing', function () {
    Fixtures::project((string) getcwd(), ['storage' => 'microsd']);
    Fixtures::toolchain($this->home, ['chips' => ['/dev/x' => 'demo']]);

    $this->artisan('flash', ['--port' => '/dev/x'])->assertExitCode(0);
    expect(array_slice(Fixtures::calls($this->home), 1))->toBe(['identify /dev/x', 'flash /dev/x', 'sync microsd /dev/x']);
});

it('reports a failed sync after flashing', function () {
    Fixtures::toolchain($this->home, ['chips' => ['/dev/x' => 'demo'], 'sync' => 'fail']);

    $this->artisan('flash', ['--port' => '/dev/x'])
        ->expectsOutputToContain('the firmware was flashed to /dev/x')
        ->assertExitCode(1);
    expect(Fixtures::calls($this->home))->toContain('flash /dev/x');
});

it('refuses a different chip', function () {
    Fixtures::toolchain($this->home, ['chips' => ['/dev/x' => 'other']]);

    $this->artisan('flash', ['--port' => '/dev/x'])
        ->expectsOutputToContain("board 'demo-board' uses demo, but the chip on /dev/x is other (use --force to flash anyway)")
        ->assertExitCode(1);
    expect(Fixtures::calls($this->home))->not->toContain('flash /dev/x');
});

it('refuses a port where nothing answers', function () {
    Fixtures::toolchain($this->home);

    $this->artisan('flash', ['--port' => '/dev/x'])
        ->expectsOutputToContain('no demo chip answered on /dev/x (use --force to flash anyway)')
        ->assertExitCode(1);
});

it('flashes anyway with --force', function () {
    Fixtures::toolchain($this->home, ['chips' => ['/dev/x' => 'other']]);

    $this->artisan('flash', ['--port' => '/dev/x', '--force' => true])->assertExitCode(0);
    expect(Fixtures::calls($this->home))->toContain('flash /dev/x');
});
