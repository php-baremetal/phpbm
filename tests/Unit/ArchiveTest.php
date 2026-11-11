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
})->skip(!class_exists(ZipArchive::class), 'needs ext-zip');

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

/** Every path under $root (relative, sorted) with what it is: dir, link -> target, or the sha256 of the file. */
function treeListing(string $root): array
{
    $listing = [];
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $path => $item) {
        $relative = substr((string) $path, strlen($root) + 1);
        // macOS's tar may write names decomposed (NFD); compare them composed.
        if (class_exists(Normalizer::class)) {
            $relative = Normalizer::normalize($relative, Normalizer::FORM_C) ?: $relative;
        }
        $listing[$relative] = match (true) {
            is_link((string) $path) => 'link -> ' . readlink((string) $path),
            is_dir((string) $path) => 'dir',
            default => hash_file('sha256', (string) $path),
        };
    }
    ksort($listing);
    return $listing;
}

/** The tar entries of a package with a UTF-8 name, a long name, a pax path, nested folders and links. */
function richTarEntries(): array
{
    $long = 'pkg/deep/' . str_repeat('l', 110) . '.txt';
    return [
        tarEntry('pkg/', '', '5'),
        tarEntry('pkg/deep/nested/dirs/', '', '5'),
        tarEntry('pkg/deep/nested/dirs/f.txt', 'nested'),
        tarEntry('pkg/Tuffy私はガラスを食べられます.ttf', 'glass'),
        tarEntry('././@LongLink', $long . "\0", 'L'),
        tarEntry('pkg/deep/short', str_repeat('L', 3000)),
        tarEntry('PaxHeaders/x', ($record = 'path=pkg/pax/経路-' . str_repeat('p', 100) . ".txt\n") ? sprintf('%d %s', strlen($record) + 4, $record) : '', 'x'),
        tarEntry('pkg/pax/placeholder', 'pax'),
        tarEntry('pkg/real.txt', 'r'),
        tarEntry('pkg/deep/up', '', '2', '../real.txt'),
        tarEntry('pkg/hard.txt', '', '1', 'pkg/real.txt'),
    ];
}

it('extracts tar archives natively on Windows, like tar does', function (string $extension, Closure $compress) {
    $dir = Fixtures::tempDir();
    $archive = $dir . '/pkg' . $extension;
    file_put_contents($archive, $compress(implode('', richTarEntries()) . str_repeat("\0", 1024)));

    $process = new PhpBaremetal\Spec\Testing\FakeProcess();
    $root = (new Archive($process, 'Windows'))->extract($archive, $dir . '/native');
    mkdir($dir . '/tar');
    expect((new SymfonyProcess())->run(['tar', '-xf', $archive, '-C', $dir . '/tar'])->successful())->toBeTrue();

    expect($process->calls)->toBe([])
        ->and($root)->toBe($dir . '/native/pkg')
        ->and(treeListing($dir . '/native'))->toBe(treeListing($dir . '/tar'))
        ->and(file_get_contents($root . '/Tuffy私はガラスを食べられます.ttf'))->toBe('glass')
        ->and(file_get_contents($root . '/deep/' . str_repeat('l', 110) . '.txt'))->toBe(str_repeat('L', 3000))
        ->and(file_get_contents($root . '/pax/経路-' . str_repeat('p', 100) . '.txt'))->toBe('pax')
        ->and(file_get_contents($root . '/hard.txt'))->toBe('r')
        ->and(readlink($root . '/deep/up'))->toBe('../real.txt');
})->with([
    'gzip' => ['.tar.gz', fn (string $tar) => gzencode($tar)],
    'bzip2' => ['.tar.bz2', fn (string $tar) => bzcompress($tar)],
    'plain' => ['.tar', fn (string $tar) => $tar],
])->skipOnWindows();

