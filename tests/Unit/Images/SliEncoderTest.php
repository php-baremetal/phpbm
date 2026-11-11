<?php

declare(strict_types=1);

use App\Images\ImageException;
use App\Images\SliEncoder;
use Tests\Support\ImageFixtures;

/** Bytes from hex pairs separated by spaces. */
function sliHex(string $hex): string
{
    return (string) hex2bin(str_replace([' ', "\n"], '', $hex));
}

/**
 * A whole .sli file: header, row table, data.
 *
 * @param list<int> $table
 */
function sliFile(int $flags, int $w, int $h, array $table, string $dataHex): string
{
    $data = sliHex($dataHex);
    return 'SLI1' . pack('CCvvvV', 1, $flags, $w, $h, 0, strlen($data)) . pack('V*', ...$table) . $data;
}

/** @return array{w: int, h: int, rows: list<string>} */
function sliCase(string $name): array
{
    return ImageFixtures::cases()[$name];
}

function sliEncodeCase(string $name): string
{
    $case = sliCase($name);
    return SliEncoder::encode($case['w'], $case['h'], $case['rows']);
}

const SLI_HAS_ALPHA = 1;
const SLI_OPAQUE = 2;

it('writes the header byte by byte', function () {
    expect(bin2hex(substr(sliEncodeCase('1x1-transparent'), 0, 16)))->toBe('534c4931' . '01' . '01' . '0100' . '0100' . '0000' . '01000000')
        ->and(bin2hex(substr(sliEncodeCase('8x8-spans'), 0, 16)))->toBe('534c4931' . '01' . '01' . '0800' . '0800' . '0000' . '7a000000');
});

