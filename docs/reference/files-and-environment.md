---
eyebrow: 'phpbm · Reference'
lede:    'What phpbm keeps on the machine and in a project, and the environment variables that move it.'
see_also:
  - { href: '../guide/sdks.md', meta: 'Guide' }
  - { href: '../guide/toolchains.md', meta: 'Guide' }
prev: { label: 'SDK and maintenance commands', href: '../commands/sdk.md' }
next: { label: 'Troubleshooting', href: './troubleshooting.md' }
---

# Files and environment

## On the machine

Everything lives under `~/.phpbm` (on Windows `%USERPROFILE%\.phpbm`):

| Path | Contents |
| --- | --- |
| `sdks/<name>/<version>/` | An installed SDK release, with `<version>.sha256` beside it. |
| `sdks/<name>/dev.link` | The directory of a linked SDK. |
| `toolchains/<name>/` | The SDK's toolchain (ESP-IDF and its tools for `esp32`). |
| `cache/downloads/` | Downloaded SDK, PHP and source archives, checked by sha256. |
| `cache/trees/` | Extracted and patched PHP sources, shared by every project. |

Only `cache/` is safe to delete at any time (`phpbm cache:clear`).

## In a project

| Path | Contents |
| --- | --- |
| `composer.json` | The project: packages and `extra.baremetal`. |
| `phpbm.lock` | The locked SDK. Commit it. |
| `project-src/` | The PHP sources (`extra.baremetal.src`). |
| `.phpbm/packages/` | The `bm-*` packages, installed by the conductor. |
| `.phpbm/manifest.json` | The resolved project and package graph, written by the conductor; phpbm reads only this. |
| `.phpbm/build/` | The firmware build. |

## Environment variables

| Variable | Default | Meaning |
| --- | --- | --- |
| `PHPBM_HOME` | `~/.phpbm` | Where phpbm keeps SDKs, toolchains and the cache. |
| `PHPBM_SDK_INDEX` | `https://github.com/php-baremetal/php-{name}-sdk/releases/latest/download/index.json` | The release index pattern; `{name}` is the SDK name. A local path or a `file://` URL also works. |
| `PHPBM_REPOSITORY` | `https://repository.php-baremetal.com` | The package registry `phpbm init` writes into new projects. |

SDKs read their own variables; for `esp32` see [Toolchains](../guide/toolchains.md).
