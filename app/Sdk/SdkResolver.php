<?php

declare(strict_types=1);

namespace App\Sdk;

use App\PhpbmException;
use App\Support\Archive;
use App\Support\Downloader;
use App\Support\Home;
use Composer\Semver\Semver;
use PhpBaremetal\Spec\Manifest\SdkRequirement;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Sdk;
use UnexpectedValueException;

final readonly class SdkResolver
{
    public function __construct(
        private SdkStore $store,
        private SdkIndex $index,
        private Downloader $downloader,
        private Archive $archive,
        private Home $home,
    ) {
    }

    public function resolve(string $projectDir, SdkRequirement $requirement, Output $out, bool $update = false): Sdk
    {
        $name = $requirement->name;
        $linked = $this->store->linked($name);
        if ($linked !== null) {
            $out->warning("using {$name} linked from {$linked}, not locked");
            return $this->store->load($name, 'dev');
        }

        $lock = Lock::read($projectDir);
        $locked = $lock !== null && $lock->name === $name ? $lock : null;

        if ($locked !== null && !$update) {
            try {
                $satisfied = Semver::satisfies($locked->version, $requirement->constraint);
            } catch (UnexpectedValueException) {
                throw new PhpbmException("invalid version constraint '{$requirement->constraint}' for sdk {$name}: use e.g. ^1.0");
            }
            if (!$satisfied) {
                throw new PhpbmException("phpbm.lock uses {$name} {$locked->version}, which does not satisfy {$requirement->constraint}: run `phpbm sdk:update`");
            }
            if ($locked->constraint !== $requirement->constraint) {
                $locked->withConstraint($requirement->constraint)->write($projectDir);
            }
            return $this->ensure($name, $locked->version, $locked->sha256, $out);
        }

        $best = $this->index->best($name, $requirement->constraint)
            ?? throw new PhpbmException("no {$name} sdk release satisfies {$requirement->constraint}");
        if ($locked !== null && $locked->version !== $best['version']) {
            $out->step("{$name} {$locked->version} -> {$best['version']}");
        }
        $sdk = $this->ensure($name, $best['version'], $best['sha256'], $out, $best['url']);
        (new Lock($name, $requirement->constraint, $best['version'], $best['sha256']))->write($projectDir);

        return $sdk;
    }

    public function ensure(string $name, string $version, string $sha256, Output $out, ?string $url = null): Sdk
    {
        if ($this->store->has($name, $version)) {
            $installed = $this->store->sha256($name, $version);
            if ($installed !== null && $installed !== $sha256) {
                throw new PhpbmException("sdk {$name} {$version} installed here differs from the locked one (sha256 {$installed}, lock {$sha256}): run `phpbm sdk:remove {$name}@{$version}` and build again");
            }
            return $this->store->load($name, $version);
        }

        $url ??= ($this->index->versions($name)[$version] ?? throw new PhpbmException("sdk {$name} {$version} is not published in the index"))['url'];
        $out->step("installing sdk {$name} {$version}");
        $archive = $this->home->downloads() . "/sdk-{$name}-{$version}-" . basename((string) parse_url($url, PHP_URL_PATH));
        $this->downloader->fetch($url, $sha256, $archive);

        return $this->store->install($name, $version, $archive, $sha256, $this->archive);
    }
}
