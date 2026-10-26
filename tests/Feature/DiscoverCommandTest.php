<?php

declare(strict_types=1);

use App\Sdk\SdkResolver;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->home = Fixtures::bootstrap();
});

function installDemo(): void
{
    $sha = app(App\Sdk\SdkIndex::class)->versions('demo')['1.0.0']['sha256'];
    app(SdkResolver::class)->ensure('demo', '1.0.0', $sha, new BufferedOutput());
}

it('needs an installed sdk', function () {
    $this->artisan('discover')
        ->expectsOutputToContain('no SDK installed: run `phpbm sdk:install <name>`')
        ->assertExitCode(1);
});

it('identifies the chip and lists matching boards', function () {
    installDemo();
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO'], 'chips' => ['/dev/ttyDEMO' => 'demo']]);

    $this->artisan('discover')
        ->expectsOutputToContain('/dev/ttyDEMO  demo serial')
        ->expectsOutputToContain('chip    Demo demo (revision v1)')
        ->expectsOutputToContain('sdk     demo 1.0.0')
        ->expectsOutputToContain('board   demo-board  Demo board')
        ->expectsOutputToContain('board   demo-net  Demo net')
        ->assertExitCode(0);
});

it('says when no sdk recognises the chip', function () {
    installDemo();
    Fixtures::toolchain($this->home);

    $this->artisan('discover', ['--port' => '/dev/x'])
        ->expectsOutputToContain('no installed sdk recognises this chip')
        ->assertExitCode(0);
});

it('says when no port is found', function () {
    installDemo();
    Fixtures::toolchain($this->home);

    $this->artisan('discover')
        ->expectsOutputToContain('no serial port found: connect the board or pass --port')
        ->assertExitCode(1);
});

it('keeps going when an sdk cannot create its driver', function () {
    installDemo();
    Fixtures::toolchain($this->home, ['ports' => ['/dev/ttyDEMO'], 'chips' => ['/dev/ttyDEMO' => 'demo']]);
    $dir = Fixtures::sdk(Fixtures::tempDir() . '/broken-sdk', '1.0.0', Fixtures::phpTarball(Fixtures::tempDir()));
    $descriptor = json_decode(file_get_contents($dir . '/sdk.json'), true);
    $descriptor['name'] = 'broken';
    $descriptor['driver']['class'] = 'BrokenSdk\\Missing';
    $descriptor['driver']['autoload'] = ['psr-4' => ['BrokenSdk\\' => 'nowhere/']];
    file_put_contents($dir . '/sdk.json', json_encode($descriptor));
    app(App\Sdk\SdkStore::class)->link('broken', $dir);

    $this->artisan('discover')
        ->expectsOutputToContain('broken:')
        ->expectsOutputToContain('sdk     demo 1.0.0')
        ->assertExitCode(0);
});
