<?php

declare(strict_types=1);

namespace App\Board;

/** The registry could not be read (network, missing file): distinct from malformed content. */
final class UnreadableException extends \RuntimeException
{
    /** @param bool $notFound the document does not exist (HTTP 404, or a missing local file) */
    public function __construct(string $message, public readonly bool $notFound = false)
    {
        parent::__construct($message);
    }
}
