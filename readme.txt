=== LW Scan ===
Contributors: lwplugins
Tags: security, malware, scanner, antivirus, vulnerability
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
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
* Email notifications and an admin notice for new alerts, plus a warning when scheduled scans keep failing
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

= Does it remove or repair what it finds? =

No. LW Scan reports, and stops there. Deciding what to do with an infected file is yours to make, and an automatic cleanup that guesses wrong takes a site down harder than the infection did.

= Will it slow down my site? =

Scans run in the background, in ticks that stay inside the request's time budget, and the default scope only looks at files that changed since the last scan. If PHP runs out of memory mid-tick, the run is reported as a failed scan naming the memory_limit it hit. On an ordinary page load the plugin reads nothing but options WordPress has already loaded for the page — it runs no database query of its own.

= Can I run it from the command line? =

Yes. `wp lw-scan run` runs a whole scan in one foreground process and exits 0 when it found nothing alerting, 1 when it found a new alert and 2 on an error — which is what a cron job or a CI pipeline wants. `wp lw-scan status`, `findings`, `ack`, `ignore`, `reopen`, `bundle`, `index` and `stop` cover the rest.

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
* New: A Status tab and a secret-keyed, read-only status endpoint (`/wp-json/lw-scan/v1/status/<key>`) that publishes the `lw_scan` status check for external monitoring, on by default.
* New: Uninstall removes the options, the scan tables and the `wp-content/lw-scan` directory.
