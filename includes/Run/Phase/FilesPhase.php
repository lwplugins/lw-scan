<?php
/**
 * Pipeline phase: content-scan the queued files.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Index\PathSignals;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\IncrementalScan;
use LightweightPlugins\Scan\Run\Runner;
use LightweightPlugins\Scan\Scanner\FileFinding;
use LightweightPlugins\Scan\Scanner\ScanOutcome;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls the content-scan queue 50 rows at a time and runs each file through
 * `Scanner\FileScanner`, turning the outcome into exactly one `file`
 * finding per path — upserted when the file has findings, deleted when it
 * comes back clean. A file whose own scan ran out of budget stores its
 * resume token in the cursor and is neither marked scanned nor stepped
 * over, so the next tick continues that same file mid-sweep. Which files
 * are checked against the new signatures only is `Run\IncrementalScan`'s
 * call, made once per queue batch.
 */
final class FilesPhase implements PhaseInterface {

	private const BATCH = 50;

	private const FREE_MEMORY_EVERY = 200;

	/** @var int Files handled during the current run() call, for the memory-release cadence. */
	private int $processed = 0;

	public function run( Context $ctx, callable $deadline ): bool {
		$cursor          = $ctx->cursor;
		$version         = $ctx->bundle_version();
		$prefix          = $cursor->path();
		$this->processed = 0;

		while ( true ) {
			$rows = $ctx->files->queue_for_scan( $version, (int) $cursor->get( 'file_id', 0 ), self::BATCH, $prefix );

			if ( [] === $rows ) {
				return true;
			}

			$incremental = IncrementalScan::row_ids( $ctx, $rows );

			foreach ( $rows as $row ) {
				if ( ! $this->handle_row( $ctx, $row, $version, $deadline, isset( $incremental[ (int) $row['id'] ] ) ) ) {
					return false;
				}
			}
		}
	}

	/**
	 * Scans one queued file and books the result.
	 *
	 * A resumed file keeps the rule set its interrupted scan started with:
	 * the resume position indexes that walk, and whether a file has a
	 * finding — part of the incremental decision — can change between ticks.
	 *
	 * @param Context              $ctx      Run context.
	 * @param array<string, mixed> $row      File row.
	 * @param int                  $version  Bundle version being scanned against.
	 * @param callable             $deadline Returns true once the tick's budget is spent.
	 * @param bool                 $only_new Whether IncrementalScan restricted this row to the new signatures.
	 * @return bool False when the phase must stop here — the file's own scan
	 *              was cut short, or the tick is out of time.
	 */
	private function handle_row( Context $ctx, array $row, int $version, callable $deadline, bool $only_new ): bool {
		$cursor = $ctx->cursor;
		$id     = (int) $row['id'];
		$resume = $this->resume_token( $cursor, $id );

		if ( [] !== $resume ) {
			$only_new = (bool) $cursor->get( 'resume_only_new', false );
		}

		$outcome = $ctx->file_scanner()->scan(
			$row,
			rtrim( ABSPATH, '/\\' ) . '/' . (string) $row['path'],
			$only_new,
			$deadline,
			$resume
		);

		$ctx->stats->inc( 'files.preg_errors', $outcome->errors );

		if ( $outcome->partial ) {
			$cursor->set( 'resume', $outcome->resume );
			$cursor->set( 'resume_file_id', $id );
			$cursor->set( 'resume_only_new', $only_new );

			return false;
		}

		$cursor->set( 'resume', [] );
		$cursor->set( 'resume_file_id', 0 );
		$cursor->set( 'resume_only_new', false );

		$this->record( $ctx, $row, $outcome );

		$ctx->files->mark_scanned( $id, $version );

		$cursor->set( 'file_id', $id );
		$cursor->set( 'files_done', (int) $cursor->get( 'files_done', 0 ) + 1 );

		++$this->processed;
		++$ctx->items;
		$ctx->stats->inc( 'files.scanned' );

		if ( 0 === $this->processed % self::FREE_MEMORY_EVERY ) {
			Runner::free_memory();
		}

		return ! $deadline();
	}

	/**
	 * The stored resume token, but only for the very file it was produced
	 * for: a row that vanished between ticks would otherwise hand its
	 * half-finished match list to whichever file took its place in the
	 * queue.
	 *
	 * @param Cursor $cursor Run cursor.
	 * @param int    $id     Row id about to be scanned.
	 * @return array<string, mixed>
	 */
	private function resume_token( Cursor $cursor, int $id ): array {
		if ( (int) $cursor->get( 'resume_file_id', 0 ) !== $id ) {
			return [];
		}

		return (array) $cursor->get( 'resume', [] );
	}

	/**
	 * Writes (or clears) the one `file` finding this path is entitled to.
	 *
	 * @param Context              $ctx     Run context.
	 * @param array<string, mixed> $row     File row.
	 * @param ScanOutcome          $outcome Scan result for the row.
	 */
	private function record( Context $ctx, array $row, ScanOutcome $outcome ): void {
		$reasons = $this->signal_reasons( $ctx, $row );

		if ( $outcome->large_php ) {
			$ctx->stats->inc( 'files.large_skipped' );

			// The size gate runs after the whole-file hash check (spec §6.6
			// steps 2–3), so an oversized file can still carry a real hash
			// match; only a file whose sole "match" is the skip marker gets
			// the bare review finding.
			if ( self::only_skipped( $outcome ) ) {
				$this->upsert( $ctx, FileFinding::large_php( $row, $reasons ) );

				return;
			}
		}

		if ( ! $outcome->has_findings() ) {
			$ctx->findings->delete_by_locator( 'file', (string) $row['path'] );

			return;
		}

		$ctx->stats->inc( 'files.heuristics', count( $outcome->heuristic ) );
		$this->upsert( $ctx, FileFinding::build( $row, $outcome, $reasons ) );
	}

	/**
	 * Whether the scan produced nothing but the `skip:large_php` marker.
	 *
	 * @param ScanOutcome $outcome Scan result.
	 */
	private static function only_skipped( ScanOutcome $outcome ): bool {
		foreach ( $outcome->matches as $match ) {
			if ( FileFinding::LARGE_PHP_SIG !== $match->sig_id ) {
				return false;
			}
		}

		return [] === $outcome->heuristic;
	}

	/**
	 * @param Context $ctx     Run context.
	 * @param Finding $finding Finding to store.
	 */
	private function upsert( Context $ctx, Finding $finding ): void {
		$result = $ctx->findings->upsert( $finding );

		if ( ! empty( $result['created'] ) ) {
			$ctx->stats->inc( 'findings.new' );
			$ctx->stats->inc( Severity::ALERT === $finding->severity ? 'findings.alerts_new' : 'findings.review_new' );

			return;
		}

		if ( ! empty( $result['changed'] ) ) {
			$ctx->stats->inc( 'findings.updated' );
		}
	}

	/**
	 * Recomputes the path-signal reasons with everything the hash pass
	 * learned about the row (kind, origin, known-good verdict) — the stat
	 * pass that first scored the row could not know any of it yet.
	 *
	 * @param Context              $ctx Run context.
	 * @param array<string, mixed> $row File row.
	 * @return array<int, string>
	 */
	private function signal_reasons( Context $ctx, array $row ): array {
		$signals = PathSignals::score(
			(string) $row['path'],
			(string) ( $row['origin'] ?? '' ),
			(string) ( $row['kind'] ?? '' ),
			$ctx->known_good()->core_paths(),
			! empty( $row['known_good'] )
		);

		return $signals['reasons'];
	}
}
