<?php

declare(strict_types=1);

use App\Images\ImageException;
use App\Images\PngReader;
use Tests\Support\Fixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\PngWriter;

/**
 * A w x h image of varied samples at $depth with $channels channels, the same for every format of a test.
 *
 * @return list<list<list<int>>>
 */
function pngSamples(int $w, int $h, int $channels, int $depth): array
{
    $max = (1 << $depth) - 1;
    $pixels = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            for ($c = 0; $c < $channels; $c++) {
                $pixels[$y][$x][$c] = (($x * 37 + $y * 101 + $c * 59) * ($depth === 16 ? 263 : 1)) % ($max + 1);
            }
        }
    }
    return $pixels;
}

/** @return array{w: int, h: int, rows: list<string>} */
function pngDecode(string $png): array
{
    return PngReader::decode($png);
}

function pngScale(int $v, int $depth): int
{
    return $depth === 16 ? $v >> 8 : intdiv($v * 255, (1 << $depth) - 1);
}

/**
 * The RGBA8 rows PngReader must give for samples of a gray, gray+alpha, RGB or RGBA image.
 *
 * @param list<list<list<int>>> $pixels
 * @param list<int>|null $trns
 * @return list<string>
 */
function pngExpected(array $pixels, int $type, int $depth, ?array $trns = null): array
{
    $rows = [];
    foreach ($pixels as $row) {
        $out = '';
        foreach ($row as $s) {
            $v = array_map(fn ($x) => pngScale($x, $depth), $s);
            $out .= match ($type) {
                PngWriter::GRAY => ImageFixtures::p($v[0], $v[0], $v[0], $trns === $s ? 0 : 255),
                PngWriter::RGB => ImageFixtures::p($v[0], $v[1], $v[2], $trns === $s ? 0 : 255),
                PngWriter::GRAY_ALPHA => ImageFixtures::p($v[0], $v[0], $v[0], $v[1]),
                PngWriter::RGBA => ImageFixtures::p($v[0], $v[1], $v[2], $v[3]),
            };
        }
        $rows[] = $out;
    }
    return $rows;
}

dataset('formats', [
    'gray 1' => [PngWriter::GRAY, 1],
    'gray 2' => [PngWriter::GRAY, 2],
    'gray 4' => [PngWriter::GRAY, 4],
    'gray 8' => [PngWriter::GRAY, 8],
    'gray 16' => [PngWriter::GRAY, 16],
    'rgb 8' => [PngWriter::RGB, 8],
    'rgb 16' => [PngWriter::RGB, 16],
    'gray+alpha 8' => [PngWriter::GRAY_ALPHA, 8],
    'gray+alpha 16' => [PngWriter::GRAY_ALPHA, 16],
    'rgba 8' => [PngWriter::RGBA, 8],
    'rgba 16' => [PngWriter::RGBA, 16],
]);

dataset('layouts', [
    'filter 0' => [['filter' => 0]],
    'filter 1' => [['filter' => 1]],
    'filter 2' => [['filter' => 2]],
    'filter 3' => [['filter' => 3]],
    'filter 4' => [['filter' => 4]],
    'adam7, every filter, split IDAT' => [['filter' => 'cycle', 'interlace' => true, 'idat' => 7]],
]);

it('reads every color type and bit depth with every filter and Adam7', function (int $type, int $depth, array $layout) {
    $channels = [PngWriter::GRAY => 1, PngWriter::RGB => 3, PngWriter::GRAY_ALPHA => 2, PngWriter::RGBA => 4][$type];
    $pixels = pngSamples(11, 9, $channels, $depth);

    expect(pngDecode(PngWriter::encode(11, 9, $type, $depth, $pixels, $layout)))
        ->toBe(['w' => 11, 'h' => 9, 'rows' => pngExpected($pixels, $type, $depth)]);
})->with('formats')->with('layouts');

