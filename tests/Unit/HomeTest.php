<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Support\Home;

it('prefers PHPBM_HOME', function () {
    expect(Home::fromEnvironment(['PHPBM_HOME' => '/tmp/phpbm-home/', 'HOME' => '/home/u'])->root)->toBe('/tmp/phpbm-home');
});

it('resolves a relative PHPBM_HOME against the working directory', function () {
    expect(Home::fromEnvironment(['PHPBM_HOME' => 'rel'])->root)->toBe(getcwd() . '/rel');
});

it('uses HOME on unix', function () {
    expect(Home::fromEnvironment(['HOME' => '/home/u'])->root)->toBe('/home/u/.phpbm');
});

it('uses USERPROFILE on windows', function () {
    expect(Home::fromEnvironment(['USERPROFILE' => 'C:\\Users\\u\\'])->root)->toBe('C:\\Users\\u/.phpbm');
});

it('falls back to HOMEDRIVE and HOMEPATH', function () {
    expect(Home::fromEnvironment(['HOMEDRIVE' => 'C:', 'HOMEPATH' => '\\Users\\u'])->root)->toBe('C:\\Users\\u/.phpbm');
});

it('explains when nothing is set', function () {
    expect(fn () => Home::fromEnvironment([]))->toThrow(PhpbmException::class, 'cannot locate the home directory: set PHPBM_HOME');
});

it('reads the real environment by default', function () {
    expect(Home::fromEnvironment()->root)->toBeString()->not->toBe('');
});

it('lays out its directories', function () {
    $home = new Home('/h');
    expect($home->sdks())->toBe('/h/sdks')
        ->and($home->toolchain('esp32'))->toBe('/h/toolchains/esp32')
        ->and($home->cache())->toBe('/h/cache')
        ->and($home->downloads())->toBe('/h/cache/downloads')
        ->and($home->trees())->toBe('/h/cache/trees');
});
