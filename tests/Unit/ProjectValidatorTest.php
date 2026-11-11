<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Project\Project;
use App\Project\ProjectValidator;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Sdk\SdkLoader;
use PhpBaremetal\Spec\SpecException;
use Tests\Support\Fixtures;

beforeEach(function () {
    $root = Fixtures::tempDir();
    $this->sdk = (new SdkLoader())->load(Fixtures::sdk($root . '/demo-sdk', '1.0.0', Fixtures::phpTarball($root)));
});

function validateDemoProject(array $baremetal = [], array $packages = []): App\Project\Target
{
    return (new ProjectValidator())->validate(Project::load(Fixtures::project(Fixtures::tempDir(), $baremetal, $packages)), test()->sdk);
}

it('resolves the target of a valid project', function () {
    $target = validateDemoProject(['board-overrides' => ['microsd' => ['cs' => 9]]]);
    expect($target->board->peripherals['microsd']['cs'])->toBe(9)
        ->and($target->chip->target)->toBe('demo')
        ->and($target->runtime->id)->toBe('loop')
        ->and($target->storage->id)->toBe('embedded')
        ->and($target->php->version)->toBe('8.4.25');
});

it('applies the defaults of the runtime and storage options', function () {
    expect(validateDemoProject()->options)->toBe(['on-error' => 'stop', 'restart-delay' => 5]);
});

it('lets the project override an option', function () {
    expect(validateDemoProject(['options' => ['on-error' => 'continue']])->options)->toBe(['on-error' => 'continue', 'restart-delay' => 5]);
});

it('lets the project set an sdk-level option next to the runtime ones', function () {
    expect(validateDemoProject(['runtime' => 'xnamed', 'options' => ['on-error' => 'continue', 'label' => 'abc']])->options)
        ->toBe(['label' => 'abc', 'on-error' => 'continue']);
    expect(fn () => validateDemoProject(['runtime' => 'xnamed', 'options' => ['on-error' => 'retry']]))
        ->toThrow(PhpbmException::class, 'extra.baremetal.options.on-error: must be one of');
});

it('rejects an unknown option and lists the accepted ones', function () {
    expect(fn () => validateDemoProject(['options' => ['nope' => 1]]))
        ->toThrow(PhpbmException::class, "unknown option 'nope' in extra.baremetal.options (accepted: on-error, restart-delay)");
});

it('rejects an invalid option value', function () {
    expect(fn () => validateDemoProject(['options' => ['restart-delay' => '5', 'on-error' => 'retry']]))
        ->toThrow(PhpbmException::class, 'extra.baremetal.options.restart-delay: must be an integer between 0 and 3600, got "5"');
    expect(fn () => validateDemoProject(['options' => ['restart-delay' => 5, 'on-error' => 'retry']]))
        ->toThrow(PhpbmException::class, 'extra.baremetal.options.on-error: must be one of');
});

it('reports an option declared by both runtime and storage', function () {
    expect(fn () => validateDemoProject(['runtime' => 'server', 'storage' => 'microsd', 'board' => 'demo-net']))
        ->toThrow(PhpbmException::class, "option 'retries' is declared by both runtime 'server' and storage 'microsd': this is an sdk bug");
});

it('reports an option declared by both the sdk and a runtime', function () {
    expect(fn () => validateDemoProject(['runtime' => 'xclash']))
        ->toThrow(PhpbmException::class, "option 'on-error' is declared by both sdk and runtime 'xclash': this is an sdk bug");
});

it('accepts and defaults string options', function () {
    expect(validateDemoProject(['runtime' => 'xnamed'])->options)->toBe(['label' => 'x', 'on-error' => 'stop']);
    expect(validateDemoProject(['runtime' => 'xnamed', 'options' => ['label' => 'abc']])->options['label'])->toBe('abc');
    expect(fn () => validateDemoProject(['runtime' => 'xnamed', 'options' => ['label' => 'ABC']]))
        ->toThrow(PhpbmException::class, 'extra.baremetal.options.label: must be a string that must match #^[a-z]*$#D, got "ABC"');
});

