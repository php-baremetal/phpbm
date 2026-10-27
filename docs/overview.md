---
eyebrow: 'Tool · CLI'
lede:    'The command-line tool that builds, flashes and monitors PHP firmware. It runs on your computer and drives an SDK, which knows the chip; Composer and the conductor plugin pick the packages.'
see_also:
  - { href: './guide/getting-started.md', meta: 'Guide' }
  - { href: './commands/project.md', meta: 'Commands' }
  - { href: 'https://github.com/php-baremetal/php-esp32-sdk', meta: 'SDK', label: 'php-esp32-sdk' }
  - { href: 'https://repository.php-baremetal.com', meta: 'registry', label: 'php-baremetal package registry' }
prev: { label: 'No previous page', href: '#' }
next: { label: 'Getting started', href: './guide/getting-started.md' }
---

# phpbm

phpbm turns a Composer project into firmware for a microcontroller. It runs on Linux, macOS or Windows, never
on the board. phpbm itself knows nothing about a chip: an **SDK** (today `esp32`, from `php-esp32-sdk`) brings
the boards, the PHP build recipe, the runtimes and the toolchain, and phpbm downloads, verifies and drives it.

## How the pieces fit

| Piece | Role |
| --- | --- |
| Your project | A `composer.json` with `extra.baremetal` (SDK, board, runtime, storage) and the PHP sources in `project-src/`. |
| Composer + conductor | `composer require` picks the packages (extensions, drivers, PHP extensions). The `php-baremetal/conductor` plugin installs them into `.phpbm/packages/`, adds the HAL for the SDK's platform and writes `.phpbm/manifest.json`. |
| phpbm | Reads the manifest, resolves and locks the SDK (`phpbm.lock`), checks the project against it, then builds, flashes, syncs and monitors. |
| SDK | Describes the boards, chips, runtimes, storages and options, and carries the driver phpbm calls to build and flash. |
| Toolchain | The SDK's compiler and flashing tools (ESP-IDF for `esp32`), installed by `phpbm toolchain:install`. |

The packages come from the [registry](https://repository.php-baremetal.com). phpbm, the conductor and the spec
library they share are on Packagist; SDKs are GitHub releases listed in a release index.

## Requirements

- PHP 8.4 or later with the `fileinfo` extension (`zip` recommended), and Composer 2.
- `git`, used to apply the SDK's patches to the PHP sources.
- Whatever the SDK's toolchain needs. For `esp32`: Python 3.10 or later and about 6 GB of disk for ESP-IDF.

<!-- @callout variant="warning" title="Windows paths" -->
ESP-IDF does not work from a path with spaces or non-ASCII characters. Keep your projects, and `PHPBM_HOME` if
you move it, on a path without them.
<!-- @endcallout -->

## Install

phpbm installs with Composer or as a single PHAR file. The conductor must be installed globally either way: it
has to be active while Composer resolves a project for the first time.

<!-- @tabs labels="Composer, PHAR on Linux and macOS, PHAR on Windows" -->
<!-- @tab index="0" -->
<!-- @code-block language="Bash" label="once per machine" -->
```bash
composer global require php-baremetal/phpbm
composer global require php-baremetal/conductor
```
<!-- @endcode-block -->
<!-- @endtab -->
<!-- @tab index="1" -->
<!-- @code-block language="Bash" label="once per machine" -->
```bash
curl -fLO https://github.com/php-baremetal/phpbm/releases/latest/download/phpbm.phar
curl -fLO https://github.com/php-baremetal/phpbm/releases/latest/download/SHA256SUMS
sha256sum -c SHA256SUMS          # macOS: shasum -a 256 -c SHA256SUMS
chmod +x phpbm.phar
sudo mv phpbm.phar /usr/local/bin/phpbm
composer global require php-baremetal/conductor
```
<!-- @endcode-block -->
<!-- @endtab -->
<!-- @tab index="2" -->
<!-- @code-block language="PowerShell" label="once per machine" -->
```text
$base = 'https://github.com/php-baremetal/phpbm/releases/latest/download'
Invoke-WebRequest "$base/phpbm.phar" -OutFile phpbm.phar
Invoke-WebRequest "$base/SHA256SUMS" -OutFile SHA256SUMS
(Get-FileHash phpbm.phar -Algorithm SHA256).Hash.ToLower()   # must match SHA256SUMS
composer global require php-baremetal/conductor
```
<!-- @endcode-block -->

Move `phpbm.phar` to a directory on your `PATH` and put a `phpbm.bat` next to it containing
`@php "%~dp0phpbm.phar" %*`.
<!-- @endtab -->
<!-- @endtabs -->

- **Composer**: Composer's global `vendor/bin` must be on your `PATH`
  (`composer global config bin-dir --absolute` prints it).
- **PHAR**: needs the same PHP, with the `phar` and `zlib` extensions (enabled in most PHP builds). Each
  [release](https://github.com/php-baremetal/phpbm/releases) has `phpbm.phar` and `SHA256SUMS`; replace
  `latest/download` with `download/v1.0.0` to pin a version, and download a new PHAR over the old one to update.

## Where next

- [Getting started](./guide/getting-started.md): from an empty machine to a running board.
- [The project](./guide/project.md): every `extra.baremetal` key.
- [Project commands](./commands/project.md) and [SDK commands](./commands/sdk.md): every option.
