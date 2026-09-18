<?php
/**
 * Whole-environment health report: the Health tab, `wp lw-scan status`, and
 * the "can a scan start?" gate the Runner consults before opening a run.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health;

use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Health\Checks\BackendCheck;
use LightweightPlugins\Scan\Health\Checks\BundleCheck;
use LightweightPlugins\Scan\Health\Checks\CheckInterface;
use LightweightPlugins\Scan\Health\Checks\CronCheck;
use LightweightPlugins\Scan\Health\Checks\MemoryCheck;
use LightweightPlugins\Scan\Health\Checks\PcreCheck;
use LightweightPlugins\Scan\Health\Checks\StorageCheck;
use LightweightPlugins\Scan\Health\Checks\TablesCheck;
use LightweightPlugins\Scan\Health\Checks\TokenizerCheck;
use LightweightPlugins\Scan\Remote\ChecksumProvider;
use LightweightPlugins\Scan\Remote\FileCache;
use LightweightPlugins\Scan\Remote\VulnerabilityProvider;
use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §12: `report()` runs every check and caches the whole result in the
 * `lw_scan_health` transient for 10 minutes (`$fresh` bypasses the cache —
 * used when the Health tab is opened) — every row but `memory`, which is
 * measured again on each call and merged into whatever was cached, because
 * it describes the request asking rather than the site (see `LIVE_ROW`). `CronCheck`'s loopback probe is
 * gated on that same `$fresh` flag (`probe_allowed()`), not on whether the
 * transient happened to be cold: any caller that builds a report with
 * `$fresh = false` — e.g. a background read that just missed the cache —
 * must never fire an HTTP request as a side effect; only an explicit
 * `report( true )` may. `blocking_issue()` is the narrower, always-fresh
 * gate `Run\Starter` calls before opening a run (spec §14): storage, bundle
 * availability and the plugin's own tables are what can block a scan, so it
 * runs just those three checks, never the cached/full set.
 *
 * `checks()` and `tiles()` are protected so a test subclass can inject
 * fake checks and a canned tiles array while exercising the real
 * `report()`/verdict-aggregation logic via late static binding
 * (`new static()`).
 */
class Environment {

	private const TRANSIENT = 'lw_scan_health';

	private const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * The one row whose answer belongs to the request that asks. Everything
	 * else a check looks at — the bundle, the storage directory, the tables,
	 * the PCRE build — is a property of the site and keeps for ten minutes.
	 * Memory is not: the same site reports 256M to an admin page and no
	 * limit at all to WP-CLI, so a cached memory row describes whichever
	 * request happened to fill the cache.
	 */
	private const LIVE_ROW = 'memory';

	/**
	 * Final on purpose: `report()` builds its working instance with
	 * `new static()` so a test subclass can override `checks()`/`tiles()`;
	 * a `final` no-arg constructor guarantees no subclass can make that
	 * unsafe by requiring constructor arguments.
	 */
	final public function __construct() {
	}

