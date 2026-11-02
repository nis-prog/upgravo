=== Upgravo – PHP Compatibility Check ===
Contributors: nicolassalinas
Tags: php compatibility, php upgrade, php 8, compatibility checker, site health
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.8
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Check PHP compatibility before you upgrade: scans plugins and theme on your own server and tells you what will break on PHP 8.x. No external service.

== Description ==

Your hosting company says PHP 7.4 (or 8.0, 8.1…) is going away and you have to upgrade. Which of your plugins will break?

**Upgravo** reads the code of every plugin and your theme, on your own server, and gives you a straight answer:

* **Ready for PHP 8.5: nothing breaks** — or **Not yet: something active breaks**, with the exact plugin, file and line.
* **Breaks** vs **deprecation notices**: a removed function stops the site; a deprecation only writes a line in the error log. Upgravo keeps the two apart so you know what is urgent.
* **What to do first**: if the plugin that breaks has an update available, it says so. If it is inactive, it says it does not matter until you activate it.
* **No noise**: code that is already guarded by `function_exists()` or a PHP version check is not reported, and findings that only apply to the PHP version you already run are hidden by default (you can still see them, with the reason).

= Designed for any hosting =

* **Runs on shared hosting.** The scan runs in short batches with a progress bar, so it never hits the 30-60 second request limit of shared hosts. Close the tab and come back: it resumes where it stopped.
* **No `exec()`, no shell, no external server.** Your code never leaves your site. Nothing to sign up for, no license key to see results.
* **Covers the whole site:** must-use plugins, drop-ins (`advanced-cache.php`, `object-cache.php`, `db.php`…), the parent theme, and every class of WordPress core itself, so a plugin that extends `WP_Widget` or `WC_Payment_Gateway` is checked properly instead of ignored.
* **Any upgrade path from PHP 7.4 to 8.1, 8.2, 8.3, 8.4 or 8.5**: removed functions and extensions (`mysql_*`, `mcrypt`, `each()`, `create_function()`…), PHP 8.0 fatal errors, PHP 8.1–8.5 deprecations including dynamic properties, `'self::method'` callables and the PHP 8.5 changes (`(boolean)`/`(integer)` casts, backticks, `curl_close()`…). Everything that already applies to the PHP version you run today is shown as hidden, with the reason.
* **Site Health integration**: the verdict shows up in Tools → Site Health.
* **WP-CLI**: `wp upgravo scan --target=8.4 --format=json` returns exit code 1 if anything active breaks, so agencies can check 50 sites from a script.
* **CSV export** of every finding, including the hidden ones and why they were hidden.

= Built on PHPCompatibility =

The analysis engine is [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) (176 rules maintained by the PHP community since 2012) running on PHP_CodeSniffer, bundled inside the plugin, plus Upgravo's own detectors for PHP 8.2 dynamic properties and relative callables. It reads code; it does not run it. A static scan cannot see problems that only appear at runtime, so treat "nothing breaks" as "nothing we can see breaks", and keep a backup before any upgrade.

= Third-party libraries included =

* [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) — BSD-3-Clause
* [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) — LGPL-3.0-or-later
* [PHPCSUtils](https://github.com/PHPCSStandards/PHPCSUtils) — LGPL-3.0-or-later

= Source code =

The free plugin is developed in the open: https://github.com/nis-prog/upgravo (support stays in the WordPress.org forum).

== Installation ==

1. Install from Plugins → Add New, or upload the `upgravo` folder to `wp-content/plugins/`.
2. Activate it.
3. Go to **Upgravo** in the admin menu, pick the PHP version you plan to upgrade to, and click **Scan plugins and theme**.

== Frequently Asked Questions ==

= Does it send my code anywhere? =

No. Everything runs on your server, inside WordPress. The plugin makes no external requests.

= Does it need `exec()` or shell access? =

No. That is why it works on SiteGround, Kinsta, WP Engine and other hosts that disable `exec()`.

= How long does a scan take? =

About 15 ms per PHP file. A site with WooCommerce and a dozen plugins (5,000 files) takes one to two minutes. The scan runs in batches of a few seconds each, so it is safe on any host.

= What is the difference between "Breaks" and "Notices"? =

"Breaks" means a fatal error on the target PHP version: a function that no longer exists, an argument order that is no longer accepted. "Notices" means the code still works but PHP marks it as deprecated and logs a message; it will break in a future version.

= Why are some findings hidden? =

Because they would mislead you. Hidden findings are code that only runs on old PHP versions (guarded by `function_exists()` or `version_compare()`), things that already apply to the PHP version you run today, analyzer housekeeping, and style advice. You can expand them and see the reason for each one.

= Does it check inactive plugins? =

By default only active plugins, the active theme (and its parent), mu-plugins and drop-ins. Tick "Include inactive plugins" to scan everything.

= Can I run it from the command line? =

Yes: `wp upgravo scan --target=8.4 --inactive --format=table`. Exit code 1 means something active breaks.

= Is there a Pro version? =

Yes. Upgravo Pro adds scheduled scans with an email when an update introduces an incompatibility, a PDF report for clients, a whitelist of false positives per plugin, and multisite support. Everything in the free plugin stays free. Details at [phpready.com](https://phpready.com/#pro).

== Screenshots ==

1. The verdict at the top, one row per plugin and theme with breaks, notices and hidden findings, update available, CSV export.
2. Detail grouped by rule, with file and line, bundled-library count and the hidden findings with their reason.
3. The scan runs in short batches with a progress bar, safe on shared hosting.
4. The verdict also shows up in Tools → Site Health.

== Changelog ==

= 0.1.8 =
* PHP 8.5 can be chosen as the target version.
* Inline `phpcs:ignore` / `phpcs:ignoreFile` comments in scanned plugins no longer hide PHP compatibility findings.
* On multisite, scanning requires a super admin (plugins and must-use plugins belong to the whole network).
* A slow file can no longer let the scan lock expire mid-batch.
* Uninstall removes Upgravo data from every site of a multisite network.

= 0.1.7 =
* After a finished scan, the Upgravo screen asks once for a review on WordPress.org (Maybe later hides it for 14 days; Don't ask again hides it for good).

= 0.1.6 =
* Bundled PHP_CodeSniffer reduced to the parts the scanner runs (unused coding-style standards removed).

= 0.1.5 =
* Renamed to Upgravo. Same scanner and settings; the WP-CLI command is now `wp upgravo scan`.

= 0.1.4 =
* Bundled PHP_CodeSniffer updated to 4.0.4.
* Development files (tests, docs, build scripts) removed from the bundled libraries.
* Translation files are no longer bundled; translations are delivered by translate.wordpress.org.

= 0.1.3 =
* Files under a plugin's `lib/` folder are no longer assumed to be third-party libraries (in many plugins that folder holds the author's own code). Only `vendor/`, `node_modules/` and similar folders count as bundled libraries.

= 0.1.2 =
* A file that does not compile on the current PHP version is no longer analyzed (the analyzer produced false findings on such files); it is listed as not checked with the line and the parser message.
* If the analyzer aborts on a file, that file is listed as not checked instead of counting as clean.
* Very large files: the scanner now asks the server for the extra memory the file needs; if the host allows it, the file is analyzed instead of skipped.

= 0.1.1 =
* The scan lock now carries a per-request token, so a long request (for example a cron run) can never release a lock held by another request.
* Fixed a spurious "no scan in progress" message when another tab or process finished the last batch.
* WP-CLI respects the scan lock and reports clearly if the scan was cancelled from the admin screen.
* Hooks for extensions (classification filter, result groups, network mode); a "Pro version" link.

= 0.1.0 =
* First public release.
