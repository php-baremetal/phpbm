<?php

declare(strict_types=1);

use App\Board\BoardSources;
use App\Support\Home;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Sdk;
use PhpBaremetal\Spec\Sdk\SdkLoader;
use Tests\Support\Fixtures;

const BS_PACKAGE = 'php-baremetal/boards-demo';

function bsOutput(): object
{
    return new class implements Output {
        /** @var list<string> */
        public array $warnings = [];
        /** @var list<string> */
        public array $steps = [];

        public function step(string $message): void
        {
            $this->steps[] = $message;
        }

        public function line(string $message): void
        {
        }

        public function warning(string $message): void
        {
            $this->warnings[] = $message;
        }
    };
}

/** @param list<string> $packages */
function bsSdk(array $packages = [BS_PACKAGE]): Sdk
{
    $root = Fixtures::tempDir();
    $dir = Fixtures::sdk("{$root}/demo-sdk", '1.0.0', Fixtures::phpTarball($root));
    Fixtures::withBoardPackages($dir, $packages);
    return (new SdkLoader())->load($dir);
}

/** A registry with 1.0.0 (old-board), 1.2.0 (pack-board and files that must not be read) and a newer beta. */
function bsRegistry(): string
{
    $zips = Fixtures::tempDir();
    return Fixtures::boardsRegistry([
        '1.0.0' => Fixtures::zip("{$zips}/v1.0.0.zip", ['boards/demo/old-board.json' => Fixtures::PACK_BOARD]),
        '1.2.0' => Fixtures::zip("{$zips}/v1.2.0.zip", [
            'composer.json' => '{"name":"php-baremetal/boards-demo","type":"bm-boards"}',
            'boards/demo/pack-board.json' => Fixtures::PACK_BOARD,
            'boards/demo/notes.md' => '# not a board',
            'boards/other/x.json' => '{"name":"Other","chip":"x","flash":"4MB"}',
            'install.php' => '<?php echo "never run";',
        ]),
        '2.0.0-beta1' => Fixtures::zip("{$zips}/v2.0.0-beta1.zip", ['boards/demo/beta-board.json' => Fixtures::PACK_BOARD]),
    ]);
}

/** Files under a folder, relative with '/'. @return list<string> */
function bsFiles(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
    }
    sort($files);
    return $files;
}

function bsBreak(string $registry): void
{
    rename($registry, $registry . '-gone');
}

beforeEach(function () {
    if (!class_exists(ZipArchive::class)) {
        $this->markTestSkipped('the zip extension builds the fixture archives');
    }
    $this->home = new Home(Fixtures::tempDir() . '/home');
});

it('reads the boards of the latest stable version of each official package', function () {
    $out = bsOutput();
    $packages = (new BoardSources($this->home, bsRegistry()))->packages(bsSdk(), $out);

    expect($packages)->toHaveCount(1)
        ->and($packages[0]->name)->toBe(BS_PACKAGE)
        ->and($packages[0]->type)->toBe('bm-boards')
        ->and($packages[0]->version)->toBe('1.2.0')
        ->and(bsFiles($packages[0]->path))->toBe(['boards/demo/pack-board.json'])
        ->and($out->warnings)->toBe([]);

    $sdk = (new SdkLoader())->withBoardPackages(bsSdk(), $packages);
    expect($sdk->board('pack-board')->source)->toBe(BS_PACKAGE)
        ->and($sdk->board(BS_PACKAGE . ':pack-board')->name)->toBe('Pack board');
});

it('reads nothing when the sdk lists no board package', function () {
    $out = bsOutput();
    expect((new BoardSources($this->home, bsRegistry()))->packages(bsSdk([]), $out))->toBe([])
        ->and($out->steps)->toBe([]);
});

it('reuses the cache within an hour without reading the registry', function () {
    $reg = bsRegistry();
    $first = (new BoardSources($this->home, $reg))->packages(bsSdk(), bsOutput());
    bsBreak($reg);

    $out = bsOutput();
    $second = (new BoardSources($this->home, $reg))->packages(bsSdk(), $out);
    expect($second)->toEqual($first)->and($out->warnings)->toBe([])->and($out->steps)->toBe([]);
});

