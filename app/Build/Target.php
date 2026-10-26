<?php

declare(strict_types=1);

namespace App\Build;

use PhpBaremetal\Spec\Sdk\BuildPlan;
use PhpBaremetal\Spec\Sdk\Driver;

final readonly class Target
{
    public function __construct(public Driver $driver, public BuildPlan $plan)
    {
    }
}
