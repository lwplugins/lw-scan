=== LW Scan ===
Contributors: lwplugins
Tags: security, malware, scanner, antivirus, vulnerability
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.4.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight malware scanner for WordPress — files, database and vulnerable software. Reports only, never modifies your site.

== Description ==

LW Scan is a lightweight malware scanner for WordPress. It scans your files, your database and your installed software for known threats and vulnerabilities and reports what it finds — it never modifies, quarantines or deletes anything on your site.

**What it scans:**

* Files — every file under the WordPress root is matched against the signature pack: a hash layer for known-bad files, a literal layer and a regex layer, with the content type taken from the file itself instead of its extension
* PHP heuristics — an optional token-based layer that recognises obfuscation, dynamic code execution and disguised uploaders no signature has seen yet
* Integrity — plugins, themes and core are checked against the official wordpress.org checksums; unmodified files are known-good and skip the expensive layers, which is both what makes the scan fast and what keeps core files out of your findings list
* Database — options, posts, post meta, user meta, users and database triggers, each row pre-filtered before the patterns run
* Vulnerable software — installed plugins, themes and the WordPress version itself matched against a vulnerability feed, with the affected version ranges resolved properly

**How it runs:**

* Scheduled scans: hourly, daily or weekly at an hour you pick, with a catch-up run when a schedule was missed (a site with no visitors and no cron is not silently unscanned)
* Scopes: changed files only (the default), a full scan, the database alone, or a single directory
* Resumable and time-budget-aware — a scan advances in ticks that respect the request's time budget, can be stopped from the admin or the terminal, and picks up where it left off; if PHP runs out of memory mid-tick, the run is reported as a failed scan naming the memory_limit it hit
* Findings workflow: acknowledge, ignore or reopen findings; only new ones are reported again
* Email notifications and an admin notice for new alerts, plus a warning when scheduled scans keep failing — all on a Notifications tab where e-mail (off by default on a new install) can be switched on or off, capped at 10, 20 or 50 items per message, and sent to a list of addresses you choose
* A Health tab that checks the things a scanner depends on: storage, signature bundle, backend, PCRE limits, memory, tokenizer, WP-Cron and the plugin's own tables
* A read-only status endpoint for external monitoring, on by default: a secret URL that answers with the scan status as JSON (see the FAQ)

**Reports only.** LW Scan never edits, quarantines or deletes a file or a database row. What it changes on your site is its own three tables and its own directory under wp-content, nothing else.

**No tracking, no upsell.** LW Scan sends nothing about your site anywhere: no site URL, no file contents and no paths. See the FAQ below for exactly what it requests from our service. The status endpoint (on by default) sends nothing either — it only answers requests that carry its secret key, with the details listed in the FAQ.

**Also available from the terminal and from an agent:** a full `wp lw-scan` WP-CLI command set, four `lw-scan/*` abilities for the WordPress Abilities API, and an `lw_scan` status check for HelloPack Client.

== Installation ==

1. Install the plugin through the WordPress plugins screen, or with Composer: `composer require lwplugins/lw-scan`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Open **LW Plugins → Scan** and press Start scan. The first scan downloads the signature bundle and builds the file index, so it takes longer than the scans after it.

== Frequently Asked Questions ==

= What is sent to your servers? =

Only package slugs (and versions for checksums). No file contents, hashes, paths or site URL.

The service is `scan-data.lwplugins.com`, operated by LW Plugins. Three kinds of request reach it: the signature bundle, the wordpress.org checksum list for an installed plugin, theme or WordPress core, and the vulnerability records for the same packages. Each is a plain `GET` whose URL carries nothing but a package slug and, for checksums, that package's version. Every request also sends a `User-Agent: lw-scan/<plugin version>; WordPress/<WordPress version>` header, so those two version numbers travel with it — that is the whole of it. No telemetry, no account, no site identifier of any kind, and nothing is ever sent back about what a scan found.

Requests happen when the plugin is activated, while a scan runs, and when you press "Check for updates" or "Re-download full bundle" on the Health tab. Nothing is sent in between.

Terms of service: https://lwplugins.com/terms
Privacy policy: https://lwplugins.com/privacy

= Why did the first scan not e-mail me? =

Because it is a baseline. The first scan on a site that has been running for a while reports everything already there: every vulnerable plugin, every library that uses dynamic code, every core file somebody once edited. That is a list to read through once, not an incident to be mailed about, so the first completed scan records its findings and sends nothing — the Notifications tab says so, as does the admin notice when it is switched on. Every scan after it mails what is new, as configured.

