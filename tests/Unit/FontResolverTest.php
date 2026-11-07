<?php

declare(strict_types=1);

use App\Build\FontResolver;
use App\Fonts\FontBaker;
use App\PhpbmException;
use App\Project\Project;
use App\Support\Home;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->root = Fixtures::tempDir();
    $this->home = new Home($this->root . '/home');
    $this->out = new BufferedOutput();
    $this->dir = $this->root . '/proj';
    mkdir($this->dir);
    $this->bdf = dirname(__DIR__) . '/fixtures/fonts/tiny.bdf';
});

function fontPackage(string $dir, string $name, array $fonts): Package
{
    $path = 'pkgs/' . basename($name);
    foreach ($fonts as $file) {
        @mkdir("{$dir}/{$path}/" . dirname($file), 0777, true);
        file_put_contents("{$dir}/{$path}/{$file}", "/* font */\n");
    }
    return new Package($name, 'bm-lib', '1.0.0', $path, ['fonts' => array_combine(array_map(fn ($f) => basename($f, '.h'), $fonts), $fonts)]);
}

function resolveFonts($test, array $baremetal = [], array $packages = []): array
{
    Fixtures::project($test->dir, $baremetal, $packages);
    return (new FontResolver($test->home, new FontBaker()))->resolve(Project::load($test->dir), $test->out);
}

it('takes the default font from ext-display', function () {
    $pkg = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);

    expect(resolveFonts($this, [], [$pkg]))->toBe(['default' => $this->dir . '/pkgs/ext-display/fonts/default.h']);
});

it('drops the default font with fonts.default false and keeps the others', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    $dejavu = fontPackage($this->dir, 'php-baremetal/font-dejavu', ['fonts/dejavu-16.h']);

    expect(array_keys(resolveFonts($this, ['fonts' => ['default' => false]], [$display, $dejavu])))->toBe(['dejavu-16']);
});

it('reads the project font directory', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    mkdir($this->dir . '/project-fonts');
    file_put_contents($this->dir . '/project-fonts/clock-48.h', 'x');
    file_put_contents($this->dir . '/project-fonts/notes.txt', 'x');

    expect(resolveFonts($this, [], [$display]))->toBe([
        'clock-48' => $this->dir . '/project-fonts/clock-48.h',
        'default' => $this->dir . '/pkgs/ext-display/fonts/default.h',
    ]);
});

it('treats a missing font directory as empty', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);

    expect(array_keys(resolveFonts($this, ['fonts' => ['dir' => 'nope']], [$display])))->toBe(['default']);
});

it('bakes the bake entries once and caches them', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    mkdir($this->dir . '/fonts-src');
    copy($this->bdf, $this->dir . '/fonts-src/tiny.bdf');
    $bake = ['fonts' => ['default' => false, 'bake' => [['name' => 'tiny', 'source' => 'fonts-src/tiny.bdf']]]];

    $fonts = resolveFonts($this, $bake, [$display]);

    expect($fonts)->toBe(['tiny' => $this->dir . '/.phpbm/build/fonts/tiny.h'])
        ->and(file_get_contents($fonts['tiny']))->toContain('font_tiny');
    $cached = glob($this->home->fonts() . '/*.h');
    expect($cached)->toHaveCount(1);
    touch($cached[0], 1000000);
    unlink($fonts['tiny']);

    $again = resolveFonts($this, $bake, [$display]);

    expect(filemtime($cached[0]))->toBe(1000000)
        ->and(file_exists($again['tiny']))->toBeTrue();

    file_put_contents($this->dir . '/fonts-src/tiny.bdf', file_get_contents($this->bdf) . "\n");
    resolveFonts($this, $bake, [$display]);
    expect(glob($this->home->fonts() . '/*.h'))->toHaveCount(2);
});

it('rejects a name defined twice', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    mkdir($this->dir . '/project-fonts');
    file_put_contents($this->dir . '/project-fonts/x.h', 'x');
    mkdir($this->dir . '/fonts-src');
    copy($this->bdf, $this->dir . '/fonts-src/a.bdf');

    expect(fn () => resolveFonts($this, ['fonts' => ['bake' => [['name' => 'x', 'source' => 'fonts-src/a.bdf']]]], [$display]))
        ->toThrow(PhpbmException::class, "font 'x' is defined twice (project-fonts/x.h, extra.baremetal.fonts.bake)");
});

it('names the package in a duplicate between package and project', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    mkdir($this->dir . '/project-fonts');
    file_put_contents($this->dir . '/project-fonts/default.h', 'x');

    expect(fn () => resolveFonts($this, [], [$display]))
        ->toThrow(PhpbmException::class, "font 'default' is defined twice (php-baremetal/ext-display, project-fonts/default.h)");
});

it('reports a missing bake source', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);

    expect(fn () => resolveFonts($this, ['fonts' => ['bake' => [['name' => 'x', 'source' => 'fonts-src/a.ttf', 'size' => 12]]]], [$display]))
        ->toThrow(PhpbmException::class, "font 'x': source 'fonts-src/a.ttf' not found");
});

it('reports a package font file that does not exist', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    unlink($this->dir . '/pkgs/ext-display/fonts/default.h');

    expect(fn () => resolveFonts($this, [], [$display]))
        ->toThrow(PhpbmException::class, "font file 'fonts/default.h' not found (php-baremetal/ext-display)");
});

it('warns about characters the source lacks', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    mkdir($this->dir . '/fonts-src');
    copy($this->bdf, $this->dir . '/fonts-src/tiny.bdf');

    resolveFonts($this, ['fonts' => ['bake' => [['name' => 'x', 'source' => 'fonts-src/tiny.bdf', 'chars' => 'I€']]]], [$display]);

    expect($this->out->lines)->toBe(["warning: font 'x': 1 characters not in the source (U+20AC)"]);
});

it('needs ext-display for any font', function () {
    $dejavu = fontPackage($this->dir, 'php-baremetal/font-dejavu', ['fonts/dejavu-16.h']);

    expect(fn () => resolveFonts($this, [], [$dejavu]))
        ->toThrow(PhpbmException::class, 'fonts need php-baremetal/ext-display: composer require php-baremetal/ext-display');
});

it('returns nothing without fonts and without ext-display', function () {
    expect(resolveFonts($this))->toBe([]);
});

it('warns about missing characters on a cache hit too', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    mkdir($this->dir . '/fonts-src');
    copy($this->bdf, $this->dir . '/fonts-src/tiny.bdf');
    $bake = ['fonts' => ['bake' => [['name' => 'x', 'source' => 'fonts-src/tiny.bdf', 'chars' => 'I€']]]];

    resolveFonts($this, $bake, [$display]);
    resolveFonts($this, $bake, [$display]);

    $warning = "warning: font 'x': 1 characters not in the source (U+20AC)";
    expect($this->out->lines)->toBe([$warning, $warning]);
});

it('fails with a clear message when the font cache cannot be written', function () {
    $display = fontPackage($this->dir, 'php-baremetal/ext-display', ['fonts/default.h']);
    mkdir($this->dir . '/fonts-src');
    copy($this->bdf, $this->dir . '/fonts-src/tiny.bdf');
    file_put_contents($this->root . '/home', 'not a directory');

    expect(fn () => resolveFonts($this, ['fonts' => ['bake' => [['name' => 'x', 'source' => 'fonts-src/tiny.bdf']]]], [$display]))
        ->toThrow(PhpbmException::class, 'cannot create the font directory');
});
