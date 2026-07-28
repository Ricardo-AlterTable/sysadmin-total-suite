=== Site Integrity & Profiler ===
Contributors: ricardomorales
Tags: security, performance, integrity, profiling, ai-bots
Requires at least: 5.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Core integrity checks, load-time profiling, user review, performance diagnostics and AI bot blocking in a single admin panel.

== Description ==

This plugin brings together several maintenance tools for WordPress in one admin panel:

* **Core integrity**: verifies your WordPress files against the official checksums, shows the differences (diff) for modified files and lets you restore them from the official package, optionally keeping a backup. It also detects files that do not belong to the core and lets you remove them, and it manages the backups it creates, listed with date and time, with restore and purge options.
* **Profiling**: measures the front page load time (core, plugins, theme, SQL and outgoing HTTP calls), keeps a history and displays it as charts.
* **Check users**: lists the registered users (name, registration date, role) and lets you delete them with a double confirmation.
* **WPO (Web Performance Optimization)**: active plugins, size of the autoloaded options, expired transients, WP-Cron status including cleanup of orphaned tasks, cache detection and environment versions (PHP, MySQL/MariaDB, WordPress).
* **AI bot blocking**: robots.txt opt-out plus real User-Agent blocking (HTTP 403) for AI crawlers, selectable bot by bot.

Every action requires administrator capabilities and is protected with nonces. Several operations are destructive (deleting users, extra files and cron tasks) and warn you before running.

== External services ==

This plugin connects to WordPress.org services in order to verify and restore the core:

1. WordPress.org checksums API (`https://api.wordpress.org/core/checksums/1.0/`): queried when you run the integrity analysis, to obtain the official checksums for your version and locale. The WordPress version and the site locale are sent.
2. WordPress.org downloads (`https://downloads.wordpress.org/` and the localized sites `https://*.wordpress.org/`): the official ZIP package for your version is downloaded only when you ask to see the differences (diff) or to restore a core file. The version and locale are sent to build the package URL.

No personal data is sent and no other third-party service is contacted. These services are provided by the WordPress Foundation; see https://wordpress.org/about/privacy/ for details.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from your WordPress dashboard.
2. Activate the plugin through the Plugins menu.
3. Open the plugin menu and its sections: Integrity, Profiling, Check users, WPO and AI bot blocking.

Optional: to measure the SQL query time in the Profiling section, add `define('SAVEQUERIES', true);` to `wp-config.php`. Leaving it permanently enabled in production is not recommended because of its overhead.

== Frequently Asked Questions ==

= Does it require any PHP extension? =

Yes, the ZipArchive extension is needed to show differences and to restore core files from the official package.

= Why does MySQL show as N/A in the Profiling section? =

The query time is only available when the `SAVEQUERIES` constant is enabled. The number of queries and the remaining timings are shown regardless.

= The page cache shows as not enabled, but I use LiteSpeed =

LiteSpeed caches at server level and does not use `WP_CACHE` or `advanced-cache.php`. The plugin detects that case and shows its real status; you can confirm it with the `x-litespeed-cache: hit` response header.

= Is User-Agent blocking foolproof? =

No. The User-Agent can be spoofed, which is why the 403 blocking complements, but does not replace, the robots.txt opt-out.

= Where are the backups stored? =

In the uploads folder, inside a dedicated directory protected against direct access, with a randomized folder name and a neutralized file extension. You can list, restore and purge them from the Integrity section.

== Screenshots ==

1. Core integrity: analysis result plus modified and extra files.
2. Profiling: last measurement and history charts.
3. WPO: plugins, autoloaded options, WP-Cron, cache and versions.
4. AI bot blocking: per-bot selection.

== Changelog ==

= 3.9 =
* Compliance with the Plugin Check tool: translator comments for every string with placeholders, output escaping, WordPress filesystem API instead of direct calls, prepared LIKE wildcards, `wp_safe_redirect()` and sanitized server variables.
* Removed a pointless database query that was only used to time itself.
* The readme is now written in English, as required by the plugin directory.

= 3.8 =
* New backup manager in the Integrity section: lists backups with their date and time, restores individual files from a backup and deletes backups individually, per batch or all at once.
* Backups saved by earlier versions in the site root are detected too, with a warning that this location is web-accessible.

= 3.7 =
* Fixed: the Profiling "Run test" button did not record measurements, because the signed URL was generated with `esc_js()`, which turns the ampersand into an HTML entity and broke the query string, so the nonce never reached the server.

= 3.6 =
* Security (important): the diff viewer no longer renders the analyzed file content as HTML. Previously, a tampered core file containing JavaScript could execute it in the admin panel when clicking "Show changes" (stored XSS).
* Backups use a randomized folder name and a neutralized extension; the ZIP cache is protected against direct access.
* The profiling test now requires a nonce and capabilities: any visitor could previously force database writes while bypassing the page cache.
* Path validation now runs after normalization; cron tasks are backed up before removal; user deletion uses the `delete_user` meta capability.
* Hidden and configuration files in the site root are no longer flagged as extra, avoiding accidental deletion of `.user.ini`, `ads.txt`, search engine verification files and similar.
* Robustness: the official ZIP is opened once per request and limits are raised for long operations.

= 3.5 =
* Hardened path resolution, restore and diff limited to core paths, and backups moved to the uploads folder with access protection.
* Fixed: restoring files reported as missing always failed.
* Added `uninstall.php` to clean options, transients and cache on uninstall.

= 3.4 =
* Prepared for the plugin directory: readme, headers and Chart.js bundled locally instead of loaded from a CDN. Internationalization with English as the base language and a Spanish translation included.

= 3.0 - 3.3 =
* AI bot blocking with an Allow/Block toggle per bot.
* Redesigned interface as a modern control panel.
* Renamed the Tunning section to WPO.

= 2.0 - 2.9 =
* New sections: Check users, WPO (performance, wp_options, WP-Cron, cache) and AI bot blocking.
* Optional backup when restoring; deletion of extra files.

= 1.6 - 1.9 =
* Integrity limited to the real core; locale alignment in diff and restore; nonce and interface fixes.

== Upgrade Notice ==

= 3.9 =
Compliance fixes for the plugin directory review. No change in behaviour.

= 3.6 =
Recommended security update: fixes a stored XSS in the diff viewer and hardens several destructive operations.
