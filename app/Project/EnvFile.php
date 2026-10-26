<?php

declare(strict_types=1);

namespace App\Project;

use App\PhpbmException;

final class EnvFile
{
    /** @return array<string,string> */
    public static function parse(string $path): array
    {
        if (!is_file($path)) {
            throw new PhpbmException("env file not found: {$path}");
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new PhpbmException("cannot read env file {$path}");
        }

        $values = [];
        foreach ($lines as $index => $line) {
            $lineNo = $index + 1;
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            }
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = preg_replace('/^export\s+/', '', $line, 1);
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                throw new PhpbmException(sprintf('%s:%d: expected KEY=value', $path, $lineNo));
            }
            $values[$m[1]] = self::value($m[2], $path, $lineNo);
        }

        return $values;
    }

    private static function value(string $raw, string $path, int $lineNo): string
    {
        if (str_starts_with($raw, '"')) {
            if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"(.*)$/', $raw, $q)) {
                throw new PhpbmException("{$path}:{$lineNo}: unterminated quote");
            }
            self::assertOnlyComment($q[2], $path, $lineNo);

            return strtr($q[1], ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\t' => "\t"]);
        }

        if (str_starts_with($raw, "'")) {
            if (!preg_match("/^'([^']*)'(.*)$/", $raw, $q)) {
                throw new PhpbmException("{$path}:{$lineNo}: unterminated quote");
            }
            self::assertOnlyComment($q[2], $path, $lineNo);

            return $q[1];
        }

        return trim(preg_replace('/\s+#.*$/', '', $raw));
    }

    private static function assertOnlyComment(string $rest, string $path, int $lineNo): void
    {
        if (!preg_match('/^\s*(#.*)?$/', $rest)) {
            throw new PhpbmException("{$path}:{$lineNo}: unexpected text after the closing quote");
        }
    }
}
