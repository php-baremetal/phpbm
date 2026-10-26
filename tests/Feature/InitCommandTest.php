<?php

declare(strict_types=1);

use App\Project\ConductorCheck;
use App\Sdk\SdkResolver;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use PhpBaremetal\Spec\Testing\FakeProcess;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->home = Fixtures::bootstrap();
    $this->dir = Fixtures::tempDir() . '/blink';
    app()->instance(ConductorCheck::class, new ConductorCheck((new FakeProcess())
        ->on('composer global show', new ProcessResult(0, '{"versions":["1.0.0"]}'))
        ->on('composer global config', new ProcessResult(0, 'true'))));
});

function installDemoSdk(): void
{
    $sha = app(App\Sdk\SdkIndex::class)->versions('demo')['1.0.0']['sha256'];
    app(SdkResolver::class)->ensure('demo', '1.0.0', $sha, new BufferedOutput());
}

it('needs an installed sdk', function () {
    $this->artisan('init', ['dir' => $this->dir, '--no-interaction' => true])
        ->expectsOutputToContain('no SDK installed: run `phpbm sdk:install <name>` first')
        ->assertExitCode(1);
});

it('scaffolds a project', function () {
    installDemoSdk();

    $this->artisan('init', [
        'dir' => $this->dir,
        '--board' => 'demo-net',
        '--runtime' => 'loop',
        '--storage' => 'embedded',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('composer install')
        ->expectsOutputToContain('phpbm build')
        ->assertExitCode(0);

    expect(json_decode(file_get_contents($this->dir . '/composer.json'), true))->toBe([
        'name' => 'local/blink',
        'description' => 'php-baremetal firmware',
        'type' => 'project',
        'repositories' => [['type' => 'composer', 'url' => 'https://repository.php-baremetal.com', 'canonical' => true]],
        'require' => ['php-baremetal/conductor' => '^1.0'],
        'config' => ['platform' => ['php' => '8.4.25'], 'allow-plugins' => ['php-baremetal/conductor' => true]],
        'extra' => ['baremetal' => ['sdk' => 'demo@^1.0', 'board' => 'demo-net', 'runtime' => 'loop', 'storage' => 'embedded']],
    ])
        ->and(file_get_contents($this->dir . '/.gitignore'))->toBe("/vendor/\n/.phpbm/\n")
        ->and(file_get_contents($this->dir . '/project-src/index.php'))->toContain('Hello from blink');
});

it('defaults to the first board, runtime and fitting storage without interaction', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--no-interaction' => true])->assertExitCode(0);

    expect(json_decode(file_get_contents($this->dir . '/composer.json'), true)['extra']['baremetal'])
        ->toBe(['sdk' => 'demo@^1.0', 'board' => 'demo-board', 'runtime' => 'loop', 'storage' => 'embedded']);
});

it('takes the package repository from PHPBM_REPOSITORY', function () {
    installDemoSdk();
    putenv('PHPBM_REPOSITORY=https://registry.example.test');
    try {
        $this->artisan('init', ['dir' => $this->dir, '--no-interaction' => true])->assertExitCode(0);
    } finally {
        putenv('PHPBM_REPOSITORY');
    }

    expect(json_decode(file_get_contents($this->dir . '/composer.json'), true)['repositories'])
        ->toBe([['type' => 'composer', 'url' => 'https://registry.example.test', 'canonical' => true]]);
});

it('refuses an existing project', function () {
    installDemoSdk();
    mkdir($this->dir);
    file_put_contents($this->dir . '/composer.json', '{}');
    $this->artisan('init', ['dir' => $this->dir, '--no-interaction' => true])
        ->expectsOutputToContain("{$this->dir} already contains a composer.json")
        ->assertExitCode(1);
});

it('rejects names that do not exist in the sdk', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'nope', '--no-interaction' => true])
        ->expectsOutputToContain("board 'nope' not found in sdk demo 1.0.0")
        ->assertExitCode(1);
    expect(is_dir($this->dir))->toBeFalse();
});

it('warns when the conductor is missing', function () {
    installDemoSdk();
    app()->instance(ConductorCheck::class, new ConductorCheck(new FakeProcess()));
    $this->artisan('init', ['dir' => $this->dir, '--no-interaction' => true])
        ->expectsOutputToContain('composer global require php-baremetal/conductor:^1.0')
        ->assertExitCode(0);
});

it('never overwrites existing files', function () {
    installDemoSdk();
    mkdir($this->dir . '/project-src', 0777, true);
    file_put_contents($this->dir . '/.gitignore', "keep-me\n/vendor/\n");
    file_put_contents($this->dir . '/project-src/index.php', 'mine');
    $this->artisan('init', ['dir' => $this->dir, '--no-interaction' => true])->assertExitCode(0);

    expect(file_get_contents($this->dir . '/.gitignore'))->toBe("keep-me\n/vendor/\n/.phpbm/\n")
        ->and(file_get_contents($this->dir . '/project-src/index.php'))->toBe('mine');
});

it('refuses a runtime the board cannot run and writes nothing', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-board', '--runtime' => 'server', '--no-interaction' => true])
        ->expectsOutputToContain("runtime 'server' needs network, but board 'demo-board' has none")
        ->assertExitCode(1);
    expect(file_exists($this->dir))->toBeFalse();
});

it('refuses a storage the board cannot use', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-net', '--storage' => 'microsd', '--no-interaction' => true])
        ->expectsOutputToContain("storage 'microsd' needs microsd, but board 'demo-net' has none")
        ->assertExitCode(1);
    expect(file_exists($this->dir))->toBeFalse();
});

it('picks the first fitting runtime and storage', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-net', '--no-interaction' => true])->assertExitCode(0);
    expect(json_decode(file_get_contents($this->dir . '/composer.json'), true)['extra']['baremetal'])
        ->toMatchArray(['runtime' => 'loop', 'storage' => 'embedded']);
});

it('suggests requiring the packages of the chosen modules', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-net', '--runtime' => 'server', '--no-interaction' => true])
        ->expectsOutputToContain('composer require php-baremetal/ext-web')
        ->assertExitCode(0);
});