it('lists every unmet requirement at once', function () {
    expect(fn () => validateDemoProject(['runtime' => 'server', 'storage' => 'microsd', 'board' => 'demo-net']))
        ->toThrow(PhpbmException::class, implode("\n  - ", [
            'the project does not fit sdk demo 1.0.0:',
            "runtime 'server' needs php-baremetal/ext-web: run `composer require php-baremetal/ext-web`",
            "storage 'microsd' needs microsd, but board 'demo-net' has none",
        ]));
});

it('accepts a runtime whose requirements are met', function () {
    $web = new Package('php-baremetal/ext-web', 'bm-ext', '1.0.0', 'p', ['module' => 'web']);
    expect(validateDemoProject(['runtime' => 'server', 'board' => 'demo-net'], [$web])->runtime->id)->toBe('server');
});

it('reports a missing entry script', function () {
    $dir = Fixtures::project(Fixtures::tempDir());
    unlink($dir . '/project-src/index.php');
    expect(fn () => (new ProjectValidator())->validate(Project::load($dir), $this->sdk))
        ->toThrow(PhpbmException::class, 'entry script project-src/index.php does not exist');
});

it('lets descriptor lookups explain unknown names', function () {
    expect(fn () => validateDemoProject(['board' => 'nope']))->toThrow(SpecException::class, "board 'nope' not found in sdk demo 1.0.0");
});

function phpExt(string $name, string $ext): Package
{
    return new Package($name, 'bm-php-ext', '1.0.0', 'p', ['php-ext' => $ext]);
}

it('selects the php extensions of the bm-php-ext packages, required ones first', function () {
    $target = validateDemoProject([], [phpExt('php-baremetal/php-pdo-base', 'pdo_base'), phpExt('php-baremetal/php-base', 'base')]);
    expect(array_map(fn ($e) => $e->name, $target->phpExtensions))->toBe(['base', 'pdo_base'])
        ->and(validateDemoProject()->phpExtensions)->toBe([]);
});

it('merges the options of the active php extensions with the others', function () {
    expect(validateDemoProject([], [phpExt('php-baremetal/php-base', 'base')])->options)
        ->toBe(['base-cjk' => true, 'on-error' => 'stop', 'restart-delay' => 5]);
    expect(validateDemoProject(['options' => ['base-cjk' => false]], [phpExt('php-baremetal/php-base', 'base')])->options['base-cjk'])
        ->toBeFalse();
});

it('rejects the option of a php extension that is not installed', function () {
    expect(fn () => validateDemoProject(['options' => ['base-cjk' => false]]))
        ->toThrow(PhpbmException::class, "unknown option 'base-cjk' in extra.baremetal.options (accepted: on-error, restart-delay)");
});

it('rejects a php extension the sdk does not provide for the PHP minor', function () {
    expect(fn () => validateDemoProject([], [phpExt('php-baremetal/php-x', 'x')]))
        ->toThrow(PhpbmException::class, "php-baremetal/php-x requires the PHP extension 'x', which sdk demo 1.0.0 does not provide for PHP 8.4");
});

it('rejects a php extension whose required extension is missing', function () {
    expect(fn () => validateDemoProject([], [phpExt('php-baremetal/php-pdo-base', 'pdo_base')]))
        ->toThrow(PhpbmException::class, "php extension 'pdo_base' needs 'base': composer require php-baremetal/php-base");
});

it('reports an extension option that collides with another option', function () {
    expect(fn () => validateDemoProject([], [phpExt('php-baremetal/php-xclash', 'xclash')]))
        ->toThrow(PhpbmException::class, "option 'restart-delay' is declared by both runtime 'loop' and php extension 'xclash': this is an sdk bug");
});

function demoSizePackage(array $options = []): Package
{
    return new Package('php-baremetal/ext-demo', 'bm-ext', '1.0.0', 'p', [
        'module' => 'demo',
        'options' => $options ?: ['demo-size' => ['type' => 'int', 'min' => 1, 'max' => 100, 'default' => 4, 'description' => 'Size of the demo buffer.']],
    ]);
}

