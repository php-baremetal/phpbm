<?php

declare(strict_types=1);

use App\Fonts\FontBaker;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->cwd = getcwd();
    $this->mono = dirname(__DIR__) . '/fixtures/fonts/DejaVuSansMono.ttf';
    $this->project = Fixtures::project(Fixtures::tempDir());
    chdir($this->project);
});

afterEach(fn () => chdir($this->cwd));

it('bakes a font into the project fonts folder', function () {
    $path = $this->project . '/project-fonts/mono-16.h';
    $bytes = (new FontBaker())->bake($this->mono, 'mono-16', 16, 1, 'Hi', null)->bitmapBytes;

    $this->artisan("font:bake {$this->mono} --name=mono-16 --size=16 --chars=Hi")
        ->expectsOutputToContain("baked mono-16: 2 glyphs, 1 bpp, {$bytes} bytes -> {$path}")
        ->assertExitCode(0);

    expect(file_get_contents($path))->toContain('const display_font_t font_mono_16 = {');
});

it('uses fonts.dir from the manifest', function () {
    Fixtures::project($this->project, ['fonts' => ['dir' => 'my-fonts']]);

    $this->artisan("font:bake {$this->mono} --name=mono-16 --size=16 --chars=Hi")
        ->expectsOutputToContain("-> {$this->project}/my-fonts/mono-16.h")
        ->assertExitCode(0);

    expect(is_file($this->project . '/my-fonts/mono-16.h'))->toBeTrue()
        ->and(is_dir($this->project . '/project-fonts'))->toBeFalse();
});

it('writes into --out', function () {
    $this->artisan("font:bake {$this->mono} --name=mono-16 --size=16 --chars=Hi --out=elsewhere")
        ->expectsOutputToContain("-> {$this->project}/elsewhere/mono-16.h")
        ->assertExitCode(0);

    expect(is_file($this->project . '/elsewhere/mono-16.h'))->toBeTrue();
});

it('writes into project-fonts outside a project', function () {
    $dir = Fixtures::tempDir();
    chdir($dir);

    $this->artisan("font:bake {$this->mono} --name=mono-16 --size=16 --chars=Hi")->assertExitCode(0);

    expect(is_file("{$dir}/project-fonts/mono-16.h"))->toBeTrue();
});

it('needs a size for a TTF', function () {
    $this->artisan("font:bake {$this->mono} --name=mono-16 --chars=Hi")
        ->expectsOutputToContain("font 'mono-16': size is required for a TTF source")
        ->assertExitCode(1);
});

it('needs a name', function () {
    $this->artisan("font:bake {$this->mono} --size=16")
        ->expectsOutputToContain('--name is required')
        ->assertExitCode(1);
});

it('warns about missing characters', function () {
    $tiny = dirname(__DIR__) . '/fixtures/fonts/tiny.bdf';

    $this->artisan("font:bake {$tiny} --name=tiny --chars=I€")
        ->expectsOutputToContain("font 'tiny': 1 characters not in the source (U+20AC)")
        ->expectsOutputToContain('baked tiny: 1 glyphs, 1 bpp, 2 bytes -> ')
        ->assertExitCode(0);
});

it('lists at most ten missing characters', function () {
    $tiny = dirname(__DIR__) . '/fixtures/fonts/tiny.bdf';

    $this->artisan("font:bake {$tiny} --name=tiny --range=0x20-0x4C")
        ->expectsOutputToContain("font 'tiny': 42 characters not in the source (U+0021, U+0022, U+0023, U+0024, U+0025, U+0026, U+0027, U+0028, U+0029, U+002A and 32 more)")
        ->assertExitCode(0);
});
