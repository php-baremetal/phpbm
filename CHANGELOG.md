# Changelog

## 0.3.0

- Added: extra.baremetal.board-overrides can add a block the board does not have, written whole with its `type` (needs php-baremetal/spec 1.3), for example a microSD slot on a generic board. Every check of the board (the runtime's and the storage's capabilities, microsd assets) and the build see the board after the overrides.
- Changed: phpbm board takes the new board with the overrides that stay: an override that adds a block stays on a board without that block and counts for the runtime and storage check and for the features offered; an override whose `type` differs from the new board's block is listed and removed like one of a block the new board does not have. phpbm board without a new board offers the features of the blocks the overrides add too.
- Added: phpbm image:convert, which turns a PNG (any color type and bit depth, interlaced or not) into a .sli image for Slate, with Slate's ordered dither; pure PHP with zlib, no GD.
- Added: asset folders (extra.baremetal.assets, needs php-baremetal/spec 1.3). phpbm checks them at every build, flash and sync (the folder exists, the storage is one of the SDK's, microsd needs a board with a microSD slot, embedded needs the code on embedded storage, no two files give the same image, no embedded image takes the place of a source file), turns their PNG files into .sli images (cached by content in ~/.phpbm/cache/assets) and skips other files with a warning.
- Added: embedded assets go into the flash image of the code: phpbm puts the sources and the converted images together in .phpbm/build/app, which the SDK images and sizes the storage partition on; phpbm sync says when they changed since the last build and need phpbm flash.
- Added: phpbm hands the SDK every converted asset (BuildPlan::$assets, all storages) at build, flash and sync, and, with embedded assets, the staging folder as the folder of the flash image (BuildPlan::$imageDir); the SDK copies the microsd assets to the card with the sync of phpbm flash and phpbm sync (only changed files; deletions only inside the asset folders).
- Changed: when the sync after phpbm flash fails, the message names what was being copied: the sources and/or the microSD assets.

## 0.2.1

- Fixed: on Windows, .tar, .tar.gz and .tar.bz2 archives are extracted by phpbm itself instead of tar.exe, which skipped the files with non-ASCII names (the Japanese ones of the PHP 8.5 sources) and failed at "preparing PHP"; .tar.xz and .tar.zst still go through tar.

## 0.2.0

- Added: options of type "set" (a list of allowed values) in extra.baremetal.options, as declared by packages (needs php-baremetal/spec 1.2).

## 0.1.1

- Fixed: on Windows, Composer found as a path with '/' (on the PATH or in PHPBM_COMPOSER) is started with '\' separators, which cmd.exe needs to run a .bat.

## 0.1.0

- Added: options declared by installed native packages are accepted in extra.baremetal.options and resolved with their defaults.
- Added: phpbm board adds or removes the board's features, or changes the board and offers the new board's features.
- Added: phpbm init offers the board's features (display, LED, I2C…) as checkboxes and installs their packages, with the runtime's and storage's, through Composer; --features and --no-install.
- Added: phpbm font:bake, which turns a TTF (TrueType outlines) or BDF font into a GFX header for ext-display.
- Added: fonts. phpbm collects the default font, font packages, the project font folder and extra.baremetal.fonts.bake, bakes TTF and BDF sources (cached in ~/.phpbm/cache/fonts) and hands them to the SDK.
- Fixed: on Windows, phpbm init and phpbm board accept Composer installed as composer.bat (as the Windows installer sets it up): a command on the PATH is looked up with the PATHEXT extensions and judged runnable by its extension, where PHP's is_executable() only accepts binaries.
- Fixed: on Windows, project paths are printed and handed to the SDK with '/' only (no more `C:\Users\u\proj/.phpbm/build`), so font paths match the project directory.
- Fixed: phpbm font:bake accepts an absolute --out on Windows (C:\fonts, C:/fonts), which it used to put inside the working directory.
