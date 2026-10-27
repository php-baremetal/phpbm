---
eyebrow: 'phpbm · Guide'
lede:    'From an empty machine to a blinking board: install the tools once, then create, build and flash a project.'
see_also:
  - { href: './project.md', meta: 'Guide' }
  - { href: '../commands/project.md', meta: 'Commands' }
  - { href: '../reference/troubleshooting.md', meta: 'Reference' }
prev: { label: 'Overview', href: '../overview.md' }
next: { label: 'The project', href: './project.md' }
---

# Getting started

## Once per machine

<!-- @steps -->
- **Install phpbm and the conductor**

  `composer global require php-baremetal/phpbm php-baremetal/conductor`. Composer asks to allow the conductor
  plugin: answer yes, or run `composer global config allow-plugins.php-baremetal/conductor true`. To use the
  PHAR instead of `php-baremetal/phpbm`, see the install section of the [overview](../overview.md).

- **Install an SDK**

  `phpbm sdk:install esp32` downloads the newest `esp32` SDK release, checks its sha256 and unpacks it into
  `~/.phpbm/sdks/esp32/<version>`.
<!-- @endsteps -->

## A new project

<!-- @code-block language="Bash" label="blink" -->
```bash
phpbm init blink                     # composer.json, project-src/index.php, .gitignore
cd blink
composer require php-baremetal/ext-gpio php-baremetal/ext-sys
phpbm toolchain:install              # once per SDK version; ESP-IDF for esp32
phpbm flash                          # builds if needed, then flashes the connected board
phpbm monitor                        # serial console
```
<!-- @endcode-block -->

`phpbm init` asks for the SDK, the board, the runtime and the storage, offering only the runtimes and storages
the board can run. It prints the next commands, including a `composer require` for any package the chosen
runtime needs (`event-driven` needs `php-baremetal/ext-events`). `composer require` runs the conductor, which
writes `.phpbm/manifest.json`: every other phpbm command reads it.

The toolchain step needs a project because it installs the toolchain of the project's SDK. It is a one-time
download (about 6 GB for ESP-IDF) shared by every project on the machine.

## Write the program

The firmware runs `project-src/index.php`. With the `init-loop` runtime the script runs once, then `setup()`
and `loop($tick)` repeatedly:

<!-- @code-block language="PHP" label="project-src/index.php" -->
```php
<?php
// LED + ~330 ohm resistor between GPIO2 and GND.

define('LED', 2);

function setup(): void {
    gpio_mode(LED, GPIO_OUTPUT);
}

function loop(int $tick): void {
    gpio_write(LED, $tick % 2);
    delay(500);
}
```
<!-- @endcode-block -->

`gpio_mode()` and `gpio_write()` come from `ext-gpio`, `delay()` from `ext-sys`
(`composer require php-baremetal/ext-sys`). Check the API of each extension on its registry page. After editing, run `phpbm flash` again: phpbm rebuilds
what changed and flashes.

## When something is off

- `phpbm discover` lists the serial ports, the chip on each one and the boards of the SDK that use that chip.
- `phpbm build --clean` rebuilds from scratch.
- Every error names the command that fixes it; [Troubleshooting](../reference/troubleshooting.md) collects
  the common ones.
