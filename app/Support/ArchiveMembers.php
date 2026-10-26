<?php

declare(strict_types=1);

namespace App\Support;

use App\PhpbmException;
use PhpBaremetal\Spec\Sdk\Process;

/**
 * Lists the members of a tar or zip archive before it is extracted and refuses the ones that could write outside
 * the destination: absolute paths, `..` segments, links that leave the destination, members below a link and
 * device or fifo entries.
 */
final class ArchiveMembers
{
    /** @var array<string,true> */
    private array $links = [];

    private function __construct(private readonly string $archive)
    {
    }

    public static function checkTar(string $archive, Process $process, string $tar): void
    {
        $check = new self($archive);
        $head = (string) @file_get_contents($archive, false, null, 0, 6);
        $wrapper = match (true) {
            str_starts_with($head, "\x1f\x8b") => 'compress.zlib://',
            str_starts_with($head, 'BZh') && extension_loaded('bz2') => 'compress.bzip2://',
            !str_starts_with($head, 'BZh') && !str_starts_with($head, "\xfd7zXZ\0") && !str_starts_with($head, "\x28\xb5\x2f\xfd") => '',
            default => null,
        };
        if ($wrapper === null) {
            $check->checkTarListing($process, $tar);
            return;
        }
        $stream = @fopen($wrapper . $archive, 'rb');
        if ($stream === false) {
            throw new PhpbmException("cannot extract {$archive}: cannot read it");
        }
        try {
            $check->scanTar($stream);
        } finally {
            fclose($stream);
        }
    }

