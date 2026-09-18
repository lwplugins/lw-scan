<?php
/**
 * Time-budgeted, resumable orchestrator for one scan run.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Run\Phase\BundlePhase;
use LightweightPlugins\Scan\Run\Phase\DbPhase;
use LightweightPlugins\Scan\Run\Phase\FilesPhase;
use LightweightPlugins\Scan\Run\Phase\FinalizePhase;
use LightweightPlugins\Scan\Run\Phase\HashPhase;
use LightweightPlugins\Scan\Run\Phase\IndexPhase;
use LightweightPlugins\Scan\Run\Phase\NullPhase;
use LightweightPlugins\Scan\Run\Phase\PhaseInterface;
use LightweightPlugins\Scan\Run\Phase\VulnPhase;
use LightweightPlugins\Scan\Scanner\Heuristic\Gate;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * `start()` only validates and opens a run; every bit of actual work
 * happens in `tick()`, which takes the run as far as the time budget allows
 * and leaves a resumable cursor behind (spec §10.2). One tick holds the run
 * lock from a single `acquire()` to the `finally` that releases it, and a
 * fatal error mid-tick still marks the run failed (FatalGuard).
 *
 * Not `final` (unlike the plugin's other classes): `phases()` is the seam
 * the pipeline is assembled behind, so tests can drive the tick loop
 * without the real phases. Implements `RunnerInterface` so `Run\Scheduler`
 * can be driven by a lighter test double instead.
 */
class Runner implements RunnerInterface {

	/** Failure-streak tracker, built in a later task; called only when present. */
	private const FAILURE_STREAK = 'LightweightPlugins\\Scan\\Notify\\FailureStreak';

	/** @var FilesRepositoryInterface File-index repository. */
	private FilesRepositoryInterface $files;

	/** @var FindingsRepositoryInterface Findings repository. */
	private FindingsRepositoryInterface $findings;

	/** @var RunsRepositoryInterface Run-history repository. */
	private RunsRepositoryInterface $runs;

	/** @var array<string, PhaseInterface>|null Memoized phase map for this request. */
	private ?array $phase_map = null;

	/** @var FatalGuard Turns a fatal error mid-tick into a failed run. */
	private FatalGuard $guard;

	/** @var int Run id the current tick is working on. */
	private int $tick_run_id = 0;

	/** @var array<string, mixed> Latest stats of the current tick, for the fatal guard. */
	private array $tick_stats = [];

	/**
	 * @param FilesRepositoryInterface|null    $files    File-index repository; defaults to the real one.
	 * @param FindingsRepositoryInterface|null $findings Findings repository; defaults to the real one.
	 * @param RunsRepositoryInterface|null     $runs     Run-history repository; defaults to the real one.
	 */
	public function __construct( ?FilesRepositoryInterface $files = null, ?FindingsRepositoryInterface $findings = null, ?RunsRepositoryInterface $runs = null ) {
		$this->files    = $files ?? new FilesRepository();
		$this->findings = $findings ?? new FindingsRepository();
		$this->runs     = $runs ?? new RunsRepository();
		$this->guard    = new FatalGuard(
			function ( string $message ): void {
				$error = 1 === preg_match( '/^Allowed memory size of \d+ bytes exhausted/', $message ) ? RunError::OUT_OF_MEMORY : $message;
				$this->fail_run( $this->tick_run_id, $error, $this->tick_stats );
				Lock::release();
			}
		);
	}

	/**
	 * Opens a run: validates, creates the run row and saves a fresh cursor.
	 * Does no scan work — the first `tick()` does (spec §10.2).
	 *
	 * @param string $trigger manual|cron|catchup|cli.
	 * @param string $scope   full|changed|db|path.
	 * @param string $path    Directory to scan, scope=path only.
	 * @param bool   $resume  Continue a stopped run instead of refusing as busy.
	 * @return int|WP_Error The run id, or why it could not start.
	 */
	public function start( string $trigger, string $scope, string $path = '', bool $resume = false ) {
		Client::reset_counters();

		return ( new Starter( $this->runs ) )->open( $trigger, $scope, $path, $resume );
	}

	/**
	 * Works the pipeline for up to `$budget` seconds, then saves the cursor
	 * and reports where the run stands.
	 *
	 * @param float $budget Seconds this tick may use; 0 or less uses `budget()`.
	 * @return array{status:string, phase:string, done:int, total:int, findings_new:int, finished:bool, run_id:int}
	 */
	public function tick( float $budget = 0.0 ): array {
		$started = microtime( true );
		$budget  = $budget > 0 ? $budget : self::budget();

		$cursor = Cursor::load();

		if ( null === $cursor ) {
			return Progress::tick( 'idle', null, 0, false );
		}

		if ( ! Lock::acquire() ) {
			return Progress::tick( 'busy', $cursor, 0, false );
		}

		try {
			return $this->work( $cursor, $started, $budget );
		} finally {
			$this->guard->disarm();
			Lock::release();
		}
	}

