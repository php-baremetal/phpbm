<?php

declare(strict_types=1);

use App\Sdk\Lock;
use App\Sdk\SdkStore;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->cwd = getcwd();
    $this->home = Fixtures::bootstrap(['1.0.0', '1.1.0']);
});

afterEach(fn () => chdir($this->cwd));

it('installs the best matching version', function () {
    $this->artisan('sdk:install', ['sdk' => 'demo@~1.0.0'])->expectsOutputToContain('installed demo 1.0.0')->assertExitCode(0);
    $this->artisan('sdk:install', ['sdk' => 'demo@~1.0.0'])->expectsOutputToContain('demo 1.0.0 is already installed')->assertExitCode(0);
    $this->artisan('sdk:install', ['sdk' => 'demo'])->expectsOutputToContain('installed demo 1.1.0')->assertExitCode(0);
    expect(app(SdkStore::class)->versions('demo'))->toBe(['1.0.0', '1.1.0']);
});

it('lists installed and linked sdks', function () {
    $this->artisan('sdk:install', ['sdk' => 'demo@1.0.0']);
    $dir = Fixtures::sdk(Fixtures::tempDir() . '/demo-sdk', '2.0.0', Fixtures::phpTarball(Fixtures::tempDir()));
    $this->artisan('sdk:link', ['name' => 'demo', 'dir' => $dir])->expectsOutputToContain('linked demo -> ' . realpath($dir))->assertExitCode(0);

    $this->artisan('sdk:list')
        ->expectsTable(['SDK', 'Version', 'Location'], [
            ['demo', '1.0.0', $this->home->sdks() . '/demo/1.0.0'],
            ['demo', 'dev', realpath($dir)],
        ])
        ->assertExitCode(0);
});

it('says when nothing is installed', function () {
    $this->artisan('sdk:list')->expectsOutputToContain('no SDK installed')->assertExitCode(0);
});

it('removes a version and a link', function () {
    $this->artisan('sdk:install', ['sdk' => 'demo@1.0.0']);
    $this->artisan('sdk:remove', ['sdk' => 'demo@1.0.0'])->expectsOutputToContain('removed demo 1.0.0')->assertExitCode(0);
    $this->artisan('sdk:remove', ['sdk' => 'demo@dev'])->expectsOutputToContain('sdk demo is not linked')->assertExitCode(1);
    $this->artisan('sdk:remove', ['sdk' => 'demo'])->expectsOutputToContain('expected <name>@<version>, got demo')->assertExitCode(1);
});

it('updates the sdk of the project', function () {
    $project = Fixtures::project(Fixtures::tempDir());
    $sha = app(App\Sdk\SdkIndex::class)->versions('demo')['1.0.0']['sha256'];
    (new Lock('demo', '^1.0', '1.0.0', $sha))->write($project);
    chdir($project);

    $this->artisan('sdk:update')
        ->expectsOutputToContain('demo 1.0.0 -> 1.1.0')
        ->expectsOutputToContain('using demo 1.1.0')
        ->assertExitCode(0);
    expect(Lock::read($project)?->version)->toBe('1.1.0');
});

it('refuses sdk names and versions that escape the sdk directory', function () {
    $this->artisan('sdk:install', ['sdk' => '../x'])->expectsOutputToContain("invalid sdk name '../x'")->assertExitCode(1);

    $victim = $this->home->root . '/x';
    mkdir($victim, 0777, true);
    file_put_contents($victim . '/sdk.json', '{}');
    $this->artisan('sdk:remove', ['sdk' => 'demo@../../x'])->expectsOutputToContain("invalid sdk version '../../x'")->assertExitCode(1);
    expect(is_file($victim . '/sdk.json'))->toBeTrue();
});
