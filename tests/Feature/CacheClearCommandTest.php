<?php

declare(strict_types=1);

use Tests\Support\Fixtures;

it('empties the cache', function () {
    $home = Fixtures::bootstrap();
    mkdir($home->trees() . '/x', 0777, true);

    $this->artisan('cache:clear')->expectsOutputToContain('cleared ' . $home->cache())->assertExitCode(0);
    expect(is_dir($home->cache()))->toBeFalse();
});