it('reads palettes of 1, 2, 4 and 8 bits, with and without tRNS', function (int $depth, bool $interlace) {
    $n = min(1 << $depth, 200);
    $palette = array_map(fn ($i) => [($i * 41) & 255, ($i * 97) & 255, ($i * 13) & 255], range(0, $n - 1));
    $trns = array_map(fn ($i) => ($i * 85) & 255, range(0, max(0, intdiv($n, 2) - 1)));
    $pixels = [];
    $plain = $alpha = [];
    for ($y = 0; $y < 7; $y++) {
        $plain[$y] = $alpha[$y] = '';
        for ($x = 0; $x < 13; $x++) {
            $i = ($x * 5 + $y * 3) % $n;
            $pixels[$y][$x] = [$i];
            $plain[$y] .= ImageFixtures::p(...$palette[$i]);
            $alpha[$y] .= ImageFixtures::p(...$palette[$i], a: $trns[$i] ?? 255);
        }
    }
    $options = ['palette' => $palette, 'filter' => 'cycle', 'interlace' => $interlace];

    expect(pngDecode(PngWriter::encode(13, 7, PngWriter::PALETTE, $depth, $pixels, $options))['rows'])->toBe($plain)
        ->and(pngDecode(PngWriter::encode(13, 7, PngWriter::PALETTE, $depth, $pixels, $options + ['trns' => $trns]))['rows'])->toBe($alpha);
})->with([1, 2, 4, 8])->with(['progressive' => false, 'adam7' => true]);

it('makes the tRNS color of gray and RGB images transparent', function (int $type, int $depth) {
    $channels = $type === PngWriter::GRAY ? 1 : 3;
    $pixels = pngSamples(9, 5, $channels, $depth);
    $key = $pixels[2][3];

    $image = pngDecode(PngWriter::encode(9, 5, $type, $depth, $pixels, ['trns' => $key]));

    expect($image['rows'])->toBe(pngExpected($pixels, $type, $depth, $key))
        ->and(ord($image['rows'][2][3 * 4 + 3]))->toBe(0);
})->with([
    'gray 1' => [PngWriter::GRAY, 1],
    'gray 4' => [PngWriter::GRAY, 4],
    'gray 8' => [PngWriter::GRAY, 8],
    'gray 16' => [PngWriter::GRAY, 16],
    'rgb 8' => [PngWriter::RGB, 8],
    'rgb 16' => [PngWriter::RGB, 16],
]);

it('compares a 16-bit tRNS color on all 16 bits', function () {
    // Same high bytes, different low bytes: only the exact sample is transparent.
    $pixels = [[[0x1234], [0x1235]]];

    expect(pngDecode(PngWriter::encode(2, 1, PngWriter::GRAY, 16, $pixels, ['trns' => [0x1234]]))['rows'])
        ->toBe([ImageFixtures::p(0x12, 0x12, 0x12, 0) . ImageFixtures::p(0x12, 0x12, 0x12)]);
});

it('skips ancillary chunks', function () {
    $png = PngWriter::rgba(1, 1, [ImageFixtures::p(1, 2, 3, 4)], ['ancillary' => [['tEXt', "Comment\0hi"], ['gAMA', pack('N', 45455)]]]);

    expect(pngDecode($png)['rows'])->toBe([ImageFixtures::p(1, 2, 3, 4)]);
});

it('reads a file', function () {
    $path = Fixtures::tempDir() . '/a.png';
    file_put_contents($path, PngWriter::rgba(2, 1, [ImageFixtures::p(1, 2, 3, 4) . ImageFixtures::p(5, 6, 7, 8)]));

    expect(PngReader::read($path))->toBe(['w' => 2, 'h' => 1, 'rows' => [ImageFixtures::p(1, 2, 3, 4) . ImageFixtures::p(5, 6, 7, 8)]]);
});

it('reads the fixtures as the pixels they were made from', function (string $name) {
    $case = ImageFixtures::cases()[$name];

    expect(PngReader::read(ImageFixtures::DIR . "/{$name}.png"))->toBe(['w' => $case['w'], 'h' => $case['h'], 'rows' => $case['rows']]);
})->with(array_keys(ImageFixtures::cases()));

