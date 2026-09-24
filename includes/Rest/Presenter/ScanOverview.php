<?php
/**
 * The Scan tab's hero, tiles, start bar and run history.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest\Presenter;

use LightweightPlugins\Scan\Admin\Settings\BundleInfo;
use LightweightPlugins\Scan\Admin\Settings\RunStatsView;
use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\ActiveRun;
use LightweightPlugins\Scan\Run\Cursor;

defined( 'ABSPATH' ) || exit;

/**
 * Everything `GET /scan` returns besides the progress and the feed. Every
 * number is read once in `build()`; `tiles()` and `starter()` are the pure
 * shaping steps behind it.
 */
final class ScanOverview {

	/** How many runs the history table lists. */
	private const RUNS_LIMIT = 10;

	/** The start bar's estimate assumes 30 ms of work per queued file. */
	private const MS_PER_FILE = 30;

	/**
	 * @param array<string, mixed> $counts `FindingsRepository::counts()` output (completed or raw).
	 * @return array{hero: array<string, mixed>, tiles: array<string, mixed>, starter: array<string, mixed>, runs: array<int, array<string, mixed>>}
	 */
	public static function build( array $counts ): array {
		$runs    = RunsRepository::latest( self::RUNS_LIMIT );
		$last    = $runs[0] ?? null;
		$files   = new FilesRepository();
		$version = BundleInfo::version();
		$queued  = $files->count_queue( $version );
		$options = Options::all();

		return [
			'hero'    => [
				'alerts'   => (int) ( $counts['state']['new']['alert'] ?? 0 ),
				'review'   => (int) ( $counts['state']['new']['review'] ?? 0 ),
				'last_run' => RunPresenter::summary( $last ),
				'bundle'   => BundleInfo::summary(),
			],
			'tiles'   => self::tiles( $files->stats(), $queued, RunStatsView::of( $last ), $options ),
			'starter' => self::starter( $options, ActiveRun::run_active( Cursor::load() ), $queued, $version ),
			'runs'    => array_map( [ RunPresenter::class, 'row' ], $runs ),
		];
	}

	/**
	 * @param array<string, mixed> $stats   `FilesRepository::stats()` output.
	 * @param int                  $queued  Files waiting for the next deep scan.
	 * @param RunStatsView         $run     The last run's stats.
	 * @param array<string, mixed> $options Plugin options.
	 * @return array<string, mixed>
	 */
	public static function tiles( array $stats, int $queued, RunStatsView $run, array $options ): array {
		$total      = (int) ( $stats['total'] ?? 0 );
		$known_good = (int) ( $stats['known_good'] ?? 0 );

		return [
			'files_total'    => $total,
			'queued'         => $queued,
			'known_good'     => $known_good,
			'known_good_pct' => round( ( $known_good / max( 1, $total ) ) * 100, 1 ),
			'deep_scanned'   => $run->files_scanned(),
			'deep_breakdown' => [
				'files'    => $run->files_scanned(),
				'db_rows'  => $run->db_rows(),
				'packages' => $run->software_checked(),
			],
			'next_due'       => self::next_due( $options ),
			'schedule'       => (string) ( $options['schedule'] ?? '' ),
			'schedule_scope' => (string) ( $options['scope'] ?? '' ),
		];
	}

	/**
	 * @param array<string, mixed> $options        Plugin options.
	 * @param bool                 $can_resume     Whether a stopped run's cursor is waiting.
	 * @param int                  $queued         Files waiting for the next deep scan.
	 * @param int                  $bundle_version Installed signature bundle, 0 for none.
	 * @return array<string, mixed>
	 */
	public static function starter( array $options, bool $can_resume, int $queued, int $bundle_version ): array {
		return [
			'scope'          => (string) ( $options['scope'] ?? 'changed' ),
			'heuristics'     => ! empty( $options['heuristics'] ),
			'can_resume'     => $can_resume,
			'queued'         => $queued,
			'estimate_ms'    => max( 0, $queued ) * self::MS_PER_FILE,
			'bundle_version' => $bundle_version,
		];
	}

	/**
	 * When the next scheduled scan is due; 0 while scheduled scans are off
	 * or nothing is scheduled yet.
	 *
	 * @param array<string, mixed> $options Plugin options.
	 */
	public static function next_due( array $options ): int {
		$due = (int) ( $options['next_due'] ?? 0 );

		return 'off' === (string) ( $options['schedule'] ?? '' ) || $due <= 0 ? 0 : $due;
	}
}
