<?php

declare(strict_types=1);

namespace App\Build;

use App\PhpbmException;

/** One file holding every image at its flash offset, to be written at offset 0 (by a flasher or an emulator). */
final class MergedImage
{
    private const CHUNK = 65536;

    /**
     * @param array<int,string> $images offset => path
     * @return int the size of the merged file in bytes
     */
    public static function write(array $images, string $target): int
    {
        if ($images === []) {
            throw new PhpbmException('the build produced no image to merge');
        }
        ksort($images);

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new PhpbmException("cannot create {$dir}");
        }
        $tmp = $target . '.part';
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            throw new PhpbmException("cannot write {$tmp}");
        }

        try {
            $at = 0;
            $previous = null;
            foreach ($images as $offset => $path) {
                if ($offset < $at) {
                    throw new PhpbmException(sprintf('%s at 0x%x overlaps %s, which ends at 0x%x', basename($path), $offset, basename((string) $previous), $at));
                }
                self::fill($out, $offset - $at);
                $in = @fopen($path, 'rb');
                if ($in === false) {
                    throw new PhpbmException("cannot read {$path}");
                }
                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                if ($copied === false) {
                    throw new PhpbmException("cannot copy {$path} into {$tmp}");
                }
                $at = $offset + $copied;
                $previous = $path;
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }

        fclose($out);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new PhpbmException("cannot write {$target}");
        }

        return $at;
    }

    /** @param resource $out */
    private static function fill($out, int $bytes): void
    {
        $chunk = str_repeat("\xFF", self::CHUNK);
        while ($bytes > 0) {
            $n = min($bytes, self::CHUNK);
            if (fwrite($out, $n === self::CHUNK ? $chunk : substr($chunk, 0, $n)) !== $n) {
                throw new PhpbmException('cannot write the merged image');
            }
            $bytes -= $n;
        }
    }
}
