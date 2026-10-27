---
eyebrow: 'phpbm · Guide'
lede:    'SDKs are versioned release archives. phpbm installs them from a release index, locks the one a project uses in phpbm.lock, and changes it only when you ask.'
see_also:
  - { href: '../commands/sdk.md', meta: 'Commands' }
  - { href: '../reference/files-and-environment.md', meta: 'Reference' }
prev: { label: 'The project', href: './project.md' }
next: { label: 'Toolchains', href: './toolchains.md' }
---

# SDKs and phpbm.lock

## The release index

Each SDK publishes an `index.json` with every release version, the archive URL and its sha256. phpbm reads
it from:

<!-- @code-block language="Text" label="default index" -->
```text
https://github.com/php-baremetal/php-{name}-sdk/releases/latest/download/index.json
```
<!-- @endcode-block -->

<!-- @callout variant="note" title="Until the esp32 SDK 2.0.0" -->
The 2.0 SDK is not released yet. Until it is, phpbm reads the previews built from the SDK's `sdk` branch
(`releases/download/sdk-preview/index.json`, versions `2.0.0-alpha.N`), which `esp32@^2.0` accepts. Once
2.0.0 is out, run `phpbm sdk:update` in your projects.
<!-- @endcallout -->

`{name}` is the SDK name (`esp32`). `PHPBM_SDK_INDEX` replaces the pattern; a local path or a `file://` URL
works too, which is how you test an SDK release before publishing it.

## Installing

`phpbm sdk:install esp32` installs the newest release; `phpbm sdk:install esp32@^2.0` the newest that
satisfies the constraint. The archive is checked against the index's sha256 and unpacked into
`~/.phpbm/sdks/esp32/<version>`. You rarely need the command: a build installs the SDK version the project
needs by itself.

## phpbm.lock

The first command that needs the SDK (`build`, `flash`, `sync`, `monitor`, `toolchain:install`) picks the newest
version that satisfies `extra.baremetal.sdk` and writes it to `phpbm.lock`:

<!-- @code-block language="JSON" label="phpbm.lock" -->
```json
{
  "sdk": {
    "name": "esp32",
    "constraint": "^2.0",
    "version": "2.0.0",
    "sha256": "…"
  }
}
```
<!-- @endcode-block -->

From then on every build uses exactly that version, on every machine. Commit the file.

- If the installed copy of that version has a different sha256, the build stops: remove it with
  `phpbm sdk:remove esp32@2.0.0` and build again.
- If you change the constraint so that the locked version no longer satisfies it, the build stops and asks
  for `phpbm sdk:update`.
- `phpbm sdk:update` moves the project to the newest version its constraint allows and prints the old and the
  new version.

## Linking a local SDK

`phpbm sdk:link esp32 ../php-esp32-sdk` makes every project that uses `esp32` build with that directory
instead of a release. It is meant for SDK development: a linked SDK is never written to `phpbm.lock`, and
each command warns that it is in use. `phpbm sdk:remove esp32@dev` removes the link; `phpbm sdk:list` shows
it as version `dev`.
