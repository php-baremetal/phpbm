<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Sdk\SdkIndex;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->dir = Fixtures::tempDir();
    file_put_contents($this->dir . '/index.json', json_encode(['name' => 'demo', 'versions' => [
        '1.0.0' => ['url' => 'demo-1.0.0.tar.gz', 'sha256' => str_repeat('a', 64)],
        '1.2.0' => ['url' => 'https://example.test/demo-1.2.0.tar.gz', 'sha256' => str_repeat('b', 64)],
        '2.0.0' => ['url' => '/abs/demo-2.0.0.tar.gz', 'sha256' => str_repeat('c', 64)],
    ]]));
    $this->index = new SdkIndex($this->dir . '/{name}.json');
    rename($this->dir . '/index.json', $this->dir . '/demo.json');
});

it('resolves relative urls against the index', function () {
    $versions = $this->index->versions('demo');
    expect($versions['1.0.0']['url'])->toBe($this->dir . '/demo-1.0.0.tar.gz')
        ->and($versions['1.2.0']['url'])->toBe('https://example.test/demo-1.2.0.tar.gz')
        ->and($versions['2.0.0']['url'])->toBe('/abs/demo-2.0.0.tar.gz');
});

it('picks the highest version satisfying a constraint', function () {
    expect($this->index->best('demo', '^1.0')['version'])->toBe('1.2.0')
        ->and($this->index->best('demo', '*')['version'])->toBe('2.0.0')
        ->and($this->index->best('demo', '^3.0'))->toBeNull();
});

it('reports an unreachable index', function () {
    expect(fn () => $this->index->versions('nope'))
        ->toThrow(PhpbmException::class, 'cannot read the sdk index ' . $this->dir . '/nope.json');
});

it('defaults to the github release index', function () {
    putenv('PHPBM_SDK_INDEX');
    expect(SdkIndex::fromEnvironment())->toEqual(new SdkIndex(SdkIndex::DEFAULT));
});

it('rejects an invalid constraint', function () {
    expect(fn () => $this->index->best('demo', '^^1'))
        ->toThrow(PhpbmException::class, "invalid version constraint '^^1' for sdk demo: use e.g. ^1.0");
});

it('rejects a version that is not semver', function () {
    file_put_contents($this->dir . '/bad.json', json_encode(['versions' => ['latest' => ['url' => 'x', 'sha256' => str_repeat('a', 64)]]]));
    expect(fn () => $this->index->versions('bad'))->toThrow(PhpbmException::class, "'latest' is not a version");
});

it('rejects a malformed checksum', function () {
    file_put_contents($this->dir . '/bad.json', json_encode(['versions' => ['1.0.0' => ['url' => 'x', 'sha256' => 'abc']]]));
    expect(fn () => $this->index->versions('bad'))->toThrow(PhpbmException::class, 'entry 1.0.0');
});

it('explains broken json', function () {
    file_put_contents($this->dir . '/bad.json', '{nope');
    expect(fn () => $this->index->versions('bad'))->toThrow(PhpbmException::class, 'invalid sdk index ' . $this->dir . '/bad.json: ');
});

it('rejects an invalid sdk name before building the url', function () {
    expect(fn () => (new App\Sdk\SdkIndex('/nowhere/{name}/index.json'))->versions('../x'))
        ->toThrow(App\PhpbmException::class, "invalid sdk name '../x'");
});

it('keeps windows absolute urls as they are', function () {
    file_put_contents($this->dir . '/win.json', json_encode(['versions' => [
        '3.0.0' => ['url' => 'C:/dist/demo-3.0.0.tar.gz', 'sha256' => str_repeat('d', 64)],
    ]]));
    expect((new SdkIndex($this->dir . '/{name}.json'))->versions('win')['3.0.0']['url'])
        ->toBe('C:/dist/demo-3.0.0.tar.gz');
});

it('reads a file url index and resolves relative urls against it', function () {
    @mkdir($this->dir . '/mirror dir');
    rename($this->dir . '/demo.json', $this->dir . '/mirror dir/demo.json');
    $url = 'file://' . (str_starts_with($this->dir, '/') ? '' : '/') . str_replace(['\\', ' '], ['/', '%20'], $this->dir . '/mirror dir') . '/{name}.json';

    $versions = (new SdkIndex($url))->versions('demo');
    expect($versions['1.0.0']['url'])->toBe(str_replace('{name}.json', 'demo-1.0.0.tar.gz', $url))
        ->and($versions['1.2.0']['url'])->toBe('https://example.test/demo-1.2.0.tar.gz');
});
