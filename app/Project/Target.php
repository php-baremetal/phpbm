<?php

declare(strict_types=1);

namespace App\Project;

use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use PhpBaremetal\Spec\Sdk\Descriptor\Chip;
use PhpBaremetal\Spec\Sdk\Descriptor\Module;
use PhpBaremetal\Spec\Sdk\Descriptor\PhpExtension;
use PhpBaremetal\Spec\Sdk\Descriptor\PhpRelease;

final readonly class Target
{
    public function __construct(
        public Board $board,
        public Chip $chip,
        public Module $runtime,
        public Module $storage,
        public PhpRelease $php,
        /** @var array<string,string|int|bool> */
        public array $options = [],
        /** @var list<PhpExtension> required ones first */
        public array $phpExtensions = [],
    ) {
    }
}
