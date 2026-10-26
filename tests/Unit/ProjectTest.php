<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Project\Project;
use PhpBaremetal\Spec\Manifest\Package;
use Tests\Support\Fixtures;

it('loads the manifest of a project', function () {
    $dir = Fixtures::project(Fixtures::tempDir());
    $project = Project::load($dir);

    expect($project->dir)->toBe($dir)
        ->and($project->manifest->project->board)->toBe('demo-board')
        ->and($project->path('project-src'))->toBe($dir . '/project-src')
        ->and($project->path('/abs'))->toBe('/abs')
        ->and($project->buildDir())->toBe($dir . '/.phpbm/build');
});

it('requires a composer.json', function () {
    $dir = Fixtures::tempDir();
    expect(fn () => Project::load($dir))->toThrow(PhpbmException::class, "{$dir} is not a phpbm project (no composer.json)");
});

it('explains a missing manifest', function () {
    $dir = Fixtures::project(Fixtures::tempDir());
    unlink($dir . '/.phpbm/manifest.json');
    expect(fn () => Project::load($dir))->toThrow(PhpbmException::class, '`composer conductor:generate` shows why the manifest was not written');
});

it('refuses a manifest older than composer.lock', function () {
    $dir = Fixtures::project(Fixtures::tempDir());
    touch($dir . '/composer.lock', time() + 60);
    expect(fn () => Project::load($dir))->toThrow(PhpbmException::class, '.phpbm/manifest.json is older than composer.lock: run `composer install`');
});

it('reports package graph problems', function () {
    $dir = Fixtures::project(Fixtures::tempDir(), [], [
        new Package('a/ext-x', 'bm-ext', '1.0.0', 'p', ['module' => 'x']),
        new Package('b/ext-x', 'bm-ext', '1.0.0', 'p', ['module' => 'x']),
    ]);
    expect(fn () => Project::load($dir))->toThrow(PhpbmException::class, "extension 'x' provided by both a/ext-x and b/ext-x");
});

it('refuses a manifest older than composer.json', function () {
    $dir = Fixtures::project(Fixtures::tempDir());
    touch($dir . '/composer.json', time() + 60);
    expect(fn () => Project::load($dir))->toThrow(PhpbmException::class, '.phpbm/manifest.json is older than composer.json: run `composer install` (or `composer update --lock` if only extra.baremetal changed');
});

it('prefers the conductor diagnosis when the manifest is missing', function () {
    $dir = Fixtures::project(Fixtures::tempDir());
    unlink($dir . '/.phpbm/manifest.json');
    $conductor = new App\Project\ConductorCheck(new PhpBaremetal\Spec\Testing\FakeProcess());
    expect(fn () => Project::load($dir, $conductor))->toThrow(PhpbmException::class, 'composer global require php-baremetal/conductor:^1.0');
});
