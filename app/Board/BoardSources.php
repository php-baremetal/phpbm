<?php

declare(strict_types=1);

namespace App\Board;

use App\Commands\InitCommand;
use App\Support\Archive;
use App\Support\Files;
use App\Support\Home;
use App\Support\SymfonyProcess;
use Closure;
use Illuminate\Filesystem\Filesystem;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Sdk;
use RuntimeException;
use Throwable;

/**
 * The boards of the official bm-boards packages an SDK lists (sdk.json "board-packages"), for `phpbm init`: read from
 * the dist archive of each package's latest stable version in the Composer v2 registry, and cached for an hour.
 *
 * Archives are untrusted: each one is extracted into a new folder and only boards/<sdk>/*.json and boards/catalog.json are kept, as data.
 */
final class BoardSources
{
    private const TTL = 3600;

    /** Largest dist archive read, in bytes. */
    private const MAX_ARCHIVE = 20 * 1024 * 1024;

    /** Composer package name, as the spec checks board-packages. */
    private const NAME = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#D';

    /** Largest boards/catalog.json kept, in bytes. */
    private const MAX_CATALOG = 1024 * 1024;

    private const VERSION = '#^v?[0-9][0-9A-Za-z.+_-]*$#D';

    private const BOARD_FILE = '#^[A-Za-z0-9][A-Za-z0-9._-]*\.json$#D';

    private readonly string $registry;
    private readonly Closure $now;
    private readonly Archive $archive;

    public function __construct(private readonly Home $home, ?string $registry = null, ?Closure $now = null, ?Archive $archive = null)
    {
        $env = getenv('PHPBM_REPOSITORY');
        $this->registry = rtrim($registry ?? (is_string($env) && $env !== '' ? $env : InitCommand::REPOSITORY), '/\\');
        $this->now = $now ?? static fn (): int => time();
        $this->archive = $archive ?? new Archive(new SymfonyProcess());
    }

    /**
     * The official board packages of the SDK as bm-boards packages whose folder holds boards/<sdk>/*.json; [] when
     * the SDK lists none, or when the registry cannot be read and nothing is cached (with a warning).
     *
     * @return list<Package>
     */
    public function packages(Sdk $sdk, Output $out, bool $refresh = false): array
    {
        $names = array_values(array_unique(array_filter(
            $sdk->descriptor->boardPackages,
            static fn ($name): bool => is_string($name) && preg_match(self::NAME, $name) === 1,
        )));
        if ($names === []) {
            return [];
        }
        $sdkName = $sdk->descriptor->name;
        $now = ($this->now)();
        $cache = $this->readCache($sdkName, $names);
        if (!$refresh && $cache !== null && $cache['time'] <= $now && $now - $cache['time'] < self::TTL) {
            return $this->toPackages($sdkName, $cache['versions']);
        }

        $out->step('reading the boards of ' . implode(', ', $names) . " from {$this->registry}…");
        try {
            $versions = $this->fetch($sdkName, $names, $out);
        } catch (Throwable) {
            if ($cache !== null) {
                $out->warning("cannot reach {$this->registry}, using the board packages read on " . date('Y-m-d H:i', $cache['time']));
                return $this->toPackages($sdkName, $cache['versions']);
            }
            $out->warning("cannot reach {$this->registry}: only the boards of the sdk are offered");
            return [];
        }

        $this->writeCache($sdkName, $names, $versions, $now);
        return $this->toPackages($sdkName, $versions);
    }

    /**
     * Throws when the registry cannot be read (never caching a partial list); a package that is missing or whose
     * archive is unusable is skipped with a warning.
     *
     * @param list<string> $names
     * @return array<string,string> name => version
     */
    private function fetch(string $sdkName, array $names, Output $out): array
    {
        $pattern = Registry::metadataPattern($this->registry);
        $versions = [];
        foreach ($names as $name) {
            try {
                $best = Registry::latestStable($name, Registry::json(str_replace('%package%', $name, $pattern)));
            } catch (UnreadableException $e) {
                if ($e->notFound) {
                    $out->warning("skipping {$name}: no metadata (404)");
                    continue;
                }
                throw $e;
            } catch (Throwable $e) {
                $out->warning("skipping {$name}: cannot read its metadata ({$e->getMessage()})");
                continue;
            }

            $version = $best['version'] ?? null;
            $dist = $best['dist'] ?? null;
            if (!is_string($version) || !is_array($dist) || !is_string($dist['url'] ?? null) || !is_string($dist['type'] ?? null)) {
                $out->warning("skipping {$name}: no stable version with a dist archive");
                continue;
            }
            if (preg_match(self::VERSION, $version) !== 1 || str_contains($version, '..')) {
                $out->warning("skipping {$name}: invalid version '{$version}'");
                continue;
            }

            $dir = $this->packageDir($sdkName, $name, $version);
            if (!is_dir($dir)) {
                try {
                    $this->download($sdkName, $name, $version, $dist, $dir);
                } catch (UnreadableException $e) {
                    if (!$e->notFound) {
                        throw $e;
                    }
                    $out->warning("skipping {$name}: its dist archive does not exist");
                    continue;
                } catch (Throwable $e) {
                    $out->warning("skipping {$name}: {$e->getMessage()}");
                    continue;
                }
            }
            $versions[$name] = $version;
        }
        return $versions;
    }