it('extracts a tarball made by tar natively on Windows', function () {
    $dir = Fixtures::tempDir();
    mkdir($dir . '/src/pkg/a/b/c', 0777, true);
    file_put_contents($dir . '/src/pkg/a/b/c/Tuffy私はガラスを食べられます.ttf', random_bytes(70000));
    file_put_contents($dir . '/src/pkg/a/' . str_repeat('x', 150), 'long');
    file_put_contents($dir . '/src/pkg/empty', '');
    mkdir($dir . '/src/pkg/empty-dir');
    Fixtures::tar($dir . '/pkg.tar.gz', $dir . '/src', 'pkg');

    $process = new PhpBaremetal\Spec\Testing\FakeProcess();
    (new Archive($process, 'Windows'))->extract($dir . '/pkg.tar.gz', $dir . '/native');

    expect($process->calls)->toBe([])
        ->and(treeListing($dir . '/native'))->toBe(treeListing($dir . '/src'));
})->skipOnWindows();

it('refuses unsafe tar members on Windows before writing anything', function (array $entries, string $member) {
    $dir = Fixtures::tempDir();
    $archive = writeTar($dir . '/evil.tar.gz', tarEntry('pkg/ok.txt', 'ok'), ...$entries);

    expect(fn () => (new Archive(new PhpBaremetal\Spec\Testing\FakeProcess(), 'Windows'))->extract($archive, $dir . '/out'))
        ->toThrow(PhpbmException::class, "cannot extract {$archive}: unsafe member '{$member}'");
    expect(file_exists($dir . '/out/pkg'))->toBeFalse()
        ->and(file_exists($dir . '/evil.txt'))->toBeFalse();
})->with([
    'dot-dot' => [[tarEntry('pkg/../../evil.txt', 'x')], 'pkg/../../evil.txt'],
    'escaping symlink' => [[tarEntry('pkg/l', '', '2', '../../etc')], 'pkg/l'],
    'member below a symlink' => [[tarEntry('pkg/l', '', '2', '..'), tarEntry('pkg/l/x', 'x')], 'pkg/l/x'],
    'device' => [[tarEntry('pkg/dev', '', '3')], 'pkg/dev'],
]);

it('reports a truncated tar archive on Windows', function () {
    $dir = Fixtures::tempDir();
    $archive = $dir . '/cut.tar.gz';
    file_put_contents($archive, gzencode(substr(tarEntry('pkg/f.txt', str_repeat('x', 2000)), 0, 1024)));

    expect(fn () => (new Archive(new PhpBaremetal\Spec\Testing\FakeProcess(), 'Windows'))->extract($archive, $dir . '/out'))
        ->toThrow(PhpbmException::class, "cannot extract {$archive}: truncated tar archive");
});

it('keeps extracting tar archives with tar outside Windows', function () {
    $dir = Fixtures::tempDir();
    $archive = writeTar($dir . '/pkg.tar.gz', tarEntry('pkg/f.txt', 'f'));
    $process = (new PhpBaremetal\Spec\Testing\FakeProcess())->on('tar -xf', new PhpBaremetal\Spec\Sdk\ProcessResult(0, '', ''));

    (new Archive($process, 'Linux'))->extract($archive, $dir . '/out');

    expect($process->calls)->toBe([['tar', '-xf', $archive, '-C', $dir . '/out']]);
});

it('extracts xz tarballs with tar on Windows', function () {
    $dir = Fixtures::tempDir();
    file_put_contents($dir . '/pkg.tar.xz', "\xfd7zXZ\0rest");
    // On a real Windows host Archive picks %SystemRoot%\System32\tar.exe; elsewhere the 'tar' on the PATH.
    $system = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe';
    $tar = is_file($system) ? $system : 'tar';
    $process = (new PhpBaremetal\Spec\Testing\FakeProcess())->on($tar, new PhpBaremetal\Spec\Sdk\ProcessResult(0, '', ''));

    (new Archive($process, 'Windows'))->extract($dir . '/pkg.tar.xz', $dir . '/out');

    expect(end($process->calls))->toBe([$tar, '-xf', $dir . '/pkg.tar.xz', '-C', $dir . '/out']);
});
