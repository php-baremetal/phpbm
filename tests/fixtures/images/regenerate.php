<?php

// Writes the PNG corpus and the expected .sli files of this folder again, from tests/Support/ImageFixtures.php.
// Run from the phpbm folder: php tests/fixtures/images/regenerate.php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

Tests\Support\ImageFixtures::regenerate(__DIR__);
foreach (array_merge(glob(__DIR__ . '/*.png'), glob(__DIR__ . '/*.sli')) as $file) {
    printf("%-24s %7d bytes\n", basename($file), filesize($file));
}
