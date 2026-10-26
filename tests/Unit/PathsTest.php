<?php

declare(strict_types=1);

use App\Support\Paths;

it('recognises absolute paths on every system', function (string $path, bool $absolute) {
    expect(Paths::isAbsolute($path))->toBe($absolute);
})->with([
    ['/home/u', true],
    ['\\Users\\u', true],
    ['C:\\Users\\u', true],
    ['c:/Users/u', true],
    ['\\\\server\\share', true],
    ['project-src', false],
    ['./x', false],
    ['C:relative', false],
    ['', false],
]);

it('resolves relative paths against a base', function () {
    expect(Paths::resolve('/p/', 'src'))->toBe('/p/src')
        ->and(Paths::resolve('C:\\p\\', 'src'))->toBe('C:\\p/src')
        ->and(Paths::resolve('/p', 'D:\\abs'))->toBe('D:\\abs');
});

it('turns file urls into local paths', function () {
    expect(Paths::local('file:///home/u/a%20b/index.json', 'Linux'))->toBe('/home/u/a b/index.json')
        ->and(Paths::local('file://localhost/srv/x.tar.gz', 'Linux'))->toBe('/srv/x.tar.gz')
        ->and(Paths::local('file:///C:/Users/u/x.tar.gz', 'Windows'))->toBe('C:/Users/u/x.tar.gz')
        ->and(Paths::local('https://example.test/a%20b', 'Linux'))->toBe('https://example.test/a%20b')
        ->and(Paths::local('/plain/path', 'Linux'))->toBe('/plain/path');
});
