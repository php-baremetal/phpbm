<?php

declare(strict_types=1);

namespace App\Build;

use App\Images\ImageException;
use App\Images\PngReader;
use App\Images\SliEncoder;
use App\PhpbmException;
use App\Support\Files;
use App\Support\Home;
use App\Support\Paths;
use Closure;
use PhpBaremetal\Spec\Manifest\Project;
use PhpBaremetal\Spec\Sdk\Output;

/**
 * The asset folders of extra.baremetal.assets: where each image goes on the board, its conversion to .sli
 * (cached by content in ~/.phpbm/cache/assets) and the folder of the embedded image (sources + embedded assets).
 */
final readonly class AssetResolver
{
    /** Where each storage is mounted on the board. */
    public const MOUNTS = ['embedded' => '/app', 'microsd' => '/sdcard'];

    /** Part of the cache key: bump it when the converter output changes. */
    private const VERSION = 'sli1';

    /** @var Closure(string): string path of a PNG => its .sli */
    private Closure $convert;

    /** @param (Closure(string): string)|null $convert path of a PNG => its .sli (PngReader + SliEncoder by default) */
    public function __construct(private Home $home, ?Closure $convert = null)
    {
        $this->convert = $convert ?? static function (string $png): string {
            $image = PngReader::read($png);
            return SliEncoder::encode($image['w'], $image['h'], $image['rows']);
        };
    }

    /**
     * Every .png and .sli of the asset folders, sorted by folder (in the declared order) then by path; other
     * files are skipped with a warning.
     *
     * @return list<array{source:string, board:string, storage:string}> board: absolute path on the board
     */
    public function plan(Project $project, string $projectDir, ?Output $out = null): array
    {
        $entries = [];
        $owners = [];
        foreach ($project->assets->dirs as $asset) {
            $mount = self::MOUNTS[$asset->storage] ?? throw new PhpbmException(sprintf(
                "assets in %s: storage '%s' cannot hold assets (%s)",
                $asset->dir,
                $asset->storage,
                implode(', ', array_keys(self::MOUNTS)),
            ));
            $root = Paths::resolve($projectDir, $asset->dir);
            if (!is_dir($root)) {
                throw new PhpbmException("asset directory {$asset->dir} does not exist");
            }
            foreach (self::files($root) as $relative) {
                $label = "{$asset->dir}/{$relative}";
                $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                if ($extension !== 'png' && $extension !== 'sli') {
                    $out?->warning("asset ignored (not .png or .sli): {$label}");
                    continue;
                }
                $board = "{$mount}/{$asset->dir}/" . substr($relative, 0, -3) . 'sli';
                $key = strtolower($board);
                if (isset($owners[$key])) {
                    $pair = [$owners[$key], $label];
                    sort($pair, SORT_STRING);
                    throw new PhpbmException("{$pair[0]} and {$pair[1]} both become {$board}");
                }
                $owners[$key] = $label;
                $entries[] = ['source' => "{$root}/{$relative}", 'board' => $board, 'storage' => $asset->storage];
            }
        }

        $this->checkSources($project, $projectDir, $entries, $owners);

        return $entries;
    }

    /**
     * The file to put on the board for an entry of plan(): the converted .png (from the cache when its content
     * did not change), or the .sli itself once its header is checked.
     *
     * @param array{source:string, board:string, storage:string} $entry
     */
    public function prepare(array $entry): string
    {
        $source = $entry['source'];
        if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'sli') {
            $head = @file_get_contents($source, false, null, 0, 5);
            if ($head === false) {
                throw new PhpbmException("{$source}: cannot read the file");
            }
            if (strlen($head) < 5 || !str_starts_with($head, 'SLI1') || ord($head[4]) !== 1) {
                throw new PhpbmException("{$source}: not a .sli image (bad header)");
            }
            return $source;
        }

        $hash = @hash_file('sha256', $source);
        if ($hash === false) {
            throw new PhpbmException("{$source}: cannot read the file");
        }
        $cached = $this->home->assets() . '/' . hash('sha256', self::VERSION . "\0" . $hash) . '.sli';
        if (is_file($cached)) {
            return $cached;
        }
        try {
            $sli = ($this->convert)($source);
        } catch (ImageException $e) {
            throw new PhpbmException($e->getMessage(), 0, $e);
        }
        self::write($cached, $sli);
        return $cached;
    }

    /**
     * Makes $staging hold the files of $srcDir and the embedded entries (at their place under /app), copying
     * only the files that changed and deleting the others, so that unchanged files keep their time.
     *
     * @param list<array{source:string, board:string, storage:string}> $entries
     */
    public function stage(string $srcDir, array $entries, string $staging): void
    {
        $wanted = [];
        // With the sources at the project root, leave out the staging folder and phpbm's .phpbm.
        $skip = Paths::real($staging) ?? $staging;
        foreach (self::files($srcDir, [$skip, dirname($skip, 2)]) as $relative) {
            $wanted[$relative] = "{$srcDir}/{$relative}";
        }
        foreach (self::embedded($entries) as $relative => $entry) {
            $wanted[$relative] = $this->prepare($entry);
        }

        self::ensureDir($staging);
        foreach (is_dir($staging) ? self::files($staging) : [] as $relative) {
            if (!isset($wanted[$relative]) && !@unlink("{$staging}/{$relative}")) {
                throw new PhpbmException("cannot delete {$staging}/{$relative}");
            }
        }
        self::pruneDirs($staging);
        foreach ($wanted as $relative => $from) {
            $to = "{$staging}/{$relative}";
            if (is_file($to) && self::same($from, $to)) {
                continue;
            }
            self::ensureDir(dirname($to));
            if (!@copy($from, $to)) {
                throw new PhpbmException("cannot copy {$from} to {$to}");
            }
        }
    }

    /**
     * Whether an embedded entry differs from (or is missing in) $staging, the folder of the last build.
     *
     * @param list<array{source:string, board:string, storage:string}> $entries
     */
    public function stale(array $entries, string $staging): bool
    {
        foreach (self::embedded($entries) as $relative => $entry) {
            $staged = "{$staging}/{$relative}";
            if (!is_file($staged) || !self::same($this->prepare($entry), $staged)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{source:string, board:string, storage:string}> $entries
     * @return array<string, array{source:string, board:string, storage:string}> path under /app => entry
     */
    public static function embedded(array $entries): array
    {
        $embedded = [];
        $prefix = self::MOUNTS['embedded'] . '/';
        foreach ($entries as $entry) {
            if ($entry['storage'] === 'embedded') {
                $embedded[substr($entry['board'], strlen($prefix))] = $entry;
            }
        }
        return $embedded;
    }

    /**
     * An embedded asset must not take the place of a source file (the sources are mounted on /app too).
     *
     * @param list<array{source:string, board:string, storage:string}> $entries
     * @param array<string,string> $owners lowercase board path => asset label
     */
    private function checkSources(Project $project, string $projectDir, array $entries, array $owners): void
    {
        $embedded = self::embedded($entries);
        $srcDir = Paths::resolve($projectDir, $project->src);
        if ($embedded === [] || !is_dir($srcDir)) {
            return;
        }
        $sources = array_map(static fn (array $e): string => Paths::real($e['source']) ?? $e['source'], $embedded);
        foreach (self::files($srcDir, [Paths::resolve($projectDir, '.phpbm')]) as $relative) {
            $board = self::MOUNTS['embedded'] . "/{$relative}";
            $key = strtolower($board);
            if (!isset($owners[$key])) {
                continue;
            }
            $file = Paths::real("{$srcDir}/{$relative}") ?? "{$srcDir}/{$relative}";
            if (in_array($file, $sources, true)) {
                continue;
            }
            throw new PhpbmException("{$owners[$key]} becomes {$board}, which is also the source file {$project->src}/{$relative}");
        }
    }

    /**
     * The files under $dir, as sorted '/'-separated relative paths. Folders compare by their real path, so that
     * an exclusion matches whatever form $dir takes (/proj/., /proj/./, a link); a link to a folder the walk is
     * already inside is not followed.
     *
     * @param list<string> $exclude directories to leave out
     * @return list<string>
     */
    private static function files(string $dir, array $exclude = []): array
    {
        $exclude = array_map(static fn (string $d): string => Paths::real($d) ?? rtrim(str_replace('\\', '/', $d), '/'), $exclude);
        $files = [];
        $walk = static function (string $dir, string $prefix, array $parents) use (&$walk, &$files, $exclude): void {
            $names = @scandir($dir);
            if ($names === false) {
                throw new PhpbmException("cannot read the directory {$dir}");
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = "{$dir}/{$name}";
                if (is_dir($path)) {
                    $real = Paths::real($path) ?? $path;
                    if (!in_array($real, $exclude, true) && !in_array($real, $parents, true)) {
                        $walk($path, "{$prefix}{$name}/", [...$parents, $real]);
                    }
                } elseif (is_file($path)) {
                    $files[] = "{$prefix}{$name}";
                }
            }
        };
        $root = rtrim($dir, '/\\');
        $walk($root, '', [Paths::real($root) ?? $root]);
        sort($files, SORT_STRING);
        return $files;
    }

    private static function same(string $a, string $b): bool
    {
        return filesize($a) === filesize($b) && hash_file('sha256', $a) === hash_file('sha256', $b);
    }

    /** Deletes the empty directories under $dir (not $dir itself). */
    private static function pruneDirs(string $dir): bool
    {
        $empty = true;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = "{$dir}/{$name}";
            if (is_dir($path) && self::pruneDirs($path) && @rmdir($path)) {
                continue;
            }
            $empty = false;
        }
        return $empty;
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new PhpbmException("cannot create the directory {$dir}");
        }
    }

    private static function write(string $path, string $content): void
    {
        self::ensureDir(dirname($path));
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false || !Files::rename($tmp, $path)) {
            @unlink($tmp);
            throw new PhpbmException("cannot write the asset cache file {$path}");
        }
    }
}