it('resolves the default of an option declared by a package', function () {
    expect(validateDemoProject([], [demoSizePackage()])->options)->toBe(['demo-size' => 4, 'on-error' => 'stop', 'restart-delay' => 5]);
});

it('lets the project set an option declared by a package', function () {
    expect(validateDemoProject(['options' => ['demo-size' => 50]], [demoSizePackage()])->options['demo-size'])->toBe(50);
});

it('rejects an out-of-range value for a package option', function () {
    expect(fn () => validateDemoProject(['options' => ['demo-size' => 500]], [demoSizePackage()]))
        ->toThrow(PhpbmException::class, 'extra.baremetal.options.demo-size: must be an integer between 1 and 100, got 500');
});

it('lists the package options among the accepted ones', function () {
    expect(fn () => validateDemoProject(['options' => ['nope' => 1]], [demoSizePackage()]))
        ->toThrow(PhpbmException::class, "unknown option 'nope' in extra.baremetal.options (accepted: demo-size, on-error, restart-delay)");
});

it('reports an option declared by both the sdk and a package', function () {
    $clash = new Package('php-baremetal/ext-demo', 'bm-ext', '1.0.0', 'p', [
        'module' => 'on',
        'options' => ['on-error' => ['type' => 'int', 'default' => 1, 'description' => 'Clash.']],
    ]);
    expect(fn () => validateDemoProject([], [$clash]))
        ->toThrow(PhpbmException::class, "option 'on-error' is declared by both sdk and php-baremetal/ext-demo");
});

it('reports an invalid option definition of a package', function () {
    $bad = demoSizePackage(['demo-size' => ['type' => 'nope']]);
    expect(fn () => validateDemoProject([], [$bad]))->toThrow(PhpbmException::class, 'php-baremetal/ext-demo');
});

function effectsPackage(): Package
{
    return new Package('php-baremetal/ext-slate', 'bm-ext', '1.0.0', 'p', [
        'module' => 'slate',
        'options' => ['slate-effects' => ['type' => 'set', 'values' => ['gradients', 'shadows', 'blur'], 'default' => ['gradients', 'shadows', 'blur'], 'description' => 'Effects.']],
    ]);
}

it('accepts and defaults set options, keeping the order of the declared values', function () {
    expect(validateDemoProject([], [effectsPackage()])->options['slate-effects'])->toBe(['gradients', 'shadows', 'blur']);
    expect(validateDemoProject(['options' => ['slate-effects' => ['blur', 'shadows']]], [effectsPackage()])->options['slate-effects'])->toBe(['shadows', 'blur']);
    expect(validateDemoProject(['options' => ['slate-effects' => []]], [effectsPackage()])->options['slate-effects'])->toBe([]);
});

it('rejects a set option value that is not allowed', function () {
    expect(fn () => validateDemoProject(['options' => ['slate-effects' => ['glow']]], [effectsPackage()]))
        ->toThrow(PhpbmException::class, 'extra.baremetal.options.slate-effects: must be a list of gradients, shadows, blur, got ["glow"]');
});

/** @param list<string> $dirs asset folders to create */
function validateAssetProject(array $baremetal, array $dirs): void
{
    $dir = Fixtures::tempDir();
    foreach ($dirs as $assets) {
        @mkdir("{$dir}/{$assets}", 0777, true);
    }
    (new ProjectValidator())->validate(Project::load(Fixtures::project($dir, $baremetal)), test()->sdk);
}

it('accepts asset folders on the storages of the sdk', function () {
    validateAssetProject(['assets' => [
        ['dir' => 'assets/ui', 'storage' => 'embedded'],
        ['dir' => 'assets/photos', 'storage' => 'microsd'],
    ]], ['assets/ui', 'assets/photos']);
})->throwsNoExceptions();

it('reports a missing asset folder', function () {
    expect(fn () => validateAssetProject(['assets' => [['dir' => 'assets/ui', 'storage' => 'embedded']]], []))
        ->toThrow(PhpbmException::class, 'asset directory assets/ui does not exist');
});

