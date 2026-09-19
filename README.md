# LW Scan

Lightweight malware scanner for WordPress — files, database and vulnerable software. Reports only, never modifies your site.

## What it scans

- **Files** — every file under the WordPress root is matched against the signature pack: a hash layer for known-bad files, a literal layer and a regex layer. The content type comes from the file itself, not from its extension, so a shell named `logo.png` is still scanned as PHP.
- **PHP heuristics** (optional) — a token-based layer that recognises obfuscation, dynamic code execution and disguised uploaders that no signature has seen yet. It never returns `infected` on its own: a heuristic hit is `suspicious`, and only becomes an alert when the path it sits in argues for one (an executable under `uploads`, a PHP file disguised as an image). Every hit carries the reason it fired.
- **Integrity** — core, plugins and themes are compared to the official wordpress.org checksums. Unmodified files are known-good and skip the expensive layers: that is what makes the scan fast, and what keeps core files out of the findings list.
- **Database** — options, posts, post meta, user meta, users and database triggers, each row cut down by a LIKE pre-filter before the patterns run.
- **Vulnerable software** — installed plugins, themes and the WordPress version itself, matched against a vulnerability feed with the affected version ranges resolved properly.

Findings are reports, never actions: LW Scan does not edit, quarantine or delete a file or a database row. The only things it writes are its own three tables and its own directory, `wp-content/lw-scan` (the signature pack, checksum and vulnerability caches).

Signatures arrive as a compact pack from `scan-data.lwplugins.com` (`/v1/pack/latest` points at the current version; `/v1/pack/{v}` and `/v1/pack/{v}/meta` deliver it), stored as `wp-content/lw-scan/pack-<v>.json` and `meta-<v>.json` — plus a smaller `new-<v>.json` when only what changed since the last pack is needed. See "Measured performance" below for what the pack costs in memory.

## Install

With Composer:

```bash
composer require lwplugins/lw-scan
```

