<?php

declare(strict_types=1);

namespace App\Support;

use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process as SymfonyProc;

final class SymfonyProcess implements Process
{
    private bool $windows;

    public function __construct(?string $osFamily = null)
    {
        $this->windows = ($osFamily ?? PHP_OS_FAMILY) === 'Windows';
    }

    public function run(array $command, ?string $cwd = null, array $env = [], ?Output $out = null): ProcessResult
    {
        $command = $this->program($command);
        if ($this->windows && $this->bareCommand($command) && $this->find($command[0]) === null) {
            return new ProcessResult(127, '', "{$command[0]}: command not found");
        }
        $process = new SymfonyProc($command, $cwd, $env === [] ? null : $env, null, null);
        $pending = [SymfonyProc::OUT => '', SymfonyProc::ERR => ''];
        $forward = $out === null ? null : static function (string $type, string $data) use ($out, &$pending): void {
            $pending[$type] .= $data;
            while (($end = strpos($pending[$type], "\n")) !== false) {
                $line = rtrim(substr($pending[$type], 0, $end), "\r");
                $pending[$type] = substr($pending[$type], $end + 1);
                if ($line !== '') {
                    $out->line($line);
                }
            }
        };

        try {
            $process->run($forward);
        } catch (RuntimeException $e) {
            return new ProcessResult(127, '', $e->getMessage());
        }
        if ($out !== null) {
            foreach ($pending as $rest) {
                if (trim($rest) !== '') {
                    $out->line(rtrim($rest));
                }
            }
        }

        $code = $process->getExitCode() ?? 1;
        if ($this->windows && $code === 9009) {
            $code = 127;
        }

        return new ProcessResult($code, $process->getOutput(), $process->getErrorOutput());
    }

    public function tty(array $command, ?string $cwd = null, array $env = []): int
    {
        $command = $this->program($command);
        if ($this->windows && $this->bareCommand($command)) {
            $found = $this->find($command[0]);
            if ($found === null) {
                return 127;
            }
            if (preg_match('/\.(bat|cmd)$/i', $found) === 1) {
                $command = [getenv('ComSpec') ?: 'cmd.exe', '/c', ...$command];
            }
        }
        if (!defined('STDIN') || !defined('STDOUT') || !defined('STDERR')) {
            return $this->run($command, $cwd, $env)->exitCode;
        }
        $environment = null;
        if ($env !== []) {
            $base = $this->windows ? array_diff_ukey(getenv(), $env, 'strcasecmp') : getenv();
            $environment = [...$base, ...$env];
        }
        $process = @proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            return 127;
        }
        $code = proc_close($process);
        return $code === -1 ? 127 : $code;
    }

    /**
     * The command with its program path in the system's own form (backslashes on Windows, see Paths::native()).
     * @param list<string> $command
     * @return list<string>
     */
    public function program(array $command): array
    {
        if ($command !== [] && $this->windows) {
            $command[0] = Paths::native($command[0], 'Windows');
        }
        return $command;
    }

    /** @param list<string> $command */
    private function bareCommand(array $command): bool
    {
        return $command !== [] && preg_match('#[/\\\\:]#', $command[0]) !== 1;
    }

    private function find(string $name): ?string
    {
        return (new ExecutableFinder())->find($name);
    }
}
