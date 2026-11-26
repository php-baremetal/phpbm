<?php

declare(strict_types=1);

use App\Project\ConductorCheck;
use App\Sdk\SdkResolver;
use App\Support\Composer;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use PhpBaremetal\Spec\Testing\FakeProcess;
use Tests\Support\Fixtures;

/** Fake Composer that records the commands it is asked to run. */
function spyComposer(int $exit = 0, string $output = ''): object
{
    $spy = new class implements Process {
        public array $calls = [];
        public array $cwds = [];
        public ProcessResult $result;

        public function run(array $command, ?string $cwd = null, array $env = [], ?Output $out = null): ProcessResult
        {
            $this->calls[] = array_slice($command, 1);
            $this->cwds[] = $cwd;
            return $this->result;
        }

        public function tty(array $command, ?string $cwd = null, array $env = []): int
        {
            return 0;
        }
    };
    $spy->result = new ProcessResult($exit, $output);
    app()->instance(Composer::class, new Composer($spy, PHP_BINARY));
    return $spy;
}

beforeEach(function () {
    putenv('PHPBM_REPOSITORY=' . dirname(__DIR__) . '/fixtures/registry');
    $this->composer = spyComposer();
    $this->home = Fixtures::bootstrap();
    $this->dir = Fixtures::tempDir() . '/blink';
    app()->instance(ConductorCheck::class, new ConductorCheck((new FakeProcess())
        ->on('composer global show', new ProcessResult(0, '{"versions":["1.0.0"]}'))
        ->on('composer global config', new ProcessResult(0, 'true'))));
});

afterEach(function () {
    putenv('PHPBM_REPOSITORY');
    if (isset($this->env)) {
        app()['env'] = $this->env;
    }
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
        ->expectsOutputToContain('phpbm build')
        ->assertExitCode(0);

    expect($this->composer->calls)->toBe([['install']])
        ->and(json_decode(file_get_contents($this->dir . '/composer.json'), true))->toBe([
        'name' => 'local/blink',
        'description' => 'php-baremetal firmware',
        'type' => 'project',
        'repositories' => [['type' => 'composer', 'url' => dirname(__DIR__) . '/fixtures/registry', 'canonical' => true]],
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
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-net', '--runtime' => 'server', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('composer require php-baremetal/ext-web')
        ->assertExitCode(0);
});

it('installs all the features of the board plus runtime and storage packages', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--no-interaction' => true])
        ->doesntExpectOutputToContain('composer install')
        ->expectsOutputToContain('phpbm build')
        ->assertExitCode(0);

    expect($this->composer->calls)->toBe([['require', 'php-baremetal/display-st77916', 'php-baremetal/led-ws2812']])
        ->and($this->composer->cwds)->toBe([$this->dir]);
});

it('installs only runtime and storage packages with --features=none', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-net', '--runtime' => 'server', '--features' => 'none', '--no-interaction' => true])
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['require', 'php-baremetal/ext-web']]);
});

it('runs composer install when there is nothing to require', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--features' => 'none', '--no-interaction' => true])
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['install']]);
});

it('installs the features of the given kinds', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--features' => 'led', '--no-interaction' => true])
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['require', 'php-baremetal/led-ws2812']]);
});

it('rejects a feature kind the board does not have', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--features' => 'display,audio', '--no-interaction' => true])
        ->expectsOutputToContain("board 'demo-screen' has no feature 'audio' (features: display, i2c, led)")
        ->assertExitCode(1);
    expect(file_exists($this->dir))->toBeFalse();
});

it('prints the composer commands with --no-install', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('composer require php-baremetal/display-st77916 php-baremetal/led-ws2812')
        ->expectsOutputToContain('composer install')
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([])->and(is_file($this->dir . '/composer.json'))->toBeTrue();
});

