<?php

declare(strict_types=1);

namespace App\Support;

use App\PhpbmException;

final class Downloader
{
    public function fetch(string $url, string $sha256, string $target): void
    {
        $sha256 = strtolower($sha256);
        if (is_file($target) && hash_file('sha256', $target) === $sha256) {
            return;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new PhpbmException("cannot create {$dir}");
        }

        $part = $target . '.part';
        if (!@copy(Paths::local($url), $part)) {
            @unlink($part);
            throw new PhpbmException("download failed: {$url}");
        }
        $actual = hash_file('sha256', $part);
        if ($actual !== $sha256) {
            @unlink($part);
            throw new PhpbmException("checksum mismatch for {$url}: expected {$sha256}, got {$actual}");
        }
        if (!@rename($part, $target)) {
            @unlink($part);
            throw new PhpbmException("cannot write {$target}");
        }
    }
}
