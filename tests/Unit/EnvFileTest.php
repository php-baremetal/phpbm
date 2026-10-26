<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Project\EnvFile;
use Tests\Support\Fixtures;

it('parses keys, quotes, comments and export', function () {
    $file = Fixtures::tempDir() . '/.env';
    file_put_contents($file, "# wifi\nSSID=home\nexport PASS=\"se cret\"\n\nEMPTY=\nQ='x'\n");
    expect(EnvFile::parse($file))->toBe(['SSID' => 'home', 'PASS' => 'se cret', 'EMPTY' => '', 'Q' => 'x']);
});

it('points at the bad line', function () {
    $file = Fixtures::tempDir() . '/.env';
    file_put_contents($file, "A=1\nnot valid\n");
    expect(fn () => EnvFile::parse($file))->toThrow(PhpbmException::class, "{$file}:2: expected KEY=value");
});

it('requires the file', function () {
    expect(fn () => EnvFile::parse('/nope/.env'))->toThrow(PhpbmException::class, 'env file not found: /nope/.env');
});

it('handles inline comments, escapes, crlf and export with tabs', function () {
    $file = Fixtures::tempDir() . '/.env';
    file_put_contents($file, "\xEF\xBB\xBFA=1 # note\r\nexport\tB=\"x\\\"y\\nz\" # c\r\nC='lit\\n' \r\nD=a#b\r\n");
    expect(EnvFile::parse($file))->toBe(['A' => '1', 'B' => "x\"y\nz", 'C' => 'lit\\n', 'D' => 'a#b']);
});

it('rejects an unterminated quote', function () {
    $file = Fixtures::tempDir() . '/.env';
    file_put_contents($file, "A=\"open\n");
    expect(fn () => EnvFile::parse($file))->toThrow(PhpbmException::class, "{$file}:1: unterminated quote");
});

it('rejects text after a closing quote', function () {
    $file = Fixtures::tempDir() . '/.env';
    file_put_contents($file, "A=\"x\"y\n");
    expect(fn () => EnvFile::parse($file))->toThrow(PhpbmException::class, "{$file}:1: unexpected text after the closing quote");
});

it('fails on an unreadable file', function () {
    $file = Fixtures::tempDir() . '/.env';
    file_put_contents($file, "A=1\n");
    chmod($file, 0000);
    expect(fn () => EnvFile::parse($file))->toThrow(PhpbmException::class, "cannot read env file {$file}");
    chmod($file, 0644);
})->skip(function_exists('posix_getuid') && posix_getuid() === 0, 'root can read anything')->skipOnWindows();
