<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Path helpers. phpbm builds paths with '/' as the separator on every system; on Windows, where the OS hands back
 * backslashes (getcwd, realpath), those are normalised to '/' so that every path phpbm prints or compares has one form.
 */
final class Paths
{
    /** On Windows, the path with '/' in place of '\'; elsewhere (where '\' is a valid file name character) unchanged. */
    public static function normalize(string $path, ?string $osFamily = null): string
    {
        return ($osFamily ?? PHP_OS_FAMILY) === 'Windows' ? str_replace('\\', '/', $path) : $path;
    }

    /**
     * On Windows, the path with '\\' in place of '/', for the program of a command line: cmd.exe reads an unquoted
     * '/' as the start of a switch, so C:/x/composer.bat would not run. Elsewhere unchanged.
     */
    public static function native(string $path, ?string $osFamily = null): string
    {
        return ($osFamily ?? PHP_OS_FAMILY) === 'Windows' ? str_replace('/', '\\', $path) : $path;
    }

    /** The working directory, normalised. */
    public static function cwd(): string
    {
        return self::normalize((string) getcwd());
    }

    /** The canonical absolute path of an existing file or directory, normalised; null when it does not exist. */
    public static function real(string $path): ?string
    {
        $real = realpath($path);
        return $real === false ? null : self::normalize($real);
    }

    public static function isAbsolute(string $path): bool
    {
        return preg_match('#^(?:[/\\\\]|[A-Za-z]:[/\\\\])#', $path) === 1;
    }

    public static function resolve(string $base, string $path): string
    {
        return self::isAbsolute($path) ? $path : rtrim($base, '/\\') . '/' . $path;
    }

    /** Turns a file:// url (file:///home/u/a%20b, file:///C:/x, file://localhost/x) into a local path; anything else is returned as is. */
    public static function local(string $url, ?string $osFamily = null): string
    {
        if (preg_match('#^file://(?:localhost)?(/.*)$#i', $url, $m) !== 1) {
            return $url;
        }
        $path = rawurldecode($m[1]);
        return ($osFamily ?? PHP_OS_FAMILY) === 'Windows' && preg_match('#^/[A-Za-z]:[/\\\\]#', $path) === 1 ? substr($path, 1) : $path;
    }
}
