<?php

declare(strict_types=1);

namespace App\Images;

/**
 * Reads a PNG into rows of RGBA8 pixels (straight alpha, not premultiplied), in pure PHP with zlib only.
 *
 * Every color type (gray, RGB, palette, gray+alpha, RGBA) at every bit depth the format allows (1, 2, 4, 8, 16;
 * 16-bit samples keep their high byte), tRNS for palettes, gray and RGB, the five filters, Adam7 interlacing and
 * image data split into several IDAT chunks. Every chunk CRC is checked. Width and height must be 1..32767.
 */
final class PngReader
{
    public const MAX_SIZE = 32767;

    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** color type => [channels, allowed bit depths] */
    private const TYPES = [
        0 => [1, [1, 2, 4, 8, 16]],
        2 => [3, [8, 16]],
        3 => [1, [1, 2, 4, 8]],
        4 => [2, [8, 16]],
        6 => [4, [8, 16]],
    ];

    /** Adam7 passes: x start, y start, x step, y step. */
    private const ADAM7 = [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]];

    /** Compressed bytes given to zlib at a time: deflate expands them at most about 1032 times, here 1 MB. */
    private const INFLATE_SLICE = 1024;

    /**
     * Memory the reader and the .sli encoder need, besides the inflated data: RGBA rows (4 bytes per pixel), the
     * .sli (up to 3), PHP string and array overhead (3), plus about 512 bytes per pixel of width for the arrays of
     * one row. Measured at 8 to 14 bytes per pixel in all.
     */
    private const BYTES_PER_PIXEL = 10;
    private const BYTES_PER_COLUMN = 512;

    /**
     * @param int|null $memoryBudget bytes the conversion may use; null: what PHP's memory_limit leaves
     * @return array{w: int, h: int, rows: list<string>} rows: w * 4 bytes of RGBA8 per row, not premultiplied
     * @throws ImageException the message starts with the path
     */
    public static function read(string $path, ?int $memoryBudget = null): array
    {
        if (!file_exists($path)) {
            throw new ImageException("{$path}: file not found");
        }
        if (!is_file($path)) {
            throw new ImageException("{$path}: not a file");
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new ImageException("{$path}: cannot read the file");
        }
        try {
            return self::decode($bytes, $memoryBudget);
        } catch (ImageException $e) {
            throw new ImageException("{$path}: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * The same as read(), from the bytes of a PNG file.
     *
     * @param int|null $memoryBudget bytes the conversion may use; null: what PHP's memory_limit leaves
     * @return array{w: int, h: int, rows: list<string>}
     * @throws ImageException
     */
    public static function decode(string $png, ?int $memoryBudget = null): array
    {
        if (!str_starts_with($png, self::SIGNATURE)) {
            throw new ImageException('not a PNG file (bad signature)');
        }

        $header = null;
        $palette = null;   // list of "RGB" strings
        $trns = null;      // string: the tRNS chunk data
        $idat = '';
        $at = strlen(self::SIGNATURE);
        $end = strlen($png);
        $ended = false;
        while ($at < $end) {
            if ($end - $at < 12) {
                throw new ImageException('file is truncated');
            }
            $len = unpack('N', $png, $at)[1];
            $type = substr($png, $at + 4, 4);
            if (!preg_match('/^[A-Za-z]{4}$/D', $type)) {
                throw new ImageException('bad chunk type');
            }
            if ($len > $end - $at - 12) {
                throw new ImageException("file is truncated in chunk {$type}");
            }
            $data = substr($png, $at + 8, $len);
            if (unpack('N', $png, $at + 8 + $len)[1] !== crc32($type . $data)) {
                throw new ImageException("bad CRC in chunk {$type}");
            }
            $at += 12 + $len;

            if ($header === null && $type !== 'IHDR') {
                throw new ImageException('missing IHDR chunk');
            }
            switch ($type) {
                case 'IHDR':
                    if ($header !== null) {
                        throw new ImageException('IHDR chunk given twice');
                    }
                    $header = self::header($data);
                    break;
                case 'PLTE':
                    if ($len === 0 || $len % 3 !== 0 || $len > 768) {
                        throw new ImageException('PLTE chunk has a bad length');
                    }
                    $palette = str_split($data, 3);
                    break;
                case 'tRNS':
                    $trns = $data;
                    break;
                case 'IDAT':
                    if ($header['type'] === 3 && $palette === null) {
                        throw new ImageException('missing PLTE chunk');
                    }
                    $idat .= $data;
                    break;
                case 'IEND':
                    $ended = true;
                    break 2;
                default:
                    if (ctype_upper($type[0])) {
                        throw new ImageException("unsupported critical chunk {$type}");
                    }
            }
        }
        if (!$ended) {
            throw new ImageException('file is truncated (no IEND chunk)');
        }
        if ($idat === '') {
            throw new ImageException('missing IDAT chunk');
        }

        $pixel = self::pixelReader($header['type'], $header['depth'], $palette, $trns);
        $size = self::rawSize($header);
        self::checkMemory($header, $size, $memoryBudget);
        $raw = self::inflate($idat, $size);
        unset($idat);
        return ['w' => $header['w'], 'h' => $header['h'], 'rows' => self::pixels($header, $raw, $pixel)];
    }

    /** @return array{w: int, h: int, depth: int, type: int, interlace: bool} */
    private static function header(string $data): array
    {
        if (strlen($data) !== 13) {
            throw new ImageException('IHDR chunk has a bad length');
        }
        ['w' => $w, 'h' => $h, 'depth' => $depth, 'type' => $type, 'compression' => $compression,
            'filter' => $filter, 'interlace' => $interlace] = unpack('Nw/Nh/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $data);
        if ($w < 1 || $h < 1 || $w > self::MAX_SIZE || $h > self::MAX_SIZE) {
            throw new ImageException(sprintf('size %dx%d is out of 1..%d', $w, $h, self::MAX_SIZE));
        }
        if (!in_array($depth, self::TYPES[$type][1] ?? [], true)) {
            throw new ImageException("bit depth {$depth} is not valid for color type {$type}");
        }
        if ($compression !== 0) {
            throw new ImageException("unknown compression method {$compression}");
        }
        if ($filter !== 0) {
            throw new ImageException("unknown filter method {$filter}");
        }
        if ($interlace > 1) {
            throw new ImageException("unknown interlace method {$interlace}");
        }
        return ['w' => $w, 'h' => $h, 'depth' => $depth, 'type' => $type, 'interlace' => $interlace === 1];
    }

    /**
     * The passes of the image: [x start, y start, x step, y step, width, height], empty ones left out.
     *
     * @param array{w: int, h: int, interlace: bool} $header
     * @return list<array{int, int, int, int, int, int}>
     */
    private static function passes(array $header): array
    {
        $passes = [];
        foreach ($header['interlace'] ? self::ADAM7 : [[0, 0, 1, 1]] as [$x0, $y0, $dx, $dy]) {
            $pw = $header['w'] > $x0 ? intdiv($header['w'] - $x0 + $dx - 1, $dx) : 0;
            $ph = $header['h'] > $y0 ? intdiv($header['h'] - $y0 + $dy - 1, $dy) : 0;
            if ($pw > 0 && $ph > 0) {
                $passes[] = [$x0, $y0, $dx, $dy, $pw, $ph];
            }
        }
        return $passes;
    }

    /** @param array{w: int, h: int, depth: int, type: int, interlace: bool} $header */
    private static function lineBytes(array $header, int $w): int
    {
        return intdiv($w * self::TYPES[$header['type']][0] * $header['depth'] + 7, 8);
    }

    /** @param array{w: int, h: int, depth: int, type: int, interlace: bool} $header */
    private static function rawSize(array $header): int
    {
        $size = 0;
        foreach (self::passes($header) as [, , , , $pw, $ph]) {
            $size += $ph * (1 + self::lineBytes($header, $pw));
        }
        return $size;
    }

    /**
     * Refuses an image that cannot be converted within the memory PHP has left, before anything large is built.
     *
     * @param array{w: int, h: int, depth: int, type: int, interlace: bool} $header
     */
    private static function checkMemory(array $header, int $rawSize, ?int $budget): void
    {
        $limit = (string) ini_get('memory_limit');
        if ($budget === null) {
            $bytes = self::iniBytes($limit);
            if ($bytes < 0) {
                return;
            }
            $budget = $bytes - memory_get_usage();
        }
        $need = $rawSize + $header['w'] * $header['h'] * self::BYTES_PER_PIXEL + $header['w'] * self::BYTES_PER_COLUMN;
        if ($need > $budget) {
            throw new ImageException(sprintf(
                'size %dx%d is too large to convert: it needs about %d MB and PHP can use %d MB more (memory_limit %s); '
                . 'raise it (php -d memory_limit=%dM) or use a smaller image',
                $header['w'],
                $header['h'],
                intdiv($need + (1 << 20) - 1, 1 << 20),
                intdiv(max(0, $budget), 1 << 20),
                $limit,
                intdiv($need - $budget + max(0, self::iniBytes($limit)), 1 << 20) + 64,
            ));
        }
    }

    /** A php.ini size (128M, 1G, 512K, -1) in bytes; negative: no limit. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || (int) $value < 0) {
            return -1;
        }
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n << 30,
            'm' => $n << 20,
            'k' => $n << 10,
            default => $n,
        };
    }

    /** Inflates the image data a slice at a time and stops as soon as it gets longer than $size (a zip bomb). */
    private static function inflate(string $data, int $size): string
    {
        $z = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($z === false) {
            throw new ImageException('image data is corrupt (zlib)');
        }
        $out = '';
        $n = strlen($data);
        for ($at = 0; $at < $n; $at += self::INFLATE_SLICE) {
            $last = $at + self::INFLATE_SLICE >= $n;
            $part = @inflate_add($z, substr($data, $at, self::INFLATE_SLICE), $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($part === false) {
                throw new ImageException('image data is corrupt (zlib)');
            }
            $out .= $part;
            if (strlen($out) > $size) {
                throw new ImageException('image data is too long');
            }
            if (inflate_get_status($z) === ZLIB_STREAM_END) {
                break;
            }
        }
        if (strlen($out) < $size) {
            throw new ImageException('image data is truncated');
        }
        return $out;
    }

    /**
     * A function from the samples of one pixel (at the image's bit depth) to its RGBA8 bytes.
     *
     * @param list<string>|null $palette
     * @return \Closure(list<int>): string
     */
    private static function pixelReader(int $type, int $depth, ?array $palette, ?string $trns): \Closure
    {
        $to8 = match ($depth) {
            16 => fn (int $v) => $v >> 8,
            8 => fn (int $v) => $v,
            default => fn (int $v) => intdiv($v * 255, (1 << $depth) - 1),
        };
        $key = null;
        // A tRNS in an image with an alpha channel is not allowed; like libpng, it is ignored.
        if ($trns !== null && ($type === 0 || $type === 2)) {
            if (strlen($trns) !== ($type === 0 ? 2 : 6)) {
                throw new ImageException('tRNS chunk has a bad length');
            }
            $key = array_values(unpack('n*', $trns));
        }

        switch ($type) {
            case 0:
                return fn (array $s) => str_repeat(chr($to8($s[0])), 3) . ($s === $key ? "\0" : "\xFF");
            case 2:
                return fn (array $s) => chr($to8($s[0])) . chr($to8($s[1])) . chr($to8($s[2])) . ($s === $key ? "\0" : "\xFF");
            case 4:
                return fn (array $s) => str_repeat(chr($to8($s[0])), 3) . chr($to8($s[1]));
            case 6:
                return fn (array $s) => chr($to8($s[0])) . chr($to8($s[1])) . chr($to8($s[2])) . chr($to8($s[3]));
        }

        if ($trns !== null && strlen($trns) > count($palette)) {
            throw new ImageException('tRNS chunk has a bad length');
        }
        $colors = [];
        foreach ($palette as $i => $rgb) {
            $colors[] = $rgb . ($trns !== null && $i < strlen($trns) ? $trns[$i] : "\xFF");
        }
        $count = count($colors);
        return function (array $s) use ($colors, $count): string {
            if ($s[0] >= $count) {
                throw new ImageException("palette index {$s[0]} is out of range ({$count} colors)");
            }
            return $colors[$s[0]];
        };
    }

    /**
     * Unfilters every pass and places its pixels in the image.
     *
     * @param array{w: int, h: int, depth: int, type: int, interlace: bool} $header
     * @param \Closure(list<int>): string $pixel
     * @return list<string>
     */
    private static function pixels(array $header, string $raw, \Closure $pixel): array
    {
        $channels = self::TYPES[$header['type']][0];
        $depth = $header['depth'];
        $bpp = max(1, intdiv($channels * $depth, 8));
        $rows = array_fill(0, $header['h'], str_repeat("\0", $header['w'] * 4));
        $at = 0;
        $row = 0;
        foreach (self::passes($header) as [$x0, $y0, $dx, $dy, $pw, $ph]) {
            $n = self::lineBytes($header, $pw);
            $prev = str_repeat("\0", $n);
            for ($i = 0; $i < $ph; $i++, $row++) {
                $filter = ord($raw[$at]);
                $line = self::unfilter($filter, substr($raw, $at + 1, $n), $prev, $bpp, $row);
                $at += 1 + $n;
                $prev = $line;

                $samples = self::samples($line, $pw * $channels, $depth);
                $y = $y0 + $i * $dy;
                if ($dx === 1) {
                    $out = '';
                    foreach (array_chunk($samples, $channels) as $s) {
                        $out .= $pixel($s);
                    }
                    $rows[$y] = $out;
                    continue;
                }
                $target = $rows[$y];
                foreach (array_chunk($samples, $channels) as $j => $s) {
                    $target = substr_replace($target, $pixel($s), 4 * ($x0 + $j * $dx), 4);
                }
                $rows[$y] = $target;
            }
        }
        return $rows;
    }

    private static function unfilter(int $type, string $line, string $prev, int $bpp, int $row): string
    {
        $n = strlen($line);
        switch ($type) {
            case 0:
                return $line;
            case 2:
                for ($i = 0; $i < $n; $i++) {
                    $line[$i] = chr((ord($line[$i]) + ord($prev[$i])) & 0xFF);
                }
                return $line;
            case 1:
            case 3:
            case 4:
                for ($i = 0; $i < $n; $i++) {
                    $a = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
                    $b = ord($prev[$i]);
                    if ($type === 1) {
                        $p = $a;
                    } elseif ($type === 3) {
                        $p = ($a + $b) >> 1;
                    } else {
                        $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                        $pp = $a + $b - $c;
                        $pa = abs($pp - $a);
                        $pb = abs($pp - $b);
                        $pc = abs($pp - $c);
                        $p = $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
                    }
                    $line[$i] = chr((ord($line[$i]) + $p) & 0xFF);
                }
                return $line;
        }
        throw new ImageException("unknown filter type {$type} in row {$row}");
    }

    /**
     * The first $count samples of an unfiltered line.
     *
     * @return list<int>
     */
    private static function samples(string $line, int $count, int $depth): array
    {
        if ($depth === 8) {
            return array_values(unpack('C*', $line));
        }
        if ($depth === 16) {
            return array_values(unpack('n*', $line));
        }
        $samples = [];
        $mask = (1 << $depth) - 1;
        $perByte = intdiv(8, $depth);
        for ($k = 0; $k < $count; $k++) {
            $byte = ord($line[intdiv($k, $perByte)]);
            $samples[] = ($byte >> (8 - $depth * ($k % $perByte + 1))) & $mask;
        }
        return $samples;
    }
}
