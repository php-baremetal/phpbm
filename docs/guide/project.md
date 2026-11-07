---
eyebrow: 'phpbm · Guide'
lede:    'A phpbm project is a Composer project: the packages are its features, extra.baremetal says what to build it for.'
see_also:
  - { href: './sdks.md', meta: 'Guide' }
  - { href: '../commands/project.md', meta: 'Commands' }
prev: { label: 'Getting started', href: './getting-started.md' }
next: { label: 'SDKs and phpbm.lock', href: './sdks.md' }
---

# The project

## composer.json

<!-- @code-block language="JSON" label="composer.json" -->
```json
{
  "name": "local/blink",
  "type": "project",
  "repositories": [
    { "type": "composer", "url": "https://repository.php-baremetal.com", "canonical": true }
  ],
  "require": {
    "php-baremetal/conductor": "^1.0",
    "php-baremetal/ext-gpio": "^1.0"
  },
  "config": {
    "allow-plugins": { "php-baremetal/conductor": true },
    "platform": { "php": "8.5.11" }
  },
  "extra": {
    "baremetal": {
      "sdk": "esp32@^2.0",
      "board": "waveshare-esp32-s3-touch-lcd-1.85b",
      "runtime": "init-loop",
      "storage": "embedded"
    }
  }
}
```
<!-- @endcode-block -->

- **`repositories`**: the registry of the `bm-*` packages, marked `canonical` so Composer never takes a
  `php-baremetal/*` package from anywhere else.
- **`require`**: the conductor (the plugin must also be installed globally) and the packages: extensions,
  drivers, `php-baremetal/php-<name>` PHP extensions. Never a HAL: the conductor adds the one for the SDK's
  platform.
- **`config.platform.php`**: the PHP version of the firmware. Its minor (`8.5`) must be one the SDK builds;
  `phpbm init` writes the newest.

## extra.baremetal

| Key | Required | Meaning |
| --- | --- | --- |
| `sdk` | yes | SDK name and version constraint, e.g. `esp32@^2.0`. |
| `board` | yes | A board id of the SDK. `phpbm discover` lists the ones that match the connected chip. |
| `runtime` | yes | How the script runs. `esp32`: `init-loop`, `event-driven`, `web-server`. |
| `storage` | yes | Where the PHP sources live. `esp32`: `embedded` (in flash, read-only), `microsd`. |
| `src` | no | Source directory, default `project-src`. |
| `entry` | no | Entry script inside `src`, default `index.php`. |
| `env` | no | A `.env` file whose values the firmware exposes as `$_ENV` and `getenv()`. |
| `options` | no | SDK, runtime, storage, PHP-extension and installed-package options, e.g. `{ "power-save": "on" }`. |
| `board-overrides` | no | Changes to the board's peripheral blocks, e.g. other pins for a block. The block's `type` cannot change. |

Installed native packages may declare options of their own (in their `extra.baremetal.options`); they follow the same
rules as the SDK's, and phpbm names the package in its messages.

phpbm checks all of them against the SDK before it builds: an unknown option lists the accepted ones, a
runtime the board cannot run names the missing capability, a missing package names the `composer require`.

