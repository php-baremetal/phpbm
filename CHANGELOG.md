# Changelog

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
