<?php

declare(strict_types=1);

namespace App\Commands;

use App\Board\BoardFeatures;
use App\Board\BoardPackages;
use App\Board\Feature;
use App\Board\FeatureChoice;
use App\PhpbmException;
use App\Project\BoardFit;
use App\Project\ConductorCheck;
use App\Sdk\SdkStore;
use App\Support\Composer;
use App\Support\Paths;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use PhpBaremetal\Spec\Sdk\Descriptor\Module;
use PhpBaremetal\Spec\Sdk\Sdk;
use PhpBaremetal\Spec\Support\Json;

use function Laravel\Prompts\multiselect;
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
        {--storage= : Where the PHP sources live}
        {--features= : Board features to install: all, none or kinds separated by commas (display,i2c)}
        {--no-install : Do not run Composer; print the commands instead}';

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
        $features = $this->chooseFeatures($board);
        $runtime = $sdk->runtime($this->chooseModule('runtime', 'Runtime', $sdk->runtimes, $board, $sdk));
        $storage = $sdk->storage($this->chooseModule('storage', 'Storage', $sdk->storages, $board, $sdk));

        $this->write($dir, $name, $sdk, $board->id, $runtime->id, $storage->id);

        $problem = app(ConductorCheck::class)->problem();
        if ($problem !== null) {
            $this->warn($problem);
        }
        $this->info("created {$dir}");

        $packages = array_values(array_unique([
            ...array_map(static fn (Feature $f): string => (string) $f->package(), $features),
            ...$runtime->requires->packages,
            ...$storage->requires->packages,
        ]));

        $failed = false;
        $showCommands = (bool) $this->option('no-install');
        if (!$showCommands) {
            $composer = app(Composer::class);
            try {
                if (!$composer->available()) {
                    throw new PhpbmException(Composer::NOT_FOUND);
                }
                // Nothing to add: install still fetches the conductor the project already requires.
                $composer->run($dir, $packages === [] ? ['install'] : ['require', ...$packages], $this->out());
            } catch (PhpbmException $e) {
                $this->error($e->getMessage());
                $failed = $showCommands = true;
            }
        }

        $this->line('next:');
        if ($dir !== Paths::cwd()) {
            $this->line("  cd {$dir}");
        }
        if ($showCommands) {
            if ($packages !== []) {
                $this->line('  composer require ' . implode(' ', $packages));
            }
            $this->line('  composer install');
        }
        $this->line('  phpbm build');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<Feature> the chosen features that have a package */
    private function chooseFeatures(Board $board): array
    {
        $option = $this->option('features');
        $option = is_string($option) ? strtolower(trim($option)) : null;
        if ($option === 'none') {
            return [];
        }

        $index = app(BoardPackages::class)->index($this->out());
        $explicit = $option !== null && $option !== '' && $option !== 'all';
        if ($index === null) {
            if ($explicit) {
                $this->warn('cannot check --features: the list of board packages is not available');
            }
            return []; // offline without cache: the warning is already printed
        }

        $features = BoardFeatures::of($board, $index);
        $selectable = FeatureChoice::selectable($features);
        $chosen = $explicit ? FeatureChoice::byKinds($board, $features, $option) : $selectable;
        foreach (FeatureChoice::notes($features) as $note) {
            $this->line("  {$note}");
        }
        if ($selectable === [] || $explicit || $option === 'all' || !$this->input->isInteractive()) {
            return $chosen;
        }

        $options = [];
        foreach ($selectable as $feature) {
            $options[$feature->block] = "{$feature->block}  {$feature->type}  {$feature->package()}";
        }
        $chosen = multiselect('Board features to install', $options, default: array_keys($options));

        return array_values(array_filter($selectable, static fn (Feature $f): bool => in_array($f->block, $chosen, true)));
    }

    private function targetDir(): string
    {
        $cwd = Paths::cwd();
        $dir = (string) ($this->argument('dir') ?? $cwd);
        return rtrim(Paths::normalize(Paths::resolve($cwd, $dir)), '/\\');
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

    /** @param array<string,Module> $modules */
    private function chooseModule(string $kind, string $label, array $modules, Board $board, Sdk $sdk): string
    {
        $explicit = $this->option($kind);
        if (is_string($explicit) && $explicit !== '') {
            $module = $modules[$explicit] ?? null;
            if ($module !== null) {
                BoardFit::check($kind, $explicit, $module, $board);
            }
            return $explicit;
        }
        $fitting = array_filter($modules, static fn (Module $m) => BoardFit::missing($m, $board) === null);
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
