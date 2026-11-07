<?php

declare(strict_types=1);

use App\Fonts\Outline;
use App\Fonts\Rasterizer;
use App\Fonts\TrueTypeFont;

function polygon(array $points): Outline
{
    return Outline::fromContours([array_map(fn (array $p) => ['x' => $p[0], 'y' => $p[1], 'on' => true], $points)]);
}

it('fills a 10x10 square at scale 1', function () {
    $glyph = Rasterizer::render(polygon([[0, 0], [10, 0], [10, 10], [0, 10]]), 1.0, 1);

    expect([$glyph->width, $glyph->height, $glyph->xOffset, $glyph->yOffset])->toBe([10, 10, 0, -10])
        ->and($glyph->coverage)->toBe(array_fill(0, 100, 255));
});

it('gives intermediate coverage on the edge of a half square at 4 bpp', function () {
    $glyph = Rasterizer::render(polygon([[0, 0], [10, 0], [0, 10]]), 1.0, 4);
    $partial = array_filter($glyph->coverage, fn (int $c) => $c > 0 && $c < 255);

    expect($glyph->width)->toBe(10)
        ->and($partial)->not->toBeEmpty()
        ->and($glyph->coverage[9 * 10])->toBe(255)
        ->and($glyph->coverage[9])->toBe(0);
});

it('leaves the hole of an O empty', function () {
    $font = TrueTypeFont::fromFile(dirname(__DIR__, 2) . '/fixtures/fonts/DejaVuSansMono.ttf');
    $glyph = Rasterizer::render($font->outline($font->glyphIndex(ord('O'))), 16 / 2048, 1);
    $middle = intdiv($glyph->height, 2) * $glyph->width;

    expect($glyph->coverage[$middle + intdiv($glyph->width, 2)])->toBe(0)
        ->and(max(array_slice($glyph->coverage, $middle, $glyph->width)))->toBe(255);
});

it('follows the non-zero rule for overlapping contours', function () {
    $square = [['x' => 0, 'y' => 0, 'on' => true], ['x' => 4, 'y' => 0, 'on' => true], ['x' => 4, 'y' => 4, 'on' => true], ['x' => 0, 'y' => 4, 'on' => true]];
    $glyph = Rasterizer::render(Outline::fromContours([$square, $square]), 1.0, 1);

    expect($glyph->coverage)->toBe(array_fill(0, 16, 255));
});

it('flattens curves', function () {
    $circle = Outline::fromContours([[
        ['x' => 10, 'y' => 0, 'on' => false], ['x' => 20, 'y' => 10, 'on' => false],
        ['x' => 10, 'y' => 20, 'on' => false], ['x' => 0, 'y' => 10, 'on' => false],
    ]]);
    $glyph = Rasterizer::render($circle, 1.0, 1);

    expect($glyph->coverage[5 * $glyph->width + 5])->toBe(255)
        ->and($glyph->coverage[0])->toBe(0);
});

it('is deterministic', function () {
    $font = TrueTypeFont::fromFile(dirname(__DIR__, 2) . '/fixtures/fonts/DejaVuSansMono.ttf');
    $render = fn () => Rasterizer::render($font->outline($font->glyphIndex(ord('g'))), 20 / 2048, 4);

    expect($render())->toEqual($render());
});

it('renders nothing for an empty outline', function () {
    $glyph = Rasterizer::render(Outline::fromContours([]), 1.0, 1);

    expect([$glyph->width, $glyph->height, $glyph->coverage])->toBe([0, 0, []]);
});
