<?php

declare(strict_types=1);

namespace App\Commands;

use App\Board\BoardCatalog;
use App\Board\BoardLabel;
use App\PhpbmException;
use App\Sdk\DriverFactory;
use App\Sdk\SdkStore;
use App\Support\FlashSize;
use App\Support\Paths;
use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Sdk\Port;
use PhpBaremetal\Spec\Sdk\SdkLoader;

final class DiscoverCommand extends PhpbmCommand
{
    protected $signature = 'discover {--p|port= : Probe only this port}';

    protected $description = 'Identify the connected boards';

    protected function perform(): int
    {
        $store = app(SdkStore::class);
        $factory = app(DriverFactory::class);

        $drivers = [];
        $packages = $this->projectBoardPackages();
        foreach ($store->names() as $name) {
            $sdk = $store->current($name);
            if ($sdk === null) {
                continue;
            }
            if ($packages !== []) {
                try {
                    $sdk = (new SdkLoader())->withBoardPackages($sdk, $packages, Paths::cwd());
                } catch (\Throwable $e) {
                    $this->warn("  {$name}: {$e->getMessage()}");
                }
            }
            try {
                $drivers[] = [$sdk, $factory->for($sdk), BoardCatalog::of($sdk, $packages, Paths::cwd())];
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

    /**
     * The bm-boards packages of the project in the current folder, when there is one with a manifest.
     *
     * @return list<\PhpBaremetal\Spec\Manifest\Package>
     */
    private function projectBoardPackages(): array
    {
        $file = Paths::cwd() . '/' . Manifest::PATH;
        if (!is_file($file)) {
            return [];
        }
        try {
            return Manifest::fromFile($file)->ofKind('boards');
        } catch (\Throwable $e) {
            $this->warn("  {$file}: {$e->getMessage()}");
            return [];
        }
    }

    /** @param list<array{0: \PhpBaremetal\Spec\Sdk\Sdk, 1: \PhpBaremetal\Spec\Sdk\Driver, 2: BoardCatalog}> $drivers */
    private function describe(Port $port, array $drivers): void
    {
        foreach ($drivers as [$sdk, $driver, $catalog]) {
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
            $fitsAny = false;
            foreach ($boards as $board) {
                $fits = FlashSize::fits($board->flash, $chip->flashSize);
                $source = $board->fromSdk() ? '' : " · {$board->source}";
                $soon = $catalog->comingSoon($board);
                $this->line("  board   {$board->id}  {$board->name}{$source}" . ($soon ? ' (coming soon)' : '') . ($fits ? '' : "  (needs {$board->flash} of flash)"));
                $fitsAny = $fitsAny || $fits;
                // A coming-soon board is listed but never suggested.
                if ($fits && !$soon) {
                    $fitting[] = BoardLabel::key($sdk, $board);
                }
            }
            if ($boards !== [] && !$fitsAny) {
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
