<?php
/**
 * `lw_scan_status` — the run-progress + live-feed payload the Scan tab polls.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Progress;

defined( 'ABSPATH' ) || exit;

/**
 * `payload()` is the thin, WP/DB-touching aggregator the AJAX action calls;
 * `build_payload()` behind it is the pure shaping step — no WP functions, no
 * `$wpdb` access — so the feed/counts/last_run shape is unit-testable
 * without any Brain Monkey stubs (see StatusPayloadTest).
 *
 * Without `feed`, `assets/js/admin.js`'s live findings feed freezes at
 * whatever the page load found (task-21-report.md); it has to carry the
 * last 20 rows of `FindingsRepository::new_since($started_at)`, each
 * shaped to `{severity, type, locator, tier, signature, reason}`.
 *
 * `payload()` mirrors `Run\Runner::progress()`'s own cursor/run lookup
 * (`Progress::snapshot()` directly) rather than calling `progress()` and
 * separately re-loading the cursor and the last run: the two would
 * otherwise read `Cursor::load()` and the run row twice per poll for no
 * reason. `new_since()` is likewise read once and reused for both the
 * `findings_new` count and the (sliced) feed.
 */
final class StatusHandler {

	use AjaxGuardTrait;

	/** Feed rows sent to the client, most recent first (spec/task-21 contract). */
	private const FEED_LIMIT = 20;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_status', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();

		wp_send_json_success( self::payload() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$cursor = Cursor::load();
		$run    = null === $cursor ? RunsRepository::last() : RunsRepository::get( $cursor->run_id() );

		$started_at = Progress::started_at( $cursor, $run );
		$feed_rows  = $started_at > 0 ? FindingsRepository::new_since( $started_at ) : [];

		$progress = Progress::snapshot( $cursor, $run, count( $feed_rows ) );
		$counts   = FindingsRepository::counts();

		return self::build_payload( $progress, $feed_rows, $counts, $run );
	}

	/**
	 * Pure: turns a `Progress::snapshot()`/`Progress::tick()`-shaped array
	 * plus the raw repository reads into the flat payload `lw_scan_status`
	 * sends the client.
	 *
	 * @param array<string, mixed>             $progress  `Progress::snapshot()` (or `Runner::progress()`) output.
	 * @param array<int, array<string, mixed>> $feed_rows Raw `FindingsRepository::new_since()` rows.
	 * @param array<string, mixed>             $counts    `FindingsRepository::counts()` output.
	 * @param array<string, mixed>|null        $last_run  The run this progress reflects (`RunsRepository::get()`/`::last()` output).
	 * @return array<string, mixed>
	 */
	public static function build_payload( array $progress, array $feed_rows, array $counts, ?array $last_run ): array {
		return array_merge(
			$progress,
			[
				'feed'     => array_map( [ self::class, 'feed_row' ], array_slice( $feed_rows, 0, self::FEED_LIMIT ) ),
				'counts'   => $counts,
				'last_run' => self::last_run_summary( $last_run ),
			]
		);
	}

	/**
	 * @param array<string, mixed> $row Raw findings-table row.
	 * @return array{severity:string, type:string, locator:string, tier:string, signature:string, reason:string}
	 */
	private static function feed_row( array $row ): array {
		$signature_ids = Finding::decode_list( (string) ( $row['signature_ids'] ?? '[]' ) );

		return [
			'severity'  => (string) ( $row['severity'] ?? '' ),
			'type'      => (string) ( $row['type'] ?? '' ),
			'locator'   => (string) ( $row['locator'] ?? '' ),
			'tier'      => (string) ( $row['tier'] ?? '' ),
			'signature' => (string) ( $signature_ids[0] ?? '' ),
			'reason'    => (string) ( $row['reason'] ?? '' ),
		];
	}

	/**
	 * @param array<string, mixed>|null $run `RunsRepository::last()` output.
	 * @return array{id:int, status:string, started_at:int, finished_at:int}|null
	 */
	private static function last_run_summary( ?array $run ): ?array {
		if ( null === $run ) {
			return null;
		}

		return [
			'id'          => (int) ( $run['id'] ?? 0 ),
			'status'      => (string) ( $run['status'] ?? '' ),
			'started_at'  => (int) ( $run['started_at'] ?? 0 ),
			'finished_at' => (int) ( $run['finished_at'] ?? 0 ),
		];
	}
}