    /** Extracts the archive into a new folder and keeps only boards/<sdk>/*.json and boards/catalog.json in $dir. @param array<string,mixed> $dist */
    private function download(string $sdkName, string $name, string $version, array $dist, string $dir): void
    {
        $extension = match ($dist['type']) {
            'zip' => 'zip',
            'tar' => 'tar',
            default => throw new RuntimeException("unsupported dist type '{$dist['type']}'"),
        };
        $files = new Filesystem();
        $tmp = $this->root($sdkName) . '/.tmp-' . bin2hex(random_bytes(4));
        try {
            $files->ensureDirectoryExists($tmp);
            $raw = Registry::read(Registry::resolveUrl($this->registry, (string) $dist['url']), self::MAX_ARCHIVE);
            $shasum = is_string($dist['shasum'] ?? null) ? strtolower($dist['shasum']) : '';
            if ($shasum !== '' && sha1($raw) !== $shasum) {
                throw new RuntimeException("checksum mismatch for the dist archive of {$version}");
            }
            $archive = "{$tmp}/dist.{$extension}";
            if (file_put_contents($archive, $raw) === false) {
                throw new RuntimeException("cannot write {$archive}");
            }
            $root = $this->archive->extract($archive, "{$tmp}/extracted");

            $target = "{$tmp}/boards/boards/{$sdkName}";
            $files->ensureDirectoryExists($target);
            $source = "{$root}/boards/{$sdkName}";
            if (!is_link("{$root}/boards") && !is_link($source) && is_dir($source)) {
                foreach (scandir($source) ?: [] as $file) {
                    $path = "{$source}/{$file}";
                    if (preg_match(self::BOARD_FILE, $file) === 1 && !is_link($path) && is_file($path) && !@copy($path, "{$target}/{$file}")) {
                        throw new RuntimeException("cannot copy {$file}");
                    }
                }
            }

            $catalog = "{$root}/" . BoardCatalog::FILE;
            if (!is_link("{$root}/boards") && !is_link($catalog) && is_file($catalog) && filesize($catalog) <= self::MAX_CATALOG) {
                @copy($catalog, "{$tmp}/boards/" . BoardCatalog::FILE);
            }

            $files->ensureDirectoryExists(dirname($dir));
            if (!Files::rename("{$tmp}/boards", $dir) && !is_dir($dir)) {
                throw new RuntimeException("cannot write {$dir}");
            }
        } finally {
            $files->deleteDirectory($tmp);
        }
    }

    /** @param array<string,string> $versions @return list<Package> */
    private function toPackages(string $sdkName, array $versions): array
    {
        $packages = [];
        foreach ($versions as $name => $version) {
            $packages[] = new Package($name, 'bm-boards', $version, $this->packageDir($sdkName, $name, $version), []);
        }
        return $packages;
    }

    /** One folder per registry and SDK. */
    private function root(string $sdkName): string
    {
        return $this->home->boardSources() . '/' . substr(hash('sha256', $this->registry), 0, 16) . "/{$sdkName}";
    }

    private function packageDir(string $sdkName, string $name, string $version): string
    {
        return $this->root($sdkName) . "/{$name}/{$version}";
    }

    /**
     * @param list<string> $names
     * @return array{time:int, versions:array<string,string>}|null
     */
    private function readCache(string $sdkName, array $names): ?array
    {
        $raw = @file_get_contents($this->root($sdkName) . '/index.json');
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || ($data['registry'] ?? null) !== $this->registry || !is_int($data['time'] ?? null)
            || ($data['names'] ?? null) !== $names || !is_array($data['versions'] ?? null)) {
            return null;
        }
        foreach ($data['versions'] as $name => $version) {
            if (!in_array($name, $names, true) || !is_string($version) || preg_match(self::VERSION, $version) !== 1
                || str_contains($version, '..') || !is_dir($this->packageDir($sdkName, $name, $version))) {
                return null;
            }
        }
        return ['time' => $data['time'], 'versions' => $data['versions']];
    }

    /** @param list<string> $names @param array<string,string> $versions */
    private function writeCache(string $sdkName, array $names, array $versions, int $now): void
    {
        $file = $this->root($sdkName) . '/index.json';
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['registry' => $this->registry, 'time' => $now, 'names' => $names, 'versions' => (object) $versions], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json !== false && @file_put_contents($tmp, $json) !== false && !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
