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
            $result = $this->process->run([$this->tar(), '-xf', $archive, '-C', $dir]);
            if (!$result->successful()) {
                throw new PhpbmException("cannot extract {$archive}: " . trim($result->errorOutput));
            }
        }

        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        return count($entries) === 1 && is_dir("{$dir}/{$entries[0]}") ? "{$dir}/{$entries[0]}" : $dir;
    }

    private function tar(): string
    {
        if (($this->osFamily ?? PHP_OS_FAMILY) !== 'Windows') {
            return 'tar';
        }
        $system = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe';
        return is_file($system) ? $system : 'tar';
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
