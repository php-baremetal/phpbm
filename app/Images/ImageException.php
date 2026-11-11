<?php

declare(strict_types=1);

namespace App\Images;

/** A PNG that cannot be read, or pixels that cannot become a .sli; the message says why. */
final class ImageException extends \RuntimeException
{
}