it('encodes the small fixtures as worked out by hand', function (string $name, string $expected) {
    expect(bin2hex(sliEncodeCase($name)))->toBe(bin2hex($expected));
})->with([
    // red: one "different" pixel, a padding byte so the color is at an even offset
    '1x1 opaque' => ['1x1-opaque', sliFile(SLI_OPAQUE, 1, 1, [0], '81 00 00f8')],
    '1x1 transparent' => ['1x1-transparent', sliFile(SLI_HAS_ALPHA, 1, 1, [0], '01')],
    // 63 pixels: a 1-byte length; from 64 the byte holds 0 and a u16 follows
    'run of 63' => ['run-63', sliFile(SLI_OPAQUE, 63, 1, [0], '7f 00f8')],
    'run of 64' => ['run-64', sliFile(SLI_OPAQUE, 64, 1, [0], '40 4000 00f8')],
    'run of 70' => ['run-70', sliFile(SLI_OPAQUE, 70, 1, [0], '40 4600 00f8')],
    '8x8 spans' => ['8x8-spans', sliFile(SLI_HAS_ALPHA, 8, 8, [0, 4, 8, 28, 40, 56, 84, 104], '
        08 000000
        48 00f8 00
        88 00 00f8 e007 1f00 ffff 0000 00f8 e007 1f00 0000
        02 c2 1084 1084 80 80 44 00f8 00
        81 00 00f8 44 e007 81 1f00 01 c1 1084 80 00
        c8 00 1084 1084 1084 1084 1084 1084 1084 1084 80 80 80 80 80 80 80 80 0000
        88 00 00f8 00f8 00f8 e007 e007 e007 1f00 1f00 0000
        88 00 2d6b 2c63 2d6b 2c63 2d6b 2c63 2d6b 2c63
    ')],
    'odd width' => ['odd-width', sliFile(SLI_HAS_ALPHA, 5, 3, [0, 12, 28], '
        85 00 00f8 e007 1f00 ffff 0000
        01 c2 1084 1084 80 80 82 00 00f8 e007 0000
        c1 00 1084 80 01 83 00 00f8 00f8 00f8
    ')],
]);

it('premultiplies and dithers semi-transparent pixels', function () {
    // (200, 100, 50) at alpha 100: div255 gives (78, 39, 20), threshold 0 gives r5 9, g6 9, b5 2 = 0x4922.
    expect(bin2hex(SliEncoder::encode(1, 1, [ImageFixtures::p(200, 100, 50, 100)])))
        ->toBe(bin2hex(sliFile(SLI_HAS_ALPHA, 1, 1, [0], 'c1 00 2249 64')));
});

it('anchors the dither at the image origin', function () {
    // (100, 102, 103) over a 4x4 block, with the thresholds th of bayer4 (0, 8, 2, 10 / 12, 4, 14, 6 / 3, 11, 1, 9 /
    // 15, 7, 13, 5): th 0 and 1 give 0x632c, 2..7 give 0x632d, 8..15 give 0x6b4d.
    $rows = array_fill(0, 4, str_repeat(ImageFixtures::p(100, 102, 103), 4));
    $sli = SliEncoder::encode(4, 4, $rows);
    $colors = [];
    for ($y = 0; $y < 4; $y++) {
        $at = 16 + 16 + unpack('V', substr($sli, 16 + 4 * $y, 4))[1];
        $colors[] = array_map(fn ($c) => sprintf('%04x', $c), array_values(unpack('v4', substr($sli, $at + 2, 8))));
    }

    // r5 = (100 + (th >> 1)) >> 3, g6 = (102 + (th >> 2)) >> 2, b5 = (103 + (th >> 1)) >> 3
    expect($colors)->toBe([
        ['632c', '6b4d', '632d', '6b4d'],
        ['6b4d', '632d', '6b4d', '632d'],
        ['632d', '6b4d', '632c', '6b4d'],
        ['6b4d', '632d', '6b4d', '632d'],
    ]);
});

it('gives the committed .sli of every fixture', function (string $name) {
    expect(sliEncodeCase($name) === file_get_contents(ImageFixtures::DIR . "/{$name}.sli"))->toBeTrue();
})->with(array_keys(ImageFixtures::cases()));

it('sets opaque or has_alpha', function (int $alpha, int $flags) {
    $rows = [str_repeat(ImageFixtures::p(10, 20, 30), 3) . ImageFixtures::p(10, 20, 30, $alpha)];

    expect(ord(SliEncoder::encode(4, 1, $rows)[5]))->toBe($flags);
})->with([
    'all opaque' => [255, SLI_OPAQUE],
    'one at 254' => [254, SLI_HAS_ALPHA],
    'one at 1' => [1, SLI_HAS_ALPHA],
    'one transparent' => [0, SLI_HAS_ALPHA],
]);

it('writes the longest row as one span', function () {
    $sli = SliEncoder::encode(32767, 1, [str_repeat(ImageFixtures::p(255, 0, 0), 32767)]);

    expect(bin2hex(substr($sli, 20)))->toBe('40ff7f00f8');
});

it('splits spans longer than 32767 pixels', function () {
    // A row cannot be that wide, but the rule holds for any run: 32767 + 1 pixels give two commands.
    expect(bin2hex(SliEncoder::encodeRow(str_repeat(ImageFixtures::p(255, 0, 0), 32768), 0)))->toBe('40ff7f00f8' . '81' . '00f8')
        ->and(bin2hex(SliEncoder::encodeRow(str_repeat(ImageFixtures::p(0, 0, 0, 0), 32768), 0)))->toBe('00ff7f' . '01');
});

it('refuses sizes and rows that do not fit', function (int $w, int $h, array $rows, string $message) {
    expect(fn () => SliEncoder::encode($w, $h, $rows))->toThrow(ImageException::class, $message);
})->with([
    'zero width' => [0, 1, [''], 'size 0x1 is out of 1..32767'],
    'too wide' => [32768, 1, [str_repeat("\0", 32768 * 4)], 'size 32768x1 is out of 1..32767'],
    'missing row' => [1, 2, ["\0\0\0\0"], '2 rows expected, 1 given'],
    'short row' => [2, 1, ["\0\0\0\0"], 'row 0 has 4 bytes, 8 expected'],
]);