Or download the release ZIP from the [Releases page](https://github.com/lwplugins/lw-scan/releases) and install it through **Plugins → Add New → Upload Plugin**. The ZIP ships with its autoloader; a clone of this repository needs `composer install` before it will run.

Requirements: WordPress 6.0+, PHP 8.0+.

## Scheduling

Scans run on WP-Cron, hourly, daily or weekly, at an hour you choose (default: daily at 03:00). Two things keep that honest:

- **Catch-up** — on a site whose cron never fires (no visitors, a missed schedule, a migration), the first ordinary page load past the due time schedules the run itself and spawns cron.
- **Ticks** — a scan advances in ticks that stay inside the request's time budget, so it never runs into a timeout. It can be stopped from the admin or the terminal and resumed where it left off. If PHP runs out of memory mid-tick, the run is reported as a failed scan naming the `memory_limit` it hit.

Scopes: `changed` (the default — only what changed since the last scan), `full`, `db` (the database alone) and `path` (a single directory).

## WP-CLI

```bash
wp lw-scan run [<dir>] [--scope=<full|changed|db|path>] [--[no-]heuristics] [--resume] [--format=<table|json|csv>] [--quiet]
wp lw-scan status [--format=<table|json|csv>]
wp lw-scan findings [--severity=<alert|review>] [--type=<file|integrity|db|vulnerability>] [--state=<new|acknowledged|ignored>] [--page=<n>] [--per-page=<n>] [--format=<table|json|csv>]
wp lw-scan ack <id>...
wp lw-scan ignore <id>...
wp lw-scan reopen <id>...
wp lw-scan bundle <status|update|force-full> [--force] [--format=<table|json|csv>]
wp lw-scan index <rebuild|stats> [--force] [--format=<table|json|csv>]
wp lw-scan stop
```

`run` carries the whole pipeline in one foreground process instead of the admin's cron relay, and its exit code is the contract a cron job or a CI pipeline reads:

| exit code | meaning |
|---|---|
| 0 | the run finished and found nothing alerting |
| 1 | the run finished with at least one new alert-level finding |
| 2 | the scan could not start, or did not survive |

The scan directory is a positional argument because `--path` is one of WP-CLI's own global parameters and never reaches the command (unless `WP_CLI_STRICT_ARGS_MODE=1` is set); `--quiet` is a WP-CLI global too, and is honoured as documented.

```bash
wp lw-scan run --scope=full                       # everything, ignoring the change index
wp lw-scan run --scope=path wp-content/uploads    # one directory
wp lw-scan run --scope=db --format=json           # database only, machine-readable
wp lw-scan findings --severity=alert --state=new  # what still needs a decision
```

## Abilities and HelloPack

Four abilities are registered in the `lw-scan` category when the WordPress Abilities API is present, each behind a `manage_options` permission callback: `lw-scan/run` (starts a scan and returns its run id without blocking), `lw-scan/status`, `lw-scan/findings` and `lw-scan/acknowledge`. That is what lets LW Site Manager's MCP server — or any other Abilities client — drive the scanner.

On a site running HelloPack Client, LW Scan adds an `lw_scan` status check: new alerts, a failed last scan or scans that stopped running all surface there. Review-severity findings do not — they are mostly benign matches best triaged in the admin UI, not surfaced as a site-health warning. The check is read-only, makes no HTTP request, and its details carry no filesystem path.

## Status endpoint

The same `lw_scan` check, published for an external monitoring service — on sites without HelloPack Client too, in the same wire format. It only reads; it changes nothing. It is **on by default**, and the URL is on **LW Plugins → Scan → Status**:

```
GET /wp-json/lw-scan/v1/status/<key>
```

The 32-character key in the path is the only credential, so treat the URL as a secret. A wrong key gets exactly the `404 rest_no_route` answer WordPress gives for a URL that does not exist; while the endpoint is switched off, the route does not exist at all.

| query | effect |
|---|---|
| `?http_status=1` | answers `503` instead of `200` while `overall` is `crit` — for monitors that only read the status code |
| `?fresh=1` | runs the check now instead of reusing the stored result |

A computed result is reused for 5 minutes by default (1 to 60 minutes, set on the Status tab); `cached` says whether this answer came from it. Every answer carries `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`.

```json
{
  "overall": "crit",
  "checked_at": "2026-09-19T08:12:03+00:00",
  "cached": false,
  "site": { "url": "https://example.com", "wp": "7.1", "php": "8.3.30", "lw_scan": "1.0.0" },
  "checks": {
    "lw_scan": {
      "status": "crit",
      "summary": "2 new alerts.",
      "details": { "alerts_new": 2, "last_run_at": 1789370000, "last_status": "done", "bundle_version": 20260916045 },
      "checked_at": "2026-09-19T08:12:03+00:00",
      "duration_ms": 3
    }
  }
}
```

`status` (and `overall`) is `ok`, `warn`, `crit` or `unknown`, by the same rules as the HelloPack check: `crit` only for new alerts, `warn` for a failed last scan or scans that stopped running. `details` never carry a filesystem path.

To turn it off, switch **Status endpoint** off on the Status tab and save. **Generate new URL** replaces the key; the old URL stops working immediately.

## Privacy

Only package slugs (and versions for checksums). No file contents, hashes, paths or site URL.

Requests go to `scan-data.lwplugins.com`, operated by LW Plugins, for the signature bundle, for wordpress.org checksums and for vulnerability records — as plain `GET`s whose URL carries nothing but a package slug and, for checksums, its version. Each one also sends a `User-Agent: lw-scan/<plugin version>; WordPress/<wp version>` header, so your WordPress version travels with the request; that is the whole of it. There is no telemetry, no account and no site identifier of any kind. Requests happen on activation, while a scan runs, and when you press "Check for updates" or "Re-download full bundle" on the Health tab.

- Terms of service: <https://lwplugins.com/terms>
- Privacy policy: <https://lwplugins.com/privacy>

## Measured performance

Measured on a live demo site: WordPress 7.1, PHP 8.3 under PHP-FPM with a
fixed `memory_limit` of 256M (PHP 8.4 on the CLI), 45,796 files indexed,
4,365 of them not covered by a checksum list and therefore scanned in full,
signature pack version 20260916045 with 13,210 signatures.

| what | measured |
|---|---|
| full scan, browser path (5 ticks under FPM, cold index) | 114 s |
| full scan, WP-CLI (warm index) | 64 s |
| changed scan, WP-CLI | 4.2 s |
| peak memory per tick, FPM, full scan | 206 MB of the 256 MB limit |
| peak memory per tick, WP-Cron, changed scan | 182 MB |
| signature pack in memory | +4.0 MB |
| pack meta (rule ids, names, categories) in memory | +2.0 MB |
| transient peak while both are decoded | 6.9 MB above the request's own footprint |

The pack is read once per process and costs 4.0 MB; its meta half is only
read when a finding has to be named, and adds 2.0 MB more. Decoding both
peaks 6.9 MB above what the request had already allocated — on this site
that is 173 MB in a front-end request whose own bootstrap is 166 MB.

A scan tick is the expensive part, not the pack: the whole pipeline stays
50 MB inside a 256 MB limit while scanning, which is why the ticks finish
instead of being killed.

## Development

```bash
composer install
composer phpcs      # WordPress Coding Standards
composer analyse    # PHPStan level 5
composer test       # PHPUnit
```

### Releasing

The plugin ships `checksums.json`, a manifest of the md5 of every file it
installs. The scanner treats its own files as known-good only when they match
that manifest, so it does not report its decoder sources as infected — and a
tampered copy of the plugin is still scanned.

Regenerate it whenever a shipped file or the version changes, and commit the
result:

```bash
composer checksums         # rewrite checksums.json
composer checksums:check   # exits 1 when it is stale (CI runs this)
```

## License

GPL-2.0-or-later.
