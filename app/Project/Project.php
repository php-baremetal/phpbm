<?php

declare(strict_types=1);

namespace App\Project;

use App\PhpbmException;
use App\Support\Paths;
use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Manifest\ManifestValidator;

final readonly class Project
{
    public function __construct(public string $dir, public Manifest $manifest)
    {
    }

    public static function load(string $dir, ?ConductorCheck $conductor = null): self
    {
        $dir = realpath($dir) ?: $dir;
        if (!is_file("{$dir}/composer.json")) {
            throw new PhpbmException("{$dir} is not a phpbm project (no composer.json)");
        }
        $file = "{$dir}/" . Manifest::PATH;
        if (!is_file($file)) {
            $problem = $conductor?->problem();
            if ($problem !== null) {
                throw new PhpbmException('no ' . Manifest::PATH . ": {$problem}, then run `composer install`");
            }
            throw new PhpbmException('no ' . Manifest::PATH . ': run `composer install`; if it already ran, `composer conductor:generate` shows why the manifest was not written');
        }
        if (filemtime($file) < filemtime("{$dir}/composer.json")) {
            // extra is part of composer.lock's content-hash: after changing only extra (the board, an option),
            // `composer install` warns that the lock file is not up to date; `composer update --lock` refreshes the hash only.
            throw new PhpbmException(Manifest::PATH . ' is older than composer.json: run `composer install` '
                . '(or `composer update --lock` if only extra.baremetal changed, e.g. the board: it refreshes the lock hash without updating packages)');
        }
        $lock = "{$dir}/composer.lock";
        if (is_file($lock) && filemtime($file) < filemtime($lock)) {
            throw new PhpbmException(Manifest::PATH . ' is older than composer.lock: run `composer install`');
        }

        $manifest = Manifest::fromFile($file);
        $problems = (new ManifestValidator())->validate($manifest);
        if ($problems !== []) {
            throw new PhpbmException("the package graph has problems:\n  - " . implode("\n  - ", $problems));
        }

        return new self($dir, $manifest);
    }

    public function path(string $relative): string
    {
        return Paths::resolve($this->dir, $relative);
    }

    public function buildDir(): string
    {
        return $this->dir . '/.phpbm/build';
    }
}
