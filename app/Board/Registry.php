<?php

declare(strict_types=1);

namespace App\Board;

use App\Support\Paths;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use JsonException;

/** Reading a Composer v2 registry (packages.json, p2 metadata, dist archives), over HTTP or from a local folder. */
final class Registry
{
    /** Seconds an HTTP read of the registry may take. */
    public const TIMEOUT = 10;

    /**
     * Reads a document of the registry. A missing document (HTTP 404, or a local file that does not exist) throws an
     * UnreadableException with notFound; any other failure (network, timeout, other HTTP errors, more than $limit
     * bytes) an UnreadableException without.
     */
    public static function read(string $url, ?int $limit = null): string
    {
        $length = $limit === null ? null : $limit + 1;
        if (preg_match('#^https?://#i', $url) === 1) {
            // GitHub answers dist archive requests without a User-Agent with 403.
            $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT, 'ignore_errors' => true, 'user_agent' => 'phpbm']]);
            $raw = @file_get_contents($url, false, $context, 0, $length);
            $status = self::status(http_get_last_response_headers() ?? []);
            if ($status === 404) {
                throw new UnreadableException("{$url}: 404", notFound: true);
            }
            if ($raw === false || $status === null || $status >= 400) {
                throw new UnreadableException("cannot read {$url}" . ($status !== null ? " (HTTP {$status})" : ''));
            }
        } else {
            $path = Paths::local($url);
            $raw = is_file($path) ? @file_get_contents($path, false, null, 0, $length) : false;
            if ($raw === false) {
                throw new UnreadableException("cannot read {$url}", notFound: !file_exists($path));
            }
        }
        if ($limit !== null && strlen($raw) > $limit) {
            throw new UnreadableException("{$url} is larger than {$limit} bytes");
        }
        return $raw;
    }

    /** @return array<string,mixed> */
    public static function json(string $url): array
    {
        $raw = self::read($url);
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

    /** The url pattern of the p2 metadata (with %package%), from packages.json. */
    public static function metadataPattern(string $registry): string
    {
        $root = self::json(self::resolveUrl($registry, 'packages.json'));
        return self::resolveUrl($registry, is_string($root['metadata-url'] ?? null) ? $root['metadata-url'] : 'p2/%package%.json');
    }

    /**
     * The newest stable version in the p2 metadata of a package; null when it has none. An invalid version string
     * skips only its entry.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public static function latestStable(string $name, array $data): ?array
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
                continue;
            }
        }
        return $best;
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
}
