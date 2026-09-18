<?php
/**
 * Validates and opens a scan run.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\State;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that has to be true before a run may exist (spec §10.2): no
 * other run in progress, a scope the pipeline knows, an environment that
 * isn't blocking scans, and — for `scope=path` — a real directory inside
 * ABSPATH. On success the run row is created and a fresh cursor saved; no
 * scan work happens here, `Runner::tick()` does all of it.
 *
 * `gates()` — the environment check — guards every run this class opens, new
 * or replacement. Plain resume is the one path past it: it reuses a run that
 * already exists, and there is no environment to re-check for a run that is
 * simply picking up where it left off.
 *
 * A stopped run leaves its cursor behind on purpose so it can be resumed,
 * which makes "a cursor exists" too blunt a busy test: only a run row that
 * is still `running` blocks a new scan. A cursor left over from a closed
 * run is cleared and replaced (spec §14: "new start resumes with the
 * resume option, or starts over").
 *
 * The whole check-then-write is bracketed by the run lock so two requests
 * can't both find no live run and both create one. No scan work happens
 * inside the lock.
 */
final class Starter {

	/** Health probe, built in a later task; consulted only when present. */
	private const ENVIRONMENT = 'LightweightPlugins\\Scan\\Health\\Environment';

	/** @var RunsRepositoryInterface Run-history repository. */
	private RunsRepositoryInterface $runs;

	/**
	 * @param RunsRepositoryInterface $runs Run-history repository.
	 */
	public function __construct( RunsRepositoryInterface $runs ) {
		$this->runs = $runs;
	}

	/**
	 * @param string $trigger manual|cron|catchup|cli.
	 * @param string $scope   full|changed|db|path.
	 * @param string $path    Directory to scan, scope=path only.
	 * @param bool   $resume  Continue a stopped run instead of refusing as busy.
	 * @return int|WP_Error The run id, or why it could not start.
	 */
	public function open( string $trigger, string $scope, string $path, bool $resume ) {
		if ( ! Lock::acquire() ) {
			return new WP_Error( 'lw_scan_busy', __( 'A scan is already in progress.', 'lw-scan' ) );
		}

		try {
			return $this->open_locked( $trigger, $scope, $path, $resume );
		} finally {
			Lock::release();
		}
	}

	/**
	 * @param string $trigger manual|cron|catchup|cli.
	 * @param string $scope   full|changed|db|path.
	 * @param string $path    Directory to scan, scope=path only.
	 * @param bool   $resume  Continue a stopped run instead of starting over.
	 * @return int|WP_Error The run id, or why it could not start.
	 */
	private function open_locked( string $trigger, string $scope, string $path, bool $resume ) {
		$existing = Cursor::load();

		if ( null !== $existing ) {
			$verdict = $this->handle_existing( $existing, $resume, $trigger );

			if ( null !== $verdict ) {
				return $verdict;
			}
		}

		if ( ! Phases::valid_scope( $scope ) ) {
			return new WP_Error( 'lw_scan_bad_scope', __( 'Unknown scan scope.', 'lw-scan' ) );
		}

		$refusal = self::gates();

		if ( null !== $refusal ) {
			return $refusal;
		}

		$relative = '';

		if ( 'path' === $scope ) {
			$relative = ScanPath::relative( $path );

			if ( null === $relative ) {
				return new WP_Error( 'lw_scan_bad_path', __( 'The scan path must be a directory inside the WordPress installation.', 'lw-scan' ) );
			}
		}

		return $this->create( $trigger, $scope, $relative );
	}

	/**
	 * What must be true of the environment before any run — new or
	 * replacement — is opened: the checks `Health\Environment::blocking_issue()`
	 * runs, storage and the bundle and the plugin's own tables. There is no
	 * memory gate here any more (Task 12) — a tick that genuinely runs out
	 * of memory fails plainly instead of being refused in advance.
	 *
	 * @return WP_Error|null Why no run may be opened, or null when one may.
	 */
	private static function gates(): ?WP_Error {
		$blocked = self::blocking_issue();

		if ( null !== $blocked ) {
			return new WP_Error( 'lw_scan_blocked', $blocked );
		}

		return null;
	}

	/**
	 * Decides what a leftover cursor means for this start request.
	 *
	 * @param Cursor $cursor  The stored cursor.
	 * @param bool   $resume  Whether the caller asked to continue the stopped run.
	 * @param string $trigger Trigger of the request asking, for a replacement run.
	 * @return int|WP_Error|null The run id or the refusal, or null when the
	 *                           cursor was stale and a fresh run may proceed.
	 */
	private function handle_existing( Cursor $cursor, bool $resume, string $trigger ) {
		$run    = $this->runs->get( $cursor->run_id() );
		$status = (string) ( is_array( $run ) ? ( $run['status'] ?? '' ) : '' );

		if ( $resume ) {
			if ( 'stopped' === $status ) {
				return $this->reopen( $cursor, $run, $trigger );
			}

			if ( 'running' === $status ) {
				return new WP_Error( 'lw_scan_busy', __( 'A scan is already in progress.', 'lw-scan' ) );
			}

			return new WP_Error( 'lw_scan_nothing_to_resume', __( 'There is no stopped scan to resume.', 'lw-scan' ) );
		}

		if ( 'running' === $status ) {
			return new WP_Error( 'lw_scan_busy', __( 'A scan is already in progress.', 'lw-scan' ) );
		}

		// The run this cursor belongs to is over (stopped, finished, failed
		// or gone); it must not block a new scan.
		Cursor::clear();

		return null;
	}

