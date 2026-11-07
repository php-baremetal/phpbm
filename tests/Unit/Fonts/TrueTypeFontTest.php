<?php

declare(strict_types=1);

use App\Fonts\TrueTypeFont;
use App\PhpbmException;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->font = TrueTypeFont::fromFile(dirname(__DIR__, 2) . '/fixtures/fonts/DejaVuSansMono.ttf');
});

it('reads the metrics of DejaVu Sans Mono', function () {
    expect($this->font->unitsPerEm())->toBe(2048)
        ->and($this->font->ascent())->toBe(1901)
        ->and($this->font->descent())->toBe(-483)
        ->and($this->font->lineGap())->toBe(0);
});

it('maps codepoints to glyphs', function () {
    expect($this->font->glyphIndex(ord('A')))->not->toBe(0)
        ->and($this->font->glyphIndex(ord('A')))->not->toBe($this->font->glyphIndex(ord('B')))
        ->and($this->font->glyphIndex(0x10FFFD))->toBe(0);
});

it('has the same advance for every glyph of a monospaced font', function () {
    $advance = fn (string $c) => $this->font->advance($this->font->glyphIndex(ord($c)));
    expect($advance('M'))->toBe($advance('i'))
        ->and($advance('M'))->toBe(1233);
});

it('reads glyph outlines, simple and composite', function () {
    expect($this->font->outline($this->font->glyphIndex(ord(' ')))->isEmpty())->toBeTrue()
        ->and(count($this->font->outline($this->font->glyphIndex(ord('O')))->contours))->toBe(2)
        ->and(count($this->font->outline($this->font->glyphIndex(0xC9))->contours))->toBeGreaterThan(1);
});

it('rejects a file that is not a font', function () {
    $file = Fixtures::tempDir() . '/notes.ttf';
    file_put_contents($file, 'hello, this is not a font');

    expect(fn () => TrueTypeFont::fromFile($file))->toThrow(PhpbmException::class, "'{$file}' is not a TTF or BDF font");
});

it('rejects a truncated font', function () {
    $file = Fixtures::tempDir() . '/cut.ttf';
    file_put_contents($file, substr((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/fonts/DejaVuSansMono.ttf'), 0, 300));

    expect(fn () => TrueTypeFont::fromFile($file))->toThrow(PhpbmException::class, "'{$file}' is not a TTF or BDF font");
});

it('rejects CFF outlines', function (string $bytes) {
    $file = Fixtures::tempDir() . '/font.otf';
    file_put_contents($file, $bytes);

    expect(fn () => TrueTypeFont::fromFile($file))->toThrow(PhpbmException::class, 'CFF outlines (OTF) are not supported, use a TTF or BDF');
})->with([
    'OTTO tag' => ['OTTO' . pack('nnnn', 0, 0, 0, 0)],
    'CFF table' => [pack('Nnnnn', 0x00010000, 1, 16, 0, 0) . 'CFF ' . pack('NNN', 0, 28, 4) . 'abcd'],
]);
