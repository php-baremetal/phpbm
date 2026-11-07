<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Finds commands on the PATH the way the system runs them: on Windows only files with a PATHEXT extension
 * (.exe, .bat, .cmd…) can be started, and `composer` means `composer.bat` or `composer.exe`; elsewhere a file
 * must have its executable bit.
 */
final class Executable
{
    private const PATHEXT = '.COM;.EXE;.BAT;.CMD';

    /**
     * The file a bare command name runs: the first PATH directory holding it, trying each PATHEXT extension in
     * order on Windows (unless the name already has one). Null when there is none.
     *
     * @param array<string,string>|null $env PATH and PATHEXT (default: the real environment)
     */
    public static function find(string $name, ?string $osFamily = null, ?array $env = null): ?string
    {
        $windows = ($osFamily ?? PHP_OS_FAMILY) === 'Windows';
        $path = $env === null ? getenv('PATH') : ($env['PATH'] ?? '');
        $candidates = [$name];
        if ($windows && !self::hasExtension($name, self::extensions($env))) {
            $candidates = array_map(static fn (string $ext): string => $name . strtolower($ext), self::extensions($env));
        }
        foreach (explode($windows ? ';' : ':', is_string($path) ? $path : '') as $dir) {
            $dir = trim($dir, " \t\"");
            if ($dir === '') {
                continue;
            }
            foreach ($candidates as $candidate) {
                $file = Paths::normalize(rtrim($dir, $windows ? '/\\' : '/'), $osFamily) . '/' . $candidate;
                if (is_file($file) && self::runnable($file, $osFamily, $env)) {
                    return $file;
                }
            }
        }
        return null;
    }

    /**
     * Whether the system can start the file: on Windows by its extension, as Windows decides (is_executable()
     * is no guide for .bat and .cmd files there), elsewhere by its executable bit.
     *
     * @param array<string,string>|null $env PATHEXT (default: the real environment)
     */
    public static function runnable(string $file, ?string $osFamily = null, ?array $env = null): bool
    {
        if (($osFamily ?? PHP_OS_FAMILY) === 'Windows') {
            return self::hasExtension($file, self::extensions($env));
        }
        return is_executable($file);
    }

    /** @param list<string> $extensions */
    private static function hasExtension(string $name, array $extensions): bool
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        return $ext !== '' && in_array('.' . strtolower($ext), array_map('strtolower', $extensions), true);
    }

    /**
     * @param array<string,string>|null $env
     * @return list<string>
     */
    private static function extensions(?array $env): array
    {
        $value = $env === null ? getenv('PATHEXT') : ($env['PATHEXT'] ?? '');
        $value = is_string($value) && trim($value) !== '' ? $value : self::PATHEXT;
        return array_values(array_filter(array_map('trim', explode(';', $value)), static fn (string $e): bool => str_starts_with($e, '.') && strlen($e) > 1));
    }
}
