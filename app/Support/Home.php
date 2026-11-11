<?php

declare(strict_types=1);

namespace App\Support;

use App\PhpbmException;

final readonly class Home
{
    public function __construct(public string $root)
    {
    }

    /**
     * @param array<string,string>|null $env
     * @param string|null $osFamily PHP_OS_FAMILY by default; on Windows the root is normalised (see Paths)
     */
    public static function fromEnvironment(?array $env = null, ?string $osFamily = null): self
    {
        $env ??= getenv();
        $value = static fn (string $name): string => is_string($env[$name] ?? null) ? $env[$name] : '';

        if ($value('PHPBM_HOME') !== '') {
            return new self(rtrim(Paths::normalize(Paths::resolve(Paths::cwd(), $value('PHPBM_HOME')), $osFamily), '/\\'));
        }
        foreach ([$value('HOME'), $value('USERPROFILE'), $value('HOMEDRIVE') . $value('HOMEPATH')] as $home) {
            if ($home !== '') {
                return new self(Paths::normalize(rtrim($home, '/\\'), $osFamily) . '/.phpbm');
            }
        }
        throw new PhpbmException('cannot locate the home directory: set PHPBM_HOME');
    }

    public function sdks(): string
    {
        return $this->root . '/sdks';
    }

    public function toolchain(string $platform): string
    {
        return $this->root . '/toolchains/' . $platform;
    }

    public function cache(): string
    {
        return $this->root . '/cache';
    }

    public function boardPackages(): string
    {
        return $this->cache() . '/board-packages.json';
    }

    public function downloads(): string
    {
        return $this->cache() . '/downloads';
    }

    /** Converted assets (.sli), named by the hash of their source. */
    public function assets(): string
    {
        return $this->cache() . '/assets';
    }

    public function fonts(): string
    {
        return $this->cache() . '/fonts';
    }

    public function trees(): string
    {
        return $this->cache() . '/trees';
    }
}
