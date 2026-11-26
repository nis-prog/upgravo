<p align="center"><img src=".wordpress-org/banner-1544x500.png" alt="Upgravo – Know what breaks before you upgrade PHP"></p>

# Upgravo – PHP Version Compatibility Checker

Scans the plugins and theme of a WordPress site **on its own server** and tells you what will break before you upgrade PHP (8.1 to 8.6). No external service, no `exec()`, no account.

- **Install:** WordPress admin → Plugins → Add New → search for **Upgravo** — or [wordpress.org/plugins/upgravo](https://wordpress.org/plugins/upgravo/)
- **Try it in your browser:** the *Live Preview* button on the WordPress.org page opens it in WordPress Playground.
- **Website:** [phpready.com](https://phpready.com/)
- **Support:** please use the [WordPress.org support forum](https://wordpress.org/support/plugin/upgravo/) (issues are disabled here so every question is answered in one place).

## What it does

- Scans active plugins, the active theme (and parent), must-use plugins and drop-ins in short batches, so it works on shared hosting.
- Gives a verdict and a "What to do first" list: what **breaks**, what only raises **deprecation notices**, and what was **hidden** as a false positive (with the reason).
- Separates third-party libraries bundled inside a plugin from the plugin's own code.
- Shows each plugin's result on the Plugins screen ("PHP 8.4: OK", "works, 12 notices" or "3 breaks").
- Explains how to fix the most common findings, with a before/after code example.
- Flags plugins not tested with recent WordPress versions; optionally checks on WordPress.org whether a plugin was closed or abandoned (off by default).
- Copies a plain-text summary for a client email or a hosting ticket.
- Exports a CSV, adds the verdict to Tools → Site Health, and includes a WP-CLI command: `wp upgravo scan`.

## Screenshots

| | |
|---|---|
| ![Verdict](.wordpress-org/screenshot-1.png) | ![Details](.wordpress-org/screenshot-2.png) |
| ![Progress](.wordpress-org/screenshot-3.png) | ![Site Health](.wordpress-org/screenshot-4.png) |
| ![Plugins screen](.wordpress-org/screenshot-5.png) | ![Summary](.wordpress-org/screenshot-6.png) |

## Building from source

The scanning engine is [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) + [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) + [PHPCSUtils](https://github.com/PHPCSStandards/PHPCSUtils), installed with Composer:

```
composer install --no-dev
```

The release package on WordPress.org ships `vendor/` without the parts the scanner never runs (tests, docs and PHP_CodeSniffer's coding-style standards).

## License

GPLv3 or later. Bundled libraries keep their own licenses (BSD-3-Clause for PHP_CodeSniffer, LGPL-3.0-or-later for PHPCompatibility and PHPCSUtils).
