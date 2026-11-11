<?php

declare(strict_types=1);

use App\Sdk\Lock;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->cwd = getcwd();
    $this->home = Fixtures::bootstrap();
    $this->project = Fixtures::project(Fixtures::tempDir(), ['env' => '.env']);
    file_put_contents($this->project . '/.env', "SSID=home\n");
    chdir($this->project);
});

afterEach(fn () => chdir($this->cwd));

it('asks for the toolchain when it is missing', function () {
    $this->artisan('build')
        ->expectsOutputToContain('the demo toolchain is not installed (demo tools missing): run `phpbm toolchain:install`')
        ->assertExitCode(1);
});

it('builds through the sdk driver', function () {
    Fixtures::toolchain($this->home);

    $this->artisan('build')
        ->expectsOutputToContain('demo build')
        ->expectsOutputToContain('built 1 image(s) in ' . $this->project . '/.phpbm/build')
        ->assertExitCode(0);

    expect(file_get_contents($this->project . '/.phpbm/build/app.bin'))->toBe("patched\n")
        ->and(Fixtures::calls($this->home))->toBe(['build demo-board 8.4.25 loop {"SSID":"home"} {"on-error":"stop","restart-delay":5}'])
        ->and(Lock::read($this->project)?->version)->toBe('1.0.0');
});

it('hands the fonts to the sdk driver', function () {
    Fixtures::toolchain($this->home);
    $dejavu = new \PhpBaremetal\Spec\Manifest\Package('php-baremetal/ext-display', 'bm-lib', '1.0.0', 'pkgs/display', ['fonts' => ['default' => 'fonts/default.h']]);
    @mkdir($this->project . '/pkgs/display/fonts', 0777, true);
    file_put_contents($this->project . '/pkgs/display/fonts/default.h', 'x');
    @mkdir($this->project . '/project-fonts');
    file_put_contents($this->project . '/project-fonts/a.h', 'x');
    Fixtures::project($this->project, ['env' => '.env'], [$dejavu]);

    $this->artisan('build')->assertExitCode(0);

    expect(Fixtures::calls($this->home))->toBe(['build demo-board 8.4.25 loop {"SSID":"home"} {"on-error":"stop","restart-delay":5} fonts a,default']);
});

it('writes one merged image with --merged', function () {
    Fixtures::toolchain($this->home);
    $merged = $this->project . '/.phpbm/build/merged.bin';

    $this->artisan('build --merged')
        ->expectsOutputToContain("merged image: {$merged} (65544 bytes, write it at offset 0x0)")
        ->assertExitCode(0);

    expect(file_get_contents($merged))->toBe(str_repeat("\xFF", 0x10000) . "patched\n");
});

it('writes no merged image without --merged', function () {
    Fixtures::toolchain($this->home);

    $this->artisan('build')->assertExitCode(0);

    expect(file_exists($this->project . '/.phpbm/build/merged.bin'))->toBeFalse();
});

it('hands the resolved options to the driver', function () {
    Fixtures::toolchain($this->home);
    Fixtures::project($this->project, ['env' => '.env', 'options' => ['restart-delay' => 30]]);

    $this->artisan('build')->assertExitCode(0);

    expect(Fixtures::calls($this->home))->toBe(['build demo-board 8.4.25 loop {"SSID":"home"} {"on-error":"stop","restart-delay":30}']);
});

it('hands the selected php extensions to the driver, required ones first', function () {
    Fixtures::toolchain($this->home);
    Fixtures::project($this->project, ['env' => '.env'], [
        new PhpBaremetal\Spec\Manifest\Package('php-baremetal/php-pdo-base', 'bm-php-ext', '1.0.0', 'p', ['php-ext' => 'pdo_base']),
        new PhpBaremetal\Spec\Manifest\Package('php-baremetal/php-base', 'bm-php-ext', '1.0.0', 'p', ['php-ext' => 'base']),
    ]);

    $this->artisan('build')->assertExitCode(0);

    expect(Fixtures::calls($this->home))->toBe(['build demo-board 8.4.25 loop {"SSID":"home"} {"base-cjk":true,"on-error":"stop","restart-delay":5} php-ext base,pdo_base']);
});

it('prepares an external source only when the extension options use it', function () {
    Fixtures::toolchain($this->home);
    $regx = [new PhpBaremetal\Spec\Manifest\Package('php-baremetal/php-regx', 'bm-php-ext', '1.0.0', 'p', ['php-ext' => 'regx'])];
    Fixtures::project($this->project, ['env' => '.env'], $regx);

    $this->artisan('build')->assertExitCode(0);

    Fixtures::project($this->project, ['env' => '.env', 'options' => ['regx-onig' => true]], $regx);
    $this->artisan('build')
        ->expectsOutputToContain('preparing rxlib')
        ->assertExitCode(1);
});

it('reports project problems before building', function () {
    Fixtures::toolchain($this->home);
    Fixtures::project($this->project, ['storage' => 'microsd', 'board' => 'demo-net']);

    $this->artisan('build')
        ->expectsOutputToContain("storage 'microsd' needs microsd, but board 'demo-net' has none")
        ->assertExitCode(1);
    expect(Fixtures::calls($this->home))->toBe([]);
});