	/**
	 * @param bool $fresh Bypass the transient cache and re-run every check.
	 * @return array{verdict:string, rows:array<int,array{id:string,label:string,status:string,message:string,blocking:bool,details?:array<string,mixed>}>, tiles:array<string,mixed>}
	 */
	public static function report( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return self::with_live_row( $cached );
			}
		}

		$environment = new static();
		$rows        = array_map( [ $environment, 'row' ], $environment->checks( self::probe_allowed( $fresh ) ) );

		$report = [
			'verdict' => self::verdict( $rows ),
			'rows'    => $rows,
			'tiles'   => $environment->tiles(),
		];

		set_transient( self::TRANSIENT, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * Re-measures `LIVE_ROW` and merges it into a cached report, verdict and
	 * all. A subclass whose checks do not include that row (the test
	 * doubles) gets its cached report back untouched.
	 *
	 * @param array<string, mixed> $report The cached report.
	 * @return array<string, mixed> The same report with a freshly measured row.
	 */
	private static function with_live_row( array $report ): array {
		$live = self::live_row();
		$rows = isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : [];

		if ( null === $live || [] === $rows ) {
			return $report;
		}

		foreach ( $rows as $index => $row ) {
			if ( self::LIVE_ROW === (string) ( is_array( $row ) ? ( $row['id'] ?? '' ) : '' ) ) {
				$rows[ $index ] = $live;
			}
		}

		$report['rows']    = $rows;
		$report['verdict'] = self::verdict( $rows );

		return $report;
	}

	/**
	 * @return array<string, mixed>|null The live row, or null when this
	 *                                   environment has no such check.
	 */
	private static function live_row(): ?array {
		$environment = new static();

		foreach ( $environment->checks( false ) as $check ) {
			if ( self::LIVE_ROW === $check->id() ) {
				return $environment->row( $check );
			}
		}

		return null;
	}

	/**
	 * Drops the cached report. Called by anything that fixes (or breaks) a
	 * checked condition — schema install, bundle download, index rebuild —
	 * so a user who just solved a blocking issue is not told for another ten
	 * minutes that it is still there.
	 *
	 * @return void
	 */
	public static function invalidate(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Whether `CronCheck` may fire its loopback probe: only for an
	 * explicitly fresh report. Pulled out as its own pure method (rather
	 * than inlining `$fresh` into `checks()`'s call site) so the "no probe
	 * unless fresh" rule is independently testable without having to
	 * construct every real check.
	 *
	 * @param bool $fresh The `report()` call's own `$fresh` argument.
	 */
	public static function probe_allowed( bool $fresh ): bool {
		return $fresh;
	}

	/**
	 * Spec §14: a storage directory that isn't writable, or a bundle that's
	 * missing and can't be (re)loaded, stops a run from starting. So does a
	 * missing table, which the spec takes for granted rather than lists: the
	 * run row is written before any scanning happens, and a run with no row
	 * has nowhere to record what it found. `Schema::missing_tables()` is
	 * three `SHOW TABLES` probes and `TablesCheck` adds three
	 * `SHOW TABLE STATUS` reads on a healthy site — six queries, once, on an
	 * explicit "start a scan", which is not a hot path. Running the same
	 * check the Health tab renders is what keeps the tab and the gate from
	 * ever disagreeing about what counts as installed.
	 *
	 * Always fresh — never reads or writes the transient.
	 *
	 * @return string|null The first blocking row's message, or null.
	 */
	public static function blocking_issue(): ?string {
		foreach ( [ new StorageCheck(), new BundleCheck(), new TablesCheck() ] as $check ) {
			$result = $check->run();

			if ( $result['blocking'] ) {
				return $result['message'];
			}
		}

		return null;
	}

	/**
	 * The two crontab lines the Health tab offers to copy when
	 * `DISABLE_WP_CRON` is set: a direct scan trigger, and a WP-Cron
	 * catch-up runner so queued events still fire without site traffic.
	 *
	 * @return string[]
	 */
	public static function cron_command_lines(): array {
		$abspath = rtrim( ABSPATH, '/\\' );

		return [
			sprintf( '0 3 * * * cd %s && wp lw-scan run --scope=changed --quiet', $abspath ),
			sprintf( '*/15 * * * * cd %s && wp cron event run --due-now --quiet', $abspath ),
		];
	}

	/**
	 * @param bool $probe_cron Whether `CronCheck` may fire its loopback probe.
	 * @return CheckInterface[]
	 */
	protected function checks( bool $probe_cron ): array {
		return [
			new BundleCheck(),
			new BackendCheck(),
			new StorageCheck(),
			new PcreCheck(),
			new MemoryCheck(),
			new TokenizerCheck(),
			new CronCheck( $probe_cron ),
			new TablesCheck(),
		];
	}

	/**
	 * @return array{bundle:array{version:int,count:int,checked_at:int}, checksums:int, vuln:int}
	 */
	protected function tiles(): array {
		$store = new Store();

		return [
			'bundle'    => [
				'version'    => (int) State::get( 'bundle_version', 0 ),
				// Read back from what Remote\PackFetcher recorded when it
				// persisted the pack, not counted afresh: counting meant
				// gunzipping and json_decoding the 5 MB source file on an
				// ordinary admin request, for a number that cannot change
				// until the next download writes it here anyway.
				'count'      => (int) State::get( 'bundle_count', 0 ),
				'checked_at' => (int) State::get( 'bundle_checked_at', 0 ),
			],
			'checksums' => $this->count_cache_files( $store, ChecksumProvider::CACHE_PREFIX ),
			'vuln'      => $this->count_cache_files( $store, VulnerabilityProvider::CACHE_PREFIX ),
		];
	}

	/**
	 * Counts one family of cached backend responses. Asks `FileCache`
	 * rather than globbing the directory again: it owns how a key prefix
	 * maps onto filenames, and two implementations of that mapping would
	 * eventually disagree.
	 *
	 * @param Store  $store  Storage directory owner.
	 * @param string $prefix Cache key prefix, e.g. `ChecksumProvider::CACHE_PREFIX`.
	 */
	private function count_cache_files( Store $store, string $prefix ): int {
		return count( ( new FileCache( $store->cache_dir() ) )->files( $prefix ) );
	}

	/**
	 * @param CheckInterface $check Check to run.
	 * @return array{id:string,label:string,status:string,message:string,blocking:bool,details?:array<string,mixed>}
	 */
	private function row( CheckInterface $check ): array {
		$result = $check->run();

		$row = [
			'id'       => $check->id(),
			'label'    => $check->label(),
			'status'   => $result['status'],
			'message'  => $result['message'],
			'blocking' => $result['blocking'],
		];

		if ( isset( $result['details'] ) ) {
			$row['details'] = $result['details'];
		}

		return $row;
	}

	/**
	 * @param array<int, array{status:string}> $rows Report rows.
	 */
	private static function verdict( array $rows ): string {
		$statuses = array_column( $rows, 'status' );

		if ( in_array( 'critical', $statuses, true ) ) {
			return 'critical';
		}

		if ( in_array( 'warning', $statuses, true ) ) {
			return 'warning';
		}

		return 'ok';
	}
}
