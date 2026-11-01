<?php

declare(strict_types=1);

use App\Support\FlashSize;

it('reads flash sizes', function (?string $size, ?int $bytes) {
    expect(FlashSize::bytes($size))->toBe($bytes);
})->with([
    ['4MB', 4 * 1024 * 1024],
    ['16mb', 16 * 1024 * 1024],
    ['512KB', 512 * 1024],
    [' 8 MB ', 8 * 1024 * 1024],
    ['Unknown', null],
    ['', null],
    [null, null],
]);

it('fits unless both sizes are known and the chip is smaller', function (string $board, ?string $chip, bool $fits) {
    expect(FlashSize::fits($board, $chip))->toBe($fits);
})->with([
    ['8MB', '4MB', false],
    ['8MB', '8MB', true],
    ['8MB', '16MB', true],
    ['8MB', null, true],
    ['8MB', 'odd', true],
]);
