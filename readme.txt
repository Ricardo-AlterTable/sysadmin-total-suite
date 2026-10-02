=== Sysadmin Total Suite ===
Contributors: ricardomorales
Tags: security, performance, integrity, profiling, ai-bots
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Core integrity, plugin vulnerability audit, profiling, disk usage, user review, WPO diagnostics and AI bot blocking in one admin panel.

== Description ==

This plugin brings together several maintenance tools for WordPress in one admin panel:

* **Core integrity**: verifies your WordPress files against the official checksums and reports which ones are modified, missing or not part of the core. For a modified file it can show a diff against the official copy, so you can see exactly what changed. The plugin never modifies your files: to repair the core it points you to Dashboard > Updates > Reinstall now, which is WordPress's own mechanism.
* **Plugin audit**: lists the installed plugins with their pending updates and flags the ones that have been closed on WordPress.org or have not been updated in more than two years. Optionally, and only after you enable it, it checks them against the free WPVulnerability database and shows the publicly known vulnerabilities that affect the installed version, with their severity and references (CVE and others). Updates are always installed through WordPress's own Updates screen.
* **Profiling**: measures the front page load time (core, plugins, theme, SQL and outgoing HTTP calls), keeps a history and displays it as charts.
* **Disk usage**: a read-only report of the space used by the WordPress core, the uploads (by year and month), each plugin and theme, the other wp-content folders (cache, plugin backups...) and any non-WordPress folder in the site root, plus the size and overhead of every database table and the free space on the server disk. Large sites are measured in batches to avoid timeouts.
* **Check users**: lists the registered users (name, registration date, role) and lets you delete them with a double confirmation.
* **WPO (Web Performance Optimization)**: active plugins, size of the autoloaded options, expired transients, WP-Cron status including cleanup of orphaned tasks, cache detection and environment versions (PHP, MySQL/MariaDB, WordPress).
* **AI bot blocking**: robots.txt opt-out plus real User-Agent blocking (HTTP 403) for AI crawlers, selectable bot by bot.

Every action requires administrator capabilities and is protected with nonces. The plugin does not write to or delete anything inside the WordPress core directories. The operations that do change something (deleting a user, clearing expired transients, removing orphaned cron tasks, blocking a bot) act on the database and always ask for confirmation first.

== External services ==

This plugin connects to WordPress.org services in order to verify the integrity of the core and to check the status of the installed plugins, and, only if you explicitly enable it, to the WPVulnerability service. Nothing is contacted until you start an action that needs it (running an analysis, viewing a diff or scanning plugins).

