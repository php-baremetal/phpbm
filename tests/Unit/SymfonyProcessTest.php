<?php

declare(strict_types=1);

use App\Support\SymfonyProcess;
use App\Support\Paths;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use Tests\Support\Fixtures;

// The child processes are PHP itself (chr(10), not PHP_EOL, so that they print the same bytes on every system).

it('captures exit code, stdout and stderr without throwing', function () {
    $result = (new SymfonyProcess())->run([PHP_BINARY, '-r', "echo 'a', chr(10); fwrite(STDERR, 'b' . chr(10)); exit(3);"]);
    expect($result->exitCode)->toBe(3)
        ->and($result->output)->toBe("a\n")
        ->and($result->errorOutput)->toBe("b\n")
        ->and($result->successful())->toBeFalse();
});

it('forwards both streams line by line when given an output', function () {
    $out = new BufferedOutput();
    $result = (new SymfonyProcess())->run([PHP_BINARY, '-r', "echo 'a', chr(10), 'b'; fwrite(STDERR, 'c' . chr(10));"], null, [], $out);
    expect($out->lines)->toContain('line: a', 'line: b', 'line: c')->toHaveCount(3)
        ->and($result->output)->toBe("a\nb");
});

it('passes cwd and env', function () {
    $dir = Fixtures::tempDir();
    $result = (new SymfonyProcess())->run([PHP_BINARY, '-r', "echo getcwd(), chr(10), getenv('PHPBM_TEST'), chr(10);"], $dir, ['PHPBM_TEST' => 'x']);
    [$cwd, $env] = explode("\n", $result->output) + ['', ''];
    expect(Paths::normalize($cwd))->toBe($dir)->and($env)->toBe('x');
});

it('reports a missing binary as exit 127', function () {
    expect((new SymfonyProcess())->run(['phpbm-no-such-binary'])->exitCode)->toBe(127);
});

it('runs interactive commands and returns their exit code', function () {
    expect((new SymfonyProcess())->tty([PHP_BINARY, '-r', 'exit(3);']))->toBe(3)
        ->and((new SymfonyProcess())->tty([PHP_BINARY, '-r', "exit(getenv('PHPBM_TTY') === 'yes' ? 0 : 1);"], null, ['PHPBM_TTY' => 'yes']))->toBe(0)
        ->and((new SymfonyProcess())->tty([PHP_BINARY, '-r', "exit(getenv('PHPBM_TTY') === 'yes' ? 0 : 1);"]))->toBe(1);
});

it('reports a missing interactive binary as exit 127', function () {
    expect((new SymfonyProcess())->tty(['phpbm-no-such-binary']))->toBe(127);
});

it('reports a missing bare command as 127 on Windows without spawning', function () {
    $result = (new SymfonyProcess('Windows'))->run(['phpbm-no-such-binary', '--version']);
    expect($result->exitCode)->toBe(127)
        ->and($result->errorOutput)->toBe('phpbm-no-such-binary: command not found');
});

it('reports a missing interactive command as 127 on Windows', function () {
    expect((new SymfonyProcess('Windows'))->tty(['phpbm-no-such-binary']))->toBe(127);
});
