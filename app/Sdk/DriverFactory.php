<?php

declare(strict_types=1);

namespace App\Sdk;

use App\Support\Home;
use PhpBaremetal\Spec\Sdk\Context;
use PhpBaremetal\Spec\Sdk\Driver;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\Sdk;

final readonly class DriverFactory
{
    public function __construct(private Home $home, private Process $process)
    {
    }

    public function for(Sdk $sdk): Driver
    {
        return $sdk->driver(new Context($sdk->dir, $this->home->toolchain($sdk->descriptor->name), $this->process));
    }
}
