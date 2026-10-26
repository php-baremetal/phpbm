<?php

declare(strict_types=1);

use Tests\Support\Fixtures;

beforeEach(function () {
    $this->cwd = getcwd();
    $this->home = Fixtures::bootstrap();
    chdir(Fixtures::project(Fixtures::tempDir()));
});

afterEach(fn () => chdir($this->cwd));

it('installs the toolchain of the project sdk', function () {
    $this->artisan('toolchain:install')
        ->expectsOutputToContain('installing demo tools')
        ->expectsOutputToContain('demo toolchain installed (1.0)')
        ->assertExitCode(0);
    expect(is_file($this->home->toolchain('demo') . '/installed'))->toBeTrue();
});

it('does nothing when it is already installed', function () {
    Fixtures::toolchain($this->home);
    $this->artisan('toolchain:install')
        ->expectsOutputToContain('demo toolchain already installed (1.0)')
        ->assertExitCode(0);
});
