<?php

declare(strict_types=1);

use App\PhpbmException;
use App\Sdk\Lock;
use App\Sdk\SdkResolver;
use App\Sdk\SdkStore;
use PhpBaremetal\Spec\Manifest\SdkRequirement;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->home = Fixtures::bootstrap(['1.0.0', '1.1.0']);
    $this->project = Fixtures::tempDir();
    $this->resolver = app(SdkResolver::class);
    $this->out = new BufferedOutput();
});

it('installs the best version and writes the lock', function () {
    $sdk = $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out);

    expect($sdk->descriptor->version)->toBe('1.1.0')
        ->and(Lock::read($this->project)?->version)->toBe('1.1.0')
        ->and(Lock::read($this->project)?->constraint)->toBe('^1.0')
        ->and($this->out->lines)->toContain('step: installing sdk demo 1.1.0');
});

it('installs exactly the locked version', function () {
    $sha = app(App\Sdk\SdkIndex::class)->versions('demo')['1.0.0']['sha256'];
    (new Lock('demo', '^1.0', '1.0.0', $sha))->write($this->project);

    expect($this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out)->descriptor->version)->toBe('1.0.0');
});

it('only rewrites the constraint when the locked version still fits', function () {
    $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out);
    $this->resolver->resolve($this->project, SdkRequirement::parse('demo@~1.1.0'), $this->out);

    expect(Lock::read($this->project)?->constraint)->toBe('~1.1.0')
        ->and(Lock::read($this->project)?->version)->toBe('1.1.0');
});

it('refuses a constraint the lock does not satisfy', function () {
    $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out);

    expect(fn () => $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^2.0'), $this->out))
        ->toThrow(PhpbmException::class, 'phpbm.lock uses demo 1.1.0, which does not satisfy ^2.0: run `phpbm sdk:update`');
});

it('rejects an invalid constraint even with a lock', function () {
    $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out);
    expect(fn () => $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^^1'), $this->out))
        ->toThrow(PhpbmException::class, "invalid version constraint '^^1' for sdk demo: use e.g. ^1.0");
});

it('updates on request and says from which version', function () {
    $sha = app(App\Sdk\SdkIndex::class)->versions('demo')['1.0.0']['sha256'];
    (new Lock('demo', '^1.0', '1.0.0', $sha))->write($this->project);

    $sdk = $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out, true);

    expect($sdk->descriptor->version)->toBe('1.1.0')
        ->and(Lock::read($this->project)?->version)->toBe('1.1.0')
        ->and($this->out->lines)->toContain('step: demo 1.0.0 -> 1.1.0');
});

it('refuses an installed sdk whose checksum differs from the lock', function () {
    $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out);
    file_put_contents($this->home->sdks() . '/demo/1.1.0.sha256', str_repeat('0', 64));

    expect(fn () => $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out))
        ->toThrow(PhpbmException::class, 'sdk demo 1.1.0 installed here differs from the locked one');
});

it('prefers a linked sdk and leaves the lock alone', function () {
    $dir = Fixtures::sdk(Fixtures::tempDir() . '/demo-sdk', '1.9.0', Fixtures::phpTarball(Fixtures::tempDir()));
    app(SdkStore::class)->link('demo', $dir);

    $sdk = $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out);

    expect($sdk->descriptor->version)->toBe('1.9.0')
        ->and(Lock::read($this->project))->toBeNull()
        ->and($this->out->lines)->toContain('warning: using demo linked from ' . realpath($dir) . ', not locked');
});

it('explains when nothing satisfies the constraint', function () {
    expect(fn () => $this->resolver->resolve($this->project, SdkRequirement::parse('demo@^5.0'), $this->out))
        ->toThrow(PhpbmException::class, 'no demo sdk release satisfies ^5.0');
});

it('installs from a file url index, as an offline mirror', function () {
    $index = str_replace('\\', '/', dirname($this->home->root) . '/dist/index.json');
    app()->instance(App\Sdk\SdkIndex::class, new App\Sdk\SdkIndex('file://' . (str_starts_with($index, '/') ? '' : '/') . $index));

    $sdk = app(SdkResolver::class)->resolve($this->project, SdkRequirement::parse('demo@^1.0'), $this->out);
    expect($sdk->descriptor->version)->toBe('1.1.0')
        ->and($sdk->dir)->toBe(realpath($this->home->sdks() . '/demo/1.1.0'))
        ->and(Lock::read($this->project)?->sha256)->toBe(hash_file('sha256', dirname($this->home->root) . '/dist/demo-sdk-1.1.0.tar.gz'));
});

it('installs a pre-release that the constraint allows', function () {
    Fixtures::bootstrap(['0.99.0-alpha1']);

    $sdk = app(SdkResolver::class)->resolve($this->project, SdkRequirement::parse('demo@^0.99'), $this->out);
    expect($sdk->descriptor->version)->toBe('0.99.0-alpha1')
        ->and(Lock::read($this->project)?->version)->toBe('0.99.0-alpha1');
});
