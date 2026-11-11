---
eyebrow: 'phpbm · Reference'
lede:    'The errors you are most likely to meet, what they mean and the command that fixes them.'
see_also:
  - { href: '../guide/project.md', meta: 'Guide' }
  - { href: '../commands/project.md', meta: 'Commands' }
prev: { label: 'Files and environment', href: './files-and-environment.md' }
next: { label: 'No next page', href: '#' }
---

# Troubleshooting

phpbm prints one line per error. For an unexpected failure it adds `(run with -vv for details)`: run the same
command with `-vv` to get the full trace.

## Project

| Message | Fix |
| --- | --- |
| `… is not a phpbm project (no composer.json)` | Run the command in the project directory, or create one with `phpbm init`. |
| `no .phpbm/manifest.json: run composer install` | The conductor has not run yet: `composer install`. If it already ran, `composer conductor:generate` shows why the manifest was not written. |
| `the php-baremetal conductor 1 is not installed globally` | `composer global require php-baremetal/conductor:^1.0`, then `composer install`. |
| `the conductor plugin is not allowed globally` | `composer global config allow-plugins.php-baremetal/conductor true`. |
| `.phpbm/manifest.json is older than composer.json` | `composer install`; if you changed only `extra.baremetal`, `composer update --lock`. |
| `runtime '…' needs php-baremetal/…: run composer require …` | The runtime needs a package (`event-driven` needs `ext-events`): run the `composer require` shown. |
| `… needs network, but board '…' has none` | The runtime or storage needs a capability the board lacks: pick another runtime, storage or board. |
| `unknown option '…' in extra.baremetal.options (accepted: …)` | A typo or an option of another runtime: use one of the names listed. |
| `entry script project-src/index.php does not exist` | Create it, or set `extra.baremetal.src` / `entry`. |
| `… requires the PHP extension '…', which sdk … does not provide for PHP …` | That PHP extension is not available for this PHP minor in this SDK: change `config.platform.php` or drop the package. |

## SDK and toolchain

| Message | Fix |
| --- | --- |
| `cannot read the sdk index …` | Check the network, or point `PHPBM_SDK_INDEX` at a reachable index. |
| `no … sdk release satisfies …` | No release matches `extra.baremetal.sdk`: loosen the constraint. |
| `phpbm.lock uses … which does not satisfy …: run phpbm sdk:update` | You changed the constraint: `phpbm sdk:update`. |
| `sdk … installed here differs from the locked one` | The local copy does not match the lock's sha256: `phpbm sdk:remove name@version`, then build again. |
| `the … toolchain is not installed …: run phpbm toolchain:install` | Install it; the message in parentheses says what is missing or which version was found. |
| `ESP-IDF needs Python 3.10 or newer` | Install Python, or set `PHPBM_PYTHON`. |
| `ESP-IDF cannot work from a path with spaces or non-ASCII characters` | Windows: set `PHPBM_HOME` to a path without them. |

## Board

| Message | Fix |
| --- | --- |
| `no serial port found: connect the board or pass --port` | Check the cable (some are charge-only) and the driver; pass `-p` if the port has an unusual name. |
| `several serial ports found (…): pass --port` | Non-interactive run with more than one port: pass `-p`. |
| `board '…' uses esp32s3, but the chip on … is esp32p4` | Wrong board in `extra.baremetal.board`, or wrong port. `phpbm discover` shows what is connected. |
| `board '…' needs 8MB of flash, but the chip on … has 4MB` | The board in `extra.baremetal.board` has more flash than the chip: pick the variant with the chip's flash (`phpbm discover` shows it). |
| `no esp32 chip answered on …` | Hold BOOT while pressing RESET to enter download mode, then flash again. `--force` flashes without the check. |
| `the firmware was flashed …, but copying the sources (or the microSD assets) to the card failed` | Check the card, then `phpbm sync`. |
