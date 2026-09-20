<?php
/**
 * Pipeline phase: close the run out.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Notify\Baseline;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §10.7: drop the index rows (and findings) for files that vanished
 * since the last run — only for the scopes that actually walked the whole
 * tree — notify about what is new, mark the run done, prune the run
 * history, clear the cursor and, for an automated run, move the schedule
 * forward. `Notify\*` and `Run\Scheduler` arrive in later tasks, so both
 * are called through `class_exists()` guards.
 *
 * The `findings.*` counters are (re)computed here from the repository
 * rather than accumulated per phase: `FilesPhase` only ever counted its own
 * `file` findings, so the run stats disagreed with `wp lw-scan status` and
 * HelloPack by exactly the integrity/db/vulnerability findings.
 *
 * Notifying is gated on `Notify\Baseline`: the first completed run on a site
 * that has never mailed is a baseline, not an incident, so it records its
 * findings and sends nothing. The same `State::merge()` that closes the run
 * out writes the flag, so a finished run still costs one state write.
 */
final class FinalizePhase implements PhaseInterface {

	private const RUNS_KEPT = 50;

	/** Notification mailer, built in a later task; called only when present. */
	private const MAILER = 'LightweightPlugins\\Scan\\Notify\\Mailer';

	/** Failure-streak tracker, built in a later task; called only when present. */
	private const FAILURE_STREAK = 'LightweightPlugins\\Scan\\Notify\\FailureStreak';

	/** Cron scheduler, built in a later task; called only when present. */
	private const SCHEDULER = 'LightweightPlugins\\Scan\\Run\\Scheduler';

	/** @var array<int, string> Scopes whose index covers the whole tree, so "unseen" means "gone". */
	private const DELETING_SCOPES = [ 'full', 'changed' ];

	/** @var callable|null Overrides the Scheduler-backed next_due computation (tests). */
	private $next_due_provider;

	/** @var Baseline|null First-scan baseline state; built on first use so no constructor touches the database. */
	private ?Baseline $baseline;

	/**
	 * @param callable|null $next_due_provider Returns the next due timestamp; defaults to Run\Scheduler when it exists.
	 * @param Baseline|null $baseline          First-scan baseline state; the State/runs-table-backed one when null.
	 */
	public function __construct( ?callable $next_due_provider = null, ?Baseline $baseline = null ) {
		$this->next_due_provider = $next_due_provider;
		$this->baseline          = $baseline;
	}

	public function run( Context $ctx, callable $deadline ): bool {
		unset( $deadline );

		$cursor = $ctx->cursor;

		$this->delete_vanished( $ctx );

		$new = $ctx->findings->new_since( $cursor->started_at() );

		self::count_findings( $ctx, $new );

		$baseline = $this->baseline ?? new Baseline();

		// The first completed run on a site that has never mailed is the
		// baseline: it records what is already there and sends nothing.
		if ( [] !== $new && $baseline->taken() && class_exists( self::MAILER ) ) {
			$run = $ctx->runs->get( $ctx->run_id );

			call_user_func( [ self::MAILER, 'send_new_findings' ], is_array( $run ) ? $run : [], $new, $ctx->options );
		}

		if ( class_exists( self::FAILURE_STREAK ) ) {
			call_user_func( [ self::FAILURE_STREAK, 'record_success' ] );
		}

		$ctx->stats->max( 'peak_memory', memory_get_peak_usage( true ) );

		$ctx->runs->finish( $ctx->run_id, 'done', $ctx->stats->to_array() );
		$ctx->runs->prune( self::RUNS_KEPT );

		Cursor::clear();

		State::merge(
			array_merge(
				[
					'last_run_id'     => $ctx->run_id,
					'last_success_at' => time(),
				],
				$baseline->record_finished_run( $ctx->run_id )
			)
		);

		$this->reschedule( (string) $cursor->get( 'trigger', '' ) );

		return true;
	}

	/**
	 * Restates `findings.new`/`alerts_new`/`review_new` from every finding
	 * first seen during this run, whatever its type.
	 *
	 * @param Context                          $ctx Run context.
	 * @param array<int, array<string, mixed>> $new Rows from FindingsRepository::new_since().
	 */
	private static function count_findings( Context $ctx, array $new ): void {
		$alerts = 0;

		foreach ( $new as $finding ) {
			if ( Severity::ALERT === (string) ( $finding['severity'] ?? '' ) ) {
				++$alerts;
			}
		}

		$ctx->stats->set( 'findings.new', count( $new ) );
		$ctx->stats->set( 'findings.alerts_new', $alerts );
		$ctx->stats->set( 'findings.review_new', count( $new ) - $alerts );
	}

	/**
	 * Removes index rows the walk never saw this run — and the findings
	 * pinned to them. Skipped for `db` and `path` scopes, whose index pass
	 * covers only part of the tree (or none of it), so "unseen" there does
	 * not mean "deleted from disk".
	 *
	 * @param Context $ctx Run context.
	 */
	private function delete_vanished( Context $ctx ): void {
		if ( ! in_array( $ctx->cursor->scope(), self::DELETING_SCOPES, true ) ) {
			return;
		}

		$ids = $ctx->files->delete_unseen( $ctx->run_id );

		if ( [] === $ids ) {
			return;
		}

		$ctx->findings->delete_for_files( $ids );
		$ctx->stats->inc( 'files.deleted', count( $ids ) );
	}

	/**
	 * Moves the automated schedule forward. Only an automated run may do
	 * this: a manual or CLI scan must not push the next scheduled scan out.
	 *
	 * @param string $trigger The run's trigger.
	 */
	private function reschedule( string $trigger ): void {
		if ( ! in_array( $trigger, [ 'cron', 'catchup' ], true ) ) {
			return;
		}

		$next_due = $this->next_due();

		if ( null === $next_due ) {
			return;
		}

		Options::update(
			[
				'next_due'      => $next_due,
				'last_auto_run' => time(),
			]
		);
	}

	/**
	 * @return int|null The next due timestamp, or null when no scheduler is available yet.
	 */
	private function next_due(): ?int {
		if ( null !== $this->next_due_provider ) {
			return (int) call_user_func( $this->next_due_provider );
		}

		if ( ! class_exists( self::SCHEDULER ) ) {
			return null;
		}

		return (int) call_user_func( [ self::SCHEDULER, 'next_due_from' ], Options::all() );
	}
}