    public static function checkZip(string $archive): void
    {
        $check = new self($archive);
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) {
                throw new PhpbmException("cannot extract {$archive}: not a valid zip archive");
            }
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    $symlink = $zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000;
                    $check->member($name, $symlink ? 'symlink' : 'file', $symlink ? (string) $zip->getFromIndex($i) : null);
                }
            } finally {
                $zip->close();
            }
            return;
        }
        foreach ($check->zipCentralDirectory() as [$name, $symlink]) {
            if ($symlink) {
                $check->refuse($name, 'symbolic links in zip archives need the PHP zip extension');
            }
            $check->member($name, 'file', null);
        }
    }

    /** @param resource $stream */
    private function scanTar($stream): void
    {
        $longName = null;
        $longLink = null;
        $pax = [];
        while (true) {
            $header = $this->read($stream, 512);
            if ($header === '' || trim($header, "\0") === '') {
                return;
            }
            if (strlen($header) !== 512) {
                throw new PhpbmException("cannot extract {$this->archive}: truncated tar header");
            }
            $size = $this->tarNumber(substr($header, 124, 12));
            $type = $header[156];
            if (in_array($type, ['L', 'K', 'x', 'g'], true)) {
                $data = $this->read($stream, $size);
                $this->read($stream, (512 - $size % 512) % 512);
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
            if ($kind === null) {
                $this->refuse($name, in_array($type, ['3', '4', '6'], true) ? 'device or fifo entry' : "unsupported tar entry type '{$type}'");
            }
            $this->member($name, $kind, $kind === 'hardlink' || $kind === 'symlink' ? $target : null);
            $this->skip($stream, $kind === 'file' || $type === 'D' ? $size : 0);
        }
    }

    /** Fallback for compressions PHP cannot read (xz, zstd): names from `tar -tf`, and no links at all. */
    private function checkTarListing(Process $process, string $tar): void
    {
        $names = $process->run([$tar, '-tf', $this->archive]);
        $verbose = $process->run([$tar, '-tvf', $this->archive]);
        if (!$names->successful() || !$verbose->successful()) {
            throw new PhpbmException("cannot extract {$this->archive}: " . trim($names->errorOutput . ' ' . $verbose->errorOutput));
        }
        foreach (preg_split('/\R/', trim($verbose->output)) ?: [] as $line) {
            if ($line !== '' && in_array($line[0], ['l', 'h', 'b', 'c', 'p'], true)) {
                $this->refuse(trim($line), 'links and special files are only accepted in .tar, .tar.gz and .tar.bz2 archives');
            }
        }
        foreach (preg_split('/\R/', trim($names->output)) ?: [] as $name) {
            if ($name !== '') {
                $this->member($name, 'file', null);
            }
        }
    }

    private function member(string $name, string $kind, ?string $target): void
    {
        $segments = $this->safeSegments($name, $name);
        for ($i = 1; $i < count($segments); $i++) {
            if (isset($this->links[implode('/', array_slice($segments, 0, $i))])) {
                $this->refuse($name, 'it is below a link of the same archive');
            }
        }
        if ($kind === 'hardlink') {
            $this->safeSegments((string) $target, $name);
            return;
        }
        if ($kind !== 'symlink') {
            return;
        }
        if ($target === null || $target === '' || $this->isAbsolute($target)) {
            $this->refuse($name, "it links to '{$target}', outside the destination");
        }
        $resolved = array_slice($segments, 0, -1);
        foreach (preg_split('#[/\\\\]#', $target) ?: [] as $segment) {
            if ($segment === '..') {
                if ($resolved === []) {
                    $this->refuse($name, "it links to '{$target}', outside the destination");
                }
                array_pop($resolved);
            } elseif ($segment !== '' && $segment !== '.') {
                $resolved[] = $segment;
            }
        }
        $this->links[implode('/', $segments)] = true;
    }

    /** @return list<string> */
    private function safeSegments(string $path, string $member): array
    {
        if ($this->isAbsolute($path)) {
            $this->refuse($member, $path === $member ? 'absolute path' : "it links to the absolute path '{$path}'");
        }
        $segments = [];
        foreach (preg_split('#[/\\\\]#', $path) ?: [] as $segment) {
            if ($segment === '..') {
                $this->refuse($member, $path === $member ? "'..' segment" : "it links to '{$path}', which has a '..' segment");
            }
            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        return $segments;
    }

    private function isAbsolute(string $path): bool
    {
        return preg_match('#^(?:[/\\\\]|[A-Za-z]:)#', $path) === 1;
    }

    private function refuse(string $member, string $reason): never
    {
        throw new PhpbmException("cannot extract {$this->archive}: unsafe member '{$member}' ({$reason})");
    }

    private function tarNumber(string $field): int
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

    /** @param resource $stream */
    private function read($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($stream)) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /** @param resource $stream */
    private function skip($stream, int $size): void
    {
        $remaining = $size + (512 - $size % 512) % 512;
        while ($remaining > 0) {
            $chunk = $this->read($stream, min($remaining, 1 << 20));
            if ($chunk === '') {
                throw new PhpbmException("cannot extract {$this->archive}: truncated tar archive");
            }
            $remaining -= strlen($chunk);
        }
    }

    /** @return list<array{string,bool}> name and "is a unix symlink" of each entry of the zip central directory */
    private function zipCentralDirectory(): array
    {
        $data = (string) @file_get_contents($this->archive);
        $end = strrpos($data, "PK\x05\x06");
        if ($end === false || strlen($data) < $end + 22) {
            throw new PhpbmException("cannot extract {$this->archive}: not a valid zip archive");
        }
        ['count' => $count, 'offset' => $offset] = unpack('vcount/x4/Voffset', substr($data, $end + 10, 10)) ?: ['count' => 0, 'offset' => 0];
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            if (substr($data, $offset, 4) !== "PK\x01\x02") {
                throw new PhpbmException("cannot extract {$this->archive}: not a valid zip archive");
            }
            $entry = unpack('vmade/x22/vname/vextra/vcomment/x4/Vattr', substr($data, $offset + 4, 38));
            if ($entry === false) {
                throw new PhpbmException("cannot extract {$this->archive}: not a valid zip archive");
            }
            $entries[] = [substr($data, $offset + 46, $entry['name']), ($entry['made'] >> 8) === 3 && (($entry['attr'] >> 16) & 0170000) === 0120000];
            $offset += 46 + $entry['name'] + $entry['extra'] + $entry['comment'];
        }
        return $entries;
    }
}
