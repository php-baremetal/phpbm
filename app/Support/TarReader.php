<?php

declare(strict_types=1);

namespace App\Support;

use App\PhpbmException;

/**
 * Walks the headers of a tar archive that PHP can read itself (plain, gzip, and bzip2 with ext-bz2): ustar prefixes,
 * GNU long names and long link names, pax path, linkpath and size records (global pax headers are ignored) and
 * base-256 sizes. The data of each member can be copied out while it is the current one; the rest is skipped.
 */
final class TarReader
{
    /** Bytes of data of the current member that are still unread. */
    private int $data = 0;

    /** Bytes of padding after the data of the current member. */
    private int $padding = 0;

    /** @param resource $stream */
    private function __construct(private $stream, private readonly string $archive)
    {
    }

    /** The stream wrapper that reads $archive as a plain tar, or null when PHP cannot (xz, zstd, bzip2 without ext-bz2). */
    public static function wrapper(string $archive): ?string
    {
        $head = (string) @file_get_contents($archive, false, null, 0, 6);
        return match (true) {
            str_starts_with($head, "\x1f\x8b") => 'compress.zlib://',
            str_starts_with($head, 'BZh') && extension_loaded('bz2') => 'compress.bzip2://',
            !str_starts_with($head, 'BZh') && !str_starts_with($head, "\xfd7zXZ\0") && !str_starts_with($head, "\x28\xb5\x2f\xfd") => '',
            default => null,
        };
    }

    public static function open(string $archive): self
    {
        $wrapper = self::wrapper($archive);
        $stream = $wrapper === null ? false : @fopen($wrapper . $archive, 'rb');
        if ($stream === false) {
            throw new PhpbmException("cannot extract {$archive}: cannot read it");
        }
        return new self($stream, $archive);
    }

    public function close(): void
    {
        fclose($this->stream);
    }

    /**
     * The members of the archive, in order. `kind` is file, dir, hardlink, symlink or null for other entry types
     * (`type` is the raw type flag); `target` is the link name of links.
     *
     * @return \Generator<int, array{name:string, type:string, kind:?string, target:string, size:int, mode:int, mtime:int}>
     */
    public function members(): \Generator
    {
        $longName = null;
        $longLink = null;
        $pax = [];
        while (true) {
            $this->skipData();
            $header = $this->read(512);
            if ($header === '' || trim($header, "\0") === '') {
                return;
            }
            if (strlen($header) !== 512) {
                throw new PhpbmException("cannot extract {$this->archive}: truncated tar header");
            }
            $size = $this->number(substr($header, 124, 12));
            $type = $header[156];
            if (in_array($type, ['L', 'K', 'x', 'g'], true)) {
                $data = $this->read($size);
                $this->read((512 - $size % 512) % 512);
                match ($type) {
                    'L' => $longName = rtrim($data, "\0"),
                    'K' => $longLink = rtrim($data, "\0"),
                    'x' => $pax = $this->paxRecords($data),
                    'g' => null,
                };
                continue;
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            if (substr($header, 257, 6) === "ustar\0" && ($prefix = rtrim(substr($header, 345, 155), "\0")) !== '') {
                $name = "{$prefix}/{$name}";
            }
            $name = $pax['path'] ?? $longName ?? $name;
            $target = $pax['linkpath'] ?? $longLink ?? rtrim(substr($header, 157, 100), "\0");
            if (isset($pax['size']) && ctype_digit($pax['size'])) {
                $size = (int) $pax['size'];
            }
            [$longName, $longLink, $pax] = [null, null, []];

            $kind = match ($type) {
                '0', "\0", '7', 'S' => 'file',
                '5', 'D' => 'dir',
                '1' => 'hardlink',
                '2' => 'symlink',
                default => null,
            };
            $this->data = $kind === 'file' || $type === 'D' ? $size : 0;
            $this->padding = (512 - $this->data % 512) % 512;
            yield [
                'name' => $name,
                'type' => $type,
                'kind' => $kind,
                'target' => $target,
                'size' => $size,
                'mode' => $this->number(substr($header, 100, 8)),
                'mtime' => $this->number(substr($header, 136, 12)),
            ];
        }
    }

    /**
     * Copies the unread data of the current member to $out, in chunks.
     *
     * @param resource $out
     */
    public function copyData($out, string $path): void
    {
        while ($this->data > 0) {
            $chunk = $this->read(min($this->data, 1 << 20));
            if ($chunk === '') {
                throw new PhpbmException("cannot extract {$this->archive}: truncated tar archive");
            }
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw new PhpbmException("cannot extract {$this->archive}: cannot write {$path}");
            }
            $this->data -= strlen($chunk);
        }
    }

    private function skipData(): void
    {
        $remaining = $this->data + $this->padding;
        [$this->data, $this->padding] = [0, 0];
        while ($remaining > 0) {
            $chunk = $this->read(min($remaining, 1 << 20));
            if ($chunk === '') {
                throw new PhpbmException("cannot extract {$this->archive}: truncated tar archive");
            }
            $remaining -= strlen($chunk);
        }
    }

    private function number(string $field): int
    {
        if ((ord($field[0]) & 0x80) !== 0) {
            $value = ord($field[0]) & 0x7f;
            for ($i = 1; $i < strlen($field); $i++) {
                $value = ($value << 8) | ord($field[$i]);
            }
            return $value;
        }
        return (int) octdec(trim($field, " \0") ?: '0');
    }

    /** @return array<string,string> */
    private function paxRecords(string $data): array
    {
        $records = [];
        $offset = 0;
        while ($offset < strlen($data) && preg_match('/\G(\d+) /', $data, $m, 0, $offset) === 1) {
            $record = substr($data, $offset + strlen($m[0]), (int) $m[1] - strlen($m[0]) - 1);
            [$key, $value] = array_pad(explode('=', $record, 2), 2, '');
            $records[$key] = $value;
            $offset += max(1, (int) $m[1]);
        }
        return $records;
    }

    private function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($this->stream)) {
            $chunk = fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }
}