it('builds the image of the embedded storage from the sources and the embedded assets', function () {
    Fixtures::toolchain($this->home);
    @mkdir($this->project . '/assets/ui/icons', 0777, true);
    @mkdir($this->project . '/assets/photos', 0777, true);
    copy(__DIR__ . '/../fixtures/images/8x8-spans.png', $this->project . '/assets/ui/icons/coffee.png');
    copy(__DIR__ . '/../fixtures/images/1x1-opaque.sli', $this->project . '/assets/ui/ready.sli');
    copy(__DIR__ . '/../fixtures/images/1x1-opaque.png', $this->project . '/assets/photos/beans.png');
    file_put_contents($this->project . '/assets/ui/notes.txt', 'x');
    Fixtures::project($this->project, ['env' => '.env', 'assets' => [
        ['dir' => 'assets/ui', 'storage' => 'embedded'],
        ['dir' => 'assets/photos', 'storage' => 'microsd'],
    ]]);

    $this->artisan('build')
        ->expectsOutputToContain('asset ignored (not .png or .sli): assets/ui/notes.txt')
        ->assertExitCode(0);

    $staging = $this->project . '/.phpbm/build/app';
    $images = __DIR__ . '/../fixtures/images';
    $assets = json_decode((string) file_get_contents($this->project . '/.phpbm/build/assets.json'), true);
    expect(file_get_contents($this->project . '/.phpbm/build/image-dir.txt'))->toBe($staging)
        ->and(file_get_contents($this->project . '/.phpbm/build/app-src.txt'))->toBe($this->project . '/project-src')
        ->and(array_map(fn ($a) => [$a['storage'], $a['board'], base64_decode($a['content'])], $assets))->toBe([
            ['embedded', '/app/assets/ui/icons/coffee.sli', file_get_contents("{$images}/8x8-spans.sli")],
            ['embedded', '/app/assets/ui/ready.sli', file_get_contents("{$images}/1x1-opaque.sli")],
            ['microsd', '/sdcard/assets/photos/beans.sli', file_get_contents("{$images}/1x1-opaque.sli")],
        ])
        ->and(array_map(fn ($a) => str_starts_with($a['file'], '/'), $assets))->toBe([true, true, true])
        ->and(file_get_contents("{$staging}/index.php"))->toBe("<?php echo 'hi';\n")
        ->and(file_get_contents("{$staging}/assets/ui/icons/coffee.sli"))->toBe(file_get_contents(__DIR__ . '/../fixtures/images/8x8-spans.sli'))
        ->and(file_get_contents("{$staging}/assets/ui/ready.sli"))->toBe(file_get_contents(__DIR__ . '/../fixtures/images/1x1-opaque.sli'))
        ->and(is_dir("{$staging}/assets/photos"))->toBeFalse();
});

it('builds the image from the source folder itself without embedded assets', function () {
    Fixtures::toolchain($this->home);
    @mkdir($this->project . '/assets/photos', 0777, true);
    copy(__DIR__ . '/../fixtures/images/1x1-opaque.png', $this->project . '/assets/photos/beans.png');
    Fixtures::project($this->project, ['env' => '.env', 'assets' => [['dir' => 'assets/photos', 'storage' => 'microsd']]]);

    $this->artisan('build')->assertExitCode(0);

    $assets = json_decode((string) file_get_contents($this->project . '/.phpbm/build/assets.json'), true);
    expect(file_get_contents($this->project . '/.phpbm/build/image-dir.txt'))->toBe($this->project . '/project-src')
        ->and(file_get_contents($this->project . '/.phpbm/build/app-src.txt'))->toBe($this->project . '/project-src')
        ->and(array_map(fn ($a) => [$a['storage'], $a['board']], $assets))->toBe([['microsd', '/sdcard/assets/photos/beans.sli']])
        ->and(base64_decode($assets[0]['content']))->toBe(file_get_contents(__DIR__ . '/../fixtures/images/1x1-opaque.sli'))
        ->and(is_dir($this->project . '/.phpbm/build/app'))->toBeFalse();
});

it('reports an asset problem before building', function () {
    Fixtures::toolchain($this->home);
    @mkdir($this->project . '/assets/ui', 0777, true);
    copy(__DIR__ . '/../fixtures/images/1x1-opaque.png', $this->project . '/assets/ui/a.png');
    copy(__DIR__ . '/../fixtures/images/1x1-opaque.sli', $this->project . '/assets/ui/a.sli');
    Fixtures::project($this->project, ['env' => '.env', 'assets' => [['dir' => 'assets/ui', 'storage' => 'embedded']]]);

    $this->artisan('build')
        ->expectsOutputToContain('assets/ui/a.png and assets/ui/a.sli both become /app/assets/ui/a.sli')
        ->assertExitCode(1);
    expect(Fixtures::calls($this->home))->toBe([]);
});

it('keeps .phpbm out of the image when the sources are the project root', function () {
    Fixtures::toolchain($this->home);
    @mkdir($this->project . '/assets/ui', 0777, true);
    copy(__DIR__ . '/../fixtures/images/1x1-opaque.png', $this->project . '/assets/ui/dot.png');
    file_put_contents($this->project . '/index.php', "<?php echo 'root';\n");
    Fixtures::project($this->project, ['env' => '.env', 'src' => '.', 'assets' => [['dir' => 'assets/ui', 'storage' => 'embedded']]]);

    $this->artisan('build')->assertExitCode(0);
    $this->artisan('build')->assertExitCode(0);

    $staging = $this->project . '/.phpbm/build/app';
    expect(file_get_contents($this->project . '/.phpbm/build/image-dir.txt'))->toBe($staging)
        ->and(is_dir("{$staging}/.phpbm"))->toBeFalse()
        ->and(is_file("{$staging}/assets/ui/dot.sli"))->toBeTrue()
        ->and(file_get_contents("{$staging}/index.php"))->toBe("<?php echo 'root';\n");
});
