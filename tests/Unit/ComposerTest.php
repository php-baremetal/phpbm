<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Support\Composer;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use PhpBaremetal\Spec\Testing\FakeProcess;
use Tests\Support\Fixtures;

it('runs composer with the arguments', function () {
    $process = (new FakeProcess())->on('/bin/composer require a/b', new ProcessResult(0, "ok\n"));
    $out = new BufferedOutput();
    (new Composer($process, '/bin/composer'))->run('/proj', ['require', 'a/b'], $out);

    expect($process->calls)->toBe([['/bin/composer', 'require', 'a/b']])->and($out->lines)->toContain('line: ok');
});

it('fails with the exit code when Composer printed no exception', function () {
    $process = (new FakeProcess())->on('/bin/composer', new ProcessResult(2, "one\nProblem here\n\n", ''));
    expect(fn () => (new Composer($process, '/bin/composer'))->run('/proj', ['remove', 'a/b'], new BufferedOutput()))
        ->toThrow(new PhpbmException('composer remove a/b failed (exit 2)'));
});

it('fails with the message of the exception Composer printed, never the usage synopsis', function () {
    $stderr = "\nIn RequireCommand.php line 42:\n                                          \n"
        . "  Could not find a matching version of package a/b.  \n                                          \n\n"
        . "require [--dev] [--dry-run] [--] [<packages>...]\n\n";
    $process = (new FakeProcess())->on('/bin/composer', new ProcessResult(1, '', $stderr));
    expect(fn () => (new Composer($process, '/bin/composer'))->run('/proj', ['require', 'a/b'], new BufferedOutput()))
        ->toThrow(new PhpbmException('composer require a/b failed (exit 1): Could not find a matching version of package a/b.'));
});

it('skips the exception class title of verbose output', function () {
    $stderr = "In Foo.php line 3:\n\n  [UnexpectedValueException]  \n  Bad thing  \n";
    $process = (new FakeProcess())->on('/bin/composer', new ProcessResult(1, '', $stderr));
    expect(fn () => (new Composer($process, '/bin/composer'))->run('/proj', ['install'], new BufferedOutput()))
        ->toThrow(new PhpbmException('composer install failed (exit 1): Bad thing'));
});

it('says composer is not found when there is no binary', function () {
    $old = getenv('PHPBM_COMPOSER');
    putenv('PHPBM_COMPOSER');
    try {
        expect(fn () => (new Composer(new FakeProcess(), 'phpbm-absent-composer'))->run('/proj', ['install'], new BufferedOutput()))
            ->toThrow(new PhpbmException(Composer::NOT_FOUND));
    } finally {
        putenv($old === false ? 'PHPBM_COMPOSER' : 'PHPBM_COMPOSER=' . $old);
    }
});

it('is unavailable when the binary is missing', function () {
    expect((new Composer(new FakeProcess(), '/nonexistent/composer'))->available())->toBeFalse();
});

it('is available for an existing binary', function () {
    expect((new Composer(new FakeProcess(), PHP_BINARY))->available())->toBeTrue();
});

it('runs in the project folder', function () {
    $spy = new class implements Process {
        public ?string $cwd = null;
        public array $command = [];

        public function run(array $command, ?string $cwd = null, array $env = [], ?Output $out = null): ProcessResult
        {
            $this->command = $command;
            $this->cwd = $cwd;
            return new ProcessResult(0);
        }

        public function tty(array $command, ?string $cwd = null, array $env = []): int
        {
            return 0;
        }
    };
    (new Composer($spy, '/bin/composer'))->run('/proj', ['install'], new BufferedOutput());

    expect($spy->cwd)->toBe('/proj')->and($spy->command)->toBe(['/bin/composer', 'install']);
});

it('resolves a bare name on the PATH', function () {
    $dir = Fixtures::tempDir();
    if (PHP_OS_FAMILY === 'Windows') {
        file_put_contents($dir . '/phpbm-fake-composer.bat', "@exit /b 0\r\n");
    } else {
        file_put_contents($dir . '/phpbm-fake-composer', "#!/bin/sh\n");
        chmod($dir . '/phpbm-fake-composer', 0755);
    }
    $oldPath = getenv('PATH');
    $oldEnv = getenv('PHPBM_COMPOSER');
    putenv('PATH=' . $dir . PATH_SEPARATOR . $oldPath);
    putenv('PHPBM_COMPOSER=phpbm-fake-composer');
    try {
        expect((new Composer(new FakeProcess()))->available())->toBeTrue()
            ->and((new Composer(new FakeProcess(), 'phpbm-fake-composer'))->available())->toBeTrue()
            ->and((new Composer(new FakeProcess(), 'phpbm-absent-composer'))->available())->toBeFalse();
    } finally {
        putenv('PATH=' . $oldPath);
        putenv($oldEnv === false ? 'PHPBM_COMPOSER' : 'PHPBM_COMPOSER=' . $oldEnv);
    }
});

it('is unavailable for a non-executable path', function () {
    $dir = Fixtures::tempDir();
    file_put_contents($dir . '/composer', 'x');
    chmod($dir . '/composer', 0644);
    expect((new Composer(new FakeProcess(), $dir . '/composer'))->available())->toBeFalse();
});

it('finds a bare name with its PATHEXT extension on Windows and never an extensionless file', function () {
    $first = Fixtures::tempDir();
    $second = Fixtures::tempDir();
    file_put_contents($first . '/composer', "#!/bin/sh\n");
    file_put_contents($second . '/composer.bat', "@exit /b 0\r\n");
    file_put_contents($second . '/tool.cmd', "@exit /b 0\r\n");
    $env = ['PATH' => str_replace('/', '\\', $first) . '\;"' . $second . '";', 'PATHEXT' => '.COM;.EXE;.BAT;.CMD'];

    expect(App\Support\Executable::find('composer', 'Windows', $env))->toBe($second . '/composer.bat')
        ->and(App\Support\Executable::find('composer.bat', 'Windows', $env))->toBe($second . '/composer.bat')
        ->and(App\Support\Executable::find('tool', 'Windows', $env))->toBe($second . '/tool.cmd')
        ->and(App\Support\Executable::find('composer', 'Windows', ['PATH' => $first]))->toBeNull()
        ->and(App\Support\Executable::runnable($second . '/composer.bat', 'Windows', $env))->toBeTrue()
        ->and(App\Support\Executable::runnable($first . '/composer', 'Windows', $env))->toBeFalse()
        ->and(App\Support\Executable::runnable('C:/x/php.EXE', 'Windows', []))->toBeTrue();
});

it('finds a bare name only when it is executable elsewhere', function () {
    $dir = Fixtures::tempDir();
    file_put_contents($dir . '/plain', 'x');
    file_put_contents($dir . '/tool', "#!/bin/sh\n");
    chmod($dir . '/tool', 0755);

    expect(App\Support\Executable::find('tool', 'Linux', ['PATH' => '/nonexistent:' . $dir]))->toBe($dir . '/tool')
        ->and(App\Support\Executable::find('plain', 'Linux', ['PATH' => $dir]))->toBeNull()
        ->and(App\Support\Executable::find('tool.bat', 'Linux', ['PATH' => $dir]))->toBeNull();
})->skip(PHP_OS_FAMILY === 'Windows', 'needs the executable bit of a POSIX file system');
