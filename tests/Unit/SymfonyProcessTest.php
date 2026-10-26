<?php

declare(strict_types=1);

use App\Support\SymfonyProcess;
use PhpBaremetal\Spec\Testing\BufferedOutput;

it('captures exit code, stdout and stderr without throwing', function () {
    $result = (new SymfonyProcess())->run(['sh', '-c', 'echo a; echo b >&2; exit 3']);
    expect($result->exitCode)->toBe(3)
        ->and($result->output)->toBe("a\n")
        ->and($result->errorOutput)->toBe("b\n")
        ->and($result->successful())->toBeFalse();
})->skipOnWindows();

it('forwards both streams line by line when given an output', function () {
    $out = new BufferedOutput();
    $result = (new SymfonyProcess())->run(['sh', '-c', 'printf "a\nb"; echo c >&2'], null, [], $out);
    expect($out->lines)->toContain('line: a', 'line: b', 'line: c')->toHaveCount(3)
        ->and($result->output)->toBe("a\nb");
})->skipOnWindows();

it('passes cwd and env', function () {
    $result = (new SymfonyProcess())->run(['sh', '-c', 'pwd; echo $PHPBM_TEST'], '/tmp', ['PHPBM_TEST' => 'x']);
    expect($result->output)->toBe(realpath('/tmp') . "\nx\n");
})->skipOnWindows();

it('reports a missing binary as exit 127', function () {
    expect((new SymfonyProcess())->run(['phpbm-no-such-binary'])->exitCode)->toBe(127);
});

it('runs interactive commands and returns their exit code', function () {
    expect((new SymfonyProcess())->tty(['sh', '-c', 'exit 3']))->toBe(3)
        ->and((new SymfonyProcess())->tty(['sh', '-c', 'test "$PHPBM_TTY" = yes'], null, ['PHPBM_TTY' => 'yes']))->toBe(0);
})->skipOnWindows();

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
