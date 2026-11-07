<?php

declare(strict_types=1);

namespace App\Commands;

use App\Board\BoardFeatures;
use App\Board\BoardPackages;
use App\Board\ChangePlan;
use App\Board\Feature;
use App\Board\FeatureChoice;
use App\PhpbmException;
use App\Project\BoardFit;
use App\Sdk\SdkResolver;
use App\Support\Composer;
use App\Support\Paths;
use PhpBaremetal\Spec\Manifest\SdkRequirement;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use PhpBaremetal\Spec\Sdk\Sdk;
use PhpBaremetal\Spec\Support\Json;
use stdClass;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

final class BoardCommand extends PhpbmCommand
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    protected $signature = 'board
        {board? : New board id}
        {--change : Choose the new board from a list}
        {--features= : Board features to keep or install: all, none or kinds separated by commas (display,i2c)}
        {--no-install : Only change composer.json and print the Composer commands}
        {--refresh : Refresh the list of board packages}';

    protected $description = "Add or remove the board's features, or change the board";

    protected function perform(): int
    {
        $dir = rtrim(Paths::real(Paths::cwd()) ?? Paths::cwd(), '/\\');
        $file = "{$dir}/composer.json";
        if (!is_file($file)) {
            throw new PhpbmException("no composer.json in {$dir}: run phpbm init");
        }
        $composer = Json::decodeFile($file);
        $baremetal = $composer['extra']['baremetal'] ?? null;
        if (!is_array($baremetal) || !is_string($baremetal['sdk'] ?? null) || !is_string($baremetal['board'] ?? null)) {
            throw new PhpbmException("{$file}: extra.baremetal.sdk and extra.baremetal.board are required: run phpbm init");
        }
        $required = is_array($composer['require'] ?? null) ? $composer['require'] : [];

        $sdk = app(SdkResolver::class)->resolve($dir, SdkRequirement::parse($baremetal['sdk']), $this->out());
        $current = $baremetal['board'];
        $target = $this->targetBoard($sdk, $current);

        $plan = $target === null || $target === $current
            ? $this->featuresPlan(self::board($sdk, $current), $required)
            : $this->changePlan($sdk, $baremetal, $current, self::board($sdk, $target), $required);

        if ($plan->isEmpty()) {
            $this->line('nothing to change');
            return self::SUCCESS;
        }

        $overrides = self::remainingOverrides($file, $plan);
        if ($this->option('no-install')) {
            $this->rewrite($file, $plan, $overrides);
            $this->finish($plan, printCommands: true);
            return self::SUCCESS;
        }

        $this->apply($dir, $plan, $overrides);
        $this->finish($plan, printCommands: false);
        return self::SUCCESS;
    }

    /** The board the user asked for: the argument, or one chosen from the list with --change. */
    private function targetBoard(Sdk $sdk, string $current): ?string
    {
        $board = $this->argument('board');
        if (is_string($board) && $board !== '') {
            return $board;
        }
        if (!$this->option('change')) {
            return null;
        }
        if (!$this->input->isInteractive()) {
            throw new PhpbmException('--change asks for the board: pass the board id instead (phpbm board <id>)');
        }
        $options = array_map(static fn (Board $b): string => "{$b->name} ({$b->id})", $sdk->boards);
        return (string) select('Board', $options, default: array_key_exists($current, $options) ? $current : null);
    }

    private static function board(Sdk $sdk, string $id): Board
    {
        return $sdk->boards[$id]
            ?? throw new PhpbmException("board '{$id}' does not exist in sdk {$sdk->descriptor->name} {$sdk->descriptor->version}");
    }

    /** 3.1: add or remove features of the current board. @param array<string,mixed> $required */
    private function featuresPlan(Board $board, array $required): ChangePlan
    {
        $index = app(BoardPackages::class)->index($this->out(), (bool) $this->option('refresh'));
        if ($index === null) {
            throw new PhpbmException('the list of board packages is not available: run phpbm board again when the registry can be reached');
        }

        $features = BoardFeatures::of($board, $index);
        $installed = array_values(array_filter(
            FeatureChoice::selectable($features),
            static fn (Feature $f): bool => array_intersect($f->packages, array_keys($required)) !== [],
        ));
        $chosen = $this->chooseFeatures($board, $features, $installed);

        return BoardFeatures::plan($features, $chosen, $required);
    }

    /**
     * 3.2: change the board. Every check runs before anything is written.
     *
     * @param array<string,mixed> $baremetal
     * @param array<string,mixed> $required
     */
    private function changePlan(Sdk $sdk, array $baremetal, string $current, Board $board, array $required): ChangePlan
    {
        foreach (['runtime', 'storage'] as $kind) {
            $id = $baremetal[$kind] ?? null;
            if (is_string($id)) {
                $module = $kind === 'runtime' ? $sdk->runtime($id) : $sdk->storage($id);
                BoardFit::check($kind, $id, $module, $board);
            }
        }

        $index = app(BoardPackages::class)->index($this->out(), (bool) $this->option('refresh')) ?? [];
        $features = BoardFeatures::of($board, $index);
        $old = isset($sdk->boards[$current]) ? BoardFeatures::of($sdk->boards[$current], $index) : [];

        $selectable = FeatureChoice::selectable($features);
        $chosen = $this->chooseFeatures($board, $features, $selectable);
        $plan = BoardFeatures::plan($features, $chosen, $required, $old);

        // Packages of the old board's features that the new board does not use at all: offered for removal.
        $newPackages = array_merge([], ...array_map(static fn (Feature $f): array => $f->packages, $features));
        $oldPackages = array_merge([], ...array_map(static fn (Feature $f): array => $f->packages, $old));
        $leaving = array_values(array_filter($plan->remove, static fn (string $p): bool => in_array($p, $oldPackages, true) && !in_array($p, $newPackages, true)));
        $remove = $plan->remove;
        if ($leaving !== [] && $this->input->isInteractive()) {
            $kept = array_diff($leaving, multiselect(
                "Packages not used by board {$board->id}, to remove",
                array_combine($leaving, $leaving),
                default: $leaving,
            ));
            $remove = array_values(array_diff($remove, $kept));
        }

        $overrides = is_array($baremetal['board-overrides'] ?? null) ? array_keys($baremetal['board-overrides']) : [];
        $unset = array_values(array_filter(array_map('strval', $overrides), static fn (string $block): bool => !$board->has($block)));
        if ($unset !== [] && $this->input->isInteractive()) {
            $this->line("board-overrides of blocks board '{$board->id}' does not have: " . implode(', ', $unset));
            if (!confirm('Remove these overrides?', default: true)) {
                throw new PhpbmException("the build fails with overrides of blocks board '{$board->id}' does not have ("
                    . implode(', ', $unset) . '): remove them from extra.baremetal.board-overrides, or keep the board');
            }
        }

        return new ChangePlan($board->id, $unset, $remove, $plan->require);
    }

    /**
     * @param list<Feature> $features all the features of the board
     * @param list<Feature> $checked  the features checked by default
     * @return list<string> the chosen blocks
     */
    private function chooseFeatures(Board $board, array $features, array $checked): array
    {
        $blocks = static fn (array $list): array => array_map(static fn (Feature $f): string => $f->block, $list);
        $option = $this->option('features');
        $option = is_string($option) ? strtolower(trim($option)) : null;
        if ($option === 'none') {
            return [];
        }
        $selectable = FeatureChoice::selectable($features);
        if ($option === 'all') {
            return $blocks($selectable);
        }
        if ($option !== null && $option !== '') {
            return $blocks(FeatureChoice::byKinds($board, $features, $option));
        }

        foreach (FeatureChoice::notes($features) as $note) {
            $this->line("  {$note}");
        }
        if ($selectable === [] || !$this->input->isInteractive()) {
            return $blocks($checked);
        }

        $options = [];
        foreach ($selectable as $feature) {
            $options[$feature->block] = "{$feature->block}  {$feature->type}  {$feature->package()}";
        }
        return array_values(array_map('strval', multiselect('Board features', $options, default: $blocks($checked))));
    }

    /** Runs the Composer steps in the design's order; a failure says which steps were already done. */
    private function apply(string $dir, ChangePlan $plan, ?stdClass $overrides): void
    {
        $composer = app(Composer::class);
        if (!$composer->available()) {
            throw new PhpbmException(Composer::NOT_FOUND);
        }

        $done = [];
        foreach (self::steps($plan, $overrides) as $args) {
            try {
                $composer->run($dir, $args, $this->out());
            } catch (PhpbmException $e) {
                throw new PhpbmException($e->getMessage() . '; done so far: ' . ($done === [] ? 'nothing' : implode(', ', $done)), 0, $e);
            }
            $done[] = 'composer ' . implode(' ', $args);
        }
    }

    /**
     * The Composer steps of a plan. Composer's `config` splits a key at most two levels below `extra`, so the
     * overrides are set as a whole (`--json`) with what remains, or unset when nothing remains.
     *
     * @param stdClass|null $overrides the overrides that remain (null when none)
     * @return list<list<string>>
     */
    private static function steps(ChangePlan $plan, ?stdClass $overrides, bool $packagesOnly = false): array
    {
        $steps = [];
        if (!$packagesOnly && $plan->board !== null) {
            $steps[] = ['config', 'extra.baremetal.board', $plan->board];
        }
        if (!$packagesOnly && $plan->unsetOverrides !== []) {
            $steps[] = $overrides === null
                ? ['config', '--unset', 'extra.baremetal.board-overrides']
                : ['config', '--json', 'extra.baremetal.board-overrides', json_encode($overrides, self::JSON | JSON_THROW_ON_ERROR)];
        }
        if ($plan->remove !== []) {
            $steps[] = ['remove', ...$plan->remove];
        }
        if ($plan->require !== []) {
            $steps[] = ['require', ...$plan->require];
        }
        return $steps;
    }

    /** The board-overrides left once the plan drops its blocks; null when none is left. */
    private static function remainingOverrides(string $file, ChangePlan $plan): ?stdClass
    {
        $data = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        $overrides = $data->extra->baremetal->{'board-overrides'} ?? null;
        if (!$overrides instanceof stdClass) {
            return null;
        }
        foreach ($plan->unsetOverrides as $block) {
            unset($overrides->{$block});
        }
        return get_object_vars($overrides) === [] ? null : $overrides;
    }

    /** --no-install: the same end state as the Composer steps, written in Composer's JSON style; every other key stays. */
    private function rewrite(string $file, ChangePlan $plan, ?stdClass $overrides): void
    {
        if ($plan->board === null && $plan->unsetOverrides === []) {
            return;
        }
        $data = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        $baremetal = $data->extra->baremetal;
        if ($plan->board !== null) {
            $baremetal->board = $plan->board;
        }
        if ($plan->unsetOverrides !== []) {
            if ($overrides === null) {
                unset($baremetal->{'board-overrides'});
            } else {
                $baremetal->{'board-overrides'} = $overrides;
            }
        }
        Json::writeAtomic($file, json_encode($data, JSON_PRETTY_PRINT | self::JSON | JSON_THROW_ON_ERROR) . "\n");
    }

    private function finish(ChangePlan $plan, bool $printCommands): void
    {
        if ($plan->board !== null) {
            $this->info("board: {$plan->board}");
        }
        $packageSteps = self::steps($plan, null, packagesOnly: true);
        if ($printCommands && $packageSteps !== []) {
            $this->line('next:');
            foreach ($packageSteps as $args) {
                $this->line('  composer ' . implode(' ', $args));
            }
        } elseif ($packageSteps === []) {
            // No require/remove refreshed composer.lock and the manifest after the change of extra.
            $this->line('next:');
            $this->line('  composer update --lock');
        }
    }
}
