<?php

declare(strict_types=1);

use App\Build\PortPicker;
use App\PhpbmException;
use PhpBaremetal\Spec\Sdk\Port;

function driverWithPorts(array $ports): PhpBaremetal\Spec\Sdk\Driver
{
    $driver = Mockery::mock(PhpBaremetal\Spec\Sdk\Driver::class);
    $driver->allows('ports')->andReturn($ports);
    return $driver;
}

it('uses an explicit port', function () {
    expect((new PortPicker())->pick(driverWithPorts([]), '/dev/x')->path)->toBe('/dev/x');
});

it('takes the only port', function () {
    expect((new PortPicker())->pick(driverWithPorts([new Port('/dev/a')]), null)->path)->toBe('/dev/a');
});

it('asks when there are several', function () {
    $picker = new PortPicker(function (array $options) {
        expect($options)->toBe(['/dev/a' => '/dev/a  (one)', '/dev/b' => '/dev/b']);
        return '/dev/b';
    });
    expect($picker->pick(driverWithPorts([new Port('/dev/a', 'one'), new Port('/dev/b')]), null)->path)->toBe('/dev/b');
});

it('explains when nothing is connected', function () {
    expect(fn () => (new PortPicker())->pick(driverWithPorts([]), null))
        ->toThrow(PhpbmException::class, 'no serial port found: connect the board or pass --port');
});

it('does not ask when the input is not interactive', function () {
    $picker = new PortPicker(fn () => throw new LogicException('asked'));
    expect(fn () => $picker->pick(driverWithPorts([new Port('/dev/a'), new Port('/dev/b')]), null, false))
        ->toThrow(PhpbmException::class, 'several serial ports found (/dev/a, /dev/b): pass --port');
});