To change the board, or to add or remove the board's features (display, LED, I2C bus...), run `phpbm board`:
it checks that the runtime and the storage fit the new board, offers the new board's features, drops the
`board-overrides` of blocks the new board does not have and runs Composer. See
[phpbm board](../commands/project.md#board).

<!-- @callout variant="note" title="After changing extra" -->
`extra` is part of the hash in `composer.lock`. After changing only `extra.baremetal` (the board, an option),
run `composer update --lock`: it refreshes the hash and the manifest without updating packages.
<!-- @endcallout -->

## fonts

Text on a display needs fonts. phpbm collects them from four places and hands them to the SDK; each font is
known by its name.

| Source | How | Name |
| --- | --- | --- |
| Default font | shipped by `php-baremetal/ext-display` (DejaVu Sans Mono 16 px, U+0020 to U+00FF, 1 bpp) | `default` |
| Font packages | a package whose `extra.baremetal.fonts` is `{"<name>": "<path of the .h>"}` | the key |
| Project folder | the `.h` files in `extra.baremetal.fonts.dir` (default `project-fonts`) | the file name without `.h` |
| Bake list | `extra.baremetal.fonts.bake`, rasterized at build time | the entry's `name` |

<!-- @code-block language="JSON" label="composer.json" -->
```json
"fonts": {
  "default": true,
  "dir": "project-fonts",
  "bake": [
    { "name": "title", "source": "fonts-src/Roboto.ttf", "size": 28, "bpp": 2, "chars": "Home °C%" },
    { "name": "icons", "source": "fonts-src/weather.bdf" }
  ]
}
```
<!-- @endcode-block -->

- `default` (bool, default `true`): with `false` the default font is left out of the firmware, and `text()` or
  `textWidth()` without `font:` throw.
- `dir` (default `project-fonts`): a path relative to the project. A folder that does not exist counts as empty.
- A `bake` entry: `name` (required, `[a-z0-9][a-z0-9-]*`), `source` (required, relative to the project, a `.ttf`
  or `.bdf`), `size` (pixels, required for a TTF, ignored for a BDF), `bpp` (1, 2 or 4; default 1), `chars` (a
  UTF-8 string) or `range` (`"0x20-0x7E"`, several ranges separated by commas). With neither, U+0020 to U+007E.

Baked fonts are written to `.phpbm/build/fonts/<name>.h`. They are cached in `~/.phpbm/cache/fonts`, keyed by
the source file, the entry's parameters and the baker version: a font is baked again only when one changes.
`phpbm font:bake` makes the same header from the command line.

Fonts need `php-baremetal/ext-display`. Errors:

| Message | When |
| --- | --- |
| `font '<name>' is defined twice (<source1>, <source2>)` | two fonts with the same name, from any source, `default` included |
| `font '<name>': source '<path>' not found` | a bake source is missing |
| `font '<name>': '<file>' is not a TTF or BDF font` | the source cannot be read |
| `font '<name>': size is required for a TTF source` | a TTF entry without `size` |
| `font file '<path>' not found (<package>)` | a package declares a font file that is not there |
| warning `font '<name>': <n> characters not in the source (...)` | `chars` lists characters the source lacks: they are skipped |
| `font file '<path>' is not a GFX font header` | a project or package `.h` the SDK cannot read |
| `fonts need php-baremetal/ext-display: composer require php-baremetal/ext-display` | fonts used without ext-display |

## Options of the esp32 SDK

| Option | Values | Default | Effect |
| --- | --- | --- | --- |
| `network` | `off`, `on` | `off` | Bring up the wired network (DHCP) before PHP starts. |
| `on-error` | `stop`, `continue` | `stop` | What an uncaught exception in `loop()` or an event handler does. |
| `restart-delay` | 0 to 3600 | `5` | Seconds before the board restarts once the program has ended. |
| `firmware-reserve` | 256 to 4096 | `1024` | PSRAM in KB kept for the rest of the firmware; PHP gets the rest as `memory_limit`. |
| `optimization` | `size`, `performance`, `debug` | `size` | Compiler optimization: `-Os`, `-O2` or `-Og`. |
| `power-save` | `off`, `on` | `off` | Automatic light sleep while every task waits. |
| `request-timeout` | 0 to 3600 | `30` | Seconds allowed for one HTTP request; `0` for no limit. |

Runtimes add their own: `init-loop` has `loop-interval` (ms between two `loop()` calls), `web-server` has
`port` and `init` (a script run once at boot). The SDK release describes every option; this table is the
`esp32` SDK 2.0.

## The env file

<!-- @code-block language="Text" label=".env" -->
```text
# KEY=value, one per line; quotes, export and comments are accepted
GREETING="Hello from the board"
WIFI_SSID=office
```
<!-- @endcode-block -->

The values are built into the firmware. Keep the file out of git when it holds secrets, and commit a
`.env.example` instead. With `microsd` storage the file is never copied to the card.

## Files phpbm writes

| Path | Commit it? | What it is |
| --- | --- | --- |
| `phpbm.lock` | yes | The locked SDK version and sha256. See [SDKs and phpbm.lock](./sdks.md). |
| `composer.lock` | your choice | Composer's lock of the packages. |
| `.phpbm/` | no | Packages installed by the conductor, `manifest.json`, the build directory. `phpbm init` adds it to `.gitignore`. |
| `vendor/` | no | Composer's own directory (the conductor). |