it('rereads with refresh', function () {
    $reg = bsRegistry();
    (new BoardSources($this->home, $reg))->packages(bsSdk(), bsOutput());
    bsBreak($reg);

    $out = bsOutput();
    (new BoardSources($this->home, $reg))->packages(bsSdk(), $out, refresh: true);
    expect($out->warnings)->toHaveCount(1)->and($out->warnings[0])->toStartWith("cannot reach {$reg}");
});

it('uses an expired cache with a warning when the registry is unreachable', function () {
    $reg = bsRegistry();
    $t = 1_800_000_000;
    $first = (new BoardSources($this->home, $reg, fn () => $t))->packages(bsSdk(), bsOutput());
    bsBreak($reg);

    $out = bsOutput();
    $second = (new BoardSources($this->home, $reg, fn () => $t + 7200))->packages(bsSdk(), $out);
    expect($second)->toEqual($first)
        ->and($out->warnings)->toBe(["cannot reach {$reg}, using the board packages read on " . date('Y-m-d H:i', $t)]);
});

it('offers only the boards of the sdk, with a warning, without registry and cache', function () {
    $reg = Fixtures::tempDir() . '/nowhere';
    $out = bsOutput();
    expect((new BoardSources($this->home, $reg))->packages(bsSdk(), $out))->toBe([])
        ->and($out->warnings)->toBe(["cannot reach {$reg}: only the boards of the sdk are offered"]);
});

it('does not use the cache of another registry', function () {
    $reg = bsRegistry();
    (new BoardSources($this->home, $reg))->packages(bsSdk(), bsOutput());

    $other = Fixtures::tempDir() . '/nowhere';
    $out = bsOutput();
    expect((new BoardSources($this->home, $other))->packages(bsSdk(), $out))->toBe([])
        ->and($out->warnings)->toBe(["cannot reach {$other}: only the boards of the sdk are offered"]);
});

it('skips a package without metadata or without a stable dist archive', function () {
    $reg = bsRegistry();
    Fixtures::boardsMetadata($reg, ['1.0.0' => null], 'acme/no-dist');
    $out = bsOutput();
    $packages = (new BoardSources($this->home, $reg))->packages(bsSdk([BS_PACKAGE, 'acme/missing', 'acme/no-dist']), $out);

    expect(array_map(fn ($p) => $p->name, $packages))->toBe([BS_PACKAGE])
        ->and($out->warnings)->toBe([
            'skipping acme/missing: no metadata (404)',
            'skipping acme/no-dist: no stable version with a dist archive',
        ]);
});

it('refuses an archive with an unsafe entry and writes nothing outside the cache', function () {
    $zips = Fixtures::tempDir();
    $reg = Fixtures::boardsRegistry(['1.0.0' => Fixtures::zip("{$zips}/evil.zip", [
        'boards/demo/pack-board.json' => Fixtures::PACK_BOARD,
        '../../evil.json' => '{}',
    ], top: '')]);
    $out = bsOutput();

    expect((new BoardSources($this->home, $reg))->packages(bsSdk(), $out))->toBe([])
        ->and($out->warnings)->toHaveCount(1)
        ->and($out->warnings[0])->toStartWith('skipping ' . BS_PACKAGE . ': ')
        ->and(file_exists(dirname($zips) . '/evil.json'))->toBeFalse();
});

it('refuses a dist archive whose shasum does not match', function () {
    $zips = Fixtures::tempDir();
    $reg = Fixtures::boardsRegistry(['1.0.0' => Fixtures::zip("{$zips}/v1.zip", ['boards/demo/pack-board.json' => Fixtures::PACK_BOARD])]);
    $file = "{$reg}/p2/" . BS_PACKAGE . '.json';
    $data = json_decode((string) file_get_contents($file), true);
    $data['packages'][BS_PACKAGE][0]['dist']['shasum'] = str_repeat('0', 40);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES));
    $out = bsOutput();

    expect((new BoardSources($this->home, $reg))->packages(bsSdk(), $out))->toBe([])
        ->and($out->warnings)->toHaveCount(1)
        ->and($out->warnings[0])->toContain('checksum');
});
