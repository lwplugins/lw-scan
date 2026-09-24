<?php
/**
 * Run rows as the React Scan tab shows them.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest\Presenter;

use LightweightPlugins\Scan\Admin\Settings\RunStatsView;
use LightweightPlugins\Scan\Run\RunError;

defined( 'ABSPATH' ) || exit;

/**
 * Two views of a `{prefix}lw_scan_runs` row (`stats` already decoded by
 * `RunsRepository`): the hero's "last run" summary and one line of the
 * run-history table. Timestamps stay Unix seconds; the client formats
 * them in the site timezone. `error_label` is `Run\RunError::label()`,
 * the same sentence the classic table printed.
 */
final class RunPresenter {

	/**
	 * The hero's last run, or null when nothing has run yet.
	 *
	 * @param array<string, mixed>|null $run Run row.
	 * @return array{id:int, trigger:string, scope:string, started_at:int, finished_at:int, duration:int|null, status:string}|null
	 */
	public static function summary( ?array $run ): ?array {
		if ( null === $run ) {
			return null;
		}

		return [
			'id'          => (int) ( $run['id'] ?? 0 ),
			'trigger'     => (string) ( $run['trigger_kind'] ?? '' ),
			'scope'       => (string) ( $run['scope'] ?? '' ),
			'started_at'  => (int) ( $run['started_at'] ?? 0 ),
			'finished_at' => (int) ( $run['finished_at'] ?? 0 ),
			'duration'    => self::duration( $run ),
			'status'      => (string) ( $run['status'] ?? '' ),
		];
	}

	/**
	 * One run-history row.
	 *
	 * @param array<string, mixed> $run Run row.
	 * @return array<string, mixed>
	 */
	public static function row( array $run ): array {
		$stats    = RunStatsView::of( $run );
		$measured = is_array( $run['stats'] ?? null ) ? $run['stats'] : [];

		return [
			'id'          => (int) ( $run['id'] ?? 0 ),
			'trigger'     => (string) ( $run['trigger_kind'] ?? '' ),
			'scope'       => (string) ( $run['scope'] ?? '' ),
			'scope_path'  => (string) ( $run['scope_path'] ?? '' ),
			'started_at'  => (int) ( $run['started_at'] ?? 0 ),
			'finished_at' => (int) ( $run['finished_at'] ?? 0 ),
			'status'      => (string) ( $run['status'] ?? '' ),
			'error_label' => RunError::label( (string) ( $run['error'] ?? '' ), $measured ),
			'indexed'     => $stats->files_indexed(),
			'scanned'     => $stats->files_scanned(),
			'alerts_new'  => $stats->alerts_new(),
			'review_new'  => $stats->review_new(),
		];
	}

	/**
	 * Seconds from start to finish; null while the run has not finished.
	 *
	 * @param array<string, mixed> $run Run row.
	 */
	public static function duration( array $run ): ?int {
		$finished = (int) ( $run['finished_at'] ?? 0 );

		if ( $finished <= 0 ) {
			return null;
		}

		return max( 0, $finished - (int) ( $run['started_at'] ?? 0 ) );
	}
}
