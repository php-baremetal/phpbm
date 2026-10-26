<?php

declare(strict_types=1);

use App\Sdk\Lock;
use PhpBaremetal\Spec\SpecException;
use Tests\Support\Fixtures;

it('writes a stable file and reads it back', function () {
    $dir = Fixtures::tempDir();
    $lock = new Lock('demo', '^1.0', '1.0.3', str_repeat('f', 64));
    $lock->write($dir);

    expect(file_get_contents($dir . '/phpbm.lock'))->toBe(<<<JSON
{
    "sdk": {
        "name": "demo",
        "constraint": "^1.0",
        "version": "1.0.3",
        "sha256": "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"
    }
}

JSON)
        ->and(Lock::read($dir))->toEqual($lock);
});

it('is absent when there is no file', function () {
    expect(Lock::read(Fixtures::tempDir()))->toBeNull();
});

it('rejects a malformed lock', function () {
    $dir = Fixtures::tempDir();
    file_put_contents($dir . '/phpbm.lock', '{"sdk":{"name":"demo"}}');
    expect(fn () => Lock::read($dir))->toThrow(SpecException::class, "phpbm.lock: 'constraint' must be a non-empty string");
});

it('changes only the constraint', function () {
    $lock = (new Lock('demo', '^1.0', '1.0.3', str_repeat('f', 64)))->withConstraint('~1.0.0');
    expect($lock->constraint)->toBe('~1.0.0')->and($lock->version)->toBe('1.0.3');
});
