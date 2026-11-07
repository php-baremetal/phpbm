<?php

declare(strict_types=1);

namespace App\Board;

use App\Commands\InitCommand;
use App\Support\Home;
use App\Support\Paths;
use Closure;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use JsonException;
use PhpBaremetal\Spec\Sdk\Output;
use Throwable;

/** Which package drives which board block ('kind:type'), read from the Composer v2 registry. */
final class BoardPackages
{
    private const TTL = 3600;

    /** Seconds an HTTP read of the registry may take. */
    private const TIMEOUT = 10;

    /** Grammar of a board-blocks entry, the same as the spec's ManifestValidator. */
    private const BLOCK = '/^[a-z0-9]+:[a-z0-9][a-z0-9-]*$/D';

    private readonly string $registry;
    private readonly Closure $now;

    public function __construct(private readonly Home $home, ?string $registry = null, ?Closure $now = null)
    {
        $env = getenv('PHPBM_REPOSITORY');
        $this->registry = rtrim($registry ?? (is_string($env) && $env !== '' ? $env : InitCommand::REPOSITORY), '/\\');
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @return array<string, list<string>>|null 'kind:type' => packages, php-baremetal/* first, then by name;
     *         [] when the registry was read and no package declares blocks, null when it is unavailable (no cache)
     */
    public function index(Output $out, bool $refresh = false): ?array
    {
        $now = ($this->now)();
        $cache = $this->readCache();
        if (!$refresh && $cache !== null && $cache['time'] <= $now && $now - $cache['time'] < self::TTL) {
            return $cache['index'];
        }

        $out->step("reading board packages from {$this->registry}…");
        try {
            $index = $this->fetch($out);
        } catch (Throwable) {
            if ($cache !== null) {
                $out->warning("cannot reach {$this->registry}, using the list of board packages from " . date('Y-m-d H:i', $cache['time']));
                return $cache['index'];
            }
            $out->warning("cannot reach {$this->registry}: board features are not offered (run phpbm board later)");
            return null;
        }

        $this->writeCache($index, $now);
        return $index;
    }

    /** @return array<string, list<string>> */
    private function fetch(Output $out): array
    {
        $root = $this->json($this->registry . '/packages.json');
        $listUrl = $this->resolve(is_string($root['list'] ?? null) ? $root['list'] : 'list.json');
        $pattern = $this->resolve(is_string($root['metadata-url'] ?? null) ? $root['metadata-url'] : 'p2/%package%.json');
        $names = $this->json($listUrl)['packageNames'] ?? null;
        if (!is_array($names)) {
            throw new \UnexpectedValueException('invalid list');
        }

        $index = [];
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $name) !== 1) {
                continue;
            }
            try {
                $blocks = $this->blocks($name, $this->json(str_replace('%package%', $name, $pattern)));
            } catch (UnreadableException $e) {
                if ($e->notFound) {
                    $out->warning("skipping {$name}: no metadata (404)");
                    continue;
                }
                throw $e; // transient: never cache a partial index
            } catch (Throwable $e) {
                $out->warning("skipping {$name}: cannot read its metadata ({$e->getMessage()})");
                continue;
            }
            foreach ($blocks as $block) {
                $index[$block][] = $name;
            }
        }

        foreach ($index as $block => $packages) {
            $packages = array_values(array_unique($packages));
            usort($packages, static fn (string $a, string $b): int => [!str_starts_with($a, 'php-baremetal/'), $a] <=> [!str_starts_with($b, 'php-baremetal/'), $b]);
            $index[$block] = $packages;
        }
        return $index;
    }

    /** @param array<string,mixed> $data @return list<string> */
    private function blocks(string $name, array $data): array
    {
        $versions = $data['packages'][$name] ?? null;
        if (!is_array($versions)) {
            throw new \UnexpectedValueException('no versions');
        }

        $parser = new VersionParser();
        $best = null;
        foreach (self::expand($versions) as $entry) {
            $version = $entry['version'] ?? null;
            if (!is_string($version)) {
                continue;
            }
            try {
                if ($parser->parseStability($version) !== 'stable') {
                    continue;
                }
                if ($best === null || Comparator::greaterThan($version, $best['version'])) {
                    $best = $entry;
                }
            } catch (\UnexpectedValueException) {
                continue; // an invalid version string skips only this entry
            }
        }

        $blocks = $best['extra']['baremetal']['board-blocks'] ?? [];
        if (!is_array($blocks)) {
            return [];
        }
        return array_values(array_filter($blocks, static fn ($b): bool => is_string($b) && preg_match(self::BLOCK, $b) === 1));
    }

    /**
     * Composer 2 minified metadata: the first version is complete, the others only carry the keys that
     * changed; the string "__unset" removes a key.
     *
     * @param array<int,mixed> $versions
     * @return list<array<string,mixed>>
     */
    private static function expand(array $versions): array
    {
        $out = [];
        $previous = [];
        foreach ($versions as $entry) {
            if (!is_array($entry)) {
                throw new \UnexpectedValueException('invalid version entry');
            }
            foreach ($entry as $key => $value) {
                if ($value === '__unset') {
                    unset($previous[$key]);
                } else {
                    $previous[$key] = $value;
                }
            }
            $out[] = $previous;
        }
        return $out;
    }

    /**
     * Reads a JSON document of the registry. A missing document (HTTP 404, or a local file that does not exist)
     * throws an UnreadableException with notFound; any other failure to read it (network, timeout, other HTTP
     * errors) an UnreadableException without.
     *
     * @return array<string,mixed>
     */
    private function json(string $url): array
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT, 'ignore_errors' => true]]);
            $raw = @file_get_contents($url, false, $context);
            $status = self::status(http_get_last_response_headers() ?? []);
            if ($status === 404) {
                throw new UnreadableException("{$url}: 404", notFound: true);
            }
            if ($raw === false || $status === null || $status >= 400) {
                throw new UnreadableException("cannot read {$url}" . ($status !== null ? " (HTTP {$status})" : ''));
            }
        } else {
            $path = Paths::local($url);
            $raw = is_file($path) ? @file_get_contents($path) : false;
            if ($raw === false) {
                throw new UnreadableException("cannot read {$url}", notFound: !file_exists($path));
            }
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException("invalid json in {$url}: {$e->getMessage()}");
        }
        if (!is_array($data)) {
            throw new \RuntimeException("unexpected content in {$url}");
        }
        return $data;
    }

    /** The status of the last HTTP response (after redirects) in a list of response headers. @param list<string> $headers */
    private static function status(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    private function resolve(string $url): string
    {
        return self::resolveUrl($this->registry, $url);
    }

    /** Resolves a (possibly relative) url of packages.json against the registry address. */
    public static function resolveUrl(string $registry, string $url): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '/') && preg_match('#^(https?://[^/]+)#i', $registry, $m) === 1) {
            return $m[1] . $url;
        }
        if (Paths::isAbsolute($url)) {
            return $url;
        }
        return rtrim($registry, '/\\') . '/' . ltrim($url, '/');
    }

    /** @return array{time:int, index:array<string,list<string>>}|null */
    private function readCache(): ?array
    {
        $raw = @file_get_contents($this->home->boardPackages());
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['registry'] ?? null) !== $this->registry || !is_int($data['time'] ?? null) || !is_array($data['index'] ?? null)) {
            return null;
        }
        foreach ($data['index'] as $block => $packages) {
            if (!is_string($block) || !is_array($packages) || !array_is_list($packages)) {
                return null;
            }
            foreach ($packages as $package) {
                if (!is_string($package)) {
                    return null;
                }
            }
        }
        return ['time' => $data['time'], 'index' => $data['index']];
    }

    /** @param array<string, list<string>> $index */
    private function writeCache(array $index, int $now): void
    {
        $file = $this->home->boardPackages();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['registry' => $this->registry, 'time' => $now, 'index' => $index], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json !== false && @file_put_contents($tmp, $json) !== false && !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