A site that was already scanning before this behaviour existed is not affected: it has taken its baseline long ago and keeps mailing exactly as it did.

= The scan e-mails are too long, or I do not want them at all =

**LW Plugins → Scan → Notifications.** "Send e-mail" has three settings — Off, new alerts only, or new alerts plus review items — and Off means no scan e-mail at all, the warning about repeatedly failing scheduled scans included. A new install starts at Off; a site that was already mailing keeps doing so. "Maximum items per e-mail" caps the body at 10, 20 or 50 items; the rest is one line linking to the Findings tab, and the subject still carries the true total. Leave Recipients empty and mail goes to the site's admin address — the tab names it, so it is never a surprise.

= Does it remove or repair what it finds? =

No. LW Scan reports, and stops there. Deciding what to do with an infected file is yours to make, and an automatic cleanup that guesses wrong takes a site down harder than the infection did.

= Will it slow down my site? =

Scans run in the background, in ticks that stay inside the request's time budget, and the default scope only looks at files that changed since the last scan. If PHP runs out of memory mid-tick, the run is reported as a failed scan naming the memory_limit it hit. On an ordinary page load the plugin reads nothing but options WordPress has already loaded for the page — it runs no database query of its own.

= Can I run it from the command line? =

Yes. `wp lw-scan run` runs a whole scan in one foreground process and exits 0 when it found nothing alerting, 1 when it found a new alert and 2 on an error — which is what a cron job or a CI pipeline wants. `wp lw-scan status`, `findings`, `ack`, `ignore`, `reopen`, `bundle`, `index` and `stop` cover the rest. `wp lw-scan endpoint` manages the status endpoint below (its URL, switch, key and reuse period) from the terminal, and `wp lw-scan notify` does the same for the e-mail settings — `status`, `enable`, `disable`, `level`, `recipients`, `limit` and `test`.

= Can an external monitoring service check the scan status? =

Yes. The Status tab gives you a secret URL, `/wp-json/lw-scan/v1/status/<key>`, that answers with the scanner's status as JSON. It is on by default. It only reads and changes nothing, and it sends nothing anywhere: it only answers requests that carry its key.

An answer contains the site's home URL (`site.url`); its WordPress, PHP and LW Scan versions; and the one `lw_scan` check: its status (`ok`, `warn`, `crit` for new alerts, or `unknown`), a one-sentence summary, the number of new alerts, when the last scan ran and how it ended, the signature bundle version (`bundle_version`), and when the check ran and how long it took. Never a file path.

Add `?http_status=1` to get HTTP 503 while there are new alerts, or `?fresh=1` to skip the stored result. The key in the URL is its only protection: switch the endpoint off on the Status tab, or press "Generate new URL" to replace the key — the old URL stops working at once.