**WordPress.org** (provided by the WordPress Foundation; see https://wordpress.org/about/privacy/):

1. WordPress.org checksums API (`https://api.wordpress.org/core/checksums/1.0/`): queried when you run the integrity analysis, to obtain the official checksums for your version and locale. The WordPress version and the site locale are sent. On sites with a translated locale, the checksums of the international (`en_US`) package may also be requested, to avoid reporting false positives.
2. WordPress.org downloads (`https://downloads.wordpress.org/` and the localized sites `https://*.wordpress.org/`): the official ZIP package for your version is downloaded only when you ask to see the differences (diff) of a modified core file. The version and locale are sent to build the package URL.
3. WordPress.org plugins API (`https://api.wordpress.org/plugins/info/1.2/`): queried when you scan plugins in the Plugin audit section, to know whether each plugin is still available in the directory, when it was last updated and up to which WordPress version it has been tested. The slug of each installed plugin is sent. Pending updates are read from the data WordPress itself already keeps.

**WPVulnerability** (optional, disabled by default):

4. WPVulnerability API (`https://www.wpvulnerability.net/`): a free and open database of WordPress vulnerabilities. It is only contacted if you enable "Check installed plugins against the WPVulnerability database" in the Plugin audit section, and only when you scan plugins. The slug of each installed plugin is sent; the installed version and the site address are not sent, because the version comparison is done on your site. Disabling the option also deletes the vulnerability data already obtained. Service: https://www.wpvulnerability.com/ — Privacy policy: https://www.wpvulnerability.com/privacy/ — Terms: https://www.robotstxt.es/legal/

No personal data is sent to any of these services and no other third-party service is contacted.

The official package is used **only** as a read-only reference copy of the core, to compare it with the files on your site. No code is fetched from any third-party server, nothing from the download is executed, and nothing from it is ever written to the WordPress core directories: the package is only cached in the plugin's own protected folder inside the uploads directory.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from your WordPress dashboard.
2. Activate the plugin through the Plugins menu.
3. Open the plugin menu and its sections: Integrity, Plugin audit, Profiling, Check users, WPO, Disk usage and AI bot blocking.

Optional: to measure the SQL query time in the Profiling section, add `define('SAVEQUERIES', true);` to `wp-config.php`. Leaving it permanently enabled in production is not recommended because of its overhead.

== Frequently Asked Questions ==

= Does the plugin modify any file? =

No. It does not write to or delete any file inside `wp-admin`, `wp-includes` or the site root. The integrity section is a read-only report: it tells you which core files differ from the official ones and can show you the diff, but repairing them is left to WordPress itself through Dashboard > Updates > Reinstall now.

The only thing the plugin writes is the cached copy of the official package, stored in its own folder inside the uploads directory (resolved at runtime with `wp_upload_dir()`), protected against direct access and removed when you purge the cache or uninstall the plugin.

= Does the plugin allow arbitrary code to be inserted or executed? =

No. It contains no PHP or JavaScript editor, no file manager, no upload form and no field where code can be entered. It never calls `eval()`, `base64_decode()`, `exec()` or any dynamic include, and it does not generate or execute code.

= Why does the plugin download a ZIP from WordPress.org? =

To show a real diff, the original file is needed. The plugin downloads the official package for your exact version and locale from WordPress.org — the same source WordPress itself uses — and reads the single file being compared straight out of it. Nothing from the download is executed and nothing is written outside the uploads cache.

= Does it require any PHP extension? =

Yes, the ZipArchive extension is needed to show the differences of a modified file against the official package. The integrity analysis itself works without it.

= Why does MySQL show as N/A in the Profiling section? =

The query time is only available when the `SAVEQUERIES` constant is enabled. The number of queries and the remaining timings are shown regardless.

= A core file is reported as modified but I have not touched anything =

Some sites run the international WordPress package while their locale is set to another language. In that case a few files (for example `wp-includes/version.php`, which carries `$wp_local_package` in translated packages) differ from the localized checksums without anything being wrong. The plugin compares against the international package as well before reporting a file, so this no longer produces a false positive.

= The page cache shows as not enabled, but I use LiteSpeed =

LiteSpeed caches at server level and does not use `WP_CACHE` or `advanced-cache.php`. The plugin detects that case and shows its real status; you can confirm it with the `x-litespeed-cache: hit` response header.

= Is User-Agent blocking foolproof? =

No. The User-Agent can be spoofed, which is why the 403 blocking complements, but does not replace, the robots.txt opt-out.

= Why is the vulnerability check disabled by default? =

Because it contacts an external service (WPVulnerability). WordPress.org guidelines require your explicit consent before a plugin sends data to a third party, so you have to enable it in the Plugin audit section. Only plugin slugs are sent; the comparison with your installed versions is done locally.

= Does "no known vulnerabilities" mean my plugins are secure? =

No. It only means that the database has no public report affecting the installed version. Keep your plugins updated and remove the ones you do not use, including inactive ones: their files are still on the server.

= Why does a plugin show as "Not in the directory"? =

It is a premium or custom plugin that is not hosted on WordPress.org, so its updates and closure status cannot be checked there. Its vulnerability check still runs by slug, but if a different plugin shares that slug the result may not apply.

= Why does Disk usage show a different size than my hosting panel? =

The plugin adds up the size of the files it can read inside the WordPress installation and the size of its database tables. Your hosting quota may also count email, logs, other sites or the space taken by file system blocks, and some folders may not be readable because of their permissions (they are flagged in the report).

= Does Disk usage delete anything? =

No. It is a read-only report.

= Does the plugin update or install plugins? =

No. It only reports. Updates are installed from Dashboard > Updates, which is WordPress's own mechanism.

== Screenshots ==

1. Core integrity: analysis result plus modified and extra files.
2. Profiling: last measurement and history charts.
3. WPO: plugins, autoloaded options, WP-Cron, cache and versions.
4. AI bot blocking: per-bot selection.

== Changelog ==

= 5.3 =
* New "Plugin audit" section: pending updates, plugins closed or not updated on WordPress.org for more than two years and, only after you enable it, publicly known vulnerabilities (CVE and others) of the installed versions, from the free WPVulnerability database. Only plugin slugs are sent; the version comparison is done locally.
* New "Disk usage" section: read-only report of the space used by the core, uploads (by year and month), plugins, themes, other wp-content folders, non-WordPress folders in the site root and database tables, plus the free disk space. Measured in batches to avoid timeouts.
* In multisite, both new sections require network administrator capabilities.
* The diff cache now deletes the official packages of previous WordPress versions instead of keeping them after every core update.
* The readme no longer mentions restoring core files, which was removed in 5.0. The external services section now documents every service used.
* Uninstall also removes the option used for the old prefix migration.
* Tested up to WordPress 7.1.

= 5.2 =
* Fixed a false positive: a site running the international WordPress package while using a translated locale reported core files such as wp-includes/version.php as modified. Files are now also compared against the international package before being flagged.

= 5.1 =
* Prefixed the variables used by the admin views so that static analysis no longer reports them as unprefixed globals.

= 5.0 =
* The integrity section is now a read-only report. Following the plugin review team's feedback, the plugin no longer writes to or deletes anything inside the WordPress core directories: restoring files, restoring from a backup, the backup manager and the removal of extra files have all been removed.
* When core issues are found, the plugin now links to Dashboard > Updates > Reinstall now, which is WordPress's own repair mechanism.

= 4.2 =
* Renamed the plugin and prefixed every function, global, constant, option, transient, AJAX action and nonce with a unique plugin prefix.
* All inline scripts moved to enqueued files, with data passed through wp_localize_script().
* Updated the bundled Chart.js library to the latest stable release.
* Removed the load_plugin_textdomain() call and the bundled translation files: translations are handled by translate.wordpress.org.
* The top level admin menu no longer uses a prominent position.

= 4.1 =
* Documented in detail how the official package is used and which files can be written or deleted, and added a clearer warning when restoring from a backup: a backup reproduces the file as it was, so it does not guarantee clean content the way the official package does.

= 4.0 =
* Remaining Plugin Check fixes and renamed the plugin so that its name and slug no longer contain restricted terms.

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

= 5.3 =
New Plugin audit (updates, closed or abandoned plugins and optional vulnerability check) and Disk usage sections.

= 5.0 =
The integrity section becomes a read-only report; file restore, backups and extra file deletion have been removed.

= 4.2 =
Renaming and compliance changes requested by the plugin review team.

= 4.1 =
Documentation and clearer warnings. No change in behaviour.

= 3.9 =
Compliance fixes for the plugin directory review. No change in behaviour.

= 3.6 =
Recommended security update: fixes a stored XSS in the diff viewer and hardens several destructive operations.
