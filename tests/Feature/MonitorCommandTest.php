<?php

declare(strict_types=1);

use Tests\Support\Fixtures;

it('opens the monitor through the driver', function () {
    $cwd = getcwd();
    $home = Fixtures::bootstrap();
    chdir(Fixtures::project(Fixtures::tempDir()));
    Fixtures::toolchain($home, ['ports' => ['/dev/ttyDEMO']]);

    $this->artisan('monitor')->assertExitCode(0);
    expect(Fixtures::calls($home))->toBe(['monitor /dev/ttyDEMO']);
    chdir($cwd);
});
