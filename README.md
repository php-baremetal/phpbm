# phpbm

`php-baremetal/phpbm` is the command-line tool of php-baremetal: it builds, flashes and monitors PHP firmware for
microcontrollers. It runs on your computer (Linux, macOS or Windows), never on the board. It knows nothing about a
particular chip: everything platform-specific comes from an SDK, such as the ESP32 one
([`php-esp32-sdk`](https://github.com/php-baremetal/php-esp32-sdk)), which phpbm downloads, verifies and drives.

## Requirements

- PHP 8.4 or later, with the `fileinfo` extension (on Windows enable it in `php.ini`; `zip` is recommended too).
- Composer 2.
- `git`, used to apply the SDK's patches to the PHP sources.
- The SDK's toolchain, which phpbm installs for you (`phpbm toolchain:install`; for ESP32 that is ESP-IDF, about
  6 GB in `~/.phpbm/toolchains/esp32`; it needs Python 3.10 or later). On Windows ESP-IDF cannot work from a
  path with spaces or non-ASCII characters: keep the project, and `PHPBM_HOME` if you move it, on a path
  without them.

## Install

phpbm installs with Composer or as a single PHAR file. Either way, the Composer plugin
[`php-baremetal/conductor`](https://packagist.org/packages/php-baremetal/conductor) must be installed globally:
it has to be active while Composer resolves a project's packages.

### With Composer

```bash
composer global require php-baremetal/phpbm
composer global require php-baremetal/conductor
```

Composer's global `vendor/bin` directory must be on your `PATH` (`composer global config bin-dir --absolute`
prints it).

### As a PHAR

Each [release](https://github.com/php-baremetal/phpbm/releases) has `phpbm.phar` and a `SHA256SUMS` file. The
PHAR needs the same PHP as above, with the `phar` and `zlib` extensions (both enabled in most PHP builds).

Linux and macOS:

```bash
curl -fLO https://github.com/php-baremetal/phpbm/releases/latest/download/phpbm.phar
curl -fLO https://github.com/php-baremetal/phpbm/releases/latest/download/SHA256SUMS
sha256sum -c SHA256SUMS          # macOS: shasum -a 256 -c SHA256SUMS
chmod +x phpbm.phar
sudo mv phpbm.phar /usr/local/bin/phpbm
composer global require php-baremetal/conductor
```

Windows (PowerShell):

```powershell
$base = 'https://github.com/php-baremetal/phpbm/releases/latest/download'
Invoke-WebRequest "$base/phpbm.phar" -OutFile phpbm.phar
Invoke-WebRequest "$base/SHA256SUMS" -OutFile SHA256SUMS
(Get-FileHash phpbm.phar -Algorithm SHA256).Hash.ToLower()   # must match the hash in SHA256SUMS
composer global require php-baremetal/conductor
```

Then move `phpbm.phar` to a directory on your `PATH`, next to a `phpbm.bat` that runs it:

```bat
@php "%~dp0phpbm.phar" %*
```

To pin a version, replace `latest/download` with `download/v1.0.0`. To update, download the new PHAR over the
old one.

## Quick start

```bash
phpbm sdk:install esp32
mkdir blink && cd blink
phpbm init                                  # composer.json, project-src/index.php, .gitignore
composer require php-baremetal/ext-gpio     # the conductor installs the packages and writes .phpbm/manifest.json
phpbm toolchain:install                     # once per machine and SDK version
phpbm flash                                 # builds if needed, then flashes the connected board
phpbm monitor
```

`phpbm init` asks for the SDK, the board, the runtime and the storage (or takes them from `--sdk`, `--board`,
`--runtime`, `--storage`) and writes a `composer.json` like this one:

```json
{
  "name": "local/blink",
  "type": "project",
  "repositories": [
    { "type": "composer", "url": "https://repository.php-baremetal.com", "canonical": true }
  ],
  "require": {
    "php-baremetal/conductor": "^1.0"
  },
  "config": {
    "allow-plugins": { "php-baremetal/conductor": true },
    "platform": { "php": "8.5.11" }
  },
  "extra": {
    "baremetal": {
      "sdk": "esp32@^1.0",
      "board": "waveshare-esp32-s3-touch-lcd-1.85b",
      "runtime": "init-loop",
      "storage": "embedded"
    }
  }
}
```

The firmware's PHP version is `config.platform.php`; its features are the packages you `composer require`
(extensions, drivers and `php-baremetal/php-<name>` PHP extensions); `extra.baremetal.options` sets the SDK and
package options. The [registry](https://repository.php-baremetal.com) lists the packages and explains the model.

The [documentation](docs/overview.md) covers the project keys, SDKs and the lock, toolchains, every command and
the common errors.

## Commands

| Command | What it does |
| --- | --- |
| `init [dir]` | Create a new firmware project. |
| `build [--clean]` | Build the firmware of the project in the current directory. |
| `flash [-p <port>] [--force]` | Build if needed, check the connected chip and flash it; with microSD storage, also copy the sources to the card. |
| `sync [-p <port>]` | Copy the project sources to the board's microSD without building or flashing. |
| `monitor [-p <port>]` | Open the board's serial console. |
| `discover [-p <port>]` | List the serial ports, the chip on each one and the boards that match it. |
| `sdk:install <name>[@<constraint>]` | Install an SDK from its release index. |
| `sdk:list` | List the installed and linked SDKs. |
| `sdk:update` | Move the project to the newest SDK version its constraint allows. |
| `sdk:remove <name>@<version>` | Remove an installed SDK version, or a link with `<name>@dev`. |
| `sdk:link <name> <dir>` | Use a local SDK directory instead of a release (SDK development). |
| `toolchain:install` | Install the toolchain of the project's SDK. |
| `cache:clear` | Delete the downloaded archives and the prepared source trees. |

`phpbm help <command>` shows every option.

## `phpbm.lock`

The first command that needs the SDK writes `phpbm.lock` with the SDK version and its sha256; commit it. Later
builds use exactly that version, and a different sha256 for the same version stops the build. The SDK only
changes with `phpbm sdk:update`, which shows the old and the new version. A linked SDK (`sdk:link`) is never
locked.

## Files and environment

phpbm keeps everything under `~/.phpbm`: installed SDKs, toolchains, downloads and prepared source trees. A
project's own build files are in its `.phpbm/` directory, which `phpbm init` adds to `.gitignore`.

| Variable | Meaning |
| --- | --- |
| `PHPBM_HOME` | Use this directory instead of `~/.phpbm`. |
| `PHPBM_SDK_INDEX` | Where SDK release indexes are read from, with `{name}` for the SDK name. Default `https://github.com/php-baremetal/php-{name}-sdk/releases/latest/download/index.json`; a local path or a `file://` URL also works. |
| `PHPBM_REPOSITORY` | The package registry `phpbm init` writes into new projects. Default `https://repository.php-baremetal.com`. |

## License

MIT, see [LICENSE](LICENSE).
