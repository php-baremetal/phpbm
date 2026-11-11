---
eyebrow: 'phpbm · Commands'
lede:    'The commands that work on a project: create it, build it, put it on the board and talk to it. All but init, discover, font:bake and image:convert run in the project directory.'
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

<!-- @method name="phpbm init [dir] [--name=] [--sdk=] [--board=] [--runtime=] [--storage=] [--features=] [--no-install]" -->

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
<!-- @param name="--features" type="string" -->
Which of the board's features to install: `all`, `none` or kinds separated by commas (`display,i2c`). Without it
phpbm shows the features as checkboxes, all checked; with `-n` the default is `all`. A kind the board does not
have is an error that lists the kinds the board has; a kind with no package is skipped with a `no package` note. When the package list cannot be read (offline, no cache) phpbm warns `cannot check --features: the list of board packages is not available` and installs no feature. When the registry is read but no package drives the board's blocks, the kinds are checked as usual and each one gets its `no package` note.
<!-- @endparam -->
<!-- @param name="--no-install" type="flag" -->
Does not run Composer: phpbm prints the `composer require` and `composer install` commands instead.
<!-- @endparam -->
<!-- @endparams -->

After the board, phpbm offers its features (display, LED, I2C bus...) as checkboxes, each with the package that
drives it, found in the package registry. A feature with no package is listed as `no package` and cannot be
checked. A package drives a block when its `composer.json` lists it in `extra.baremetal.board-blocks` as
`<kind>:<type>` (`led:ws2812`): the kind is the block name up to the first `-` (`[a-z0-9]+`, so `led-2` is kind
`led`), the type the block's type (`[a-z0-9][a-z0-9-]*`). phpbm prints `reading board packages from <registry>…`
when it reads the registry (HTTP reads time out after 10 seconds); a package whose metadata is missing (HTTP 404) is
skipped with `skipping <package>: no metadata (404)`. The registry answer is cached for an hour in
`~/.phpbm/cache`; offline phpbm uses the cache, or, with none, warns that board features are not offered and goes
on with no feature (once you are online, run `phpbm board --features=all` in the project to add them).

It writes `composer.json` (registry, conductor, `config.platform.php` set to the newest PHP the SDK builds,
`extra.baremetal`, the SDK constraint as `^major.minor` of the installed version), `project-src/index.php`
when missing, and adds `/vendor/` and `/.phpbm/` to `.gitignore`. It warns when the conductor is not installed
or allowed globally.

