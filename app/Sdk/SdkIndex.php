<?php

declare(strict_types=1);

namespace App\Sdk;

use App\PhpbmException;
use App\Support\Paths;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use JsonException;
use UnexpectedValueException;

final readonly class SdkIndex
{
    public const DEFAULT = 'https://github.com/php-baremetal/php-{name}-sdk/releases/latest/download/index.json';

    public function __construct(private string $pattern = self::DEFAULT)
    {
    }

    public static function fromEnvironment(): self
    {
        $pattern = getenv('PHPBM_SDK_INDEX');
        return new self(is_string($pattern) && $pattern !== '' ? $pattern : self::DEFAULT);
    }

    /** @return array<string, array{url:string, sha256:string}> */
    public function versions(string $name): array
    {
        SdkStore::assertName($name);
        $url = str_replace('{name}', $name, $this->pattern);
        $raw = @file_get_contents(Paths::local($url));
        if ($raw === false) {
            throw new PhpbmException("cannot read the sdk index {$url}: check the network or set PHPBM_SDK_INDEX");
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PhpbmException("invalid sdk index {$url}: {$e->getMessage()}");
        }
        if (!is_array($data) || !is_array($data['versions'] ?? null)) {
            throw new PhpbmException('invalid sdk index ' . $url . ': expected {"versions": {…}}');
        }

        $parser = new VersionParser();
        $versions = [];
        foreach ($data['versions'] as $version => $entry) {
            $version = (string) $version;
            try {
                $parser->normalize($version);
            } catch (UnexpectedValueException) {
                throw new PhpbmException("invalid sdk index {$url}: '{$version}' is not a version");
            }
            if (
                !is_array($entry)
                || !is_string($entry['url'] ?? null)
                || !is_string($entry['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/i', $entry['sha256']) !== 1
            ) {
                throw new PhpbmException("invalid sdk index {$url}: entry {$version}");
            }
            $versions[$version] = ['url' => self::resolve($url, $entry['url']), 'sha256' => strtolower($entry['sha256'])];
        }
        return $versions;
    }

    /** @return array{version:string, url:string, sha256:string}|null */
    public function best(string $name, string $constraint): ?array
    {
        $versions = $this->versions($name);
        try {
            $matching = Semver::satisfiedBy(array_map('strval', array_keys($versions)), $constraint);
        } catch (UnexpectedValueException) {
            throw new PhpbmException("invalid version constraint '{$constraint}' for sdk {$name}: use e.g. ^1.0");
        }
        if ($matching === []) {
            return null;
        }
        $version = Semver::rsort($matching)[0];
        return ['version' => $version, ...$versions[$version]];
    }

    private static function resolve(string $index, string $url): string
    {
        if (Paths::isAbsolute($url) || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }
        $cut = max((int) strrpos($index, '/'), (int) strrpos($index, '\\'));
        return substr($index, 0, $cut + 1) . $url;
    }
}
