# Contributing

Thanks for taking a look.

## Reporting a bug

Open an issue with:

- What you did, what you expected, what happened instead
- PHP version (`php -v`) and web server
- Whether MySQL is configured or you're running on JSON files
- Anything from `diag.php` that looks relevant

Screenshots help a lot for visual bugs.

## Pull requests

This is a self-hosted PHP app with no build step. To work on it:

1. Copy `php-dashboard/` to a PHP 8.0+ web root
2. Load it in a browser and complete the setup wizard
3. Edit the files directly — no compilation, just refresh

A few conventions worth knowing:

- **No Composer, no npm.** Everything is plain PHP, HTML, CSS and vanilla JS.
  Please keep it that way; the whole point is that it drops onto any PHP host.
- **Themes** are canvas-drawn. Each one is a self-contained IIFE in `index.php`
  exposing `_startX()` / `_stopX()`, registered in `stopAllCanvases()`,
  `applyTheme()`, `$theme_groups` and the CSS block.
- **Never ship third-party artwork.** Themes are drawn procedurally on purpose.
  No logos, no trademarked icons, no copyrighted wallpapers.
- **User data is sacred.** `apply_update.php` protects `dash_config.php` and
  every `dash_*.json`. If you add a new user-data file, add it to that list.

## Testing before you submit

There's no test suite yet. At minimum, please confirm:

- `php -l` passes on every file you touched
- The setup wizard still completes on a fresh install
- Login, logout, and remember-me still work
- Adding, editing and reordering links still work
