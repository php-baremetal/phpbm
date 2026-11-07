<?php

declare(strict_types=1);

use App\Fonts\CharSet;
use App\PhpbmException;

it('reads chars as literal text, not as a range', function () {
    expect(CharSet::codepoints('0-9:', null))->toBe([0x2D, 0x30, 0x39, 0x3A]);
});

it('reads utf-8 chars, sorted and without duplicates', function () {
    expect(CharSet::codepoints('€aa°', null))->toBe([0x61, 0xB0, 0x20AC]);
});

it('reads comma-separated ranges', function () {
    expect(CharSet::codepoints(null, '0x30-0x39,0x3A-0x3A'))->toBe(range(0x30, 0x3A))
        ->and(CharSet::codepoints(null, ' 0x41 , 0x61-0x62 '))->toBe([0x41, 0x61, 0x62]);
});

it('defaults to printable ascii', function () {
    expect(CharSet::codepoints(null, null))->toBe(range(0x20, 0x7E))
        ->and(CharSet::codepoints('', ''))->toBe(range(0x20, 0x7E));
});

it('groups contiguous codepoints into runs', function () {
    expect(CharSet::runs([0x20, 0x21, 0x22, 0x41, 0x43, 0x44]))->toBe([[0x20, 0x22], [0x41, 0x41], [0x43, 0x44]])
        ->and(CharSet::runs([]))->toBe([]);
});

it('rejects a malformed range', function (string $range) {
    expect(fn () => CharSet::codepoints(null, $range))
        ->toThrow(PhpbmException::class, "invalid range '{$range}': use codepoints like 0x20-0x7E, separated by commas");
})->with(['0x7E-0x20', 'abc', '0x20-', '0x110000']);

it('rejects chars and range together', function () {
    expect(fn () => CharSet::codepoints('a', '0x20-0x7E'))
        ->toThrow(PhpbmException::class, 'use either chars or range, not both');
});

it('rejects chars that are not utf-8', function () {
    expect(fn () => CharSet::codepoints("\xFF", null))
        ->toThrow(PhpbmException::class, 'chars is not valid UTF-8');
});