	/**
	 * A non-locking snapshot for status polling: never touches the lock, so
	 * it can run while a tick is in flight.
	 *
	 * @return array{status:string, phase:string, done:int, total:int, findings_new:int, elapsed:int, run_id:int, last_tick_at:int}
	 */
	public function progress(): array {
		$cursor  = Cursor::load();
		$run     = null === $cursor ? $this->runs->last() : $this->runs->get( $cursor->run_id() );
		$started = Progress::started_at( $cursor, $run );

		return Progress::snapshot( $cursor, $run, $this->findings_new( $started ) );
	}

	/**
	 * Per-tick time budget (spec §10.2). Delegates to Run\Budget; kept here
	 * because every entry point asks the Runner for it.
	 */
	public static function budget(): float {
		return Budget::seconds();
	}

	/**
	 * Releases the memory a long run accumulates. Delegates to Run\Budget.
	 */
	public static function free_memory(): void {
		Budget::free_memory();
	}

	/**
	 * The pipeline. Overridden in tests to drive the tick loop with doubles.
	 *
	 * @return array<string, PhaseInterface>
	 */
	protected function phases(): array {
		return [
			'bundle'   => new BundlePhase(),
			'index'    => new IndexPhase(),
			'hash'     => new HashPhase(),
			'files'    => new FilesPhase(),
			'db'       => new DbPhase(),
			'vuln'     => new VulnPhase(),
			'finalize' => new FinalizePhase(),
		];
	}

	/**
	 * The locked part of a tick: a stop, then phase steps until the budget
	 * runs out, the pipeline ends, or something goes wrong.
	 *
	 * @param Cursor $cursor  Loaded run cursor.
	 * @param float  $started microtime() this tick began at.
	 * @param float  $budget  Seconds this tick may use.
	 * @return array{status:string, phase:string, done:int, total:int, findings_new:int, finished:bool, run_id:int}
	 */
	private function work( Cursor $cursor, float $started, float $budget ): array {
		$run    = $this->runs->get( $cursor->run_id() );
		$stats  = RunStats::from_array( self::stats_of( $run ) );
		$closed = 'stopped' === (string) ( is_array( $run ) ? ( $run['status'] ?? '' ) : '' );

		$this->tick_run_id = $cursor->run_id();
		$this->tick_stats  = $stats->to_array();

		$this->guard->arm();

		if ( $this->stop_requested( $cursor ) ) {
			return $this->stopped( $cursor, $stats, $closed );
		}

		// Carried so a later `out_of_memory` failure can name the limit this
		// tick ran at; skipped for Gate's PHP_INT_MAX "unlimited" sentinel, or memory_label() would print "8 EB".
		$limit = Gate::memory_limit_bytes();

		if ( $limit > 0 && PHP_INT_MAX !== $limit ) {
			$stats->set( 'memory_limit', $limit );
			$this->tick_stats = $stats->to_array();
		}

		$stats->inc( 'ticks' );
		$stats->set( 'budget_s', (int) round( $budget ) );

		$cursor->set( 'last_tick_at', time() );

		$deadline = static function () use ( $started, $budget ): bool {
			return microtime( true ) - $started >= $budget;
		};

		try {
			$finished = $this->loop( $cursor, $stats, $deadline );
		} catch ( Throwable $e ) {
			$this->fail_run( $cursor->run_id(), $e->getMessage(), $stats->to_array() );

			return Progress::tick( 'failed', $cursor, $this->findings_new( $cursor->started_at() ), false );
		}

		if ( ! $finished && $cursor->stop_requested() ) {
			return $this->stopped( $cursor, $stats, false );
		}

		return Progress::tick(
			$finished ? 'finished' : 'running',
			$cursor,
			$this->findings_new( $cursor->started_at() ),
			$finished
		);
	}

	/**
	 * Closes the run as stopped — unless the run row already says so, in
	 * which case every later tick would otherwise re-finish it and push its
	 * `finished_at` forward.
	 *
	 * @param Cursor   $cursor  Run cursor (kept, so the run can be resumed).
	 * @param RunStats $stats   Stats as of the stop.
	 * @param bool     $already Whether the run row is already `stopped`.
	 * @return array{status:string, phase:string, done:int, total:int, findings_new:int, finished:bool, run_id:int}
	 */
	private function stopped( Cursor $cursor, RunStats $stats, bool $already ): array {
		if ( ! $already ) {
			$this->runs->finish( $cursor->run_id(), 'stopped', $stats->to_array() );
		}

		return Progress::tick( 'stopped', $cursor, $this->findings_new( $cursor->started_at() ), false );
	}

