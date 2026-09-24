<?php
/**
 * The run-progress + live-feed payload the Scan tab polls.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest\Presenter;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Run\Progress;

defined( 'ABSPATH' ) || exit;

/**
 * `payload()` is the thin, WP/DB-touching aggregator behind
 * `GET /scan/status` (and the `progress`/`feed` half of `GET /scan`);
 * `build_payload()` and `run_context()` behind it are pure shaping steps,
 * unit-testable without any stubs.
 *
 * It mirrors `Run\Runner::progress()`'s own cursor/run lookup
 * (`Progress::snapshot()` directly) rather than calling `progress()` and
 * separately re-loading the cursor and the run: that would read both twice
 * per poll for no reason. `new_since()` is likewise read once and reused
 * for both the `findings_new` count and the (sliced) feed.
 */
final class ScanStatus {

	/** Feed rows sent to the client, most recent first. */
	private const FEED_LIMIT = 20;

	/**
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$cursor = Cursor::load();
		$run    = null === $cursor ? RunsRepository::last() : RunsRepository::get( $cursor->run_id() );

		$started_at = Progress::started_at( $cursor, $run );
		$feed_rows  = $started_at > 0 ? FindingsRepository::new_since( $started_at ) : [];

		$progress = array_merge(
			Progress::snapshot( $cursor, $run, count( $feed_rows ) ),
			self::run_context( null === $cursor ? '' : $cursor->scope(), $run, Options::scope(), $started_at )
		);

		return self::build_payload( $progress, $feed_rows, FindingsRepository::counts(), $run );
	}

	/**
	 * Pure: the scope the progress refers to — the live cursor's, else the
	 * last run's, else the configured one — its phase list, and when that
	 * run started.
	 *
	 * @param string                    $cursor_scope Live cursor's scope, '' without one.
	 * @param array<string, mixed>|null $run          The run this progress reflects.
	 * @param string                    $fallback     Configured scope (`Options::scope()`).
	 * @param int                       $started_at   `Progress::started_at()`.
	 * @return array{scope: string, phases: array<int, string>, started_at: int}
	 */
	public static function run_context( string $cursor_scope, ?array $run, string $fallback, int $started_at ): array {
		$scope = $cursor_scope;

		if ( '' === $scope && null !== $run ) {
			$scope = (string) ( $run['scope'] ?? '' );
		}

		$scope  = '' === $scope ? $fallback : $scope;
		$phases = Phases::for_scope( $scope );

		return [
			'scope'      => $scope,
			'phases'     => [] === $phases ? Phases::ALL : $phases,
			'started_at' => $started_at,
		];
	}

	/**
	 * Pure: progress plus the feed, the counts and the last-run summary.
	 *
	 * @param array<string, mixed>             $progress  Snapshot plus `run_context()`.
	 * @param array<int, array<string, mixed>> $feed_rows Raw `FindingsRepository::new_since()` rows.
	 * @param array<string, mixed>             $counts    `FindingsRepository::counts()` output.
	 * @param array<string, mixed>|null        $last_run  The run this progress reflects.
	 * @return array<string, mixed>
	 */
	public static function build_payload( array $progress, array $feed_rows, array $counts, ?array $last_run ): array {
		return array_merge(
			$progress,
			[
				'feed'     => array_map( [ self::class, 'feed_row' ], array_slice( $feed_rows, 0, self::FEED_LIMIT ) ),
				'counts'   => FindingCounts::complete( $counts ),
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
	 * @param array<string, mixed>|null $run Run row.
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
