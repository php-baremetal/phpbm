<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Sdk\SdkStore;
use App\Support\Archive;
use App\Support\Home;
use App\Support\SymfonyProcess;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->root = Fixtures::tempDir();
    $this->php = Fixtures::phpTarball($this->root . '/dist');
    $this->store = new SdkStore(new Home($this->root . '/home'));
    $this->archive = new Archive(new SymfonyProcess());
});

function demoTarball(string $root, array $php, string $version): array
{
    $sdk = Fixtures::sdk("{$root}/src-{$version}/demo-sdk", $version, $php);
    return Fixtures::sdkTarball($sdk, "{$root}/dist", $version);
}

it('installs an sdk from its archive and remembers the checksum', function () {
    $tarball = demoTarball($this->root, $this->php, '1.0.0');
    $sdk = $this->store->install('demo', '1.0.0', $tarball['path'], $tarball['sha256'], $this->archive);

    expect($sdk->descriptor->version)->toBe('1.0.0')
        ->and($this->store->versions('demo'))->toBe(['1.0.0'])
        ->and($this->store->names())->toBe(['demo'])
        ->and($this->store->sha256('demo', '1.0.0'))->toBe($tarball['sha256'])
        ->and(glob($this->root . '/home/sdks/demo/.tmp-*'))->toBe([]);
});

it('sorts versions semantically', function () {
    foreach (['1.10.0', '1.2.0'] as $version) {
        $t = demoTarball($this->root, $this->php, $version);
        $this->store->install('demo', $version, $t['path'], $t['sha256'], $this->archive);
    }
    expect($this->store->versions('demo'))->toBe(['1.2.0', '1.10.0'])
        ->and($this->store->current('demo')?->descriptor->version)->toBe('1.10.0');
});

it('refuses an archive holding a different sdk version', function () {
    $tarball = demoTarball($this->root, $this->php, '1.0.0');
    expect(fn () => $this->store->install('demo', '2.0.0', $tarball['path'], $tarball['sha256'], $this->archive))
        ->toThrow(PhpbmException::class, 'archive for demo 2.0.0 contains sdk demo 1.0.0');
    expect($this->store->has('demo', '2.0.0'))->toBeFalse();
});

it('links a local sdk with a link file and prefers it', function () {
    $dir = Fixtures::sdk($this->root . '/work/demo-sdk', '1.5.0', $this->php);
    $this->store->link('demo', $dir);

    expect(file_get_contents($this->root . '/home/sdks/demo/dev.link'))->toBe(realpath($dir) . "\n")
        ->and(is_link($this->root . '/home/sdks/demo/dev'))->toBeFalse()
        ->and($this->store->linked('demo'))->toBe(realpath($dir))
        ->and($this->store->has('demo', 'dev'))->toBeTrue()
        ->and($this->store->load('demo', 'dev')->descriptor->version)->toBe('1.5.0')
        ->and($this->store->current('demo')?->dir)->toBe(realpath($dir))
        ->and($this->store->versions('demo'))->toBe([])
        ->and($this->store->names())->toBe(['demo']);
});

it('explains a link to a directory that is gone', function () {
    $dir = Fixtures::sdk($this->root . '/work/demo-sdk', '1.5.0', $this->php);
    $this->store->link('demo', $dir);
    (new Illuminate\Filesystem\Filesystem())->deleteDirectory($this->root . '/work');

    expect($this->store->has('demo', 'dev'))->toBeFalse()
        ->and(fn () => $this->store->load('demo', 'dev'))
        ->toThrow(PhpbmException::class, 'which has no sdk.json: run `phpbm sdk:remove demo@dev` or link it again');
});

it('refuses to link a directory holding another sdk', function () {
    $dir = Fixtures::sdk($this->root . '/work/demo-sdk', '1.0.0', $this->php);
    expect(fn () => $this->store->link('esp32', $dir))
        ->toThrow(PhpbmException::class, 'contains sdk demo, not esp32');
});

it('removes installed versions and links', function () {
    $tarball = demoTarball($this->root, $this->php, '1.0.0');
    $this->store->install('demo', '1.0.0', $tarball['path'], $tarball['sha256'], $this->archive);
    $this->store->link('demo', Fixtures::sdk($this->root . '/work/demo-sdk', '1.0.0', $this->php));

    $this->store->remove('demo', '1.0.0');
    $this->store->remove('demo', 'dev');

    expect($this->store->has('demo', '1.0.0'))->toBeFalse()
        ->and($this->store->sha256('demo', '1.0.0'))->toBeNull()
        ->and($this->store->linked('demo'))->toBeNull()
        ->and($this->store->current('demo'))->toBeNull();
});

it('explains what is not installed', function () {
    expect(fn () => $this->store->load('demo', '9.9.9'))->toThrow(PhpbmException::class, 'sdk demo 9.9.9 is not installed');
    expect(fn () => $this->store->remove('demo', 'dev'))->toThrow(PhpbmException::class, 'sdk demo is not linked');
});

it('rejects names and versions that are not safe path segments', function () {
    expect(fn () => $this->store->dir('../x', '1.0.0'))->toThrow(PhpbmException::class, "invalid sdk name '../x'")
        ->and(fn () => $this->store->dir('demo', '../x'))->toThrow(PhpbmException::class, "invalid sdk version '../x'")
        ->and(fn () => $this->store->dir('demo', '1..0'))->toThrow(PhpbmException::class, "invalid sdk version '1..0'")
        ->and($this->store->dir('demo', 'dev'))->toEndWith('/sdks/demo/dev');
});

it('ignores directories that are not versions', function () {
    $t = demoTarball($this->root, $this->php, '1.0.0');
    $this->store->install('demo', '1.0.0', $t['path'], $t['sha256'], $this->archive);
    mkdir($this->root . '/home/sdks/demo/foo');
    expect($this->store->versions('demo'))->toBe(['1.0.0']);
});
