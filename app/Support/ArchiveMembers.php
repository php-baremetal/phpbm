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
        if (TarReader::wrapper($archive) === null) {
            $check->checkTarListing($process, $tar);
            return;
        }
        $reader = TarReader::open($archive);
        try {
            foreach ($reader->members() as $member) {
                $check->tarMember($member);
            }
        } finally {
            $reader->close();
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

    /** @param array{name:string, type:string, kind:?string, target:string} $member */
    private function tarMember(array $member): void
    {
        ['name' => $name, 'type' => $type, 'kind' => $kind] = $member;
        if ($kind === null) {
            $this->refuse($name, in_array($type, ['3', '4', '6'], true) ? 'device or fifo entry' : "unsupported tar entry type '{$type}'");
        }
        $this->member($name, $kind, $kind === 'hardlink' || $kind === 'symlink' ? $member['target'] : null);
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
