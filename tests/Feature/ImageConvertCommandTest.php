<?php

declare(strict_types=1);

use Tests\Support\Fixtures;
use Tests\Support\ImageFixtures;

beforeEach(function () {
    $this->cwd = getcwd();
    $this->dir = Fixtures::tempDir();
    chdir($this->dir);
});

afterEach(fn () => chdir($this->cwd));

// The paths go in the parameter array, not in a command string (see FontBakeCommandTest).

it('converts a PNG into a .sli and prints its size', function () {
    $in = ImageFixtures::DIR . '/icon-48.png';
    $sli = file_get_contents(ImageFixtures::DIR . '/icon-48.sli');
    $bytes = strlen($sli);

    $this->artisan('image:convert', ['input' => $in, 'output' => 'icon.sli'])
        ->expectsOutputToContain("icon.sli (48x48, {$bytes} bytes)")
        ->assertExitCode(0);

    expect(file_get_contents($this->dir . '/icon.sli') === $sli)->toBeTrue();
});

it('creates the output folder and overwrites the output file', function () {
    $out = $this->dir . '/assets/ui/dot.sli';
    mkdir(dirname($out), 0777, true);
    file_put_contents($out, 'old');

    $this->artisan('image:convert', ['input' => ImageFixtures::DIR . '/1x1-opaque.png', 'output' => $out])
        ->expectsOutputToContain("{$out} (1x1, 24 bytes)")
        ->assertExitCode(0);
    $this->artisan('image:convert', ['input' => ImageFixtures::DIR . '/run-70.png', 'output' => $this->dir . '/new/dir/run.sli'])
        ->assertExitCode(0);

    expect(file_get_contents($out))->toBe(file_get_contents(ImageFixtures::DIR . '/1x1-opaque.sli'))
        ->and(file_get_contents($this->dir . '/new/dir/run.sli'))->toBe(file_get_contents(ImageFixtures::DIR . '/run-70.sli'));
});

it('fails on a missing file', function () {
    $this->artisan('image:convert', ['input' => 'none.png', 'output' => 'none.sli'])
        ->expectsOutputToContain('none.png: file not found')
        ->assertExitCode(1);

    expect(is_file($this->dir . '/none.sli'))->toBeFalse();
});

it('fails on a file that is not a PNG', function () {
    file_put_contents($this->dir . '/fake.png', 'GIF89a');

    $this->artisan('image:convert', ['input' => 'fake.png', 'output' => 'fake.sli'])
        ->expectsOutputToContain('fake.png: not a PNG file (bad signature)')
        ->assertExitCode(1);
});

it('fails on an image wider than 32767 pixels', function () {
    $this->artisan('image:convert', ['input' => ImageFixtures::DIR . '/wide-40000.png', 'output' => 'wide.sli'])
        ->expectsOutputToContain('size 40000x1 is out of 1..32767')
        ->assertExitCode(1);

    expect(is_file($this->dir . '/wide.sli'))->toBeFalse();
});

it('fails when the output cannot be written', function () {
    file_put_contents($this->dir . '/file', '');

    $this->artisan('image:convert', ['input' => ImageFixtures::DIR . '/1x1-opaque.png', 'output' => 'file/out.sli'])
        ->expectsOutputToContain('cannot')
        ->assertExitCode(1);
});
