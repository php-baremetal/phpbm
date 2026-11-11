<?php

declare(strict_types=1);

use App\Build\AssetResolver;
use App\Images\PngReader;
use App\Images\SliEncoder;
use App\PhpbmException;
use App\Support\Home;
use PhpBaremetal\Spec\Manifest\Project;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use Tests\Support\Fixtures;

const ASSET_FIXTURES = __DIR__ . '/../../fixtures/images';

beforeEach(function () {
    $this->root = Fixtures::tempDir();
    $this->home = new Home($this->root . '/home');
    $this->dir = $this->root . '/proj';
    $this->out = new BufferedOutput();
    $this->conversions = 0;
    $this->resolver = new AssetResolver($this->home, function (string $png): string {
        $this->conversions++;
        $image = PngReader::read($png);
        return SliEncoder::encode($image['w'], $image['h'], $image['rows']);
    });
    @mkdir($this->dir . '/project-src', 0777, true);
    file_put_contents($this->dir . '/project-src/index.php', "<?php\n");
});

/** @param list<array{dir:string, storage:string}> $assets */
function assetProject(array $assets, string $storage = 'embedded', string $src = 'project-src'): Project
{
    return Project::fromComposer('acme/demo', [
        'sdk' => 'demo@^1.0', 'board' => 'demo-board', 'runtime' => 'loop', 'storage' => $storage, 'src' => $src, 'assets' => $assets,
    ], '8.4.25');
}

function putAsset(string $dir, string $relative, ?string $fixture = '1x1-opaque.png'): void
{
    @mkdir(dirname("{$dir}/{$relative}"), 0777, true);
    $fixture === null ? file_put_contents("{$dir}/{$relative}", 'text') : copy(ASSET_FIXTURES . "/{$fixture}", "{$dir}/{$relative}");
}

it('plans every image of every asset folder with its place on the board', function () {
    putAsset($this->dir, 'assets/ui/logo.png');
    putAsset($this->dir, 'assets/ui/icons/coffee.png');
    putAsset($this->dir, 'assets/ui/icons/ready.sli', '1x1-opaque.sli');
    putAsset($this->dir, 'assets/photos/beans.PNG');
    putAsset($this->dir, 'assets/photos/2026/cup.png');

    $plan = $this->resolver->plan(assetProject([
        ['dir' => 'assets/ui', 'storage' => 'embedded'],
        ['dir' => 'assets/photos', 'storage' => 'microsd'],
    ]), $this->dir, $this->out);

    expect($plan)->toBe([
        ['source' => $this->dir . '/assets/ui/icons/coffee.png', 'board' => '/app/assets/ui/icons/coffee.sli', 'storage' => 'embedded'],
        ['source' => $this->dir . '/assets/ui/icons/ready.sli', 'board' => '/app/assets/ui/icons/ready.sli', 'storage' => 'embedded'],
        ['source' => $this->dir . '/assets/ui/logo.png', 'board' => '/app/assets/ui/logo.sli', 'storage' => 'embedded'],
        ['source' => $this->dir . '/assets/photos/2026/cup.png', 'board' => '/sdcard/assets/photos/2026/cup.sli', 'storage' => 'microsd'],
        ['source' => $this->dir . '/assets/photos/beans.PNG', 'board' => '/sdcard/assets/photos/beans.sli', 'storage' => 'microsd'],
    ])->and($this->out->lines)->toBe([]);
});

it('is empty without assets', function () {
    expect($this->resolver->plan(assetProject([]), $this->dir, $this->out))->toBe([]);
});

it('ignores other files with a warning', function () {
    putAsset($this->dir, 'assets/ui/logo.png');
    putAsset($this->dir, 'assets/ui/notes.txt', null);

    $plan = $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out);

    expect(array_column($plan, 'board'))->toBe(['/app/assets/ui/logo.sli'])
        ->and($this->out->lines)->toBe(['warning: asset ignored (not .png or .sli): assets/ui/notes.txt']);
});

it('rejects a missing asset folder', function () {
    expect(fn () => $this->resolver->plan(assetProject([['dir' => 'assets/nope', 'storage' => 'embedded']]), $this->dir, $this->out))
        ->toThrow(PhpbmException::class, 'asset directory assets/nope does not exist');
});