it('keeps the files and prints the commands when composer fails', function () {
    installDemoSdk();
    spyComposer(2, "Problem here\n");
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--no-interaction' => true])
        ->expectsOutputToContain('composer require php-baremetal/display-st77916 php-baremetal/led-ws2812 failed (exit 2)')
        ->expectsOutputToContain('composer require php-baremetal/display-st77916 php-baremetal/led-ws2812')
        ->expectsOutputToContain('composer install')
        ->assertExitCode(1);
    expect(is_file($this->dir . '/composer.json'))->toBeTrue();
});

it('keeps the files and prints the commands when composer is missing', function () {
    installDemoSdk();
    app()->instance(Composer::class, new Composer(new FakeProcess(), '/nonexistent/composer'));
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--no-interaction' => true])
        ->expectsOutputToContain('composer not found: set PHPBM_COMPOSER, install Composer, or use --no-install')
        ->expectsOutputToContain('composer install')
        ->assertExitCode(1);
    expect(is_file($this->dir . '/composer.json'))->toBeTrue();
});

it('warns and installs only runtime and storage packages when the registry is unreachable', function () {
    installDemoSdk();
    putenv('PHPBM_REPOSITORY=' . $this->dir . '-nowhere');
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--runtime' => 'loop', '--no-interaction' => true])
        ->expectsOutputToContain('board features are not offered')
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['install']]);
});

it('offers the features as checkboxes, all checked', function () {
    installDemoSdk();
    $this->env = app()['env'];
    app()['env'] = 'testing'; // makes Laravel Prompts fall back to console questions the test can answer

    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--runtime' => 'loop', '--storage' => 'embedded'])
        ->expectsOutputToContain('i2c i2c: no package')
        ->expectsQuestion('Project name', 'blink')
        ->expectsChoice('Board features to install', ['display', 'led'], [
            'display' => 'display  st77916  php-baremetal/display-st77916',
            'led' => 'led  ws2812  php-baremetal/led-ws2812',
        ])
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['require', 'php-baremetal/display-st77916', 'php-baremetal/led-ws2812']]);
});

it('treats a kind without a package as a no-op with a note', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--features' => 'i2c,led', '--no-interaction' => true])
        ->expectsOutputToContain('i2c i2c: no package')
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['require', 'php-baremetal/led-ws2812']]);
});

it('warns that --features cannot be checked when the registry is unreachable', function () {
    installDemoSdk();
    putenv('PHPBM_REPOSITORY=' . $this->dir . '-nowhere');
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--features' => 'display', '--no-interaction' => true])
        ->expectsOutputToContain('cannot check --features: the list of board packages is not available')
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['install']]);
});

it('requires features first, then runtime and storage packages, without duplicates', function () {
    installDemoSdk();
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--runtime' => 'xdup', '--no-interaction' => true])
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['require', 'php-baremetal/display-st77916', 'php-baremetal/led-ws2812', 'php-baremetal/ext-web']]);
});

it('prints a note per feature and installs none when the registry has no board packages', function () {
    installDemoSdk();
    putenv('PHPBM_REPOSITORY=' . Fixtures::registryWithoutBlocks());
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--no-interaction' => true])
        ->expectsOutputToContain('display st77916: no package')
        ->expectsOutputToContain('led ws2812: no package')
        ->doesntExpectOutputToContain('board features are not offered')
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['install']]);
});

it('checks --features normally when the registry has no board packages', function () {
    installDemoSdk();
    putenv('PHPBM_REPOSITORY=' . Fixtures::registryWithoutBlocks());
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--features' => 'led', '--no-interaction' => true])
        ->expectsOutputToContain('led ws2812: no package')
        ->doesntExpectOutputToContain('cannot check --features')
        ->assertExitCode(0);
    expect($this->composer->calls)->toBe([['install']]);
});

it('rejects an unknown kind when the registry has no board packages', function () {
    installDemoSdk();
    putenv('PHPBM_REPOSITORY=' . Fixtures::registryWithoutBlocks());
    $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-screen', '--features' => 'audio', '--no-interaction' => true])
        ->expectsOutputToContain("board 'demo-screen' has no feature 'audio' (features: display, i2c, led)")
        ->assertExitCode(1);
});

