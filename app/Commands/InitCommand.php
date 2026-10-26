<?php

declare(strict_types=1);

namespace App\Commands;

use App\PhpbmException;
use App\Project\ConductorCheck;
use App\Sdk\SdkStore;
use App\Support\Paths;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use PhpBaremetal\Spec\Sdk\Descriptor\Module;
use PhpBaremetal\Spec\Sdk\Sdk;
use PhpBaremetal\Spec\Support\Json;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class InitCommand extends PhpbmCommand
{
    // Composer repository of the bm-* packages; PHPBM_REPOSITORY overrides it.
    public const REPOSITORY = 'https://repository.php-baremetal.com';

    protected $signature = 'init
        {dir? : Directory of the new project (default: current directory)}
        {--name= : Project name}
        {--sdk= : SDK to use}
        {--board= : Board id}
        {--runtime= : Execution model}
        {--storage= : Where the PHP sources live}';

    protected $description = 'Create a new firmware project';

    protected function perform(): int
    {
        $dir = $this->targetDir();
        if (is_file("{$dir}/composer.json")) {
            throw new PhpbmException("{$dir} already contains a composer.json");
        }

        $sdk = $this->chooseSdk();
        $name = $this->option('name') ?? ($this->input->isInteractive()
            ? text('Project name', default: basename($dir), required: true)
            : basename($dir));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name)) {
            throw new PhpbmException("invalid project name '{$name}': use lowercase letters, digits, '.', '_' and '-'");
        }

        $board = $sdk->board($this->choose('board', 'Board', array_map(static fn ($b) => "{$b->name} ({$b->id})", $sdk->boards)));
        $runtime = $sdk->runtime($this->chooseModule('runtime', 'Runtime', $sdk->runtimes, $board, $sdk));
        $storage = $sdk->storage($this->chooseModule('storage', 'Storage', $sdk->storages, $board, $sdk));

        $this->write($dir, $name, $sdk, $board->id, $runtime->id, $storage->id);

        $problem = app(ConductorCheck::class)->problem();
        if ($problem !== null) {
            $this->warn($problem);
        }
        $this->info("created {$dir}");
        $this->line('next:');
        if ($dir !== getcwd()) {
            $this->line("  cd {$dir}");
        }
        foreach (array_unique([...$runtime->requires->packages, ...$storage->requires->packages]) as $package) {
            $this->line("  composer require {$package}");
        }
        $this->line('  composer install');
        $this->line('  phpbm build');

        return self::SUCCESS;
    }

    private function targetDir(): string
    {
        $dir = (string) ($this->argument('dir') ?? getcwd());
        return rtrim(Paths::resolve((string) getcwd(), $dir), '/\\');
    }

    private function chooseSdk(): Sdk
    {
        $store = app(SdkStore::class);
        $sdks = [];
        foreach ($store->names() as $name) {
            $sdk = $store->current($name);
            if ($sdk !== null) {
                $sdks[$name] = $sdk;
            }
        }
        if ($sdks === []) {
            throw new PhpbmException('no SDK installed: run `phpbm sdk:install <name>` first');
        }
        $name = $this->choose('sdk', 'SDK', array_map(static fn (Sdk $s) => "{$s->descriptor->name} {$s->descriptor->version}", $sdks));

        return $sdks[$name] ?? throw new PhpbmException("sdk {$name} is not installed: run `phpbm sdk:install {$name}`");
    }

    /** @param array<string,string> $options id => label */
    private function choose(string $option, string $label, array $options): string
    {
        $value = $this->option($option);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (count($options) === 1 || !$this->input->isInteractive()) {
            return (string) array_key_first($options);
        }
        return (string) select($label, $options);
    }

    private static function missing(Module $module, Board $board): ?string
    {
        foreach ($module->requires->capabilities as $capability) {
            if (!$board->has($capability)) {
                return $capability;
            }
        }
        return null;
    }

    /** @param array<string,Module> $modules */
    private function chooseModule(string $kind, string $label, array $modules, Board $board, Sdk $sdk): string
    {
        $explicit = $this->option($kind);
        if (is_string($explicit) && $explicit !== '') {
            $module = $modules[$explicit] ?? null;
            $missing = $module === null ? null : self::missing($module, $board);
            if ($missing !== null) {
                throw new PhpbmException("{$kind} '{$explicit}' needs {$missing}, but board '{$board->id}' has none");
            }
            return $explicit;
        }
        $fitting = array_filter($modules, static fn (Module $m) => self::missing($m, $board) === null);
        if ($fitting === []) {
            throw new PhpbmException("no {$kind} of sdk {$sdk->descriptor->name} fits board {$board->id}");
        }
        return $this->choose($kind, $label, array_map(static fn (Module $m) => $m->description, $fitting));
    }

    private function write(string $dir, string $name, Sdk $sdk, string $board, string $runtime, string $storage): void
    {
        $releases = $sdk->descriptor->php;
        uksort($releases, 'version_compare');
        $php = end($releases)->version;
        [$major, $minor] = array_pad(explode('.', $sdk->descriptor->version), 2, '0');

        $this->put("{$dir}/composer.json", Json::encode([
            'name' => "local/{$name}",
            'description' => 'php-baremetal firmware',
            'type' => 'project',
            'repositories' => [['type' => 'composer', 'url' => self::repository(), 'canonical' => true]],
            'require' => ['php-baremetal/conductor' => '^1.0'],
            'config' => [
                'platform' => ['php' => $php],
                'allow-plugins' => ['php-baremetal/conductor' => true],
            ],
            'extra' => ['baremetal' => [
                'sdk' => "{$sdk->descriptor->name}@^{$major}.{$minor}",
                'board' => $board,
                'runtime' => $runtime,
                'storage' => $storage,
            ]],
        ]));
        if (!is_file("{$dir}/project-src/index.php")) {
            $this->put("{$dir}/project-src/index.php", "<?php\n\necho \"Hello from {$name}!\\n\";\n");
        }

        $existing = is_file("{$dir}/.gitignore") ? (string) file_get_contents("{$dir}/.gitignore") : '';
        $present = array_map('trim', explode("\n", $existing));
        $missing = array_filter(['/vendor/', '/.phpbm/'], static fn (string $line) => !in_array($line, $present, true));
        if ($missing !== []) {
            $prefix = $existing === '' || str_ends_with($existing, "\n") ? '' : "\n";
            $this->put("{$dir}/.gitignore", $existing . $prefix . implode("\n", $missing) . "\n");
        }
    }

    private static function repository(): string
    {
        $url = getenv('PHPBM_REPOSITORY');
        return is_string($url) && $url !== '' ? $url : self::REPOSITORY;
    }

    private function put(string $path, string $contents): void
    {
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
            throw new PhpbmException("cannot write {$path}");
        }
        if (file_put_contents($path, $contents) === false) {
            throw new PhpbmException("cannot write {$path}");
        }
    }
}