A plugin that restricts the REST API to logged-in users (LW Disable's REST API restriction, for example) makes the endpoint answer 401 to your monitor; the endpoint only works with that restriction off.

= Does it work on multisite? =

The plugin activates and scans files on multisite, but the database scan covers the site the request runs on — sub-sites are not iterated.

= What happens when I delete the plugin? =

Everything goes: the settings, the run state, the three scan tables and the `wp-content/lw-scan` directory with the signature bundle and its caches. The cleanup covers the site it runs on, so a network install may leave per-site options behind. A re-install starts from a clean scan.

== Changelog ==

= 1.4.5 =
* Change: E-mail notifications are off by default on a new install; switch them on from the Notifications tab or with `wp lw-scan notify enable`. A site that was already mailing keeps doing so, including an older install that never saved the setting.

= 1.4.4 =
* Fix: newly published vulnerabilities could take up to a day to show up: each plugin's, theme's and core's vulnerability list was cached for 24 hours. The cache now lasts one hour, matching the backend's hourly sync.
* Fix: starting a scan by hand (Scan tab, WP-CLI or the Site Manager ability) now drops the cached vulnerability lists first, so a manual scan always checks against the latest data. Scheduled scans keep using the hourly cache.
* Fix: a vulnerability finding's details showed the Wordfence Intelligence copyright notice but not the license text the record is shared under, which the license requires every copy to include. The details now show the notice and the license verbatim from the record, with a link to the license terms. The record link is now labelled "Vulnerability record".
* Fix: wp lw-scan findings and wp lw-scan run now carry the Wordfence Intelligence attribution for vulnerability findings: JSON rows get reference, copyright, license and license_url fields; table and CSV rows get a reference column, and the copyright notice and license text are printed once after the output (after a table on STDOUT, after CSV on STDERR so the CSV still parses).
* Fix: the notification e-mail's line for a vulnerability no longer quotes the vulnerability record's title. It now names the package, the installed version and the version to update to ("Elementor Website Builder 4.3.0: known vulnerability, update to 4.3.2", or "no fixed version yet"), and points to the Findings tab for details and sources. The e-mail carries no third-party record or license text.

= 1.4.3 =
* Fix: notices from themes and other plugins (for example a theme's purchase-code or recommended-plugins notice) showed above the LW Scan screen when no other LW plugin was active. They are now kept off every LW Plugins screen, whatever their markup.

= 1.4.2 =
* New: Hungarian (hu_HU) translation, and a fresh lw-scan.pot template covering the React admin.
* Fix: The React admin now loads its translations from the plugin's own languages folder, so it is no longer left in English.
* Fix: The Health tab's checks list and the Scan tab's live feed show label, detail and status in aligned columns instead of running together.
* Fix: The status badges in the checks list are translatable (OK, Warning, Critical, Info) instead of the raw status code.

= 1.4.1 =
* Fix: The admin screen fills the whole content area again instead of sitting inside the core .wrap margins.

= 1.4.0 =
* New: A new admin interface built with WordPress's own React components — side navigation, a top bar with Save (and Cmd/Ctrl+S), loading placeholders and a phone-friendly layout.
* New: The Scan tab follows a running scan live and starts, stops and resumes without page reloads.
* New: The Findings tab loads page by page with filter chips, search, per-row and bulk actions and a details window.
* New: A confirmation before rebuilding the file index.
* New: A REST API (lw-scan/v1, administrators only) behind the new interface.
* Fix: Saving one settings tab no longer empties the notification recipients or the excluded paths of the other.
* Change: The admin notice is off by default; switch it on from the Notifications tab. A site that already saved the setting keeps what it chose.
* Change: `wp lw-scan notify` has a one-line description in `wp help`.
* Change: The classic admin screens are replaced; links to the old ?tab= addresses keep working.

= 1.3.0 =
* New: A Notifications tab with an e-mail off switch, the recipients, a per-e-mail item cap and a "Send a test e-mail" button.
* New: `wp lw-scan notify` WP-CLI command — status, enable, disable, level, recipients, limit and test.
* Change: E-mail can be switched off entirely; off stops the failure-streak warning too.
* Change: A new-findings e-mail lists at most 20 items by default and links to the Findings tab for the rest; the subject keeps the true total.
* Change: The first completed scan on a site is a baseline — it records what is already there and sends no e-mail. A site that has scanned before keeps mailing as it did.
* Change: The recipients field says which address is used when it is left empty.

= 1.2.1 =
* Update: The distributed ZIP no longer carries Composer's generated autoloader or the install-time `composer/installers` package — the plugin now ships its own small PSR-4 autoloader. Composer installs (`composer require lwplugins/lw-scan`) are unaffected.

= 1.2.0 =
* New: `wp lw-scan endpoint` WP-CLI command — `url`, `enable`, `disable`, `rotate`, `ttl` and `status` for the status endpoint from the terminal.

= 1.1.0 =
* New: A Status tab and a secret-keyed, read-only status endpoint (`/wp-json/lw-scan/v1/status/<key>`) that publishes the `lw_scan` status check for external monitoring, on by default.

= 1.0.0 =
* New: File scanning with a compact signature pack — hash, literal and regex layers, with the content type detected from the file itself.
* New: Signatures arrive as a compact pack built by the signature service; scanning keeps them in about 6 MB of memory (4 MB for the pack, 2 MB more once a finding has to be named).
* New: Optional PHP heuristic layer for obfuscation, dynamic execution and disguised uploaders.
* New: Integrity checking against the official wordpress.org checksums, so unmodified core, plugin and theme files skip the expensive layers.
* New: Database scanning of options, posts, post meta, user meta, users and database triggers.
* New: Vulnerability matching for installed plugins, themes and WordPress core.
* New: Scheduled scans (hourly, daily, weekly) with catch-up for missed schedules, and a resumable, budget-aware runner.
* New: Admin screen with Scan, Findings, Settings and Health tabs, live progress and acknowledge / ignore / reopen actions.
* New: Email notification and admin notice for new alerts, plus a failure-streak warning for scheduled scans.
* New: WP-CLI commands: run, status, findings, ack, ignore, reopen, bundle, index, stop.
* New: Four `lw-scan/*` abilities for the WordPress Abilities API and an `lw_scan` HelloPack status check.
* New: Uninstall removes the options, the scan tables and the `wp-content/lw-scan` directory.