it('reports an asset storage the sdk does not have', function () {
    expect(fn () => validateAssetProject(['assets' => [['dir' => 'assets/ui', 'storage' => 'flash']]], ['assets/ui']))
        ->toThrow(PhpbmException::class, "assets in assets/ui: unknown storage 'flash' (sdk demo 1.0.0 has embedded, microsd)");
});

it('reports microsd assets on a board without a microSD slot', function () {
    expect(fn () => validateAssetProject(['board' => 'demo-net', 'assets' => [['dir' => 'assets/photos', 'storage' => 'microsd']]], ['assets/photos']))
        ->toThrow(PhpbmException::class, 'assets in assets/photos need a microSD slot, and Demo net has none');
});

it('reports embedded assets when the code is not on embedded storage', function () {
    expect(fn () => validateAssetProject(['storage' => 'microsd', 'assets' => [['dir' => 'assets/ui', 'storage' => 'embedded']]], ['assets/ui']))
        ->toThrow(PhpbmException::class, "assets in assets/ui: with the code on the microSD (extra.baremetal.storage 'microsd') assets go on the microSD: use storage 'microsd'");
});

const PV_SD = ['type' => 'spi', 'cs' => 10, 'sclk' => 12, 'mosi' => 11, 'miso' => 13];

it('adds a block the board does not have from board-overrides', function () {
    $target = validateDemoProject(['board' => 'demo-net', 'storage' => 'microsd', 'board-overrides' => ['microsd' => PV_SD]]);
    expect($target->board->peripherals['microsd'])->toBe(PV_SD)
        ->and($target->board->capabilities())->toBe(['network' => 'wifi', 'microsd' => 'spi']);
});

it('checks the storage against the board after the overrides', function () {
    expect(fn () => validateDemoProject(['board' => 'demo-net', 'storage' => 'microsd', 'board-overrides' => ['network' => ['hostname' => 'x']]]))
        ->toThrow(PhpbmException::class, "storage 'microsd' needs microsd, but board 'demo-net' has none");
});

it('accepts microsd assets on a board whose microSD slot comes from the overrides', function () {
    validateAssetProject(['board' => 'demo-net', 'board-overrides' => ['microsd' => PV_SD], 'assets' => [['dir' => 'assets/photos', 'storage' => 'microsd']]], ['assets/photos']);
})->throwsNoExceptions();

it('rejects an override that changes the type of a block of the board', function () {
    expect(fn () => validateDemoProject(['board-overrides' => ['microsd' => ['type' => 'sdio']]]))
        ->toThrow(SpecException::class, "board-overrides: 'microsd.type' cannot be overridden: board 'demo-board' has a 'spi' microsd");
});

it('rejects an added block of a kind neither the sdk nor an installed package knows', function () {
    foreach (['netwrok', 'php', 'sdk'] as $name) {
        expect(fn () => validateDemoProject(['board' => 'demo-net', 'board-overrides' => [$name => ['type' => 'w5500', 'cs' => 1]]]))
            ->toThrow(PhpbmException::class, "board-overrides: '{$name}' is not a block of sdk demo 1.0.0 or of an installed package (blocks: display, i2c, led, microsd, network); a second block of a kind takes a suffix, e.g. led-2");
    }
});

it('accepts added blocks of the kinds of the sdk boards, with a suffix, and of installed packages', function () {
    $imu = new Package('acme/imu-x', 'bm-ext', '1.0.0', 'p', ['module' => 'imu', 'board-blocks' => ['imu:x1']]);
    $target = validateDemoProject(['board' => 'demo-net', 'board-overrides' => [
        'led-2' => ['type' => 'ws2812', 'pin' => 3],
        'display' => ['type' => 'st77916'],
        'imu' => ['type' => 'x1'],
    ]], [$imu]);
    expect(array_keys($target->board->peripherals))->toBe(['network', 'led-2', 'display', 'imu']);
});

it('rejects an added sdk block of a type the chip does not support', function () {
    expect(fn () => validateDemoProject(['board' => 'demo-net', 'board-overrides' => ['microsd' => ['type' => 'sdio']]]))
        ->toThrow(PhpbmException::class, "board-overrides: chip 'demo' does not support microsd 'sdio' (it supports spi)");
});
