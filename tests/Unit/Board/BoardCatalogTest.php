<?php

declare(strict_types=1);

use App\Board\BoardCatalog;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use Tests\Support\Fixtures;

function catalogBoard(string $id, string $source = Board::SDK): Board
{
    return new Board($id, $id, 'demo', null, '4MB', null, [], source: $source);
}

it('reads the coming-soon flag of the sdk catalog, keyed by sdk name and board id', function () {
    $dir = Fixtures::tempDir();
    mkdir("{$dir}/boards");
    file_put_contents("{$dir}/boards/catalog.json", json_encode([
        'demo' => ['soon' => ['coming-soon' => true], 'docs-only' => ['docs' => 'https://example.com'], 'false' => ['coming-soon' => false]],
        'other' => ['elsewhere' => ['coming-soon' => true]],
    ]));
    $catalog = new BoardCatalog('demo', [Board::SDK => $dir]);

    expect($catalog->comingSoon(catalogBoard('soon')))->toBeTrue()
        ->and($catalog->comingSoon(catalogBoard('docs-only')))->toBeFalse()
        ->and($catalog->comingSoon(catalogBoard('false')))->toBeFalse()
        ->and($catalog->comingSoon(catalogBoard('elsewhere')))->toBeFalse()
        ->and($catalog->comingSoon(catalogBoard('missing')))->toBeFalse();
});

it('reads the catalog of the package a board comes from', function () {
    $sdk = Fixtures::tempDir();
    $package = Fixtures::tempDir();
    Fixtures::comingSoon($package, ['pack-board']);
    $catalog = new BoardCatalog('demo', [Board::SDK => $sdk, 'acme/boards' => $package]);

    expect($catalog->comingSoon(catalogBoard('pack-board', 'acme/boards')))->toBeTrue()
        ->and($catalog->comingSoon(catalogBoard('pack-board')))->toBeFalse()
        ->and($catalog->comingSoon(catalogBoard('pack-board', 'acme/unknown')))->toBeFalse();
});

it('has no entries when the catalog is missing or invalid', function (?string $contents) {
    $dir = Fixtures::tempDir();
    if ($contents !== null) {
        mkdir("{$dir}/boards");
        file_put_contents("{$dir}/boards/catalog.json", $contents);
    }

    expect((new BoardCatalog('demo', [Board::SDK => $dir]))->comingSoon(catalogBoard('soon')))->toBeFalse();
})->with([
    'missing' => null,
    'not json' => '{oops',
    'a list' => '[1, 2]',
    'sdk not an object' => '{"demo": "soon"}',
    'entry not an object' => '{"demo": {"soon": true}}',
    'flag not true' => '{"demo": {"soon": {"coming-soon": "yes"}}}',
]);