Then it runs `composer require` once in the project, with the packages of the chosen features and those the
runtime and storage need (`composer install` when there are none). Composer is `PHPBM_COMPOSER` or `composer` on
the `PATH`. If Composer is missing (`composer not found: set PHPBM_COMPOSER, install Composer, or use --no-install`) or
fails (`composer <args> failed (exit <code>)`, followed by Composer's error message when it printed one), the files
stay, phpbm prints the error and the commands to run by hand, and exits with 1. Otherwise it prints `phpbm build` as the next step.

<!-- @code-block language="Bash" label="non-interactive" -->
```bash
phpbm init sensor -n --board=waveshare-esp32-s3-eth --runtime=event-driven --storage=embedded
```
<!-- @endcode-block -->

## board

<!-- @method name="phpbm board [board] [--change] [--features=…] [--no-install] [--refresh]" -->

Adds or removes the features of the project's board, or changes the board and offers the new board's features.
It runs in the project directory and reads `composer.json` directly, so it also works right after
`phpbm init --no-install`. The SDK is resolved as the build does (`phpbm.lock`, or the `sdk` constraint).

<!-- @params heading="Arguments and options" -->
<!-- @param name="board" type="string" -->
The new board id. Without it, or with the current board, phpbm works on the features of the current board.
<!-- @endparam -->
<!-- @param name="--change" type="flag" -->
Choose the new board from the list of the SDK's boards (needs a terminal).
<!-- @endparam -->
<!-- @param name="--features" type="string" -->
The features to have after the command: `all`, `none` or kinds separated by commas (`display,i2c`). It replaces
the checkboxes. A kind the board does not have is an error that lists the kinds the board has.
<!-- @endparam -->
<!-- @param name="--no-install" type="flag" -->
Does not run Composer: phpbm changes `extra.baremetal.board` (and drops the overrides, the whole key when none is
left) in `composer.json` itself, keeping every other key, and prints the `composer remove` and `composer require` commands to run.
<!-- @endparam -->
<!-- @param name="--refresh" type="flag" -->
Reads the list of board packages from the registry again instead of the one-hour cache.
<!-- @endparam -->
<!-- @endparams -->

Without `board`, phpbm shows the board's features as checkboxes, like `init`. A feature is checked when one of
its packages is in the direct `require` of `composer.json`. Unchecking a feature runs `composer remove` of its
installed package, when no other checked feature uses it; checking one runs `composer require` of its package.
Packages that are not features of the board are never touched. With `-n` and no `--features` the installed
features stay as they are. When nothing changes, phpbm prints `nothing to change` and does not run Composer.

<!-- @code-block language="Bash" label="the features of the current board" -->
```bash
phpbm board                     # check or uncheck the features
phpbm board -n --features=led   # keep only the LED: remove the other feature packages, require the LED's
```
<!-- @endcode-block -->

With `board` (or `--change`), phpbm first checks that the board exists in the project's SDK and that the
project's runtime and storage fit it, before it changes anything. The new board is taken with the overrides in
`extra.baremetal.board-overrides` that stay on it: an override that adds a block (it gives the block's `type`)
stays on any board that does not have that block, so a microSD slot added on the old board still counts for
`"storage": "microsd"`. Then it offers the features of the new board, all checked (a block added by an override
included), and lists the installed feature packages the new board does not use, checked for removal. The overrides
the new board cannot take are listed too: those without a `type` for a block the new board does not have, and
those whose `type` differs from the new board's block. phpbm asks to remove them, because the build would fail with
them (with `-n` it removes them). Then it runs, in this order:

1. `composer config extra.baremetal.board <id>`;
2. when overrides are removed, `composer config --json extra.baremetal.board-overrides '<the overrides left>'`, or
   `composer config --unset extra.baremetal.board-overrides` when none is left (Composer's `config` cannot reach
   one block inside `board-overrides`);
3. `composer remove …` of the packages to remove;
4. `composer require …` of the packages to add.

It ends with `board: <id>`. When no package changed, it also prints `composer update --lock`, which refreshes the
lock hash and the manifest after the change of `extra`. The project's PHP code does not change: an `onboard()`
of a block the new board does not have fails.

<!-- @code-block language="Bash" label="change the board" -->
```bash
phpbm board waveshare-esp32-s3-touch-lcd-1.85b   # or: phpbm board --change
```
<!-- @endcode-block -->

Composer is `PHPBM_COMPOSER` or `composer` on the `PATH`, run in the project directory; without it, phpbm asks for
Composer or `--no-install`.

| Message | When |
| --- | --- |
| `no composer.json in <dir>: run phpbm init` | outside a project |
| `board '<id>' does not exist in sdk <name> <version>` | an unknown board |
| `storage '<s>' needs <capability>, but board '<id>' has none` (and the same for the runtime) | a board the runtime or the storage does not fit; `composer.json` is not changed |
| `composer <args> failed (exit <code>)[: <Composer's message>]; done so far: <steps>` | a Composer step fails; the steps already done stay, exit code 1 |
| `composer not found: set PHPBM_COMPOSER, install Composer, or use --no-install` | Composer is missing |
| `the build fails with overrides of blocks board '<id>' does not have (<blocks>): …` | you answered no to removing the overrides; nothing is changed |
| `--change asks for the board: pass the board id instead (phpbm board <id>)` | `--change` without a terminal (`-n`) |
| `the list of board packages is not available: …` | without a board, when the registry cannot be reached and there is no cache (a registry that is read but has no board packages gives `nothing to change` instead) |

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
the card, and with `microsd` assets the converted images, like `phpbm sync`.

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

Copies files to the board's microSD card over the serial port, without building or flashing: the source
directory when the code storage is `microsd`, and the converted images of `microsd` assets (see
[assets](../guide/project.md#assets)) whatever the storage of the code. With `embedded` storage and no `microsd`
assets it says there is nothing to sync. The env file is never copied. The firmware must already be on the board.
`embedded` assets change only with `phpbm flash`, and sync says when they changed since the last build.

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

## font:bake

<!-- @method name="phpbm font:bake <source> --name= [--size=] [--bpp=1|2|4] [--chars=|--range=] [--out=]" -->

Turns a font file into a C header that `php-baremetal/ext-display` draws with: the Adafruit GFX format, extended
with several codepoint ranges and 2 or 4 bits per pixel for anti-aliasing. It writes `<name>.h` and prints the
glyph count and the bitmap size. The same inputs always give the same bytes. It works outside a project too.

Supported sources:

- TTF with TrueType outlines (quadratic curves), rasterized at `--size` pixels without hinting;
- BDF bitmap fonts, read as they are: `--size` is ignored, and at 2 or 4 bpp a lit pixel is fully lit.

An OpenType font with CFF outlines (`.otf`, usually) stops with
`font '<name>': CFF outlines (OTF) are not supported, use a TTF or BDF`; convert it to TrueType outlines first.

<!-- @params heading="Arguments and options" -->
<!-- @param name="source" type="path" -->
The TTF or BDF file.
<!-- @endparam -->
<!-- @param name="--name" type="string" -->
Required. Lowercase letters, digits and `-`. It names the file and the font in `Font::get()`; the C symbol is
`font_` followed by the name with `-` turned into `_`.
<!-- @endparam -->
<!-- @param name="--size" type="int" -->
Size in pixels (the em square). Required for a TTF, ignored for a BDF.
<!-- @endparam -->
<!-- @param name="--bpp" type="int" -->
Bits per pixel: `1` (default), `2` or `4`.
<!-- @endparam -->
<!-- @param name="--chars" type="string" -->
The characters to include, as UTF-8 text taken literally (`0-9` is three characters). A character the source
does not have is skipped, with a warning.
<!-- @endparam -->
<!-- @param name="--range" type="string" -->
Codepoint ranges instead of `--chars`, separated by commas: `0x20-0x7E,0xA0-0xFF`. Without either option the
font holds U+0020 to U+007E.
<!-- @endparam -->
<!-- @param name="--out" type="path" -->
Output folder, relative to the current directory. Default: `extra.baremetal.fonts.dir` of the project in the
current directory, or `project-fonts`.
<!-- @endparam -->
<!-- @endparams -->

<!-- @code-block language="Bash" label="a 2 bpp title font" -->
```bash
phpbm font:bake fonts-src/Roboto.ttf --name=title --size=28 --bpp=2 --chars="Home °C%"
```
<!-- @endcode-block -->

The bitmap of one font must stay within 64 KB (GFX glyph offsets are 16-bit): a larger one stops with
`font '<name>' is larger than 64 KB: use fewer characters or a smaller size`.

## image:convert

<!-- @method name="phpbm image:convert <input> <output>" -->

Turns a PNG into a `.sli` image, the format Slate (`php-baremetal/ext-slate`) draws without decoding: rows of
spans (transparent, one repeated color, different colors, semi-transparent) in the RGB565 colors of the screen,
with a table of rows so a band of the screen reads only its rows. Use it for images you put on the board by hand,
for example on the microSD. It works outside a project too, writes `<output>` (creating its folder, replacing an
existing file) and prints `<output> (<width>x<height>, <bytes> bytes)`.

Every PNG is accepted: gray, RGB, palette (with or without transparency), gray with alpha and RGBA, at 1 to 16
bits per channel (16-bit channels keep their high 8 bits), interlaced or not. Width and height must be 1..32767.
Colors are reduced to RGB565 with Slate's 4×4 ordered dither, anchored at the top-left corner of the image, so
gradients and photos show no bands. The same PNG always gives the same bytes, and the board, when it decodes a PNG
itself, follows the same rule.

<!-- @params heading="Arguments" -->
<!-- @param name="input" type="path" -->
The PNG file.
<!-- @endparam -->
<!-- @param name="output" type="path" -->
The `.sli` file to write.
<!-- @endparam -->
<!-- @endparams -->

<!-- @code-block language="Bash" label="an image for the microSD" -->
```bash
phpbm image:convert art/beans.png sdcard/assets/photos/beans.sli
```
<!-- @endcode-block -->

A file that cannot be read stops with the path and the reason, and exit code 1: `<input>: file not found`,
`<input>: not a file`, `<input>: not a PNG file (bad signature)`, `<input>: bad CRC in chunk IDAT`,
`<input>: size 40000x1 is out of 1..32767` and so on.

The conversion holds the whole image in memory, about 13 bytes per pixel. Before decoding, phpbm checks that
PHP's `memory_limit` leaves enough and otherwise stops with `size <w>x<h> is too large to convert: it needs about
<n> MB and PHP can use <m> MB more (memory_limit 128M); raise it (php -d memory_limit=<k>M) or use a smaller image`.
With the usual 128M an image of about 2300×2300 converts in the worst case (larger ones when they compress well); a 1024×600 background needs about 9 MB.
Image data that inflates to more than the header's size stops with `image data is too long` as soon as it does.
