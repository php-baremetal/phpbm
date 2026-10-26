<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Support\Archive;
use App\Support\ArchiveMembers;
use App\Support\SymfonyProcess;
use Tests\Support\Fixtures;

it('extracts a tarball and returns its single top-level folder', function () {
    $dir = Fixtures::tempDir();
    mkdir($dir . '/pkg/sub', 0777, true);
    file_put_contents($dir . '/pkg/sub/f.txt', 'x');
    Fixtures::tar($dir . '/pkg.tar.gz', $dir, 'pkg');

    $root = (new Archive(new SymfonyProcess()))->extract($dir . '/pkg.tar.gz', $dir . '/out');
    expect($root)->toBe($dir . '/out/pkg')
        ->and(file_get_contents($root . '/sub/f.txt'))->toBe('x');
});

it('returns the target when the archive has several top-level entries', function () {
    $dir = Fixtures::tempDir();
    mkdir($dir . '/src');
    file_put_contents($dir . '/src/a', 'a');
    file_put_contents($dir . '/src/b', 'b');
    Fixtures::tar($dir . '/flat.tar.gz', $dir . '/src', 'a', 'b');

    expect((new Archive(new SymfonyProcess()))->extract($dir . '/flat.tar.gz', $dir . '/out'))->toBe($dir . '/out');
});

it('reports a broken archive', function () {
    $dir = Fixtures::tempDir();
    file_put_contents($dir . '/bad.tar.gz', 'not an archive');
    expect(fn () => (new Archive(new SymfonyProcess()))->extract($dir . '/bad.tar.gz', $dir . '/out'))
        ->toThrow(PhpbmException::class, 'cannot extract ' . $dir . '/bad.tar.gz');
});

it('refuses a target that is not empty', function () {
    $dir = Fixtures::tempDir();
    mkdir($dir . '/pkg');
    file_put_contents($dir . '/pkg/f', 'x');
    Fixtures::tar($dir . '/pkg.tar.gz', $dir, 'pkg');
    mkdir($dir . '/out');
    file_put_contents($dir . '/out/old', 'y');
    expect(fn () => (new Archive(new SymfonyProcess()))->extract($dir . '/pkg.tar.gz', $dir . '/out'))
        ->toThrow(PhpbmException::class, 'is not empty');
});

it('extracts a zip archive', function () {
    $dir = Fixtures::tempDir();
    $zip = new ZipArchive();
    $zip->open($dir . '/pkg.zip', ZipArchive::CREATE);
    $zip->addFromString('pkg/f.txt', 'z');
    $zip->close();

    $root = (new Archive(new SymfonyProcess()))->extract($dir . '/pkg.zip', $dir . '/out');
    expect(file_get_contents($root . '/f.txt'))->toBe('z');
})->skip(!class_exists(ZipArchive::class) || trim((string) shell_exec('command -v unzip')) === '', 'needs ext-zip and unzip');

it('extracts zips with the zip extension even without unzip on the path', function () {
    $dir = Fixtures::tempDir();
    $zip = new ZipArchive();
    $zip->open($dir . '/pkg.ZIP', ZipArchive::CREATE);
    $zip->addFromString('pkg/sub/f.txt', 'z');
    $zip->close();

    $process = new PhpBaremetal\Spec\Testing\FakeProcess();
    $root = (new Archive($process))->extract($dir . '/pkg.ZIP', $dir . '/out');

    expect(file_get_contents($root . '/sub/f.txt'))->toBe('z')
        ->and($process->calls)->toBe([]);
})->skip(!class_exists(ZipArchive::class), 'needs ext-zip');

it('keeps the executable bit of zip entries', function () {
    $dir = Fixtures::tempDir();
    $zip = new ZipArchive();
    $zip->open($dir . '/tool.zip', ZipArchive::CREATE);
    $zip->addFromString('tool/bin/run', "#!/bin/sh\n");
    $zip->setExternalAttributesName('tool/bin/run', ZipArchive::OPSYS_UNIX, 0100755 << 16);
    $zip->close();

    $root = (new Archive(new SymfonyProcess()))->extract($dir . '/tool.zip', $dir . '/out');
    expect(is_executable($root . '/bin/run'))->toBeTrue();
})->skip(!class_exists(ZipArchive::class), 'needs ext-zip')->skipOnWindows();