	/**
	 * Whether this run should stop, reading past the options cache so a
	 * Stop sent from another request is actually seen. A stop found this
	 * way is folded into the in-memory cursor, which is what the next
	 * `save()` writes back.
	 *
	 * @param Cursor $cursor Run cursor, updated in place when a stop is found.
	 */
	private function stop_requested( Cursor $cursor ): bool {
		if ( $cursor->stop_requested() ) {
			return true;
		}

		if ( ! StopFlag::requested_fresh() ) {
			return false;
		}

		$cursor->set( 'stop_requested', true );

		return true;
	}

	/**
	 * @param Cursor   $cursor   Run cursor, advanced in place.
	 * @param RunStats $stats    Run stats, accumulated in place.
	 * @param callable $deadline Returns true once the budget is spent.
	 * @return bool True when the pipeline reached its end.
	 */
	private function loop( Cursor $cursor, RunStats $stats, callable $deadline ): bool {
		$ctx = new Context( $cursor, $stats, Options::all(), $this->files, $this->findings, $this->runs );

		BundleGuard::verify( $ctx );

		do {
			$phase = $cursor->phase();

			$ctx->items = 0;
			$stats->phase_start( $phase );

			// Metered per phase, not per tick: a tick-level delta recorded
			// after the loop returned stored `remote.calls` as 0 for every
			// run, since the last tick's delta lands after FinalizePhase
			// already wrote the stats (which makes no backend calls today).
			$remote = new RemoteMeter();

			try {
				$complete = $this->phase( $phase )->run( $ctx, $deadline );
			} finally {
				$remote->record( $stats );
			}

			$stats->phase_end( $phase, $ctx->items );
			$stats->max( 'peak_memory', memory_get_peak_usage( true ) );
			$this->tick_stats = $stats->to_array();

			if ( $complete && null === $cursor->next_phase() ) {
				return true;
			}

			$this->save( $cursor, $stats );

			if ( $complete ) {
				do_action( 'lw_scan_phase_changed', $cursor->phase(), $stats->to_array() );
			}

			// A phase that did not complete has already spent this tick's
			// budget inside its own loop; there is nothing left to give it.
			if ( ! $complete || $cursor->stop_requested() ) {
				return false;
			}
		} while ( ! $deadline() );

		return false;
	}

	/**
	 * The handler for `$name`. An unknown phase counts as already complete,
	 * so a cursor written by another version still drains instead of
	 * looping forever.
	 *
	 * @param string $name Phase name from the cursor.
	 */
	private function phase( string $name ): PhaseInterface {
		if ( null === $this->phase_map ) {
			$this->phase_map = $this->phases();
		}

		return $this->phase_map[ $name ] ?? new NullPhase();
	}

	/**
	 * Persists the cursor and this tick's stats. A stop requested from
	 * another request lands in the stored cursor while this one holds an
	 * older copy in memory, so the flag is merged back in first — writing
	 * the in-memory copy over it would silently swallow the stop.
	 *
	 * @param Cursor   $cursor Run cursor to persist.
	 * @param RunStats $stats  Stats to persist onto the run row.
	 */
	private function save( Cursor $cursor, RunStats $stats ): void {
		$this->stop_requested( $cursor );

		$cursor->save();
		$this->runs->update( $cursor->run_id(), [ 'stats' => $stats->to_array() ] );
	}

	/**
	 * Marks the run failed and drops the cursor — a failed run is not
	 * resumable, the next scheduled scan starts over.
	 *
	 * @param int                  $run_id  Run id.
	 * @param string               $message Failure message.
	 * @param array<string, mixed> $stats   Stats as of the failure.
	 */
	private function fail_run( int $run_id, string $message, array $stats ): void {
		$this->runs->finish( $run_id, 'failed', $stats, $message );

		Cursor::clear();

		if ( class_exists( self::FAILURE_STREAK ) ) {
			call_user_func( [ self::FAILURE_STREAK, 'record_failure' ], $message );
		}
	}

	/**
	 * @param array<string, mixed>|null $run Run row, if it still exists.
	 * @return array<string, mixed> Stats stored on the row so far.
	 */
	private static function stats_of( ?array $run ): array {
		return is_array( $run ) && isset( $run['stats'] ) && is_array( $run['stats'] ) ? $run['stats'] : [];
	}

	/**
	 * @param int $started_at Run start timestamp; 0 when there is no run.
	 * @return int Findings first seen since then.
	 */
	private function findings_new( int $started_at ): int {
		return $started_at > 0 ? count( $this->findings->new_since( $started_at ) ) : 0;
	}
}
