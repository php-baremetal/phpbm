<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Sdk\SdkIndex;
use App\Support\Home;
use App\Support\SymfonyProcess;
use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Manifest\Project;
use RuntimeException;

final class Fixtures
{
    /** @var list<string> */
    private static array $dirs = [];

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpbm-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        return self::$dirs[] = realpath($dir);
    }

    public static function cleanup(): void
    {
        $tmp = realpath(sys_get_temp_dir());
        foreach (self::$dirs as $dir) {
            if (dirname($dir) === $tmp && str_starts_with(basename($dir), 'phpbm-test-')) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
            }
        }
        self::$dirs = [];
    }

    public static function tar(string $archive, string $from, string ...$entries): void
    {
        $result = (new SymfonyProcess())->run(['tar', '-czf', $archive, '-C', $from, ...$entries]);
        if (!$result->successful()) {
            throw new RuntimeException($result->errorOutput);
        }
    }

    /** @return array{path:string, sha256:string} */
    public static function phpTarball(string $dir, string $version = '8.4.25'): array
    {
        @mkdir("{$dir}/php-src/php-{$version}/main", 0777, true);
        file_put_contents("{$dir}/php-src/php-{$version}/main/php.h", "orig\n");
        $path = "{$dir}/php-{$version}.tar.gz";
        self::tar($path, "{$dir}/php-src", "php-{$version}");
        return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }

    /** @param array{path:string, sha256:string} $php */
    public static function sdk(string $dir, string $version, array $php): string
    {
        $files = [
            'sdk.json' => json_encode([
                'name' => 'demo',
                'version' => $version,
                'contract' => 1,
                'driver' => ['class' => 'DemoSdk\\Driver', 'autoload' => ['psr-4' => ['DemoSdk\\' => 'driver/']]],
                'options' => ['on-error' => ['description' => 'On an uncaught exception', 'values' => ['stop', 'continue'], 'default' => 'stop']],
                'php' => ['8.4' => ['version' => '8.4.25', 'url' => $php['path'], 'sha256' => $php['sha256']]],
                'sources' => ['rxlib' => ['url' => "{$dir}/missing/rxlib.tar.gz", 'sha256' => str_repeat('0', 64)]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'chips/demo/chip.json' => '{"name":"Demo","target":"demo","capabilities":{"microsd":["spi"],"network":["wifi"]},"psram":["quad"]}',
            'boards/demo-board.json' => '{"name":"Demo board","chip":"demo","flash":"4MB","microsd":{"type":"spi","cs":1,"sclk":2,"mosi":3,"miso":4}}',
            'boards/demo-net.json' => '{"name":"Demo net","chip":"demo","flash":"8MB","network":{"type":"wifi"}}',
            'runtimes/loop/runtime.json' => '{"description":"Runs a loop","options":{"restart-delay":{"description":"Seconds before a restart","type":"int","min":0,"max":3600,"default":5}}}',
            'runtimes/server/runtime.json' => '{"description":"HTTP server","options":{"retries":{"description":"Retries","type":"int","min":0,"max":9,"default":3}},"requires":{"capabilities":["network"],"packages":["php-baremetal/ext-web"]}}',
            'runtimes/xclash/runtime.json' => '{"description":"Clashes with the sdk","options":{"on-error":{"description":"Again","values":["stop","continue"],"default":"stop"}}}',
            'runtimes/xnamed/runtime.json' => '{"description":"Has a string option","options":{"label":{"description":"A label","type":"string","pattern":"#^[a-z]*$#D","default":"x"}}}',
            'storages/embedded/storage.json' => '{"description":"Sources in flash"}',
            'storages/microsd/storage.json' => '{"description":"Sources on the card","options":{"retries":{"description":"Retries","type":"int","min":0,"max":9,"default":1}},"requires":{"capabilities":["microsd"]}}',
            'php/8.4/patches/0001-greet.patch' => "--- a/main/php.h\n+++ b/main/php.h\n@@ -1 +1 @@\n-orig\n+patched\n",
            'php/8.4/compat/php_config.h' => "#pragma once\n",
            'php/8.4/extensions/base.json' => '{"module":"base","sources":["ext/base/*.c"],"options":{"base-cjk":{"description":"CJK tables","type":"bool","default":true,"when":{"false":{"defines":{"BASE_NO_CJK":1}}}}}}',
            'php/8.4/extensions/pdo_base.json' => '{"module":"pdo_base","sources":["ext/pdo_base/*.c"],"requires":{"extensions":["base"]}}',
            'php/8.4/extensions/regx.json' => '{"module":"regx","sources":["ext/regx/*.c"],"requires":{"sources":["rxlib"]},"options":{"regx-onig":{"description":"Regex","type":"bool","default":false,"when":{"true":{"sources":["rxlib:src/*.c"],"include-dirs":["rxlib:src"]}}}}}',
            'php/8.4/extensions/xclash.json' => '{"module":"xclash","sources":["ext/xclash/*.c"],"options":{"restart-delay":{"description":"Again","type":"int","default":1}}}',
            'driver/Driver.php' => self::DRIVER,
            'driver/Toolchain.php' => self::TOOLCHAIN,
        ];
        foreach ($files as $path => $contents) {
            @mkdir(dirname("{$dir}/{$path}"), 0777, true);
            file_put_contents("{$dir}/{$path}", $contents);
        }
        return $dir;
    }

    /** @return array{path:string, sha256:string} */
    public static function sdkTarball(string $sdkDir, string $outDir, string $version): array
    {
        @mkdir($outDir, 0777, true);
        $path = "{$outDir}/demo-sdk-{$version}.tar.gz";
        self::tar($path, dirname($sdkDir), basename($sdkDir));
        return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }

    /** @param array<string, array{path:string, sha256:string}> $entries version => tarball */
    public static function index(string $dir, array $entries): string
    {
        $versions = [];
        foreach ($entries as $version => $entry) {
            $versions[$version] = ['url' => basename($entry['path']), 'sha256' => $entry['sha256']];
        }
        $path = "{$dir}/index.json";
        file_put_contents($path, json_encode(['name' => 'demo', 'versions' => $versions], JSON_PRETTY_PRINT));
        return $path;
    }

    /** @param list<string> $versions */
    public static function bootstrap(array $versions = ['1.0.0']): Home
    {
        $root = self::tempDir();
        $php = self::phpTarball("{$root}/dist");
        $entries = [];
        foreach ($versions as $version) {
            $sdk = self::sdk("{$root}/src-{$version}/demo-sdk", $version, $php);
            $entries[$version] = self::sdkTarball($sdk, "{$root}/dist", $version);
        }
        $home = new Home("{$root}/home");
        app()->instance(Home::class, $home);
        app()->instance(SdkIndex::class, new SdkIndex(self::index("{$root}/dist", $entries)));
        return $home;
    }

    /**
     * @param array<string,mixed> $baremetal merged over a valid default config
     * @param list<Package>       $packages
     */
    public static function project(string $dir, array $baremetal = [], array $packages = []): string
    {
        $baremetal = [
            'sdk' => 'demo@^1.0',
            'board' => 'demo-board',
            'runtime' => 'loop',
            'storage' => 'embedded',
            ...$baremetal,
        ];
        @mkdir("{$dir}/project-src", 0777, true);
        file_put_contents("{$dir}/project-src/index.php", "<?php echo 'hi';\n");
        file_put_contents("{$dir}/composer.json", json_encode([
            'name' => 'acme/blink',
            'config' => ['platform' => ['php' => '8.4.25']],
            'extra' => ['baremetal' => $baremetal],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents("{$dir}/composer.lock", '{}');
        touch("{$dir}/composer.lock", time() - 60);
        touch("{$dir}/composer.json", time() - 60);

        $manifest = new Manifest(Project::fromComposer('acme/blink', $baremetal, '8.4.25'), $packages);
        @mkdir("{$dir}/.phpbm", 0777, true);
        file_put_contents("{$dir}/" . Manifest::PATH, $manifest->toJson());
        return $dir;
    }

    /** @param array{ports?: list<string>, chips?: array<string,string>, flash?: array<string,?string>} $config */
    public static function toolchain(Home $home, array $config = [], bool $installed = true): void
    {
        $dir = $home->toolchain('demo');
        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/demo.json", json_encode($config));
        $installed ? touch("{$dir}/installed") : @unlink("{$dir}/installed");
    }

    /** @return list<string> */
    public static function calls(Home $home): array
    {
        $file = $home->toolchain('demo') . '/calls.log';
        return is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    }

    private const DRIVER = <<<'PHP'
<?php

declare(strict_types=1);

namespace DemoSdk;

use PhpBaremetal\Spec\Sdk\Artifacts;
use PhpBaremetal\Spec\Sdk\BuildPlan;
use PhpBaremetal\Spec\Sdk\ChipInfo;
use PhpBaremetal\Spec\Sdk\Context;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Port;

final class Driver implements \PhpBaremetal\Spec\Sdk\Driver
{
    public function __construct(private readonly Context $context)
    {
    }

    public function toolchain(): Toolchain
    {
        return new Toolchain($this->context->toolchainDir);
    }

    public function build(BuildPlan $plan, Output $out): Artifacts
    {
        $this->log(sprintf('build %s %s %s %s %s', $plan->board->id, $plan->php->version, $plan->runtime->id, json_encode($plan->env), json_encode($plan->options))
            . ($plan->phpExtensions === [] ? '' : ' php-ext ' . implode(',', array_map(fn ($e) => $e->name, $plan->phpExtensions))));
        @mkdir($plan->buildDir, 0777, true);
        copy($plan->phpSourceDir . '/main/php.h', $plan->buildDir . '/app.bin');
        $out->step('demo build');
        return new Artifacts($plan->buildDir, [0x10000 => $plan->buildDir . '/app.bin']);
    }

    public function flash(Artifacts $artifacts, BuildPlan $plan, Port $port, Output $out): void
    {
        $this->log('flash ' . $port->path);
    }

    public function sync(BuildPlan $plan, Port $port, Output $out): bool
    {
        $this->log("sync {$plan->storage->id} " . $port->path);
        if (($this->config()['sync'] ?? null) === 'fail') {
            throw new \PhpBaremetal\Spec\SpecException('the board did not answer the sync');
        }
        return $plan->storage->id === 'microsd';
    }

    public function monitor(Port $port): int
    {
        $this->log('monitor ' . $port->path);
        return 0;
    }

    public function identify(Port $port): ?ChipInfo
    {
        $this->log('identify ' . $port->path);
        $target = $this->config()['chips'][$port->path] ?? null;
        $flash = $this->config()['flash'] ?? [];
        return $target === null ? null : new ChipInfo($target, 'Demo ' . $target, 'v1', array_key_exists($port->path, $flash) ? $flash[$port->path] : '4MB');
    }

    public function ports(): array
    {
        return array_map(static fn (string $path) => new Port($path, 'demo serial'), $this->config()['ports'] ?? []);
    }

    private function config(): array
    {
        $file = $this->context->toolchainDir . '/demo.json';
        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }

    private function log(string $line): void
    {
        @mkdir($this->context->toolchainDir, 0777, true);
        file_put_contents($this->context->toolchainDir . '/calls.log', $line . "\n", FILE_APPEND);
    }
}
PHP;

    private const TOOLCHAIN = <<<'PHP'
<?php

declare(strict_types=1);

namespace DemoSdk;

use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\ToolchainStatus;

final class Toolchain implements \PhpBaremetal\Spec\Sdk\Toolchain
{
    public function __construct(private readonly string $dir)
    {
    }

    public function status(): ToolchainStatus
    {
        return is_file($this->dir . '/installed') ? new ToolchainStatus(true, '1.0') : new ToolchainStatus(false, null, 'demo tools missing');
    }

    public function install(Output $out): void
    {
        @mkdir($this->dir, 0777, true);
        touch($this->dir . '/installed');
        $out->step('installing demo tools');
    }

    public function env(): array
    {
        return [];
    }
}
PHP;
}
