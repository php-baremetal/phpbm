<?php

declare(strict_types=1);

use App\Build\SourceTree;
use App\PhpbmException;
use App\Support\Home;
use PhpBaremetal\Spec\Sdk\SdkLoader;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->root = Fixtures::tempDir();
    $this->php = Fixtures::phpTarball($this->root . '/dist');
    $this->sdkDir = Fixtures::sdk($this->root . '/demo-sdk', '1.0.0', $this->php);
    app()->instance(Home::class, new Home($this->root . '/home'));
    $this->tree = app(SourceTree::class);
    $this->out = new BufferedOutput();
});

function demoSdk(): PhpBaremetal\Spec\Sdk\Sdk
{
    return (new SdkLoader())->load(test()->sdkDir);
}

it('prepares a patched php tree', function () {
    $sdk = demoSdk();
    $dir = $this->tree->php($sdk, $sdk->descriptor->release('8.4'), $this->out);

    expect(file_get_contents($dir . '/main/php.h'))->toBe("patched\n")
        ->and(is_file($dir . '.complete'))->toBeTrue()
        ->and(basename($dir))->toStartWith('php-8.4.25-')
        ->and($this->out->lines)->toContain('step: preparing PHP 8.4.25');
});

it('reuses a complete tree without downloading again', function () {
    $sdk = demoSdk();
    $first = $this->tree->php($sdk, $sdk->descriptor->release('8.4'), $this->out);
    unlink($this->php['path']);
    array_map('unlink', glob($this->root . '/home/cache/downloads/*'));

    expect($this->tree->php($sdk, $sdk->descriptor->release('8.4'), new BufferedOutput()))->toBe($first);
});

it('prepares a new tree when a patch changes', function () {
    $first = $this->tree->php(demoSdk(), demoSdk()->descriptor->release('8.4'), $this->out);
    file_put_contents($this->sdkDir . '/php/8.4/patches/0002-more.patch', "--- a/main/php.h\n+++ b/main/php.h\n@@ -1 +1 @@\n-patched\n+more\n");

    $second = $this->tree->php(demoSdk(), demoSdk()->descriptor->release('8.4'), $this->out);
    expect($second)->not->toBe($first)
        ->and(file_get_contents($second . '/main/php.h'))->toBe("more\n");
});

it('names the patch that does not apply and leaves no tree', function () {
    file_put_contents($this->sdkDir . '/php/8.4/patches/0002-bad.patch', "--- a/main/php.h\n+++ b/main/php.h\n@@ -1 +1 @@\n-nothing\n+else\n");

    expect(fn () => $this->tree->php(demoSdk(), demoSdk()->descriptor->release('8.4'), $this->out))
        ->toThrow(PhpbmException::class, 'patch 0002-bad.patch does not apply to PHP 8.4.25');
    expect(glob($this->root . '/home/cache/trees/*'))->toBe([]);
});

it('extracts the third-party sources of the sdk', function () {
    mkdir($this->root . '/lib/sqlite', 0777, true);
    file_put_contents($this->root . '/lib/sqlite/sqlite3.c', "int x;\n");
    Fixtures::tar($this->root . '/dist/sqlite.tar.gz', $this->root . '/lib', 'sqlite');
    $json = json_decode(file_get_contents($this->sdkDir . '/sdk.json'), true);
    $json['sources']['sqlite'] = ['url' => $this->root . '/dist/sqlite.tar.gz', 'sha256' => hash_file('sha256', $this->root . '/dist/sqlite.tar.gz')];
    file_put_contents($this->sdkDir . '/sdk.json', json_encode($json));

    expect($this->tree->sources(demoSdk(), [], $this->out))->toBe([]);
    $dirs = $this->tree->sources(demoSdk(), ['sqlite', 'sqlite'], $this->out);
    expect(array_keys($dirs))->toBe(['sqlite'])
        ->and(file_get_contents($dirs['sqlite'] . '/sqlite3.c'))->toBe("int x;\n");

    mkdir($this->sdkDir . '/sources/sqlite/patches', 0777, true);
    file_put_contents($this->sdkDir . '/sources/sqlite/patches/0001-y.patch', "--- a/sqlite3.c\n+++ b/sqlite3.c\n@@ -1 +1 @@\n-int x;\n+int y;\n");
    $patched = $this->tree->sources(demoSdk(), ['sqlite'], $this->out);
    expect($patched['sqlite'])->not->toBe($dirs['sqlite'])
        ->and(file_get_contents($patched['sqlite'] . '/sqlite3.c'))->toBe("int y;\n");

    expect(fn () => $this->tree->sources(demoSdk(), ['onig'], $this->out))
        ->toThrow(PhpbmException::class, "sdk demo 1.0.0 declares no source 'onig'");
});
