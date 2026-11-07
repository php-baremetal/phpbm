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

it('normalises backslashes to slashes on Windows only', function () {
    expect(Paths::normalize('C:\\Users\\u\\AppData\\Local\\Temp\\phpbm-test-1\\proj/pkgs', 'Windows'))->toBe('C:/Users/u/AppData/Local/Temp/phpbm-test-1/proj/pkgs')
        ->and(Paths::normalize('\\\\server\\share\\x', 'Windows'))->toBe('//server/share/x')
        ->and(Paths::normalize('/home/u/a\\b', 'Linux'))->toBe('/home/u/a\\b')
        ->and(Paths::normalize('/home/u/a\\b', 'Darwin'))->toBe('/home/u/a\\b');
});

it('gives the working directory and canonical paths normalised', function () {
    $dir = Tests\Support\Fixtures::tempDir();
    mkdir($dir . '/sub');

    expect(Paths::real($dir . '/sub/../sub'))->toBe($dir . '/sub')
        ->and(Paths::real($dir . '/missing'))->toBeNull()
        ->and(Paths::cwd())->toBe(Paths::normalize((string) getcwd()))
        ->and(Paths::cwd())->not->toContain(PHP_OS_FAMILY === 'Windows' ? '\\' : "\0");
});

it('gives a program path in the Windows form for cmd.exe', function () {
    expect(Paths::native('C:/Users/u/AppData/Roaming/Composer/composer.bat', 'Windows'))->toBe('C:\\Users\\u\\AppData\\Roaming\\Composer\\composer.bat')
        ->and(Paths::native('/usr/local/bin/composer', 'Linux'))->toBe('/usr/local/bin/composer')
        ->and((new App\Support\SymfonyProcess('Windows'))->program(['C:/x/composer.bat', 'a/b']))->toBe(['C:\\x\\composer.bat', 'a/b'])
        ->and((new App\Support\SymfonyProcess('Linux'))->program(['/x/composer', 'a/b']))->toBe(['/x/composer', 'a/b']);
});
