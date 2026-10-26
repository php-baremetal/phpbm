<?php

declare(strict_types=1);

namespace App\Support;

final class Paths
{
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
