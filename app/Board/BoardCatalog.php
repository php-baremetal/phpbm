<?php

declare(strict_types=1);

namespace App\Board;

use App\Support\Paths;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use PhpBaremetal\Spec\Sdk\Sdk;

/**
 * The board catalog (boards/catalog.json) of the SDK and of bm-boards packages: { "<sdk>": { "<board id>": {...} } }.
 * Only the "coming-soon" flag is read. A missing or invalid file has no entries.
 */
final class BoardCatalog
{
    public const FILE = 'boards/catalog.json';

    /** @var array<string, array<string, mixed>> source => entries of this SDK, read once */
    private array $entries = [];

    /** @param array<string,string> $dirs source (Board::SDK or a package name) => folder holding boards/catalog.json */
    public function __construct(private readonly string $sdkName, private readonly array $dirs)
    {
    }

    /**
     * The catalogs of the SDK and of the given bm-boards packages (a relative package path is resolved against
     * $projectDir, as in the manifest).
     *
     * @param iterable<Package> $packages
     */
    public static function of(Sdk $sdk, iterable $packages = [], ?string $projectDir = null): self
    {
        $dirs = [Board::SDK => $sdk->dir];
        foreach ($packages as $package) {
            if ($package->kind() !== 'boards') {
                continue;
            }
            $dirs[$package->name] = $projectDir === null ? $package->path : Paths::resolve($projectDir, $package->path);
        }
        return new self($sdk->descriptor->name, $dirs);
    }

    public function comingSoon(Board $board): bool
    {
        $entry = $this->entriesOf($board->source)[$board->id] ?? null;
        return is_array($entry) && ($entry['coming-soon'] ?? false) === true;
    }

    /** @return array<string,mixed> */
    private function entriesOf(string $source): array
    {
        if (!isset($this->entries[$source])) {
            $dir = $this->dirs[$source] ?? null;
            $raw = $dir === null ? false : @file_get_contents(rtrim($dir, '/\\') . '/' . self::FILE);
            $data = $raw === false ? null : json_decode($raw, true);
            $entries = is_array($data) ? ($data[$this->sdkName] ?? null) : null;
            $this->entries[$source] = is_array($entries) ? $entries : [];
        }
        return $this->entries[$source];
    }
}
