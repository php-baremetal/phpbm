<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Support\Downloader;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->dir = Fixtures::tempDir();
    file_put_contents($this->dir . '/src.bin', 'payload');
    $this->sha = hash('sha256', 'payload');
});

it('downloads and verifies', function () {
    (new Downloader())->fetch($this->dir . '/src.bin', $this->sha, $this->dir . '/out/a.bin');
    expect(file_get_contents($this->dir . '/out/a.bin'))->toBe('payload');
});

it('accepts file urls', function () {
    (new Downloader())->fetch('file://' . $this->dir . '/src.bin', $this->sha, $this->dir . '/b.bin');
    expect(is_file($this->dir . '/b.bin'))->toBeTrue();
})->skipOnWindows();

it('reuses a target that already has the right checksum', function () {
    (new Downloader())->fetch($this->dir . '/src.bin', $this->sha, $this->dir . '/c.bin');
    unlink($this->dir . '/src.bin');
    (new Downloader())->fetch($this->dir . '/src.bin', $this->sha, $this->dir . '/c.bin');
    expect(file_get_contents($this->dir . '/c.bin'))->toBe('payload');
});

it('rejects a checksum mismatch and leaves nothing behind', function () {
    expect(fn () => (new Downloader())->fetch($this->dir . '/src.bin', str_repeat('0', 64), $this->dir . '/d.bin'))
        ->toThrow(PhpbmException::class, 'checksum mismatch for ' . $this->dir . '/src.bin');
    expect(glob($this->dir . '/d.bin*'))->toBe([]);
});

it('reports a failed download', function () {
    expect(fn () => (new Downloader())->fetch($this->dir . '/missing.bin', $this->sha, $this->dir . '/e.bin'))
        ->toThrow(PhpbmException::class, 'download failed: ' . $this->dir . '/missing.bin');
    expect(is_file($this->dir . '/e.bin.part'))->toBeFalse();
});

it('accepts an uppercase checksum', function () {
    (new Downloader())->fetch($this->dir . '/src.bin', strtoupper($this->sha), $this->dir . '/u.bin');
    expect(is_file($this->dir . '/u.bin'))->toBeTrue();
});

it('fails when the target cannot be written', function () {
    mkdir($this->dir . '/taken/x', 0777, true);
    expect(fn () => (new Downloader())->fetch($this->dir . '/src.bin', $this->sha, $this->dir . '/taken'))
        ->toThrow(App\PhpbmException::class, 'cannot write ' . $this->dir . '/taken');
    expect(is_file($this->dir . '/taken.part'))->toBeFalse();
});

it('accepts percent-encoded file urls', function () {
    mkdir($this->dir . '/a b');
    copy($this->dir . '/src.bin', $this->dir . '/a b/src.bin');
    $path = str_replace('\\', '/', $this->dir . '/a b/src.bin');
    (new Downloader())->fetch('file://' . (str_starts_with($path, '/') ? '' : '/') . str_replace(' ', '%20', $path), $this->sha, $this->dir . '/f.bin');
    expect(file_get_contents($this->dir . '/f.bin'))->toBe('payload');
});
