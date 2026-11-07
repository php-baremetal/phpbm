<?php

declare(strict_types=1);

namespace App\Board;

/**
 * What `phpbm board` changes. BoardFeatures::plan() only fills $remove and $require ($board is null and
 * $unsetOverrides empty): on a board change the caller builds the final plan with its own board id and overrides.
 */
final readonly class ChangePlan
{
    /**
     * @param list<string> $unsetOverrides
     * @param list<string> $remove
     * @param list<string> $require
     */
    public function __construct(
        public ?string $board,
        public array $unsetOverrides,
        public array $remove,
        public array $require,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->board === null && $this->unsetOverrides === [] && $this->remove === [] && $this->require === [];
    }
}
