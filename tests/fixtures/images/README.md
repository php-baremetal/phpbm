# PNG and .sli fixtures

The reference corpus of the PNG reader and the `.sli` encoder of phpbm (`app/Images`). Each `<name>.png` has the
`<name>.sli` that `phpbm image:convert` must write for it, byte for byte. Any other `.sli` encoder (the PNG
decoder of ext-slate) must give the same bytes from the same PNG.

## How they are made

- `tests/Support/PngWriter.php` writes PNGs in pure PHP (zlib only): every color type and bit depth, palettes
  with `tRNS`, a chosen filter per row, Adam7, IDAT split into several chunks.
- `tests/Support/ImageFixtures.php` defines each case: its PNG options and the RGBA8 pixels it holds, worked
  out without the PNG reader.
- `regenerate.php` writes every PNG and its `.sli` (from `App\Images\SliEncoder`) again:

  ```bash
  php tests/fixtures/images/regenerate.php     # from the phpbm folder
  ```

  Regenerate only when a case changes. zlib may compress differently from one version to another, so a PNG can
  change bytes while holding the same pixels; the tests compare the pixels of the PNGs, and the `.sli` byte by byte.

The tests (`tests/Unit/Images`) check that the reader gives each case's pixels, that the encoder gives each
committed `.sli`, and, for the small cases, the `.sli` bytes written out by hand in `SliEncoderTest.php`.

## The cases

| Name | PNG | What it covers |
| --- | --- | --- |
| `1x1-opaque` | RGB 8 | red: one "different" pixel after a padding byte |
| `1x1-transparent` | RGBA 8 | one empty pixel, `has_alpha` |
| `8x8-spans` | RGBA 8, every filter | every kind of command; row 7 is gray 100, which the dither turns into two colors |
| `run-63`, `run-64`, `run-70` | RGBA 8 | one repeated color: 1-byte count at 63, 2-byte count from 64 |
| `odd-width` | RGBA 8, 5×3 | rows that end off a 4-byte boundary, colors after odd offsets |
| `icon-48` | RGBA 8, Paeth | a disk with antialiased edges (4×4 samples per pixel) |
| `gradient-256x16` | RGBA 8, every filter | opaque gradients: the dither |
| `palette-1bit-trns`, `palette-4bit-trns`, `palette-8bit-trns` | palette 1/4/8, `tRNS` | palettes, partial `tRNS` |
| `gray-alpha` | gray+alpha 8 | gray with alpha |
| `rgba-16bit` | RGBA 16 | 16-bit channels, high byte kept |
| `adam7` | RGBA 8, Adam7, IDAT of 50 bytes | interlacing, split IDAT |
| `wide-40000` | gray 1, 40000×1 | no `.sli`: phpbm stops with `size 40000x1 is out of 1..32767` |

## The rule, worked out on the small cases

Header (16 bytes, little-endian): `SLI1`, version `01`, flags (`01` has_alpha, `02` opaque), width u16, height
u16, `0000`, data size u32. Then one u32 per row (offset from the start of the data, a multiple of 4), then the
data. Command byte: bits 7..6 the kind (`0` empty, `1` repeated, `2` different, `3` semi-transparent), bits 5..0
the count, or `0` and a u16 count from 64. Colors are RGB565, little-endian.

Pixel (x, y), threshold `th = bayer4[y & 3][x & 3]` with bayer4 `{0,8,2,10},{12,4,14,6},{3,11,1,9},{15,7,13,5}`:
alpha 0 is empty; alpha 255 is `r5 = min((r + (th >> 1)) >> 3, 31)`, `g6 = min((g + (th >> 2)) >> 2, 63)`,
`b5 = min((b + (th >> 1)) >> 3, 31)`; another alpha `a` premultiplies first, `div255(c * a)` with
`div255(v) = (v + 128 + ((v + 128) >> 8)) >> 8`. Channels at 0 or 255 do not depend on `th`; white at alpha 128
is `0x8410` for every `th`.

`1x1-opaque` (24 bytes):

```
534c4931 01 02 0100 0100 0000 04000000   header: opaque, 1x1, 4 bytes of data
00000000                                 row 0 at 0
81 00 00f8                               1 different pixel, padding, 0xF800
```

`8x8-spans` (data: 122 bytes, rows at 0, 4, 8, 28, 40, 56, 84, 104):

```
08 000000                                8 empty; padding to 4
48 00f8 00                               8 x 0xF800
88 00 00f8 e007 1f00 ffff 0000 00f8 e007 1f00 0000
02 c2 1084 1084 80 80 44 00f8 00         2 empty, 2 semi (0x8410, alpha 0x80), 4 x red
81 00 00f8 44 e007 81 1f00 01 c1 1084 80 00
c8 00 1084 x8 80 x8 0000
88 00 00f8 00f8 00f8 e007 e007 e007 1f00 1f00 0000   runs of 3 stay "different"
88 00 2d6b 2c63 2d6b 2c63 2d6b 2c63 2d6b 2c63        gray 100, th 15, 7, 13, 5
```

A semi-transparent pixel: (200, 100, 50) at alpha 100 is premultiplied to (78, 39, 20); at th 0 that is r5 9,
g6 9, b5 2, so `0x4922`, and the row is `c1 00 2249 64`.