it('rejects a storage it cannot place assets on', function () {
    @mkdir($this->dir . '/assets/ui', 0777, true);

    expect(fn () => $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'spiffs']]), $this->dir, $this->out))
        ->toThrow(PhpbmException::class, "assets in assets/ui: storage 'spiffs' cannot hold assets (embedded, microsd)");
});

it('rejects a png and a sli that make the same file', function () {
    putAsset($this->dir, 'assets/ui/a.png');
    putAsset($this->dir, 'assets/ui/A.sli', '1x1-opaque.sli');

    expect(fn () => $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out))
        ->toThrow(PhpbmException::class, 'assets/ui/A.sli and assets/ui/a.png both become /app/assets/ui/a.sli');
});

it('rejects an embedded asset with the path of a source file', function () {
    putAsset($this->dir, 'assets/ui/logo.png');
    @mkdir($this->dir . '/project-src/assets/ui', 0777, true);
    file_put_contents($this->dir . '/project-src/assets/ui/logo.sli', 'x');

    expect(fn () => $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out))
        ->toThrow(PhpbmException::class, 'assets/ui/logo.png becomes /app/assets/ui/logo.sli, which is also the source file project-src/assets/ui/logo.sli');
});

it('lets a microsd asset share the path of a source file', function () {
    putAsset($this->dir, 'assets/ui/logo.png');
    @mkdir($this->dir . '/project-src/assets/ui', 0777, true);
    file_put_contents($this->dir . '/project-src/assets/ui/logo.sli', 'x');

    expect($this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'microsd']]), $this->dir, $this->out))->toHaveCount(1);
});

it('does not count a .sli asset inside the sources as a clash with itself', function () {
    putAsset($this->dir, 'app/index.php', null);
    putAsset($this->dir, 'app/assets/ok.sli', '1x1-opaque.sli');

    $plan = $this->resolver->plan(assetProject([['dir' => 'app/assets', 'storage' => 'embedded']], src: 'app'), $this->dir, $this->out);

    expect(array_column($plan, 'board'))->toBe(['/app/app/assets/ok.sli']);
});

it('converts a png once and takes it from the cache afterwards', function () {
    putAsset($this->dir, 'assets/ui/logo.png', '8x8-spans.png');
    [$entry] = $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out);

    $first = $this->resolver->prepare($entry);
    $second = $this->resolver->prepare($entry);

    expect($this->conversions)->toBe(1)
        ->and($second)->toBe($first)
        ->and(str_starts_with($first, $this->home->assets() . '/'))->toBeTrue()
        ->and(file_get_contents($first))->toBe(file_get_contents(ASSET_FIXTURES . '/8x8-spans.sli'));
});

it('converts a png again when it changes', function () {
    putAsset($this->dir, 'assets/ui/logo.png', '8x8-spans.png');
    [$entry] = $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out);
    $this->resolver->prepare($entry);

    putAsset($this->dir, 'assets/ui/logo.png', 'icon-48.png');
    $file = $this->resolver->prepare($entry);

    expect($this->conversions)->toBe(2)
        ->and(file_get_contents($file))->toBe(file_get_contents(ASSET_FIXTURES . '/icon-48.sli'));
});

it('hands a .sli over as it is after checking its header', function () {
    putAsset($this->dir, 'assets/ui/ready.sli', '1x1-opaque.sli');
    [$entry] = $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out);

    expect($this->resolver->prepare($entry))->toBe($this->dir . '/assets/ui/ready.sli')
        ->and($this->conversions)->toBe(0);
});

it('rejects a .sli without the header', function () {
    putAsset($this->dir, 'assets/ui/ready.sli', '1x1-opaque.png');
    [$entry] = $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out);

    expect(fn () => $this->resolver->prepare($entry))
        ->toThrow(PhpbmException::class, $this->dir . '/assets/ui/ready.sli: not a .sli image (bad header)');
});

it('reports a png it cannot read', function () {
    putAsset($this->dir, 'assets/ui/broken.png', null);
    [$entry] = $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out);

    expect(fn () => $this->resolver->prepare($entry))
        ->toThrow(PhpbmException::class, $this->dir . '/assets/ui/broken.png: not a PNG file (bad signature)');
});

