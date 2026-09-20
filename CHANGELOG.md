# Changelog

## [1.3.0] - 2026-09-20

### Added
- A Notifications tab: the e-mail switch (Off / New alerts only / New alerts + review items), the recipients, a per-e-mail item cap (10 / 20 / 50 / All), the admin notice and a "Send a test e-mail" button.
- `wp lw-scan notify` WP-CLI command — `status`, `enable`, `disable`, `level`, `recipients`, `limit` and `test`.

### Changed
- E-mail can be switched off entirely. Off stops the failure-streak warning too.
- A new-findings e-mail lists at most 20 items by default and links to the Findings tab for the rest; the subject still carries the true total.
- The admin notice is off by default; switch it on from the Notifications tab. A site that already saved the setting keeps what it chose.
- The first completed scan on a site is a baseline: it records what is already there and sends no e-mail. A site that has scanned before keeps mailing exactly as it did.
- The recipients field says which address is used when it is left empty.
- The notification settings moved off the Settings tab onto the new one.

## [1.2.1] - 2026-09-19

### Changed
- The distributed ZIP no longer carries Composer's generated autoloader or the install-time `composer/installers` package — the plugin now ships its own small PSR-4 autoloader. Composer installs are unaffected.

## [1.2.0] - 2026-09-19

### Added
- `wp lw-scan endpoint` WP-CLI command — `url`, `enable`, `disable`, `rotate`, `ttl` and `status` for the status endpoint from the terminal.

## [1.1.0] - 2026-09-19

### Added
- A Status tab and a secret-keyed, read-only status endpoint (`/wp-json/lw-scan/v1/status/<key>`) that publishes the `lw_scan` status check for external monitoring, on by default.

## [1.0.0] - 2026-09-17

### Added
- File scanning with a compact signature pack — hash, literal and regex layers, with the content type detected from the file itself.
- Signatures arrive as a compact pack built by the signature service; scanning keeps them in about 6 MB of memory (4 MB for the pack, 2 MB more once a finding has to be named).
- Optional PHP heuristic layer for obfuscation, dynamic execution and disguised uploaders.
- Integrity checking against the official wordpress.org checksums, so unmodified core, plugin and theme files skip the expensive layers.
- Database scanning of options, posts, post meta, user meta, users and database triggers.
- Vulnerability matching for installed plugins, themes and WordPress core.
- Scheduled scans (hourly, daily, weekly) with catch-up for missed schedules, and a resumable, budget-aware runner.
- Admin screen with Scan, Findings, Settings and Health tabs, live progress and acknowledge / ignore / reopen actions.
- Email notification and admin notice for new alerts, plus a failure-streak warning for scheduled scans.
- WP-CLI commands: run, status, findings, ack, ignore, reopen, bundle, index, stop.
- Four `lw-scan/*` abilities for the WordPress Abilities API and an `lw_scan` HelloPack status check.
- Uninstall removes the options, the scan tables and the `wp-content/lw-scan` directory.
