<?php

declare(strict_types=1);

use App\Fonts\BdfFont;
use App\Fonts\FontBaker;
use App\PhpbmException;
use Tests\Support\Fixtures;

beforeEach(fn () => $this->bdf = dirname(__DIR__, 2) . '/fixtures/fonts/tiny.bdf');

it('reads bitmaps and metrics', function () {
    $font = BdfFont::fromFile($this->bdf);
    $i = $font->glyph(ord('I'));
    $l = $font->glyph(ord('L'));
    $space = $font->glyph(ord(' '));

    expect([$font->ascent(), $font->descent()])->toBe([5, 1])
        ->and([$i->width, $i->height, $i->xAdvance, $i->xOffset, $i->yOffset])->toBe([3, 5, 4, 0, -5])
        ->and($i->coverage)->toBe([
            255, 255, 255,
            0, 255, 0,
            0, 255, 0,
            0, 255, 0,
            255, 255, 255,
        ])
        ->and([$l->width, $l->height, $l->xAdvance, $l->xOffset, $l->yOffset])->toBe([4, 5, 5, 1, -4])
        ->and([$space->width, $space->height, $space->xAdvance])->toBe([0, 0, 4])
        ->and($font->glyph(0x20AC))->toBeNull();
});

it('ignores size, and lights pixels fully at more bpp', function () {
    $baker = new FontBaker();
    $plain = $baker->bake($this->bdf, 'tiny', null, 1, 'IL', null);

    expect($baker->bake($this->bdf, 'tiny', 40, 1, 'IL', null)->header)->toBe($plain->header)
        ->and($baker->bake($this->bdf, 'tiny', null, 4, 'I', null)->header)
        ->toContain("    0xFF, 0xF0, 0xF0, 0x0F, 0x00, 0xF0, 0xFF, 0xF0,\n");
});

it('rejects a malformed BDF', function () {
    $file = Fixtures::tempDir() . '/bad.bdf';
    file_put_contents($file, "STARTFONT 2.1\nSTARTCHAR A\nENCODING 65\nBITMAP\nZZ\nENDCHAR\nENDFONT\n");

    expect(fn () => BdfFont::fromFile($file))->toThrow(PhpbmException::class, "'{$file}' is not a TTF or BDF font");
});
