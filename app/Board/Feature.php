<?php

declare(strict_types=1);

namespace App\Board;

/** A board block that packages can drive: the block name, its kind ('led-2' => 'led'), type and candidate packages. */
final readonly class Feature
{
    /** @param list<string> $packages candidates, best first */
    public function __construct(
        public string $block,
        public string $kind,
        public string $type,
        public array $packages,
    ) {
    }

    /** The package chosen for this feature: the first candidate. */
    public function package(): ?string
    {
        return $this->packages[0] ?? null;
    }
}