it('refuses zip entries with ../ segments or absolute paths before extracting', function (string $name) {
    $dir = Fixtures::tempDir();
    $zip = new ZipArchive();
    $zip->open($dir . '/evil.zip', ZipArchive::CREATE);
    $zip->addFromString('ok.txt', 'y');
    $zip->addFromString($name, 'x');
    $zip->close();

    expect(fn () => (new Archive(new SymfonyProcess()))->extract($dir . '/evil.zip', $dir . '/out'))
        ->toThrow(PhpbmException::class, "unsafe member '{$name}'");
    expect(file_exists($dir . '/evil.txt'))->toBeFalse()
        ->and(file_exists($dir . '/out/ok.txt'))->toBeFalse();
})->with(['../evil.txt', 'a/../../evil.txt', '/evil.txt', 'C:/evil.txt', '..\\evil.txt'])->skip(!class_exists(ZipArchive::class), 'needs ext-zip');

it('reports an invalid zip', function () {
    $dir = Fixtures::tempDir();
    file_put_contents($dir . '/bad.zip', 'not a zip');
    expect(fn () => (new Archive(new SymfonyProcess()))->extract($dir . '/bad.zip', $dir . '/out'))
        ->toThrow(PhpbmException::class, 'not a valid zip archive');
})->skip(!class_exists(ZipArchive::class), 'needs ext-zip');

