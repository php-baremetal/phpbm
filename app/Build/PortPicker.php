<?php

declare(strict_types=1);

namespace App\Build;

use App\PhpbmException;
use PhpBaremetal\Spec\Sdk\Driver;
use PhpBaremetal\Spec\Sdk\Port;

use function Laravel\Prompts\select;

final readonly class PortPicker
{
    /** @param (\Closure(array<string,string>): string)|null $choose */
    public function __construct(private ?\Closure $choose = null)
    {
    }

    public function pick(Driver $driver, ?string $path, bool $interactive = true): Port
    {
        if ($path !== null && $path !== '') {
            return new Port($path);
        }

        $ports = $driver->ports();
        if ($ports === []) {
            throw new PhpbmException('no serial port found: connect the board or pass --port');
        }
        if (count($ports) === 1) {
            return $ports[0];
        }

        if (!$interactive) {
            throw new PhpbmException('several serial ports found (' . implode(', ', array_map(static fn (Port $p) => $p->path, $ports)) . '): pass --port');
        }

        $options = [];
        foreach ($ports as $port) {
            $options[$port->path] = $port->path . ($port->description === null ? '' : "  ({$port->description})");
        }
        $choose = $this->choose ?? static fn (array $options): string => (string) select('Serial port', $options);
        $chosen = $choose($options);

        foreach ($ports as $port) {
            if ($port->path === $chosen) {
                return $port;
            }
        }
        throw new PhpbmException("unknown port {$chosen}");
    }
}
