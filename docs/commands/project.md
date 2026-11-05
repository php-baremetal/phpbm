---
eyebrow: 'phpbm · Commands'
lede:    'The commands that work on a project: create it, build it, put it on the board and talk to it. All but init and discover run in the project directory.'
see_also:
  - { href: './sdk.md', meta: 'Commands' }
  - { href: '../guide/project.md', meta: 'Guide' }
prev: { label: 'Toolchains', href: '../guide/toolchains.md' }
next: { label: 'SDK and maintenance commands', href: './sdk.md' }
---

# Project commands

Every command accepts the usual console options (`-n` for no questions, `-v` / `-vv` for more output).
`phpbm help <command>` shows the options of one command. With `-n` phpbm never asks: `init` takes the first
choice for anything you did not pass, and a choice between several serial ports becomes an error asking for
`--port`.

## init

<!-- @method name="phpbm init [dir] [--name=] [--sdk=] [--board=] [--runtime=] [--storage=]" -->

Creates a project in `dir` (default: the current directory), which must not already hold a `composer.json`.

<!-- @params heading="Arguments and options" -->
<!-- @param name="dir" type="path" -->
Directory of the new project; created if missing.
<!-- @endparam -->
<!-- @param name="--name" type="string" -->
Project name, default the directory name. Lowercase letters, digits, `.`, `_`, `-`; written as `local/<name>`.
<!-- @endparam -->
<!-- @param name="--sdk" type="string" -->
An installed SDK. Asked when several are installed.
<!-- @endparam -->
<!-- @param name="--board" type="string" -->
A board id of the SDK. Asked when the SDK has several.
<!-- @endparam -->
<!-- @param name="--runtime" type="string" -->
A runtime the board can run; an explicit one it cannot run is an error.
<!-- @endparam -->
<!-- @param name="--storage" type="string" -->
A storage the board has (`microsd` needs a card slot).
<!-- @endparam -->
<!-- @endparams -->

It writes `composer.json` (registry, conductor, `config.platform.php` set to the newest PHP the SDK builds,
`extra.baremetal`, the SDK constraint as `^major.minor` of the installed version), `project-src/index.php`
when missing, and adds `/vendor/` and `/.phpbm/` to `.gitignore`. Then it prints the next commands and warns
when the conductor is not installed or allowed globally.

<!-- @code-block language="Bash" label="non-interactive" -->
```bash
phpbm init sensor -n --board=waveshare-esp32-s3-eth --runtime=event-driven --storage=embedded
```
<!-- @endcode-block -->

## build

<!-- @method name="phpbm build [--clean] [--merged]" -->

Resolves the SDK (writing `phpbm.lock` the first time), checks the project against it, prepares the patched
PHP sources and builds the firmware into `.phpbm/build`. A second build only rebuilds what changed.
`--clean` rebuilds from scratch: use it after changing the toolchain or when a build looks stale.

A firmware is several images, each written at its own flash offset; on the ESP32 they are the bootloader, the
partition table, the app and the storage. `--merged` also writes all of them into one file,
`.phpbm/build/merged.bin`, with `0xFF` in the gaps. Write that file at offset `0x0`. Use it where only one file
can be loaded, such as an emulator or a web flasher. The app image alone does not boot from offset `0x0`: the
ROM fails with `Invalid image block, can't boot`.

The PHP sources are downloaded and patched once per PHP version and SDK version, then shared by every
project under `~/.phpbm/cache/trees`.

## flash

<!-- @method name="phpbm flash [-p port] [--force]" -->

Builds if needed, reads the chip on the port and flashes. With `microsd` storage it then copies the sources to
the card, like `phpbm sync`.

Before flashing it checks the chip against the board: the chip must be the board's, and its flash must be at
least as large as the board's `flash` (a firmware built for 8MB does not start on a 4MB chip). When the chip
does not report its flash size, that check is skipped.

<!-- @params heading="Options" -->
<!-- @param name="-p, --port" type="string" -->
Serial port, e.g. `/dev/ttyACM0` or `COM5`. Without it phpbm uses the only port it finds, or asks when there
are several.
<!-- @endparam -->
<!-- @param name="--force" type="bool" -->
Flash even when no chip answers, the chip is not the board's (an ESP32-P4 board on an ESP32-S3), or its flash
is smaller than the board's.
<!-- @endparam -->
<!-- @endparams -->

<!-- @callout variant="tip" title="The board does not answer" -->
On ESP32 boards, hold BOOT while you press RESET (or plug the cable in while holding BOOT), release it, and
flash again: the chip then waits in its download mode.
<!-- @endcallout -->

If flashing succeeded but copying to the card failed, phpbm says so: fix the card and run `phpbm sync`, no
need to flash again.

## sync

<!-- @method name="phpbm sync [-p port]" -->

Copies the source directory to the board's microSD card over the serial port, without building or flashing.
For `microsd` storage only; with `embedded` storage it says there is nothing to sync. The env file is never
copied. The firmware must already be on the board.

## monitor

<!-- @method name="phpbm monitor [-p port]" -->

Opens the board's serial console: the script's output, errors, boot messages. On ESP32 it is ESP-IDF's
monitor at 115200 baud; leave it with <kbd>Ctrl</kbd> + <kbd>]</kbd>.

## discover

<!-- @method name="phpbm discover [-p port]" -->

Probes the serial ports with every installed SDK and prints, for each port, the chip (name, revision, flash
size, MAC, radios), the SDK that recognised it and the boards of that SDK built on that chip. A board with more
flash than the chip is marked `(needs 16MB of flash)`. When exactly one board fits, the last line is the
`"board"` entry to put in `extra.baremetal`. Run it to pick the `board` of a new project, or to check which
port a board is on. It works outside a project.

<!-- @code-block language="Text" label="phpbm discover" -->
```text
/dev/ttyACM0  USB JTAG/serial debug unit
  chip    ESP32-S3 (revision v0.2)
  flash   8MB
  mac     xx:xx:xx:xx:xx:xx
  radio   WiFi, BLE
  sdk     esp32 2.0.0
  board   waveshare-esp32-s3-zero-n8r8  Waveshare ESP32-S3-Zero (N8R8)
  board   waveshare-esp32-s3-eth  Waveshare ESP32-S3-ETH  (needs 16MB of flash)
  board   waveshare-esp32-s3-touch-lcd-1.85b  Waveshare ESP32-S3-Touch-LCD-1.85B  (needs 16MB of flash)
  …       one line per ESP32-S3 board of the SDK
  use     "board": "<id>" in extra.baremetal, with one of the boards above
```
<!-- @endcode-block -->
