<?php

declare(strict_types=1);

use Tests\Support\Fixtures;

beforeEach(function () {
    $this->cwd = getcwd();
    $this->home = Fixtures::bootstrap();
    chdir(Fixtures::project(Fixtures::tempDir(), ['storage' => 'microsd']));
});

afterEach(fn () => chdir($this->cwd));

it('syncs without building or flashing', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('sync')->expectsOutputToContain('synced /dev/ttyDEMO')->assertExitCode(0);
    expect(Fixtures::calls($this->home))->toBe(['sync microsd /dev/ttyDEMO']);
});

it('does not prepare the PHP sources', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('sync')->doesntExpectOutputToContain('preparing')->assertExitCode(0);
    expect(glob($this->home->trees() . '/php-*'))->toBe([]);
});

it('says when the storage has nothing to sync', function () {
    chdir(Fixtures::project(Fixtures::tempDir()));
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('sync')
        ->expectsOutputToContain("storage 'embedded' has nothing to sync")
        ->doesntExpectOutputToContain('synced')
        ->assertExitCode(0);
});

it('uses the given port', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/a', '/dev/b']]);

    $this->artisan('sync', ['-p' => '/dev/b'])->assertExitCode(0);
    expect(Fixtures::calls($this->home))->toBe(['sync microsd /dev/b']);
});

it('asks for a port when several are connected', function () {
    Fixtures::toolchain($this->home, ['ports' => ['/dev/a', '/dev/b']]);

    $this->artisan('sync', ['--no-interaction' => true])
        ->expectsOutputToContain('several serial ports found (/dev/a, /dev/b): pass --port')
        ->assertExitCode(1);
    expect(Fixtures::calls($this->home))->toBe([]);
});

it('needs the toolchain', function () {
    $this->artisan('sync', ['--port' => '/dev/x'])
        ->expectsOutputToContain('the demo toolchain is not installed')
        ->assertExitCode(1);
});

it('reports a failed sync', function () {
    Fixtures::toolchain($this->home, ['sync' => 'fail']);

    $this->artisan('sync', ['--port' => '/dev/x'])
        ->expectsOutputToContain('the board did not answer the sync')
        ->assertExitCode(1);
});

it('says when the embedded assets changed since the last build', function () {
    $project = Fixtures::project(Fixtures::tempDir(), ['assets' => [['dir' => 'assets/ui', 'storage' => 'embedded']]]);
    @mkdir("{$project}/assets/ui", 0777, true);
    copy(__DIR__ . '/../fixtures/images/8x8-spans.png', "{$project}/assets/ui/logo.png");
    chdir($project);
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);
    $this->artisan('build')->assertExitCode(0);

    $this->artisan('sync')->doesntExpectOutputToContain('phpbm flash')->assertExitCode(0);

    copy(__DIR__ . '/../fixtures/images/icon-48.png', "{$project}/assets/ui/logo.png");
    $this->artisan('sync')
        ->expectsOutputToContain('the embedded assets changed since the last build: run `phpbm flash` to put them on the board')
        ->assertExitCode(0);
});

it('hands the prepared microSD assets to the sync', function () {
    $project = Fixtures::project(Fixtures::tempDir(), ['assets' => [['dir' => 'assets/photos', 'storage' => 'microsd']]]);
    @mkdir("{$project}/assets/photos/2026", 0777, true);
    copy(__DIR__ . '/../fixtures/images/8x8-spans.png', "{$project}/assets/photos/2026/beans.png");
    copy(__DIR__ . '/../fixtures/images/1x1-opaque.sli', "{$project}/assets/photos/cup.sli");
    chdir($project);
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('sync')->assertExitCode(0);

    expect(Fixtures::calls($this->home))->toBe(['sync embedded /dev/ttyDEMO assets microsd:/sdcard/assets/photos/2026/beans.sli,microsd:/sdcard/assets/photos/cup.sli']);
    $assets = json_decode((string) file_get_contents($this->home->toolchain('demo') . '/sync-assets.json'), true);
    expect(base64_decode($assets[0]['content']))->toBe(file_get_contents(__DIR__ . '/../fixtures/images/8x8-spans.sli'))
        ->and($assets[1]['file'])->toBe("{$project}/assets/photos/cup.sli");
});

it('says when the embedded assets were never built', function () {
    $project = Fixtures::project(Fixtures::tempDir(), ['assets' => [['dir' => 'assets/ui', 'storage' => 'embedded']]]);
    @mkdir("{$project}/assets/ui", 0777, true);
    copy(__DIR__ . '/../fixtures/images/8x8-spans.png', "{$project}/assets/ui/logo.png");
    chdir($project);
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('sync')
        ->expectsOutputToContain('the embedded assets are not built yet: run `phpbm flash` to put them on the board')
        ->doesntExpectOutputToContain('changed since the last build')
        ->assertExitCode(0);
});