it('stages the sources and the embedded assets into one folder, copying only what changed', function () {
    putAsset($this->dir, 'assets/ui/icons/coffee.png', '8x8-spans.png');
    putAsset($this->dir, 'assets/photos/beans.png');
    @mkdir($this->dir . '/project-src/lib', 0777, true);
    file_put_contents($this->dir . '/project-src/lib/a.php', "<?php\n");
    $project = assetProject([
        ['dir' => 'assets/ui', 'storage' => 'embedded'],
        ['dir' => 'assets/photos', 'storage' => 'microsd'],
    ]);
    $staging = $this->dir . '/.phpbm/build/app';

    $this->resolver->stage($this->dir . '/project-src', $this->resolver->plan($project, $this->dir, $this->out), $staging);

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = substr($file->getPathname(), strlen($staging) + 1);
    }
    sort($files);
    expect($files)->toBe(['assets/ui/icons/coffee.sli', 'index.php', 'lib/a.php'])
        ->and(file_get_contents("{$staging}/assets/ui/icons/coffee.sli"))->toBe(file_get_contents(ASSET_FIXTURES . '/8x8-spans.sli'));

    touch("{$staging}/index.php", 1000);
    unlink($this->dir . '/project-src/lib/a.php');
    file_put_contents($this->dir . '/project-src/new.php', "<?php\n");
    $this->resolver->stage($this->dir . '/project-src', $this->resolver->plan($project, $this->dir, $this->out), $staging);

    clearstatcache();
    expect(filemtime("{$staging}/index.php"))->toBe(1000)
        ->and(is_file("{$staging}/lib/a.php"))->toBeFalse()
        ->and(is_dir("{$staging}/lib"))->toBeFalse()
        ->and(is_file("{$staging}/new.php"))->toBeTrue();
});

it('tells when the staged embedded assets are out of date', function () {
    putAsset($this->dir, 'assets/ui/logo.png', '8x8-spans.png');
    $project = assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]);
    $staging = $this->dir . '/.phpbm/build/app';

    expect($this->resolver->stale($this->resolver->plan($project, $this->dir, $this->out), $staging))->toBeTrue();
    $this->resolver->stage($this->dir . '/project-src', $this->resolver->plan($project, $this->dir, $this->out), $staging);
    expect($this->resolver->stale($this->resolver->plan($project, $this->dir, $this->out), $staging))->toBeFalse();

    putAsset($this->dir, 'assets/ui/logo.png', 'icon-48.png');
    expect($this->resolver->stale($this->resolver->plan($project, $this->dir, $this->out), $staging))->toBeTrue();
});

it('leaves .phpbm out of the staging when the sources are the project root', function (string $src) {
    putAsset($this->dir, 'assets/ui/logo.png', '8x8-spans.png');
    file_put_contents($this->dir . '/index.php', "<?php\n");
    @mkdir($this->dir . '/.phpbm/build/esp-idf', 0777, true);
    file_put_contents($this->dir . '/.phpbm/build/esp-idf/big.bin', 'x');
    $project = assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']], src: $src);
    $staging = $this->dir . '/.phpbm/build/app';

    for ($i = 0; $i < 2; $i++) {
        $this->resolver->stage($this->dir . '/' . $src, $this->resolver->plan($project, $this->dir, $this->out), $staging);
    }

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = substr($file->getPathname(), strlen($staging) + 1);
    }
    sort($files);
    expect($files)->toBe(['assets/ui/logo.png', 'assets/ui/logo.sli', 'index.php', 'project-src/index.php']);
})->with(['.', './']);

it('does not follow a link back to a folder it is already in', function () {
    putAsset($this->dir, 'assets/ui/logo.png');
    if (!@symlink($this->dir . '/assets', $this->dir . '/assets/ui/loop')) {
        $this->markTestSkipped('symlinks are not available');
    }

    $plan = $this->resolver->plan(assetProject([['dir' => 'assets/ui', 'storage' => 'embedded']]), $this->dir, $this->out);

    expect(array_column($plan, 'board'))->toBe(['/app/assets/ui/logo.sli']);
});
