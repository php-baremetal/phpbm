<?php

declare(strict_types=1);

namespace App\Providers;

use App\Sdk\SdkIndex;
use App\Support\Home;
use App\Support\SymfonyProcess;
use Illuminate\Support\ServiceProvider;
use PhpBaremetal\Spec\Sdk\Process;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Home::class, static fn () => Home::fromEnvironment());
        $this->app->singleton(Process::class, SymfonyProcess::class);
        $this->app->singleton(SdkIndex::class, static fn () => SdkIndex::fromEnvironment());
    }
}
