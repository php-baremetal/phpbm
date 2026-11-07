<?php

declare(strict_types=1);

namespace App\Support;

use App\PhpbmException;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Process;
use Symfony\Component\Process\ExecutableFinder;

/** Runs Composer in a project folder. */
final class Composer
{
    public const NOT_FOUND = 'composer not found: set PHPBM_COMPOSER, install Composer, or use --no-install';

    private ?string $binary;

    public function __construct(private readonly Process $process, ?string $binary = null)
    {
        $env = getenv('PHPBM_COMPOSER');
        $name = $binary ?? (is_string($env) && $env !== '' ? $env : 'composer');
        // A bare name is looked up on the PATH; a value with a directory is used as given.
        $this->binary = preg_match('#[/\\\\]#', $name) === 1 ? $name : (new ExecutableFinder())->find($name);
    }

    public function available(): bool
    {
        return $this->binary !== null && is_file($this->binary) && is_executable($this->binary);
    }

    /**
     * @param list<string> $args
     * @throws PhpbmException `composer <args> failed (exit <code>)`, followed by `: <message>` when Composer
     *         printed an exception (the first line after its `In <file> line <n>:` header)
     */
    public function run(string $dir, array $args, Output $out): void
    {
        if ($this->binary === null) {
            throw new PhpbmException(self::NOT_FOUND);
        }

        $result = $this->process->run([$this->binary, ...$args], $dir, [], $out);
        if ($result->successful()) {
            return;
        }

        $message = 'composer ' . implode(' ', $args) . " failed (exit {$result->exitCode})";
        $reason = self::reason($result->output . "\n" . $result->errorOutput);
        throw new PhpbmException($reason === null ? $message : "{$message}: {$reason}");
    }

    /** The message of the exception Composer printed: the first non-empty line after `In <file> line <n>:`. */
    private static function reason(string $output): ?string
    {
        $lines = preg_split('/\R/', $output) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*In \S.* line \d+:\s*$/', $line) !== 1) {
                continue;
            }
            foreach (array_slice($lines, $i + 1) as $next) {
                $next = trim($next);
                // Skip the blank padding and, with -v, the `[ExceptionClass]` title.
                if ($next !== '' && preg_match('/^\[[\w\\\\]+\]$/', $next) !== 1) {
                    return $next;
                }
            }
            return null;
        }
        return null;
    }
}