	/**
	 * Opens the run row and the cursor that points at it, in that order and
	 * only if the first one worked.
	 *
	 * `RunsRepository::create()` returns `$wpdb->insert_id`, which is 0 when
	 * the INSERT did not happen — a missing table, most of all. Treating that
	 * as a run id gave a cursor pointing at run 0, ticks writing findings
	 * against a row that does not exist, and a Scan tab with nothing to show
	 * for any of it. `Health\Environment::blocking_issue()` catches the
	 * missing-table case before this, so reaching here means something the
	 * gate could not see went wrong; the advice is the same either way,
	 * because reinstalling the schema is what fixes both.
	 *
	 * Nothing is written or cleared on the way out: the cursor is saved only
	 * after there is a run for it to refer to.
	 *
	 * @param string $trigger  Run trigger.
	 * @param string $scope    Scan scope.
	 * @param string $relative ABSPATH-relative scan path, '' outside scope=path.
	 * @return int|WP_Error The new run's id, or why there is none.
	 */
	private function create( string $trigger, string $scope, string $relative ) {
		$run_id = $this->runs->create( $trigger, $scope, $relative, (int) State::get( 'bundle_version', 0 ) );

		if ( $run_id <= 0 ) {
			return new WP_Error( 'lw_scan_no_run', __( 'The scan could not be recorded — the plugin tables are missing. Deactivate and reactivate the plugin.', 'lw-scan' ) );
		}

		$cursor = Cursor::fresh( $run_id, $scope, $relative, Phases::for_scope( $scope ) );
		$cursor->set( 'trigger', $trigger );
		$cursor->save();

		return $run_id;
	}

	/**
	 * Reopens a stopped run: the cursor stays exactly where the stop left
	 * it, so the next tick picks the run up mid-phase — unless the
	 * signature bundle has been replaced since it stopped.
	 *
	 * @param Cursor                    $cursor  The stopped run's cursor.
	 * @param array<string, mixed>|null $run     Its run row.
	 * @param string                    $trigger Trigger of the request asking to resume.
	 * @return int|WP_Error The reopened run's id, a replacement run's, or why neither could happen.
	 */
	private function reopen( Cursor $cursor, ?array $run, string $trigger ) {
		$pinned = (int) $cursor->get( BundleGuard::FIELD, 0 );

		if ( 0 !== $pinned && (int) State::get( 'bundle_version', 0 ) !== $pinned ) {
			return $this->restart( $cursor, $run, $trigger );
		}

		$cursor->set( 'stop_requested', false );
		$cursor->save();

		$this->runs->update(
			$cursor->run_id(),
			[
				'status'      => 'running',
				'finished_at' => 0,
			]
		);

		return $cursor->run_id();
	}

	/**
	 * Replaces a run the new signature set has overtaken. Resuming into a
	 * different bundle is what `Run\BundleGuard` fails a live tick for: the
	 * index records the version each row was scanned at, so half a run at
	 * one version and half at another describes nothing.
	 *
	 * The stopped run is closed as `failed` rather than left `stopped`: the
	 * Scan tab renders an error beside a failed row, which is the only place
	 * the site owner would learn why the resume did not resume, and a run
	 * already finished once must not have its `finished_at` pushed forward
	 * as a second stop would.
	 *
	 * A replacement run is a new run, so it answers to what a new run
	 * answers to: the same environment gates `open()` applies, checked
	 * before anything is written. A refusal leaves the stopped run stopped —
	 * it is no use to anyone, but closing it as part of saying "no" would
	 * destroy the one thing the site owner could still look at.
	 *
	 * @param Cursor                    $cursor  The stopped run's cursor.
	 * @param array<string, mixed>|null $run     Its run row.
	 * @param string                    $trigger Trigger of the request asking to resume.
	 * @return int|WP_Error The replacement run's id, or why it could not be opened.
	 */
	private function restart( Cursor $cursor, ?array $run, string $trigger ) {
		$refusal = self::gates();

		if ( null !== $refusal ) {
			return $refusal;
		}

		$stats = is_array( $run ) && isset( $run['stats'] ) && is_array( $run['stats'] ) ? $run['stats'] : [];

		$this->runs->finish( $cursor->run_id(), 'failed', $stats, 'bundle_changed' );

		Cursor::clear();

		return $this->create( (string) $cursor->get( 'trigger', $trigger ), $cursor->scope(), $cursor->path() );
	}

	/**
	 * Health\Environment lands in a later task; until then nothing blocks.
	 *
	 * @return string|null The blocking reason, or null when there is none.
	 */
	private static function blocking_issue(): ?string {
		if ( ! class_exists( self::ENVIRONMENT ) ) {
			return null;
		}

		$issue = call_user_func( [ self::ENVIRONMENT, 'blocking_issue' ] );

		return is_string( $issue ) && '' !== $issue ? $issue : null;
	}
}
