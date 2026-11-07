<?php

declare(strict_types=1);

namespace App\Build;

use App\Fonts\FontBaker;
use App\PhpbmException;
use App\Project\Project;
use App\Support\Home;
use PhpBaremetal\Spec\Sdk\Output;

/** Collects the fonts of the build: ext-display's default, font packages, the project folder, the bake list. */
final readonly class FontResolver
{
    private const DISPLAY = 'php-baremetal/ext-display';

    public function __construct(private Home $home, private FontBaker $baker)
    {
    }

    /** @return array<string,string> font name => absolute path of its .h, sorted by name */
    public function resolve(Project $project, Output $out): array
    {
        $config = $project->manifest->project->fonts;
        $fonts = [];
        $sources = [];
        $add = function (string $name, string $path, string $label) use (&$fonts, &$sources): void {
            if (isset($fonts[$name])) {
                throw new PhpbmException("font '{$name}' is defined twice ({$sources[$name]}, {$label})");
            }
            $fonts[$name] = $path;
            $sources[$name] = $label;
        };

        $hasDisplay = false;
        foreach ($project->manifest->packages as $package) {
            $hasDisplay = $hasDisplay || $package->name === self::DISPLAY;
            $declared = $package->meta['fonts'] ?? [];
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $name => $relative) {
                $name = (string) $name;
                if ($name === 'default' && !$config->default && $package->name === self::DISPLAY) {
                    continue;
                }
                $path = $project->path($package->path . '/' . $relative);
                if (!is_file($path)) {
                    throw new PhpbmException("font file '{$relative}' not found ({$package->name})");
                }
                $add($name, $path, $package->name);
            }
        }
        $packageFonts = $fonts !== [] && array_diff($sources, [self::DISPLAY]) !== [];

        $files = glob($project->path($config->dir) . '/*.h') ?: [];
        sort($files);
        foreach ($files as $file) {
            $add(basename($file, '.h'), $file, $config->dir . '/' . basename($file));
        }

        foreach ($config->bake as $bake) {
            $source = $project->path($bake->source);
            if (!is_file($source)) {
                throw new PhpbmException("font '{$bake->name}': source '{$bake->source}' not found");
            }
            $add($bake->name, $this->bake($project, $bake, $source, $out), 'extra.baremetal.fonts.bake');
        }

        if (!$hasDisplay && ($files !== [] || $config->bake !== [] || $packageFonts)) {
            throw new PhpbmException('fonts need ' . self::DISPLAY . ': composer require ' . self::DISPLAY);
        }

        ksort($fonts, SORT_STRING);
        return $fonts;
    }

    private function bake(Project $project, \PhpBaremetal\Spec\Manifest\FontBake $bake, string $source, Output $out): string
    {
        $params = [$bake->name, $bake->size, $bake->bpp, $bake->chars, $bake->range];
        $key = hash('sha256', (string) file_get_contents($source) . json_encode($params) . FontBaker::VERSION);
        $cached = $this->home->fonts() . "/{$key}.h";
        $meta = $this->home->fonts() . "/{$key}.json";

        if (!is_file($cached) || !is_file($meta)) {
            $baked = $this->baker->bake($source, $bake->name, $bake->size, $bake->bpp, $bake->chars, $bake->range);
            $this->store($cached, $baked->header);
            $this->store($meta, json_encode($baked->missing, JSON_THROW_ON_ERROR));
            $missing = $baked->missing;
        } else {
            $decoded = json_decode((string) file_get_contents($meta), true);
            $missing = is_array($decoded) ? array_values($decoded) : [];
        }
        if ($missing !== []) {
            $out->warning(FontBaker::missingWarning($bake->name, $missing));
        }

        $target = $project->buildDir() . "/fonts/{$bake->name}.h";
        $this->ensureDir(dirname($target));
        if (!@copy($cached, $target)) {
            throw new PhpbmException("cannot write font '{$bake->name}' to {$target}");
        }
        return $target;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new PhpbmException("cannot create the font directory {$dir}");
        }
    }

    private function store(string $path, string $content): void
    {
        $this->ensureDir(dirname($path));
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw new PhpbmException("cannot write the font cache file {$path}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new PhpbmException("cannot write the font cache file {$path}");
        }
    }
}
