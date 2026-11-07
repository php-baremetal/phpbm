# Changelog

## 0.1.0

- Added: options declared by installed native packages are accepted in extra.baremetal.options and resolved with their defaults.
- Added: phpbm board adds or removes the board's features, or changes the board and offers the new board's features.
- Added: phpbm init offers the board's features (display, LED, I2C…) as checkboxes and installs their packages, with the runtime's and storage's, through Composer; --features and --no-install.
- Added: phpbm font:bake, which turns a TTF (TrueType outlines) or BDF font into a GFX header for ext-display.
- Added: fonts. phpbm collects the default font, font packages, the project font folder and extra.baremetal.fonts.bake, bakes TTF and BDF sources (cached in ~/.phpbm/cache/fonts) and hands them to the SDK.
