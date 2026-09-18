<?php
/**
 * Read model for "where does the run stand".
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a cursor plus a run row into the flat arrays `Runner::tick()` and
 * `Runner::progress()` hand to their callers (admin AJAX polling, WP-CLI,
 * the Abilities API). Pure shaping: no locking, no DB access, no writes.
 */
final class Progress {

	/** @var array<int, string> Run statuses that mean the run row is closed. */
	private const FINISHED_STATUSES = [ 'done', 'stopped', 'failed' ];

	/**
	 * The tick's return value.
	 *
	 * @param string      $status       One of idle|busy|running|stopped|finished|failed.
	 * @param Cursor|null $cursor       Cursor the numbers come from.
	 * @param int         $findings_new Findings first seen during this run.
	 * @param bool        $finished     Whether the pipeline reached its end.
	 * @return array{status:string, phase:string, done:int, total:int, findings_new:int, finished:bool, run_id:int}
	 */
	public static function tick( string $status, ?Cursor $cursor, int $findings_new, bool $finished ): array {
		return [
			'status'       => $status,
			'phase'        => null === $cursor ? '' : $cursor->phase(),
			'done'         => null === $cursor ? 0 : (int) $cursor->get( 'files_done', 0 ),
			'total'        => null === $cursor ? 0 : (int) $cursor->get( 'files_total', 0 ),
			'findings_new' => $findings_new,
			'finished'     => $finished,
			'run_id'       => null === $cursor ? 0 : $cursor->run_id(),
		];
	}

	/**
	 * The polling snapshot.
	 *
	 * @param Cursor|null               $cursor       Loaded cursor, if any.
	 * @param array<string, mixed>|null $run          The matching run row, if any.
	 * @param int                       $findings_new Findings first seen during that run.
	 * @return array{status:string, phase:string, done:int, total:int, findings_new:int, elapsed:int, run_id:int, last_tick_at:int}
	 */
	public static function snapshot( ?Cursor $cursor, ?array $run, int $findings_new ): array {
		$ended = (int) ( is_array( $run ) ? ( $run['finished_at'] ?? 0 ) : 0 );

		return [
			'status'       => self::status( $cursor, $run ),
			'phase'        => null !== $cursor ? $cursor->phase() : '',
			'done'         => null !== $cursor ? (int) $cursor->get( 'files_done', 0 ) : 0,
			'total'        => null !== $cursor ? (int) $cursor->get( 'files_total', 0 ) : 0,
			'findings_new' => $findings_new,
			'elapsed'      => self::elapsed( self::started_at( $cursor, $run ), $ended, null !== $cursor ),
			'run_id'       => null !== $cursor ? $cursor->run_id() : (int) ( is_array( $run ) ? ( $run['id'] ?? 0 ) : 0 ),
			'last_tick_at' => null !== $cursor ? (int) $cursor->get( 'last_tick_at', 0 ) : $ended,
		];
	}

	/**
	 * When the run being reported on started — the cursor knows for a live
	 * run, the row for a finished one.
	 *
	 * @param Cursor|null               $cursor Loaded cursor, if any.
	 * @param array<string, mixed>|null $run    The matching run row, if any.
	 */
	public static function started_at( ?Cursor $cursor, ?array $run ): int {
		if ( null !== $cursor ) {
			return $cursor->started_at();
		}

		return (int) ( is_array( $run ) ? ( $run['started_at'] ?? 0 ) : 0 );
	}

	/**
	 * `idle` with no run at all, the run's own status once its row is
	 * closed, `running` while a cursor is in flight.
	 *
	 * @param Cursor|null               $cursor Loaded cursor, if any.
	 * @param array<string, mixed>|null $run    The matching run row, if any.
	 */
	private static function status( ?Cursor $cursor, ?array $run ): string {
		$status = (string) ( is_array( $run ) ? ( $run['status'] ?? '' ) : '' );

		if ( in_array( $status, self::FINISHED_STATUSES, true ) ) {
			return $status;
		}

		return null === $cursor ? 'idle' : 'running';
	}

	/**
	 * @param int  $started    Run start timestamp.
	 * @param int  $ended      Run finish timestamp, or 0 while it runs.
	 * @param bool $has_cursor Whether a run is currently in progress.
	 */
	private static function elapsed( int $started, int $ended, bool $has_cursor ): int {
		if ( 0 === $started ) {
			return 0;
		}

		if ( $has_cursor || $ended <= 0 ) {
			return max( 0, time() - $started );
		}

		return max( 0, $ended - $started );
	}
}