/** Replaces the first chunk of $type (data and CRC) with $data; $crc false keeps the old CRC. */
function pngPatchChunk(string $png, string $type, string $data, bool $crc = true): string
{
    $at = strpos($png, $type) - 4;
    $len = unpack('N', substr($png, $at, 4))[1];
    $old = substr($png, $at, 12 + $len);
    $new = pack('N', strlen($data)) . $type . $data . ($crc ? pack('N', crc32($type . $data)) : substr($old, -4));
    return substr_replace($png, $new, $at, strlen($old));
}

function pngIhdr(int $w, int $h, int $depth = 8, int $type = 6, int $compression = 0, int $filter = 0, int $interlace = 0): string
{
    return pack('NNCCCCC', $w, $h, $depth, $type, $compression, $filter, $interlace);
}

it('refuses broken files with a clear message', function (Closure $break, string $message) {
    $png = PngWriter::rgba(3, 2, [str_repeat(ImageFixtures::p(9, 8, 7), 3), str_repeat(ImageFixtures::p(1, 2, 3), 3)]);

    expect(fn () => pngDecode($break($png)))->toThrow(ImageException::class, $message);
})->with([
    'bad signature' => [fn ($png) => 'GIF89a' . substr($png, 6), 'not a PNG file (bad signature)'],
    'empty' => [fn ($png) => '', 'not a PNG file (bad signature)'],
    'bad CRC' => [fn ($png) => substr_replace($png, chr(ord($png[strlen($png) - 13]) ^ 1), strlen($png) - 13, 1), 'bad CRC in chunk IDAT'],
    'truncated in a chunk' => [fn ($png) => substr($png, 0, 40), 'file is truncated'],
    'truncated before IEND' => [fn ($png) => substr($png, 0, -12), 'file is truncated (no IEND chunk)'],
    'missing IHDR' => [fn ($png) => substr($png, 0, 8) . substr($png, 8 + 25), 'missing IHDR chunk'],
    'missing IDAT' => [fn ($png) => substr($png, 0, 8 + 25) . PngWriter::chunk('IEND', ''), 'missing IDAT chunk'],
    'missing PLTE' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(3, 2, 8, 3)), 'missing PLTE chunk'],
    'bad depth' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(3, 2, 4, 6)), 'bit depth 4 is not valid for color type 6'],
    'bad color type' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(3, 2, 8, 5)), 'bit depth 8 is not valid for color type 5'],
    'bad compression' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(3, 2, 8, 6, 1)), 'unknown compression method 1'],
    'bad filter method' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(3, 2, 8, 6, 0, 1)), 'unknown filter method 1'],
    'bad interlace' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(3, 2, 8, 6, 0, 0, 2)), 'unknown interlace method 2'],
    'bad IHDR length' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(3, 2) . "\0"), 'IHDR chunk has a bad length'],
    'zero size' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(0, 0)), 'size 0x0 is out of 1..32767'],
    'too tall' => [fn ($png) => pngPatchChunk($png, 'IHDR', pngIhdr(3, 32768)), 'size 3x32768 is out of 1..32767'],
    'corrupt data' => [fn ($png) => pngPatchChunk($png, 'IDAT', 'not zlib at all'), 'image data is corrupt'],
    'short data' => [fn ($png) => pngPatchChunk($png, 'IDAT', gzcompress("\0" . str_repeat('x', 12))), 'image data is truncated'],
    'bad filter type' => [fn ($png) => pngPatchChunk($png, 'IDAT', gzcompress(str_repeat("\x05" . str_repeat('x', 12), 2))), 'unknown filter type 5 in row 0'],
    'unknown critical chunk' => [fn ($png) => substr($png, 0, -12) . PngWriter::chunk('ABCD', '') . PngWriter::chunk('IEND', ''), 'unsupported critical chunk ABCD'],
]);

