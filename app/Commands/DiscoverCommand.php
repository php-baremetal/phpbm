<?php

declare(strict_types=1);

namespace App\Commands;

use App\PhpbmException;
use App\Sdk\DriverFactory;
use App\Sdk\SdkStore;
use App\Support\FlashSize;
use PhpBaremetal\Spec\Sdk\Port;

final class DiscoverCommand extends PhpbmCommand
{
    protected $signature = 'discover {--p|port= : Probe only this port}';

    protected $description = 'Identify the connected boards';

    protected function perform(): int
    {
        $store = app(SdkStore::class);
        $factory = app(DriverFactory::class);

        $drivers = [];
        foreach ($store->names() as $name) {
            $sdk = $store->current($name);
            if ($sdk === null) {
                continue;
            }
            try {
                $drivers[] = [$sdk, $factory->for($sdk)];
            } catch (\Throwable $e) {
                $this->warn("  {$name}: {$e->getMessage()}");
            }
        }
        if ($drivers === []) {
            throw new PhpbmException('no SDK installed: run `phpbm sdk:install <name>`');
        }

        $ports = [];
        if ($this->option('port')) {
            $ports[] = new Port((string) $this->option('port'));
        } else {
            foreach ($drivers as [$sdk, $driver]) {
                try {
                    foreach ($driver->ports() as $port) {
                        $ports[$port->path] ??= $port;
                    }
                } catch (\Throwable $e) {
                    $this->warn("  {$sdk->descriptor->name}: {$e->getMessage()}");
                }
            }
        }
        if ($ports === []) {
            throw new PhpbmException('no serial port found: connect the board or pass --port');
        }

        foreach ($ports as $port) {
            $this->line("<info>{$port->path}</info>" . ($port->description === null ? '' : "  {$port->description}"));
            $this->describe($port, $drivers);
        }

        return self::SUCCESS;
    }

    /** @param list<array{0: \PhpBaremetal\Spec\Sdk\Sdk, 1: \PhpBaremetal\Spec\Sdk\Driver}> $drivers */
    private function describe(Port $port, array $drivers): void
    {
        foreach ($drivers as [$sdk, $driver]) {
            try {
                $chip = $driver->identify($port);
            } catch (\Throwable $e) {
                $this->warn("  {$sdk->descriptor->name}: {$e->getMessage()}");
                continue;
            }
            if ($chip === null) {
                continue;
            }
            $this->line("  chip    {$chip->name}" . ($chip->revision === null ? '' : " (revision {$chip->revision})"));
            foreach (['flash' => $chip->flashSize, 'mac' => $chip->mac] as $label => $value) {
                if ($value !== null) {
                    $this->line(sprintf('  %-7s %s', $label, $value));
                }
            }
            if ($chip->features !== []) {
                $this->line('  radio   ' . implode(', ', $chip->features));
            }
            $this->line("  sdk     {$sdk->descriptor->name} {$sdk->descriptor->version}");

            $boards = array_filter($sdk->boards, static fn ($board) => ($sdk->chips[$board->chip] ?? null)?->target === $chip->target);
            if ($boards === []) {
                $this->line('  board   none for this chip in the sdk');
            }
            $fitting = [];
            foreach ($boards as $board) {
                $fits = FlashSize::fits($board->flash, $chip->flashSize);
                $this->line("  board   {$board->id}  {$board->name}" . ($fits ? '' : "  (needs {$board->flash} of flash)"));
                if ($fits) {
                    $fitting[] = $board->id;
                }
            }
            if ($boards !== [] && $fitting === []) {
                $this->line("  board   none of these fits the chip's {$chip->flashSize} of flash");
            } elseif (count($fitting) === 1) {
                $this->line("  use     \"board\": \"{$fitting[0]}\" in extra.baremetal");
            } elseif ($fitting !== []) {
                $this->line('  use     "board": "<id>" in extra.baremetal, with one of the boards above');
            }
            return;
        }
        $this->line('  no installed sdk recognises this chip');
    }
}