it('restores relative zip symlinks', function () {
    $dir = Fixtures::tempDir();
    $zip = new ZipArchive();
    $zip->open($dir . '/pkg.zip', ZipArchive::CREATE);
    $zip->addFromString('pkg/real.txt', 'r');
    $zip->addFromString('pkg/link', 'real.txt');
    $zip->setExternalAttributesName('pkg/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();

    $root = (new Archive(new SymfonyProcess()))->extract($dir . '/pkg.zip', $dir . '/out');
    expect(is_link($root . '/link'))->toBeTrue()
        ->and(readlink($root . '/link'))->toBe('real.txt');
})->skip(!class_exists(ZipArchive::class), 'needs ext-zip')->skipOnWindows();

it('refuses zip symlinks that leave the target', function (string $target) {
    $dir = Fixtures::tempDir();
    $zip = new ZipArchive();
    $zip->open($dir . '/pkg.zip', ZipArchive::CREATE);
    $zip->addFromString('pkg/real.txt', 'r');
    $zip->addFromString('pkg/evil', $target);
    $zip->setExternalAttributesName('pkg/evil', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();

    expect(fn () => (new Archive(new SymfonyProcess()))->extract($dir . '/pkg.zip', $dir . '/out'))
        ->toThrow(PhpbmException::class, "unsafe member 'pkg/evil' (it links to '{$target}', outside the destination)");
    expect(file_exists($dir . '/out/pkg'))->toBeFalse();
})->with(['../../etc/passwd', '/etc/passwd', 'C:\\Windows'])->skip(!class_exists(ZipArchive::class), 'needs ext-zip');

it('refuses zip members below a symlink of the same archive', function () {
    $dir = Fixtures::tempDir();
    $zip = new ZipArchive();
    $zip->open($dir . '/pkg.zip', ZipArchive::CREATE);
    $zip->addFromString('pkg/up', '..');
    $zip->setExternalAttributesName('pkg/up', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->addFromString('pkg/up/x', 'x');
    $zip->close();

    expect(fn () => (new Archive(new SymfonyProcess()))->extract($dir . '/pkg.zip', $dir . '/out'))
        ->toThrow(PhpbmException::class, "unsafe member 'pkg/up/x' (it is below a link of the same archive)");
})->skip(!class_exists(ZipArchive::class), 'needs ext-zip');

it('reads names and symlinks from the zip central directory without the zip extension', function () {
    $dir = Fixtures::tempDir();
    $zip = new ZipArchive();
    $zip->open($dir . '/pkg.zip', ZipArchive::CREATE);
    $zip->addFromString('pkg/a.txt', str_repeat('a', 1000));
    $zip->addFromString('pkg/link', 'a.txt');
    $zip->setExternalAttributesName('pkg/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->setArchiveComment('comment');
    $zip->close();

    $read = Closure::bind(function (string $archive) {
        $this->archive = $archive;
        return $this->zipCentralDirectory();
    }, (new ReflectionClass(ArchiveMembers::class))->newInstanceWithoutConstructor(), ArchiveMembers::class);
    expect($read($dir . '/pkg.zip'))->toBe([['pkg/a.txt', false], ['pkg/link', true]]);
})->skip(!class_exists(ZipArchive::class), 'needs ext-zip');

/** A raw tar entry: ustar header + data, with the given type flag and link name. */
function tarEntry(string $name, string $data = '', string $type = '0', string $link = ''): string
{
    $header = pack('a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12', $name, $type === '5' ? '0000755' : '0000644', '0000000', '0000000', sprintf('%011o', strlen($data)), sprintf('%011o', 0), str_repeat(' ', 8), $type, $link, 'ustar', '00', 'root', 'root', '', '', '', '');
    $header = substr_replace($header, sprintf('%06o', array_sum(array_map('ord', str_split($header)))) . "\0 ", 148, 8);
    return $header . $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
}

function writeTar(string $path, string ...$entries): string
{
    file_put_contents($path, gzencode(implode('', $entries) . str_repeat("\0", 1024)));
    return $path;
}

it('refuses unsafe tar members before extracting', function (array $entries, string $member, string $reason) {
    $dir = Fixtures::tempDir();
    $archive = writeTar($dir . '/evil.tar.gz', tarEntry('pkg/ok.txt', 'ok'), ...$entries);

    expect(fn () => (new Archive(new SymfonyProcess()))->extract($archive, $dir . '/out'))
        ->toThrow(PhpbmException::class, "cannot extract {$archive}: unsafe member '{$member}' ({$reason})");
    expect(file_exists($dir . '/out/pkg'))->toBeFalse();
})->with([
    'absolute path' => [[tarEntry('/tmp/evil.txt', 'x')], '/tmp/evil.txt', 'absolute path'],
    'dot-dot' => [[tarEntry('pkg/../../evil.txt', 'x')], 'pkg/../../evil.txt', "'..' segment"],
    'gnu long name' => [[tarEntry('././@LongLink', '../' . str_repeat('d', 120) . "\0", 'L'), tarEntry('short', 'x')], '../' . str_repeat('d', 120), "'..' segment"],
    'pax path' => [[tarEntry('PaxHeaders/x', "24 path=../pax-evil.txt\n", 'x'), tarEntry('pkg/x', 'x')], '../pax-evil.txt', "'..' segment"],
    'absolute symlink' => [[tarEntry('pkg/l', '', '2', '/etc')], 'pkg/l', "it links to '/etc', outside the destination"],
    'escaping symlink' => [[tarEntry('pkg/l', '', '2', '../../etc')], 'pkg/l', "it links to '../../etc', outside the destination"],
    'member below a symlink' => [[tarEntry('pkg/l', '', '2', '..'), tarEntry('pkg/l/x', 'x')], 'pkg/l/x', 'it is below a link of the same archive'],
    'escaping hard link' => [[tarEntry('pkg/h', '', '1', '../etc/passwd')], 'pkg/h', "it links to '../etc/passwd', which has a '..' segment"],
    'absolute hard link' => [[tarEntry('pkg/h', '', '1', '/etc/passwd')], 'pkg/h', "it links to the absolute path '/etc/passwd'"],
    'device' => [[tarEntry('pkg/dev', '', '3')], 'pkg/dev', 'device or fifo entry'],
]);

it('extracts tar links that stay inside the target', function () {
    $dir = Fixtures::tempDir();
    $archive = writeTar(
        $dir . '/pkg.tar.gz',
        tarEntry('pkg/', '', '5'),
        tarEntry('pkg/sub/', '', '5'),
        tarEntry('pkg/real.txt', 'r'),
        tarEntry('pkg/sub/up', '', '2', '../real.txt'),
        tarEntry('pkg/hard.txt', '', '1', 'pkg/real.txt'),
        tarEntry('././@LongLink', 'pkg/' . str_repeat('n', 120) . "\0", 'L'),
        tarEntry('pkg/short', 'long'),
    );

    $root = (new Archive(new SymfonyProcess()))->extract($archive, $dir . '/out');
    expect(file_get_contents($root . '/sub/up'))->toBe('r')
        ->and(file_get_contents($root . '/hard.txt'))->toBe('r')
        ->and(file_get_contents($root . '/' . str_repeat('n', 120)))->toBe('long');
})->skipOnWindows();