it('refuses a palette index past the palette', function () {
    $png = PngWriter::encode(2, 1, PngWriter::PALETTE, 8, [[[0], [2]]], ['palette' => [[1, 2, 3], [4, 5, 6]]]);

    expect(fn () => pngDecode($png))->toThrow(ImageException::class, 'palette index 2 is out of range (2 colors)');
});

it('refuses a tRNS with more entries than the palette', function () {
    $png = PngWriter::encode(1, 1, PngWriter::PALETTE, 8, [[[0]]], ['palette' => [[1, 2, 3]], 'trns' => [0, 0]]);

    expect(fn () => pngDecode($png))->toThrow(ImageException::class, 'tRNS chunk has a bad length');
});

it('refuses images wider than 32767 pixels', function () {
    expect(fn () => pngDecode(ImageFixtures::rejected()['wide-40000']))->toThrow(ImageException::class, 'size 40000x1 is out of 1..32767')
        ->and(fn () => PngReader::read(ImageFixtures::DIR . '/wide-40000.png'))->toThrow(ImageException::class, 'size 40000x1 is out of 1..32767');
});

it('names the file in the messages of read()', function () {
    $dir = Fixtures::tempDir();
    file_put_contents("{$dir}/bad.png", 'nope');

    expect(fn () => PngReader::read("{$dir}/bad.png"))->toThrow(ImageException::class, "{$dir}/bad.png: not a PNG file (bad signature)")
        ->and(fn () => PngReader::read("{$dir}/none.png"))->toThrow(ImageException::class, "{$dir}/none.png: file not found");
});

it('stops a decompression bomb as soon as the data passes the image size', function () {
    // The data of an 8000x8000 1-bit image (8 MB inflated, a few KB compressed) behind a 100x100 header.
    $png = PngWriter::encode(100, 100, PngWriter::GRAY, 1, array_fill(0, 100, array_fill(0, 100, [0])));
    $bomb = pngPatchChunk($png, 'IDAT', gzcompress(str_repeat("\0" . str_repeat("\0", 1000), 8000), 9));
    $before = memory_get_usage();
    memory_reset_peak_usage();

    expect(fn () => pngDecode($bomb))->toThrow(ImageException::class, 'image data is too long')
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(4 << 20);
});

it('refuses an 8000x8000 1-bit image that needs more memory than PHP allows', function () {
    $old = ini_set('memory_limit', '128M');
    try {
        $png = PngWriter::encode(8000, 8000, PngWriter::GRAY, 1, [], ['zdata' => gzcompress(str_repeat("\0" . str_repeat("\0", 1000), 8000), 9)]);

        expect(strlen($png))->toBeLessThan(16 << 10)
            ->and(fn () => pngDecode($png))->toThrow(ImageException::class, 'size 8000x8000 is too large to convert: it needs about 622 MB');
    } finally {
        ini_set('memory_limit', $old);
    }
});

it('checks the size against the memory budget it is given', function () {
    $png = PngWriter::rgba(100, 100, array_fill(0, 100, str_repeat(ImageFixtures::p(1, 2, 3), 100)));

    expect(fn () => PngReader::decode($png, 100_000))
        ->toThrow(ImageException::class, 'size 100x100 is too large to convert: it needs about 1 MB and PHP can use 0 MB more (memory_limit')
        ->and(PngReader::decode($png, 2_000_000)['w'])->toBe(100);
});

it('works with no memory limit', function () {
    $old = ini_set('memory_limit', '-1');
    try {
        expect(pngDecode(PngWriter::rgba(1, 1, [ImageFixtures::p(1, 2, 3)]))['w'])->toBe(1);
    } finally {
        ini_set('memory_limit', $old);
    }
});

it('says when the path is not a file', function () {
    $dir = Fixtures::tempDir();

    expect(fn () => PngReader::read($dir))->toThrow(ImageException::class, "{$dir}: not a file");
});
