<?php

declare(strict_types=1);

namespace App\Support;

use App\PhpbmException;
use Illuminate\Filesystem\Filesystem;
use PhpBaremetal\Spec\Sdk\Process;

final readonly class Archive
{
    public function __construct(private Process $process, private ?string $osFamily = null)
    {
    }

    public function extract(string $archive, string $dir): string
    {
        if (is_dir($dir) && array_diff(scandir($dir) ?: [], ['.', '..'])) {
            throw new PhpbmException("cannot extract {$archive}: {$dir} is not empty");
        }
        (new Filesystem())->ensureDirectoryExists($dir);

        if (strtolower(pathinfo($archive, PATHINFO_EXTENSION)) === 'zip') {
            ArchiveMembers::checkZip($archive);
            $this->unzip($archive, $dir);
        } else {
            ArchiveMembers::checkTar($archive, $this->process, $this->tar());
            if ($this->windows() && TarReader::wrapper($archive) !== null) {
                $this->untarNatively($archive, $dir);
            } else {
                $result = $this->process->run([$this->tar(), '-xf', $archive, '-C', $dir]);
                if (!$result->successful()) {
                    throw new PhpbmException("cannot extract {$archive}: " . trim($result->errorOutput));
                }
            }
        }

        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        return count($entries) === 1 && is_dir("{$dir}/{$entries[0]}") ? "{$dir}/{$entries[0]}" : $dir;
    }

    private function windows(): bool
    {
        return ($this->osFamily ?? PHP_OS_FAMILY) === 'Windows';
    }

    private function tar(): string
    {
        if (!$this->windows()) {
            return 'tar';
        }
        $system = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe';
        return is_file($system) ? $system : 'tar';
    }

    /**
     * Extracts a tar archive that PHP can read without tar.exe, whose bsdtar skips the names it cannot convert to the
     * console code page (the PHP sources have Japanese ones). Names are written as UTF-8. Links come last: hard links
     * as copies of their target, symbolic links as links or, when Windows refuses them, as copies of the file.
     */
    private function untarNatively(string $archive, string $dir): void
    {
        $reader = TarReader::open($archive);
        $links = [];
        try {
            foreach ($reader->members() as $member) {
                $name = $this->memberPath($member['name']);
                if ($name === '') {
                    continue;
                }
                $path = "{$dir}/{$name}";
                match ($member['kind']) {
                    'dir' => $this->makeDir($archive, $path),
                    'file' => $this->writeFile($archive, $reader, $member, $path),
                    'hardlink', 'symlink' => $links[] = [$member, $name],
                    default => throw new PhpbmException("cannot extract {$archive}: unsupported tar entry '{$member['name']}'"),
                };
            }
        } finally {
            $reader->close();
        }
        foreach ($links as [$member, $name]) {
            $member['kind'] === 'hardlink'
                ? $this->hardLink($archive, $dir, $member, $name)
                : $this->symbolicLink($archive, $dir, $member, $name);
        }
    }

    private function memberPath(string $name): string
    {
        return implode('/', array_filter(preg_split('#[/\\\\]#', $name) ?: [], fn (string $s) => $s !== '' && $s !== '.'));
    }

    private function makeDir(string $archive, string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new PhpbmException("cannot extract {$archive}: cannot create {$path}");
        }
    }

    /** @param array{name:string, type:string, mode:int, mtime:int} $member */
    private function writeFile(string $archive, TarReader $reader, array $member, string $path): void
    {
        if ($member['type'] === 'S') {
            throw new PhpbmException("cannot extract {$archive}: sparse file '{$member['name']}' is not supported");
        }
        $this->makeDir($archive, dirname($path));
        if (is_link($path)) {
            @unlink($path);
        }
        $out = @fopen($path, 'wb');
        if ($out === false) {
            throw new PhpbmException("cannot extract {$archive}: cannot write {$path}");
        }
        try {
            $reader->copyData($out, $path);
        } finally {
            fclose($out);
        }
        if (PHP_OS_FAMILY !== 'Windows' && ($member['mode'] & 0777) !== 0) {
            @chmod($path, $member['mode'] & 0777 & ~umask());
        }
        if ($member['mtime'] > 0) {
            @touch($path, $member['mtime']);
        }
    }

    /** @param array{name:string, target:string} $member */
    private function hardLink(string $archive, string $dir, array $member, string $name): void
    {
        $source = $dir . '/' . $this->memberPath($member['target']);
        $path = "{$dir}/{$name}";
        if (!is_file($source)) {
            throw new PhpbmException("cannot extract {$archive}: hard link '{$member['name']}' points to '{$member['target']}', which is not a file of the archive");
        }
        $this->makeDir($archive, dirname($path));
        if (is_link($path)) {
            @unlink($path);
        }
        if (!@copy($source, $path)) {
            throw new PhpbmException("cannot extract {$archive}: cannot write {$path}");
        }
    }

    /** @param array{name:string, target:string} $member */
    private function symbolicLink(string $archive, string $dir, array $member, string $name): void
    {
        $path = "{$dir}/{$name}";
        $parent = dirname($path);
        $this->makeDir($archive, $parent);
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }
        $target = $member['target'];
        if (PHP_OS_FAMILY === 'Windows') {
            // PHP checks the target relative to the working directory, and Windows wants '\' in relative links.
            $target = str_replace('/', '\\', $target);
            $cwd = getcwd();
            $linked = @chdir($parent) && @symlink($target, $path);
            if ($cwd !== false) {
                @chdir($cwd);
            }
        } else {
            $linked = @symlink($target, $path);
        }
        if ($linked) {
            return;
        }
        $source = $parent . '/' . $member['target'];
        if (!is_file($source) || !@copy($source, $path)) {
            throw new PhpbmException("cannot extract {$archive}: cannot create the symbolic link '{$member['name']}' to '{$member['target']}' (allow symbolic links, e.g. with the Windows developer mode)");
        }
    }

    private function unzip(string $archive, string $dir): void
    {
        if (class_exists(\ZipArchive::class)) {
            $this->unzipNatively($archive, $dir);
            return;
        }
        $stderr = '';
        foreach ([[$this->tar(), '-xf', $archive, '-C', $dir], ['unzip', '-q', $archive, '-d', $dir]] as $command) {
            $result = $this->process->run($command);
            if ($result->successful()) {
                return;
            }
            $stderr = trim($result->errorOutput) ?: $stderr;
        }
        $detail = $stderr !== '' ? " ({$stderr})" : '';
        throw new PhpbmException("cannot extract {$archive}: install the PHP zip extension or unzip{$detail}");
    }

    private function unzipNatively(string $archive, string $dir): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new PhpbmException("cannot extract {$archive}: not a valid zip archive");
        }
        try {
            if (!$zip->extractTo($dir)) {
                throw new PhpbmException("cannot extract {$archive}: not a valid zip archive");
            }
            $this->restoreUnixModes($zip, $dir);
        } finally {
            $zip->close();
        }
    }

    private function restoreUnixModes(\ZipArchive $zip, string $dir): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (!$zip->getExternalAttributesIndex($i, $opsys, $attr) || $opsys !== \ZipArchive::OPSYS_UNIX) {
                continue;
            }
            $name = $zip->getNameIndex($i);
            $path = "{$dir}/{$name}";
            if ($name === false || str_contains($name, '..')) {
                continue;
            }
            $type = ($attr >> 16) & 0170000;
            if ($type === 0120000) {
                $target = $zip->getFromIndex($i);
                if (is_string($target) && $target !== '' && !Paths::isAbsolute($target) && !in_array('..', preg_split('#[/\\\\]#', $target) ?: [], true)) {
                    @unlink($path);
                    @symlink($target, $path);
                }
                continue;
            }
            $mode = ($attr >> 16) & 0777;
            if ($mode !== 0 && (is_file($path) || (str_ends_with($name, '/') && is_dir($path)))) {
                @chmod($path, $mode);
            }
        }
    }
}
