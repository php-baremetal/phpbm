---
eyebrow: 'phpbm · Guide'
lede:    'Each SDK names the compiler and flashing tools it builds with. phpbm installs them once per machine under ~/.phpbm/toolchains.'
see_also:
  - { href: '../commands/sdk.md', meta: 'Commands' }
  - { href: '../reference/files-and-environment.md', meta: 'Reference' }
prev: { label: 'SDKs and phpbm.lock', href: './sdks.md' }
next: { label: 'Project commands', href: '../commands/project.md' }
---

# Toolchains

`phpbm toolchain:install`, run inside a project, installs the toolchain of the project's SDK, or says it is
already there. `build`, `flash` and `sync` check the toolchain first and stop with the reason when it is
missing or unusable.

## esp32: ESP-IDF

The `esp32` SDK 1.0 builds with ESP-IDF v6.1, for the ESP32-S3 and ESP32-P4.

| | |
| --- | --- |
| Needs | Python 3.10 or later on the machine, and `git`. |
| Installs | A shallow clone of ESP-IDF v6.1 into `~/.phpbm/toolchains/esp32/esp-idf-v6.1`, its tools for both chips and its Python environment into `~/.phpbm/toolchains/esp32/tools`. |
| Size | About 6 GB. |

A failed step (network, disk) stops with the reason; run `phpbm toolchain:install` again to resume.

<!-- @callout variant="warning" title="Windows" -->
Install Python from python.org and tick "Add python.exe to PATH". ESP-IDF does not work from a path with spaces
or non-ASCII characters: this applies to `PHPBM_HOME` and to your projects.
<!-- @endcallout -->

Variables the `esp32` SDK reads:

| Variable | Meaning |
| --- | --- |
| `PHPBM_PYTHON` | The Python 3.10+ to use, tried before `python3` / `py -3` on `PATH`. |
| `PHPBM_ESP32_IDF_PATH` | An ESP-IDF v6.1 you manage yourself. phpbm uses it and never installs into it: install its tools with ESP-IDF's own `idf_tools.py`. |
| `PHPBM_ESP32_IDF_TOOLS_PATH` | With `PHPBM_ESP32_IDF_PATH`: where that ESP-IDF's tools live. `IDF_TOOLS_PATH` is also read; the default is `~/.espressif`. |
