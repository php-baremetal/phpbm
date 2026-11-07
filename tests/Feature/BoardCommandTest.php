<?php

declare(strict_types=1);

use App\Support\Composer;
use App\Support\SymfonyProcess;
use PhpBaremetal\Spec\Testing\FakeProcess;
use Tests\Support\Fixtures;

/**
 * A fake Composer with the semantics of real Composer (2.8) for what phpbm runs. It logs its arguments and applies
 * to the composer.json of its working directory:
 * - `config [--json] <key> <value>` and `config --unset <key>`: like Composer, the key is split at most two levels
 *   below `extra` (`extra.a.b.c` names the literal key `b.c` inside `extra.a`, so an unset of it is a no-op);
 * - `require <pkgs>` (constraint `^1.0`) and `remove <pkgs>` (a package not required only warns, exit 0).
 * With $failOn it fails on that subcommand, changing nothing.
 */
function fakeComposer(string $dir, string $failOn = ''): string
{
    $log = "{$dir}/composer-calls.log";
    $script = "{$dir}/fake-composer.php";
    file_put_contents($script, '<?php
$args = array_slice($argv, 1);
file_put_contents(' . var_export($log, true) . ', implode(" ", $args) . "\n", FILE_APPEND);
if (($args[0] ?? "") === ' . var_export($failOn, true) . ') {
    fwrite(STDERR, "\nIn Solver.php line 42:\n                                            \n  Your requirements could not be resolved  \n                                            \n\n" . $args[0] . " [--dev] [--] [<packages>...]\n");
    exit(2);
}
$file = getcwd() . "/composer.json";
$data = json_decode(file_get_contents($file));
function node(stdClass $data, array $path): stdClass {
    $node = $data;
    foreach ($path as $key) {
        if (!isset($node->{$key}) || !$node->{$key} instanceof stdClass) { $node->{$key} = new stdClass(); }
        $node = $node->{$key};
    }
    return $node;
}
function path(string $key): array {
    if (!str_starts_with($key, "extra.")) { fwrite(STDERR, "unsupported key {$key}\n"); exit(1); }
    return explode(".", $key, 3);
}
switch ($args[0]) {
    case "config":
        $rest = array_slice($args, 1);
        $json = $rest[0] === "--json";
        $unset = $rest[0] === "--unset";
        if ($json || $unset) { array_shift($rest); }
        $path = path($rest[0]);
        $last = array_pop($path);
        if ($unset) {
            $parent = $data;
            foreach ($path as $key) { $parent = $parent->{$key} ?? null; if (!$parent instanceof stdClass) { break 2; } }
            unset($parent->{$last});
        } else {
            node($data, $path)->{$last} = $json ? json_decode($rest[1]) : $rest[1];
        }
        break;
    case "require":
        foreach (array_slice($args, 1) as $package) { node($data, ["require"])->{$package} = "^1.0"; }
        break;
    case "remove":
        foreach (array_slice($args, 1) as $package) {
            if (!isset($data->require->{$package})) {
                fwrite(STDERR, "{$package} is not required in your composer.json and has not been removed\n");
            }
            unset($data->require->{$package});
        }
        break;
}
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
');
    $binary = "{$dir}/fake-composer";
    file_put_contents($binary, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . " \"\$@\"\n");
    chmod($binary, 0755);
    app()->instance(Composer::class, new Composer(new SymfonyProcess(), $binary));
    return $log;
}

/** @return list<string> */
function composerCalls(string $log): array
{
    return is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
}

/**
 * @param array<string,mixed> $baremetal merged over demo-screen, loop, embedded
 * @param array<string,string> $require added to the conductor
 */
function boardProject(string $dir, array $baremetal = [], array $require = []): string
{
    $baremetal = ['sdk' => 'demo@^1.0', 'board' => 'demo-screen', 'runtime' => 'loop', 'storage' => 'embedded', ...$baremetal];
    file_put_contents("{$dir}/composer.json", json_encode([
        'name' => 'local/blink',
        'description' => 'Firmware für die Küche',
        'require' => ['php-baremetal/conductor' => '^1.0', ...$require],
        'config' => ['platform' => ['php' => '8.4.25']],
        'scripts' => new stdClass(),
        'extra' => ['baremetal' => $baremetal],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return "{$dir}/composer.json";
}

function composerJson(string $dir): array
{
    return json_decode((string) file_get_contents("{$dir}/composer.json"), true);
}

beforeEach(function () {
    putenv('PHPBM_REPOSITORY=' . dirname(__DIR__) . '/fixtures/registry');
    $this->cwd = getcwd();
    $this->home = Fixtures::bootstrap();
    $this->tools = Fixtures::tempDir();
    $this->log = fakeComposer($this->tools);
    $this->dir = Fixtures::tempDir();
    chdir($this->dir);
});

afterEach(function () {
    chdir($this->cwd);
    putenv('PHPBM_REPOSITORY');
    if (isset($this->env)) {
        app()['env'] = $this->env;
    }
});

const BC_DISPLAY = 'php-baremetal/display-st77916';
const BC_LED = 'php-baremetal/led-ws2812';

it('adds a feature and removes another', function () {
    boardProject($this->dir, require: [BC_DISPLAY => '^1.0']);

    $this->artisan('board', ['--features' => 'led', '--no-interaction' => true])->assertExitCode(0);

    expect(composerCalls($this->log))->toBe(['remove ' . BC_DISPLAY, 'require ' . BC_LED])
        ->and(composerJson($this->dir)['require'])->toBe(['php-baremetal/conductor' => '^1.0', BC_LED => '^1.0']);
});

it('never touches the packages that are not features of the board', function () {
    boardProject($this->dir, require: [BC_DISPLAY => '^1.0', 'acme/sensor' => '^2.0', BC_LED => '^1.0']);

    $this->artisan('board', ['--features' => 'none', '--no-interaction' => true])->assertExitCode(0);

    expect(composerCalls($this->log))->toBe(['remove ' . BC_DISPLAY . ' ' . BC_LED])
        ->and(composerJson($this->dir)['require'])->toBe(['php-baremetal/conductor' => '^1.0', 'acme/sensor' => '^2.0']);
});

it('says there is nothing to change', function () {
    boardProject($this->dir, require: [BC_DISPLAY => '^1.0', 'acme/led-ws2812-alt' => '^2.0']);

    $this->artisan('board', ['--features' => 'all', '--no-interaction' => true])
        ->expectsOutputToContain('nothing to change')
        ->assertExitCode(0);

    expect(composerCalls($this->log))->toBe([]);
});

it('keeps the installed features without a terminal and without --features', function () {
    boardProject($this->dir, require: [BC_LED => '^1.0']);

    $this->artisan('board', ['--no-interaction' => true])
        ->expectsOutputToContain('nothing to change')
        ->assertExitCode(0);

    expect(composerCalls($this->log))->toBe([]);
});

it('offers the features as checkboxes, the installed ones checked', function () {
    boardProject($this->dir, require: [BC_LED => '^1.0']);
    $this->env = app()['env'];
    app()['env'] = 'testing';

    $this->artisan('board')
        ->expectsOutputToContain('i2c i2c: no package')
        ->expectsChoice('Board features', ['display', 'led'], [
            'display' => 'display  st77916  ' . BC_DISPLAY,
            'led' => 'led  ws2812  ' . BC_LED,
        ])
        ->assertExitCode(0);

    expect(composerCalls($this->log))->toBe(['require ' . BC_DISPLAY]);
});

it('rejects a feature kind the board does not have', function () {
    $file = boardProject($this->dir);
    $before = file_get_contents($file);

    $this->artisan('board', ['--features' => 'audio', '--no-interaction' => true])
        ->expectsOutputToContain("board 'demo-screen' has no feature 'audio' (features: display, i2c, led)")
        ->assertExitCode(1);

    expect(file_get_contents($file))->toBe($before)->and(composerCalls($this->log))->toBe([]);
});

it('changes the board, removing the packages and the overrides the new board does not use', function () {
    boardProject($this->dir, ['board-overrides' => ['display' => ['backlight' => 5], 'network' => ['hostname' => 'blink']]], [BC_DISPLAY => '^1.0', BC_LED => '^1.0', 'acme/sensor' => '^2.0']);

    $this->artisan('board', ['board' => 'demo-net', '--no-interaction' => true])
        ->expectsOutputToContain('board: demo-net')
        ->assertExitCode(0);

    $json = composerJson($this->dir);
    expect(composerCalls($this->log))->toBe([
        'config extra.baremetal.board demo-net',
        'config --json extra.baremetal.board-overrides {"network":{"hostname":"blink"}}',
        'remove ' . BC_DISPLAY . ' ' . BC_LED,
    ])
        ->and($json['extra']['baremetal']['board'])->toBe('demo-net')
        ->and($json['extra']['baremetal']['board-overrides'])->toBe(['network' => ['hostname' => 'blink']])
        ->and($json['require'])->toBe(['php-baremetal/conductor' => '^1.0', 'acme/sensor' => '^2.0']);
});

it('changes the board and installs the features of the new one', function () {
    boardProject($this->dir, ['board' => 'demo-net']);

    $this->artisan('board', ['board' => 'demo-screen', '--no-interaction' => true])
        ->expectsOutputToContain('board: demo-screen')
        ->assertExitCode(0);

    expect(composerCalls($this->log))->toBe([
        'config extra.baremetal.board demo-screen',
        'require ' . BC_DISPLAY . ' ' . BC_LED,
    ]);
});

it('asks before removing the overrides and the packages of the old board', function () {
    boardProject($this->dir, ['board-overrides' => ['led' => ['pin' => 3]]], [BC_DISPLAY => '^1.0', BC_LED => '^1.0']);
    $this->env = app()['env'];
    app()['env'] = 'testing';

    $this->artisan('board', ['board' => 'demo-net'])
        ->expectsChoice('Packages not used by board demo-net, to remove', [BC_LED], [
            BC_DISPLAY => BC_DISPLAY,
            BC_LED => BC_LED,
        ])
        ->expectsOutputToContain('led')
        ->expectsConfirmation('Remove these overrides?', 'yes')
        ->assertExitCode(0);

    expect(composerCalls($this->log))->toBe([
        'config extra.baremetal.board demo-net',
        'config --unset extra.baremetal.board-overrides',
        'remove ' . BC_LED,
    ]);
});

it('stops when the overrides are kept', function () {
    $file = boardProject($this->dir, ['board-overrides' => ['led' => ['pin' => 3]]]);
    $before = file_get_contents($file);
    $this->env = app()['env'];
    app()['env'] = 'testing';

    $this->artisan('board', ['board' => 'demo-net'])
        ->expectsConfirmation('Remove these overrides?', 'no')
        ->expectsOutputToContain("the build fails with overrides of blocks board 'demo-net' does not have (led)")
        ->assertExitCode(1);

    expect(file_get_contents($file))->toBe($before)->and(composerCalls($this->log))->toBe([]);
});

it('chooses the new board from a list with --change', function () {
    boardProject($this->dir, ['board' => 'demo-net']);
    $this->env = app()['env'];
    app()['env'] = 'testing';

    $this->artisan('board', ['--change' => true, '--features' => 'none'])
        ->expectsChoice('Board', 'demo-board', [
            'demo-board' => 'Demo board (demo-board)',
            'demo-net' => 'Demo net (demo-net)',
            'demo-screen' => 'Demo screen (demo-screen)',
        ])
        ->expectsOutputToContain('board: demo-board')
        ->assertExitCode(0);

    expect(composerCalls($this->log))->toBe(['config extra.baremetal.board demo-board']);
});

it('refuses a board the storage cannot use and leaves composer.json as it is', function () {
    $file = boardProject($this->dir, ['board' => 'demo-board', 'storage' => 'microsd']);
    $before = file_get_contents($file);

    $this->artisan('board', ['board' => 'demo-screen', '--no-interaction' => true])
        ->expectsOutputToContain("storage 'microsd' needs microsd, but board 'demo-screen' has none")
        ->assertExitCode(1);

    expect(file_get_contents($file))->toBe($before)->and(composerCalls($this->log))->toBe([]);
});

it('refuses a board the runtime cannot run', function () {
    $file = boardProject($this->dir, ['board' => 'demo-net', 'runtime' => 'server']);
    $before = file_get_contents($file);

    $this->artisan('board', ['board' => 'demo-board', '--no-interaction' => true])
        ->expectsOutputToContain("runtime 'server' needs network, but board 'demo-board' has none")
        ->assertExitCode(1);

    expect(file_get_contents($file))->toBe($before);
});

it('treats the current board as no board', function () {
    boardProject($this->dir, require: [BC_LED => '^1.0']);

    $this->artisan('board', ['board' => 'demo-screen', '--no-interaction' => true])
        ->expectsOutputToContain('nothing to change')
        ->doesntExpectOutputToContain('board: demo-screen')
        ->assertExitCode(0);

    expect(composerCalls($this->log))->toBe([]);
});

it('rejects an unknown board', function () {
    $file = boardProject($this->dir);
    $before = file_get_contents($file);

    $this->artisan('board', ['board' => 'nope', '--no-interaction' => true])
        ->expectsOutputToContain("board 'nope' does not exist in sdk demo 1.0.0")
        ->assertExitCode(1);

    expect(file_get_contents($file))->toBe($before);
});

it('needs a project', function () {
    $this->artisan('board', ['--no-interaction' => true])
        ->expectsOutputToContain("no composer.json in {$this->dir}: run phpbm init")
        ->assertExitCode(1);
});

it('only changes the board in composer.json with --no-install and prints the commands', function () {
    boardProject($this->dir, ['board-overrides' => ['display' => ['backlight' => 5]]], [BC_DISPLAY => '^1.0', BC_LED => '^1.0']);
    $before = composerJson($this->dir);

    $this->artisan('board', ['board' => 'demo-net', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('board: demo-net')
        ->expectsOutputToContain('composer remove ' . BC_DISPLAY . ' ' . BC_LED)
        ->assertExitCode(0);

    $after = composerJson($this->dir);
    $before['extra']['baremetal']['board'] = 'demo-net';
    unset($before['extra']['baremetal']['board-overrides']);
    $raw = file_get_contents($this->dir . '/composer.json');
    expect(composerCalls($this->log))->toBe([])
        ->and($after)->toBe($before)
        ->and($raw)->toContain('"scripts": {}')
        ->and($raw)->toContain('"description": "Firmware für die Küche"')
        ->and($raw)->not->toContain('board-overrides');
});

it('prints the feature commands with --no-install and leaves composer.json as it is', function () {
    $file = boardProject($this->dir, require: [BC_DISPLAY => '^1.0']);
    $before = file_get_contents($file);

    $this->artisan('board', ['--features' => 'led', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('composer remove ' . BC_DISPLAY)
        ->expectsOutputToContain('composer require ' . BC_LED)
        ->assertExitCode(0);

    expect(file_get_contents($file))->toBe($before)->and(composerCalls($this->log))->toBe([]);
});

it('says which steps were done when composer fails half way', function () {
    $this->log = fakeComposer($this->tools, 'remove');
    boardProject($this->dir, ['board-overrides' => ['display' => ['backlight' => 5]]], [BC_DISPLAY => '^1.0', BC_LED => '^1.0']);

    $this->artisan('board', ['board' => 'demo-net', '--no-interaction' => true])
        ->expectsOutputToContain('composer remove ' . BC_DISPLAY . ' ' . BC_LED . ' failed (exit 2): Your requirements could not be resolved; '
            . 'done so far: composer config extra.baremetal.board demo-net, composer config --unset extra.baremetal.board-overrides')
        ->assertExitCode(1);

    expect(composerJson($this->dir)['extra']['baremetal']['board'])->toBe('demo-net');
});

it('says nothing was done when the first composer step fails', function () {
    $this->log = fakeComposer($this->tools, 'require');
    boardProject($this->dir);

    $this->artisan('board', ['--features' => 'led', '--no-interaction' => true])
        ->expectsOutputToContain('composer require ' . BC_LED . ' failed (exit 2): Your requirements could not be resolved; done so far: nothing')
        ->assertExitCode(1);
});

it('needs composer unless --no-install', function () {
    $file = boardProject($this->dir);
    $before = file_get_contents($file);
    app()->instance(Composer::class, new Composer(new FakeProcess(), '/nonexistent/composer'));

    $this->artisan('board', ['--features' => 'led', '--no-interaction' => true])
        ->expectsOutputToContain('composer not found: set PHPBM_COMPOSER, install Composer, or use --no-install')
        ->assertExitCode(1);

    expect(file_get_contents($file))->toBe($before);
});

it('removes board-overrides when no override is left, with Composer and with --no-install alike', function () {
    $overrides = ['board-overrides' => ['display' => ['backlight' => 5], 'led' => ['pin' => 3]]];
    boardProject($this->dir, $overrides, [BC_DISPLAY => '^1.0']);
    $other = Fixtures::tempDir();
    boardProject($other, $overrides, [BC_DISPLAY => '^1.0']);

    $this->artisan('board', ['board' => 'demo-net', '--no-interaction' => true])->assertExitCode(0);
    chdir($other);
    $this->artisan('board', ['board' => 'demo-net', '--no-install' => true, '--no-interaction' => true])
        ->expectsOutputToContain('composer remove ' . BC_DISPLAY)
        ->assertExitCode(0);

    $json = composerJson($this->dir);
    expect(composerCalls($this->log))->toBe([
        'config extra.baremetal.board demo-net',
        'config --unset extra.baremetal.board-overrides',
        'remove ' . BC_DISPLAY,
    ])
        ->and($json['extra']['baremetal'])->not->toHaveKey('board-overrides')
        ->and($json['extra']['baremetal']['board'])->toBe('demo-net')
        ->and(composerJson($other)['extra'])->toBe($json['extra']);
});

it('prints the notes and has nothing to change when the registry has no board packages', function () {
    putenv('PHPBM_REPOSITORY=' . Fixtures::registryWithoutBlocks());
    boardProject($this->dir, require: [BC_LED => '^1.0']);

    $this->artisan('board', ['--no-interaction' => true])
        ->expectsOutputToContain('led ws2812: no package')
        ->expectsOutputToContain('nothing to change')
        ->doesntExpectOutputToContain('cannot be reached')
        ->assertExitCode(0);

    expect(composerCalls($this->log))->toBe([]);
});

it('checks --features normally when the registry has no board packages', function () {
    putenv('PHPBM_REPOSITORY=' . Fixtures::registryWithoutBlocks());
    boardProject($this->dir);

    $this->artisan('board', ['--features' => 'led', '--no-interaction' => true])
        ->expectsOutputToContain('nothing to change')
        ->assertExitCode(0);
    $this->artisan('board', ['--features' => 'audio', '--no-interaction' => true])
        ->expectsOutputToContain("board 'demo-screen' has no feature 'audio' (features: display, i2c, led)")
        ->assertExitCode(1);

    expect(composerCalls($this->log))->toBe([]);
});

it('fails in features mode only when the registry cannot be read', function () {
    putenv('PHPBM_REPOSITORY=' . $this->dir . '-nowhere');
    boardProject($this->dir);

    $this->artisan('board', ['--no-interaction' => true])
        ->expectsOutputToContain('the list of board packages is not available')
        ->assertExitCode(1);
});
