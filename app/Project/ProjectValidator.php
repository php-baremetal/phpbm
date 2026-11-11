<?php

declare(strict_types=1);

namespace App\Project;

use App\Build\AssetResolver;
use App\PhpbmException;
use PhpBaremetal\Spec\Sdk\Descriptor\PhpExtension;
use PhpBaremetal\Spec\Sdk\Sdk;
use PhpBaremetal\Spec\SpecException;

final class ProjectValidator
{
    public function validate(Project $project, Sdk $sdk): Target
    {
        $config = $project->manifest->project;
        $board = $sdk->board($config->board)->withOverrides($config->boardOverrides);
        $target = new Target(
            $board,
            $sdk->chip($board->chip),
            $sdk->runtime($config->runtime),
            $sdk->storage($config->storage),
            $sdk->descriptor->release($config->phpMinor()),
        );

        $problems = [];
        foreach ([$target->runtime, $target->storage] as $module) {
            foreach ($module->requires->capabilities as $capability) {
                if (!$board->has($capability)) {
                    $problems[] = "{$module->kind} '{$module->id}' needs {$capability}, but board '{$board->id}' has none";
                }
            }
            foreach ($module->requires->packages as $package) {
                if (!$project->manifest->has($package)) {
                    $problems[] = "{$module->kind} '{$module->id}' needs {$package}: run `composer require {$package}`";
                }
            }
        }
        if (!is_dir($project->path($config->src))) {
            $problems[] = "source directory {$config->src} does not exist";
        } elseif (!is_file($project->path($config->src) . '/' . $config->entry)) {
            $problems[] = "entry script {$config->src}/{$config->entry} does not exist";
        }

        $this->assets($project, $sdk, $target, $problems);

        $extensions = $this->phpExtensions($project, $sdk, $target->php->minor, $problems);

        $declared = [];
        $owners = [];
        $sources = [
            'sdk' => $sdk->descriptor->options,
            "runtime '{$target->runtime->id}'" => $target->runtime->options,
            "storage '{$target->storage->id}'" => $target->storage->options,
        ];
        foreach ($extensions as $extension) {
            $sources["php extension '{$extension->name}'"] = $extension->options;
        }
        foreach ($project->manifest->packages as $package) {
            try {
                $sources[$package->name] = $package->options();
            } catch (SpecException $e) {
                $problems[] = $e->getMessage();
            }
        }
        foreach ($sources as $owner => $options) {
            foreach ($options as $name => $option) {
                if (isset($declared[$name])) {
                    $problems[] = "option '{$name}' is declared by both {$owners[$name]} and {$owner}"
                        . ($this->isPackage($project, $owner) ? '' : ': this is an sdk bug');
                    continue;
                }
                $declared[$name] = $option;
                $owners[$name] = $owner;
            }
        }
        ksort($declared);
        $options = [];
        foreach ($config->options as $name => $value) {
            if (!isset($declared[$name])) {
                $accepted = $declared === [] ? 'none' : implode(', ', array_keys($declared));
                $problems[] = "unknown option '{$name}' in extra.baremetal.options (accepted: {$accepted})";
                continue;
            }
            try {
                $options[$name] = $declared[$name]->accept($value, "extra.baremetal.options.{$name}");
            } catch (SpecException $e) {
                $problems[] = $e->getMessage();
            }
        }
        foreach ($declared as $name => $option) {
            $options[$name] ??= $option->default;
        }
        ksort($options);

        if ($problems !== []) {
            throw new PhpbmException(sprintf(
                "the project does not fit sdk %s %s:\n  - %s",
                $sdk->descriptor->name,
                $sdk->descriptor->version,
                implode("\n  - ", $problems),
            ));
        }

        return new Target($target->board, $target->chip, $target->runtime, $target->storage, $target->php, $options, $extensions);
    }

    /**
     * The folders of extra.baremetal.assets exist and use a storage of the sdk that the board and the code allow.
     *
     * @param list<string> $problems
     */
    private function assets(Project $project, Sdk $sdk, Target $target, array &$problems): void
    {
        foreach ($project->manifest->project->assets->dirs as $asset) {
            if (!is_dir($project->path($asset->dir))) {
                $problems[] = "asset directory {$asset->dir} does not exist";
            }
            if (!isset($sdk->storages[$asset->storage]) || !isset(AssetResolver::MOUNTS[$asset->storage])) {
                $known = array_keys(array_intersect_key($sdk->storages, AssetResolver::MOUNTS));
                sort($known);
                $problems[] = sprintf(
                    "assets in %s: unknown storage '%s' (sdk %s %s has %s)",
                    $asset->dir,
                    $asset->storage,
                    $sdk->descriptor->name,
                    $sdk->descriptor->version,
                    $known === [] ? 'none for assets' : implode(', ', $known),
                );
                continue;
            }
            if ($asset->storage === 'microsd' && !$target->board->has('microsd')) {
                $problems[] = "assets in {$asset->dir} need a microSD slot, and {$target->board->name} has none";
            }
            if ($asset->storage === 'embedded' && $target->storage->id === 'microsd') {
                $problems[] = "assets in {$asset->dir}: with the code on the microSD (extra.baremetal.storage 'microsd') "
                    . "assets go on the microSD: use storage 'microsd'";
            } elseif ($asset->storage === 'embedded' && $target->storage->id !== 'embedded') {
                $problems[] = "assets in {$asset->dir}: storage 'embedded' puts assets into the flash image of the code, "
                    . "which needs extra.baremetal.storage 'embedded' (it is '{$target->storage->id}')";
            }
        }
    }

    private function isPackage(Project $project, string $owner): bool
    {
        foreach ($project->manifest->packages as $package) {
            if ($package->name === $owner) {
                return true;
            }
        }
        return false;
    }

    /**
     * The optional PHP extensions selected by the bm-php-ext packages, required ones first.
     *
     * @param list<string> $problems
     * @return list<PhpExtension>
     */
    private function phpExtensions(Project $project, Sdk $sdk, string $minor, array &$problems): array
    {
        $selected = [];
        foreach ($project->manifest->ofKind('php-ext') as $package) {
            $name = (string) $package->meta['php-ext'];
            $extension = $sdk->phpExtension($minor, $name);
            if ($extension === null) {
                $problems[] = sprintf(
                    "%s requires the PHP extension '%s', which sdk %s %s does not provide for PHP %s",
                    $package->name,
                    $name,
                    $sdk->descriptor->name,
                    $sdk->descriptor->version,
                    $minor,
                );
                continue;
            }
            $selected[$name] = $extension;
        }
        ksort($selected);
        foreach ($selected as $extension) {
            foreach ($extension->requiresExtensions as $required) {
                if (!isset($selected[$required])) {
                    $problems[] = "php extension '{$extension->name}' needs '{$required}': composer require php-baremetal/php-{$required}";
                }
            }
        }

        $ordered = [];
        $visit = static function (PhpExtension $extension) use (&$visit, &$ordered, $selected): void {
            if (isset($ordered[$extension->name])) {
                return;
            }
            foreach ($extension->requiresExtensions as $required) {
                if (isset($selected[$required])) {
                    $visit($selected[$required]);
                }
            }
            $ordered[$extension->name] = $extension;
        };
        foreach ($selected as $extension) {
            $visit($extension);
        }
        return array_values($ordered);
    }
}