/** The demo sdk lists php-baremetal/boards-demo, published at 1.2.0 with pack-board in a registry that PHPBM_REPOSITORY names. */
/**
 * @param array<string,string> $boards id => JSON of the boards of php-baremetal/boards-demo 1.2.0
 * @param list<string> $packages the sdk's board-packages
 */
function officialBoards(array $boards = ['pack-board' => Fixtures::PACK_BOARD], array $packages = ['php-baremetal/boards-demo']): string
{
    installDemoSdk();
    Fixtures::withBoardPackages(app(App\Support\Home::class)->sdks() . '/demo/1.0.0', $packages);
    $zips = Fixtures::tempDir();
    $files = [];
    foreach ($boards as $id => $json) {
        $files["boards/demo/{$id}.json"] = $json;
    }
    $registry = Fixtures::boardsRegistry([
        '1.2.0' => Fixtures::zip("{$zips}/boards-demo-1.2.0.zip", $files),
    ]);
    putenv("PHPBM_REPOSITORY={$registry}");
    return $registry;
}

describe('official board packages', function () {
    beforeEach(function () {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('the zip extension builds the fixture archives');
        }
    });

    it('requires the package of the chosen board', function (string $board) {
        officialBoards();
        $this->artisan('init', ['dir' => $this->dir, '--board' => $board, '--no-interaction' => true])->assertExitCode(0);

        $composer = json_decode(file_get_contents($this->dir . '/composer.json'), true);
        expect($composer['require'])->toBe(['php-baremetal/conductor' => '^1.0', 'php-baremetal/boards-demo' => '^1.2'])
            ->and($composer['extra']['baremetal']['board'])->toBe('pack-board')
            ->and($this->composer->calls)->toBe([['install']]);
    })->with(['pack-board', 'php-baremetal/boards-demo:pack-board']);

    it('requires no board package for a board of the sdk', function () {
        officialBoards();
        $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-net', '--no-interaction' => true])->assertExitCode(0);

        expect(json_decode(file_get_contents($this->dir . '/composer.json'), true)['require'])->toBe(['php-baremetal/conductor' => '^1.0']);
    });

    it('lists the boards of the official packages with their package', function () {
        officialBoards();
        $this->env = app()['env'];
        app()['env'] = 'testing';

        $this->artisan('init', ['dir' => $this->dir, '--runtime' => 'loop', '--storage' => 'embedded', '--features' => 'none'])
            ->expectsQuestion('Project name', 'blink')
            ->expectsChoice('Board', 'pack-board', [
                'demo-board' => 'Demo board (demo-board)',
                'demo-net' => 'Demo net (demo-net)',
                'demo-screen' => 'Demo screen (demo-screen)',
                'demo-sdio' => 'Demo sdio (demo-sdio)',
                'pack-board' => 'Pack board (pack-board) · php-baremetal/boards-demo',
            ])
            ->assertExitCode(0);

        expect(json_decode(file_get_contents($this->dir . '/composer.json'), true)['require'])->toHaveKey('php-baremetal/boards-demo');
    });

    it('reuses the cached boards when the registry is gone', function () {
        $registry = officialBoards();
        $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-net', '--no-interaction' => true])->assertExitCode(0);
        rename($registry, $registry . '-gone');

        $second = Fixtures::tempDir() . '/second';
        $this->artisan('init', ['dir' => $second, '--board' => 'pack-board', '--no-interaction' => true])
            ->doesntExpectOutputToContain('only the boards of the sdk')
            ->assertExitCode(0);
        expect(json_decode(file_get_contents($second . '/composer.json'), true)['require'])->toHaveKey('php-baremetal/boards-demo');
    });

    it('uses an expired cache with a warning when the registry is gone', function () {
        $registry = officialBoards();
        $t = 1_800_000_000;
        app()->instance(App\Board\BoardSources::class, new App\Board\BoardSources($this->home, null, fn () => $t));
        $this->artisan('init', ['dir' => $this->dir, '--board' => 'demo-net', '--no-interaction' => true])->assertExitCode(0);
        rename($registry, $registry . '-gone');

        app()->instance(App\Board\BoardSources::class, new App\Board\BoardSources($this->home, null, fn () => $t + 7200));
        $second = Fixtures::tempDir() . '/second';
        $this->artisan('init', ['dir' => $second, '--board' => 'pack-board', '--no-interaction' => true])
            ->expectsOutputToContain('using the board packages read on ' . date('Y-m-d H:i', $t))
            ->assertExitCode(0);
    });

    it('writes the long form when the sdk and a package define the same id', function (string $board, string $written, array $require) {
        officialBoards(['pack-board' => Fixtures::PACK_BOARD, 'demo-net' => Fixtures::PACK_BOARD]);
        $this->artisan('init', ['dir' => $this->dir, '--board' => $board, '--no-interaction' => true])->assertExitCode(0);

        $composer = json_decode(file_get_contents($this->dir . '/composer.json'), true);
        expect($composer['extra']['baremetal']['board'])->toBe($written)
            ->and($composer['require'])->toBe(['php-baremetal/conductor' => '^1.0', ...$require]);
    })->with([
        'sdk board' => ['sdk:demo-net', 'sdk:demo-net', []],
        'package board' => ['php-baremetal/boards-demo:demo-net', 'php-baremetal/boards-demo:demo-net', ['php-baremetal/boards-demo' => '^1.2']],
        'unique board' => ['pack-board', 'pack-board', ['php-baremetal/boards-demo' => '^1.2']],
    ]);

    it('writes the long form of the board chosen from the menu when its id has two sources', function () {
        officialBoards(['demo-net' => Fixtures::PACK_BOARD]);
        $this->env = app()['env'];
        app()['env'] = 'testing';

        $this->artisan('init', ['dir' => $this->dir, '--runtime' => 'loop', '--storage' => 'embedded', '--features' => 'none'])
            ->expectsQuestion('Project name', 'blink')
            ->expectsChoice('Board', 'sdk:demo-net', [
                'demo-board' => 'Demo board (demo-board)',
                'sdk:demo-net' => 'Demo net (demo-net)',
                'demo-screen' => 'Demo screen (demo-screen)',
                'demo-sdio' => 'Demo sdio (demo-sdio)',
                'php-baremetal/boards-demo:demo-net' => 'Pack board (demo-net) · php-baremetal/boards-demo',
            ])
            ->assertExitCode(0);

        expect(json_decode(file_get_contents($this->dir . '/composer.json'), true)['extra']['baremetal']['board'])->toBe('sdk:demo-net');
    });

    it('writes the long form when two packages define the same id', function () {
        $registry = officialBoards(['twin' => Fixtures::PACK_BOARD], ['php-baremetal/boards-demo', 'php-baremetal/boards-other']);
        $zip = Fixtures::zip(Fixtures::tempDir() . '/boards-other-2.0.0.zip', ['boards/demo/twin.json' => Fixtures::PACK_BOARD]);
        Fixtures::boardsMetadata($registry, ['2.0.0' => $zip], 'php-baremetal/boards-other');

        $this->artisan('init', ['dir' => $this->dir, '--board' => 'php-baremetal/boards-other:twin', '--no-interaction' => true])->assertExitCode(0);

        $composer = json_decode(file_get_contents($this->dir . '/composer.json'), true);
        expect($composer['extra']['baremetal']['board'])->toBe('php-baremetal/boards-other:twin')
            ->and($composer['require'])->toBe(['php-baremetal/conductor' => '^1.0', 'php-baremetal/boards-other' => '^2.0']);
    });

    it('offers only the boards of the sdk without registry and cache', function () {
        officialBoards();
        putenv('PHPBM_REPOSITORY=' . $this->dir . '-nowhere');

        $this->artisan('init', ['dir' => $this->dir, '--board' => 'pack-board', '--no-interaction' => true])
            ->expectsOutputToContain('only the boards of the sdk are offered')
            ->expectsOutputToContain("board 'pack-board' not found in sdk demo 1.0.0")
            ->assertExitCode(1);
    });
});
