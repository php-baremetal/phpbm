---
eyebrow: 'phpbm · Commands'
lede:    'Install, list, update, link and remove SDKs, install a toolchain, and clear the download cache.'
see_also:
  - { href: '../guide/sdks.md', meta: 'Guide' }
  - { href: '../guide/toolchains.md', meta: 'Guide' }
prev: { label: 'Project commands', href: './project.md' }
next: { label: 'Files and environment', href: '../reference/files-and-environment.md' }
---

# SDK and maintenance commands

| Command | Needs a project | What it does |
| --- | --- | --- |
| `sdk:install` | no | Install an SDK release. |
| `sdk:list` | no | List installed and linked SDKs. |
| `sdk:update` | yes | Move the project to the newest SDK its constraint allows. |
| `sdk:link` | no | Use a local SDK directory instead of releases. |
| `sdk:remove` | no | Remove an installed version or a link. |
| `toolchain:install` | yes | Install the toolchain of the project's SDK. |
| `cache:clear` | no | Delete downloads and prepared source trees. |

## sdk:install

<!-- @method name="phpbm sdk:install name[@constraint]" -->

Reads the SDK's release index and installs the newest version that satisfies the constraint (any version when
there is none): `phpbm sdk:install esp32`, `phpbm sdk:install esp32@^2.0`, `phpbm sdk:install esp32@2.0.0`.
An already installed version is left as it is.

## sdk:list

<!-- @method name="phpbm sdk:list" -->

A table of SDK, version and location. A linked SDK shows as version `dev` with the linked directory.

## sdk:update

<!-- @method name="phpbm sdk:update" -->

In a project: picks the newest version that satisfies `extra.baremetal.sdk`, installs it if needed, rewrites
`phpbm.lock` and prints the change. It is the only command that moves a locked project to another SDK version.

## sdk:link

<!-- @method name="phpbm sdk:link name dir" -->

Makes `dir` (an SDK checkout with its `sdk.json`) the `name` SDK for every project, in place of the releases.
The directory must hold the SDK of that name. Linked SDKs are never locked.

## sdk:remove

<!-- @method name="phpbm sdk:remove name@version" -->

`phpbm sdk:remove esp32@2.0.0` deletes that installed version; `phpbm sdk:remove esp32@dev` removes the link
and leaves the linked directory alone. A project locked to a removed version installs it again on its next build.

## toolchain:install

<!-- @method name="phpbm toolchain:install" -->

In a project: installs the toolchain of its SDK into `~/.phpbm/toolchains/<sdk>`, or reports the version
already there. See [Toolchains](../guide/toolchains.md).

## cache:clear

<!-- @method name="phpbm cache:clear" -->

Deletes `~/.phpbm/cache`: the downloaded SDK and PHP archives and the patched source trees. Installed SDKs,
toolchains and projects are kept; the next build downloads and prepares what it needs again.
