<?php

declare(strict_types=1);

namespace App\Commands;

use App\Fonts\FontBaker;
use App\PhpbmException;
use PhpBaremetal\Spec\Manifest\Manifest;

final class FontBakeCommand extends PhpbmCommand
{
    public const DEFAULT_DIR = 'project-fonts';

    protected $signature = 'font:bake {source : TTF or BDF file} {--name= : Font name} {--size= : Size in pixels (TTF)} {--bpp=1 : 1, 2 or 4} {--chars= : Characters to include} {--range= : Codepoint ranges, e.g. 0x20-0x7E} {--out= : Output folder}';

    protected $description = 'Turn a TTF or BDF font into a font header for ext-display';

    protected function perform(): int
    {
        $name = (string) $this->option('name');
        if ($name === '') {
            throw new PhpbmException('--name is required');
        }
        $baked = app(FontBaker::class)->bake(
            (string) $this->argument('source'),
            $name,
            $this->intOption('size'),
            $this->intOption('bpp') ?? 1,
            $this->option('chars'),
            $this->option('range'),
        );

        if ($baked->missing !== []) {
            $this->warn(FontBaker::missingWarning($name, $baked->missing));
        }

        $dir = $this->outDir();
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new PhpbmException("cannot create {$dir}");
        }
        $path = "{$dir}/{$name}.h";
        if (@file_put_contents($path, $baked->header) === false) {
            throw new PhpbmException("cannot write {$path}");
        }
        $bpp = $this->intOption('bpp') ?? 1;
        $this->info(sprintf('baked %s: %d glyphs, %d bpp, %d bytes -> %s', $name, count($baked->glyphs), $bpp, $baked->bitmapBytes, $path));

        return self::SUCCESS;
    }

    private function intOption(string $option): ?int
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/D', (string) $value)) {
            throw new PhpbmException("--{$option} must be a whole number");
        }
        return (int) $value;
    }

    private function outDir(): string
    {
        $cwd = (string) getcwd();
        $dir = $this->option('out');
        if ($dir === null || $dir === '') {
            $manifest = $cwd . '/' . Manifest::PATH;
            $dir = is_file($manifest) ? Manifest::fromFile($manifest)->project->fonts->dir : self::DEFAULT_DIR;
        }
        if (str_starts_with($dir, '/')) {
            return rtrim($dir, '/') ?: '/';
        }
        $dir = trim(preg_replace('#^(\./)+|/\.$#', '', rtrim($dir, '/')), '/');
        return $dir === '' || $dir === '.' ? $cwd : "{$cwd}/{$dir}";
    }
}
