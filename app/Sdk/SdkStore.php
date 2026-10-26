<?php

declare(strict_types=1);

namespace App\Sdk;

use App\PhpbmException;
use App\Support\Archive;
use App\Support\Files;
use App\Support\Home;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Filesystem\Filesystem;
use PhpBaremetal\Spec\Sdk\Sdk;
use PhpBaremetal\Spec\Sdk\SdkLoader;

final readonly class SdkStore
{
    private const LINK = 'dev.link';

    public function __construct(private Home $home, private SdkLoader $loader = new SdkLoader())
    {
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_map('basename', glob($this->home->sdks() . '/*', GLOB_ONLYDIR) ?: []);
        sort($names);
        return $names;
    }

    /** @return list<string> */
    public function versions(string $name): array
    {
        $versions = [];
        foreach (glob($this->home->sdks() . "/{$name}/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $version = basename($dir);
            if ($version === 'dev' || str_starts_with($version, '.') || is_link($dir)) {
                continue;
            }
            try {
                (new VersionParser())->normalize($version);
            } catch (\UnexpectedValueException) {
                continue;
            }
            $versions[] = $version;
        }
        return array_values(Semver::sort($versions));
    }

    public function linked(string $name): ?string
    {
        $file = $this->linkFile($name);
        if (!is_file($file)) {
            $legacy = $this->legacyLink($name);
            return is_link($legacy) ? (readlink($legacy) ?: null) : null;
        }
        $path = trim((string) file_get_contents($file));
        return $path === '' ? null : $path;
    }

    public static function assertName(string $name): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
            throw new PhpbmException("invalid sdk name '{$name}'");
        }
    }

    public static function assertVersion(string $version): void
    {
        if ($version !== 'dev' && (preg_match('/^[0-9A-Za-z.+-]+$/', $version) !== 1 || str_contains($version, '..'))) {
            throw new PhpbmException("invalid sdk version '{$version}'");
        }
    }

    public function dir(string $name, string $version): string
    {
        self::assertName($name);
        self::assertVersion($version);
        if ($version === 'dev' && ($linked = $this->linked($name)) !== null) {
            return $linked;
        }
        return $this->home->sdks() . "/{$name}/{$version}";
    }

    public function has(string $name, string $version): bool
    {
        return is_file($this->dir($name, $version) . '/sdk.json');
    }

    public function load(string $name, string $version): Sdk
    {
        if (!$this->has($name, $version)) {
            $linked = $version === 'dev' ? $this->linked($name) : null;
            throw new PhpbmException($linked === null
                ? "sdk {$name} {$version} is not installed"
                : "sdk {$name} is linked to {$linked}, which has no sdk.json: run `phpbm sdk:remove {$name}@dev` or link it again");
        }
        return $this->loader->load($this->dir($name, $version));
    }

    public function current(string $name): ?Sdk
    {
        if ($this->linked($name) !== null) {
            return $this->load($name, 'dev');
        }
        $versions = $this->versions($name);
        return $versions === [] ? null : $this->load($name, end($versions));
    }

    public function sha256(string $name, string $version): ?string
    {
        $file = $this->dir($name, $version) . '.sha256';
        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }

    public function install(string $name, string $version, string $archive, string $sha256, Archive $extractor): Sdk
    {
        $files = new Filesystem();
        $target = $this->dir($name, $version);
        $tmp = $this->home->sdks() . "/{$name}/.tmp-" . bin2hex(random_bytes(4));

        try {
            $root = $extractor->extract($archive, $tmp);
            $sdk = $this->loader->load($root);
            if ($sdk->descriptor->name !== $name || $sdk->descriptor->version !== $version) {
                throw new PhpbmException("archive for {$name} {$version} contains sdk {$sdk->descriptor->name} {$sdk->descriptor->version}");
            }
            $files->deleteDirectory($target);
            if (!Files::rename($root, $target)) {
                throw new PhpbmException("cannot move sdk {$name} {$version} into {$target}");
            }
            if (file_put_contents("{$target}.sha256", $sha256 . "\n") === false) {
                throw new PhpbmException("cannot write {$target}.sha256");
            }
        } finally {
            $files->deleteDirectory($tmp);
        }

        return $this->loader->load($target);
    }

    public function link(string $name, string $dir): Sdk
    {
        $sdk = $this->loader->load($dir);
        if ($sdk->descriptor->name !== $name) {
            throw new PhpbmException("{$dir} contains sdk {$sdk->descriptor->name}, not {$name}");
        }
        $file = $this->linkFile($name);
        (new Filesystem())->ensureDirectoryExists(dirname($file));
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $sdk->dir . "\n") === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new PhpbmException("cannot write {$file}");
        }
        return $sdk;
    }

    private function linkFile(string $name): string
    {
        self::assertName($name);
        return $this->home->sdks() . "/{$name}/" . self::LINK;
    }

    private function legacyLink(string $name): string
    {
        return $this->home->sdks() . "/{$name}/dev";
    }

    public function remove(string $name, string $version): void
    {
        if ($version === 'dev') {
            if ($this->linked($name) === null) {
                throw new PhpbmException("sdk {$name} is not linked");
            }
            foreach ([$this->linkFile($name), $this->legacyLink($name)] as $file) {
                if ((is_file($file) || is_link($file)) && !@unlink($file)) {
                    throw new PhpbmException("cannot remove {$file}");
                }
            }
            return;
        }
        if (!$this->has($name, $version)) {
            throw new PhpbmException("sdk {$name} {$version} is not installed");
        }
        (new Filesystem())->deleteDirectory($this->dir($name, $version));
        @unlink($this->dir($name, $version) . '.sha256');
    }
}
