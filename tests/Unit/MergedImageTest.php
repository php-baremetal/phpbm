<?php

declare(strict_types=1);

use App\Build\MergedImage;
use App\PhpbmException;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->dir = Fixtures::tempDir();
    $this->image = function (string $name, string $bytes): string {
        file_put_contents("{$this->dir}/{$name}", $bytes);
        return "{$this->dir}/{$name}";
    };
});

it('places each image at its offset and fills the gaps with 0xFF', function () {
    $images = [
        0x10 => ($this->image)('app.bin', 'APP'),
        0x0 => ($this->image)('boot.bin', 'BOOT'),
    ];

    $size = MergedImage::write($images, "{$this->dir}/out/merged.bin");

    expect($size)->toBe(0x13)
        ->and(file_get_contents("{$this->dir}/out/merged.bin"))->toBe('BOOT' . str_repeat("\xFF", 12) . 'APP')
        ->and(file_exists("{$this->dir}/out/merged.bin.part"))->toBeFalse();
});

it('starts at offset 0 even when the first image does not', function () {
    MergedImage::write([0x3 => ($this->image)('app.bin', 'A')], "{$this->dir}/merged.bin");

    expect(file_get_contents("{$this->dir}/merged.bin"))->toBe("\xFF\xFF\xFFA");
});

it('fills gaps larger than one chunk', function () {
    $size = MergedImage::write([0x0 => ($this->image)('a', 'A'), 0x30001 => ($this->image)('b', 'B')], "{$this->dir}/merged.bin");

    $bytes = (string) file_get_contents("{$this->dir}/merged.bin");
    expect($size)->toBe(0x30002)
        ->and(strlen($bytes))->toBe(0x30002)
        ->and($bytes[0x30001])->toBe('B')
        ->and(substr_count($bytes, "\xFF"))->toBe(0x30000);
});

it('refuses overlapping images and leaves no file behind', function () {
    $images = [0x0 => ($this->image)('boot.bin', 'BOOT'), 0x2 => ($this->image)('app.bin', 'APP')];

    expect(fn () => MergedImage::write($images, "{$this->dir}/merged.bin"))
        ->toThrow(PhpbmException::class, 'app.bin at 0x2 overlaps boot.bin, which ends at 0x4');
    expect(file_exists("{$this->dir}/merged.bin"))->toBeFalse()
        ->and(file_exists("{$this->dir}/merged.bin.part"))->toBeFalse();
});

it('reports a missing image', function () {
    expect(fn () => MergedImage::write([0x0 => "{$this->dir}/nope.bin"], "{$this->dir}/merged.bin"))
        ->toThrow(PhpbmException::class, "cannot read {$this->dir}/nope.bin");
});
