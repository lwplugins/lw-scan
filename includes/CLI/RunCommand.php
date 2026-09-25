<?php
/**
 * `wp lw-scan run` — scans and prints what it found.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Run\RunError;
use LightweightPlugins\Scan\Run\Runner;
use WP_CLI;
use WP_CLI\Utils;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The only entry point that runs a scan to completion in the foreground:
 * `Runner::start()` opens the run, then `tick()` is called with an
 * effectively unlimited budget, so one CLI process carries the whole
 * pipeline instead of the admin's cron/AJAX relay (spec §11.2).
 *
 * Exit codes are the contract cron jobs and CI pipelines read: 0 when the
 * run brought back nothing alerting, 1 when it found at least one new
 * alert-severity finding (via `WP_CLI::halt()`, so nothing is printed to
 * STDERR for a working scanner doing its job), 2 when the scan could not
 * start or did not survive.
 *
 * Two of the documented options collide with WP-CLI's own global
 * parameters, which the Configurator strips before a command ever sees
 * them: `--path` (WP-CLI's WordPress root) and `--quiet`. So the scan path
 * is accepted as a positional argument too, and WP-CLI's global `--quiet`
 * is honoured alongside a command-level one — it already suppresses
 * `WP_CLI::log()` while leaving `format_items()` output alone, which is
 * exactly the documented behaviour.
 */
final class RunCommand {

	/** Ticks one invocation may take before giving up; see drain(). */
	private const MAX_TICKS = 500;

	/**
	 * Seconds one tick may spend: a CLI run answers to no request timeout,
	 * so this is "as long as it takes". It is a day rather than something
	 * larger because `Runner::tick()` rounds the budget to an int for
	 * `stats.budget_s`, and a float that big is not int-representable —
	 * PHP 8.4+ warns on the cast and the stored number wraps negative.
	 */
	private const UNLIMITED_BUDGET = DAY_IN_SECONDS;

	/** @var Runner The runner this invocation drives. */
	private Runner $runner;

	/**
	 * Run a scan in the foreground and report what it found.
	 *
	 * ## OPTIONS
	 *
	 * [<dir>]
	 * : Directory to scan with --scope=path, relative to the WordPress root.
	 * Same as --path=<dir>, which WP-CLI's own global --path parameter
	 * shadows unless WP_CLI_STRICT_ARGS_MODE=1 is set.
	 *
	 * [--scope=<scope>]
	 * : What to scan.
	 * ---
	 * default: changed
	 * options:
	 *   - full
	 *   - changed
	 *   - db
	 *   - path
	 * ---
	 *
	 * [--path=<dir>]
	 * : Directory to scan with --scope=path. See <dir> above.
	 *
	 * [--[no-]heuristics]
	 * : Run the heuristic layer. --no-heuristics skips it for this run only;
	 * the stored setting is left untouched.
	 *
	 * [--resume]
	 * : Continue the stopped run where it left off instead of starting a new one.
	 *
	 * [--format=<format>]
	 * : Output format. table prints the run summary and the new findings;
	 * json and csv print one parseable document of the new findings only
	 * (json with the full finding rows), and no progress lines.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--quiet]
	 * : Suppress the per-phase progress lines. The result tables are still printed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-scan run
	 *     wp lw-scan run --scope=full
	 *     wp lw-scan run --scope=path wp-content/uploads
	 *     wp lw-scan run --scope=db --format=json
	 *
	 * @param array<int, string>   $args  Positional arguments: the scan path, with --scope=path.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$this->runner = new Runner();
		$format       = (string) Utils\get_flag_value( $assoc, 'format', 'table' );

		if ( ! (bool) Utils\get_flag_value( $assoc, 'heuristics', true ) ) {
			add_filter( 'lw_scan_options', [ $this, 'disable_heuristics' ] );
		}

		// Progress lines are `WP_CLI::log()`, i.e. STDOUT: they would sit
		// in front of the JSON/CSV document and make it unparseable, so
		// only the human format gets them.
		if ( 'table' === $format && ! self::is_quiet( $assoc ) ) {
			add_action( 'lw_scan_phase_changed', [ $this, 'log_phase' ] );
		}

		$run_id = $this->open( $args, $assoc );

		try {
			$tick = $this->drain();
		} finally {
			remove_filter( 'lw_scan_options', [ $this, 'disable_heuristics' ] );
			remove_action( 'lw_scan_phase_changed', [ $this, 'log_phase' ] );
		}

		$this->report( $run_id, $format, (string) $tick['status'] );
	}

	/**
	 * Forces the heuristic layer off for the lifetime of this command
	 * (`--no-heuristics`). Public because `remove_filter()` needs the very
	 * same callable back; not part of the command surface.
	 *
	 * @param mixed $options The plugin's merged options.
	 * @return mixed
	 */
	public function disable_heuristics( $options ) {
		if ( is_array( $options ) ) {
			$options['heuristics'] = false;
		}

		return $options;
	}

	/**
	 * Prints where the run stands whenever it enters a new phase. Public
	 * for the same reason as `disable_heuristics()`.
	 *
	 * @param string $phase The phase just entered.
	 * @return void
	 */
	public function log_phase( string $phase ): void {
		$progress = $this->runner->progress();

		WP_CLI::log(
			sprintf(
				'phase: %s (%d/%d files, %d new findings)',
				$phase,
				(int) $progress['done'],
				(int) $progress['total'],
				(int) $progress['findings_new']
			)
		);
	}

	/**
	 * Opens the run, or gives up with exit code 2.
	 *
	 * @param array<int, string>   $args  Positional arguments.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return int The new run's id.
	 */
	private function open( array $args, array $assoc ): int {
		$path = (string) ( $args[0] ?? Utils\get_flag_value( $assoc, 'path', '' ) );

		$result = $this->runner->start(
			'cli',
			(string) Utils\get_flag_value( $assoc, 'scope', 'changed' ),
			$path,
			(bool) Utils\get_flag_value( $assoc, 'resume', false )
		);

		if ( $result instanceof WP_Error ) {
			WP_CLI::error( $result->get_error_message(), 2 );
		}

		return (int) $result;
	}

	/**
	 * Ticks until the pipeline ends. One `tick( UNLIMITED_BUDGET )`
	 * normally carries the whole run, but a phase that hands the tick back
	 * mid-pipeline — its own budget spent, or the day gone — leaves the
	 * rest for the next one, so keep ticking while the run is still going.
	 * The tick cap is a backstop against a phase that can neither finish
	 * nor fail: without it the loop would spin forever.
	 *
	 * @return array{status:string, phase:string, done:int, total:int, findings_new:int, finished:bool, run_id:int}
	 */
	private function drain(): array {
		$ticks = 0;

		do {
			$tick = $this->runner->tick( (float) self::UNLIMITED_BUDGET );
			++$ticks;
		} while ( ! $tick['finished'] && 'running' === $tick['status'] && $ticks < self::MAX_TICKS );

		if ( 'busy' === $tick['status'] ) {
			WP_CLI::error( 'Another process is already working on this scan.', 2 );
		}

		if ( ! $tick['finished'] && 'running' === $tick['status'] ) {
			WP_CLI::error( sprintf( 'The scan is still going after %d ticks. Stop it with `wp lw-scan stop`, then run `wp lw-scan run --resume`.', $ticks ), 2 );
		}

		return $tick;
	}

	/**
	 * Prints the result and sets the exit code.
	 *
	 * @param int    $run_id Run id.
	 * @param string $format table|json|csv.
	 * @param string $status Status the last tick reported.
	 * @return void
	 */
	private function report( int $run_id, string $format, string $status ): void {
		$run     = RunsRepository::get( $run_id );
		$run     = null === $run ? [] : $run;
		$started = (int) ( $run['started_at'] ?? 0 );

		// A run row that is gone (pruned, or never created) would turn
		// new_since( 0 ) into "every new finding ever", so report nothing.
		$findings = $started > 0 ? FindingsRepository::new_since( $started ) : [];

		if ( 'table' === $format ) {
			self::tables( $run, $findings );
		} else {
			self::document( $format, $findings );
		}

		if ( 'stopped' === $status ) {
			WP_CLI::warning( 'The scan stopped before it finished. Resume it with: wp lw-scan run --resume' );
		}

		if ( 'failed' === $status ) {
			$stats = isset( $run['stats'] ) && is_array( $run['stats'] ) ? $run['stats'] : [];
			$error = RunError::label( (string) ( $run['error'] ?? '' ), $stats );

			WP_CLI::error( sprintf( 'The scan failed: %s', '' === $error ? 'unknown error' : $error ), 2 );
		}

		if ( self::has_alerts( $findings ) ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * The human-readable pair of tables: what the run did, then what it
	 * found. Table format only — the prose line below is STDOUT too.
	 *
	 * @param array<string, mixed>             $run      Run row.
	 * @param array<int, array<string, mixed>> $findings Findings first seen during the run.
	 * @return void
	 */
	private static function tables( array $run, array $findings ): void {
		Utils\format_items( 'table', Formatter::run_summary( $run ), Formatter::SUMMARY_COLUMNS );

		if ( [] === $findings ) {
			WP_CLI::log( 'No new findings.' );

			return;
		}

		Utils\format_items( 'table', Formatter::findings_rows( $findings ), Formatter::FINDINGS_COLUMNS );
		Attribution::print_footer( $findings, false );
	}

	/**
	 * The machine-readable output: exactly one document, the findings.
	 * A run-summary document in front of it would make `--format=json` two
	 * JSON arrays and `--format=csv` two header rows, and neither parses;
	 * the run's own facts stay available through `wp lw-scan status`. An
	 * empty run prints the empty document, never prose.
	 *
	 * @param string                           $format   json|csv.
	 * @param array<int, array<string, mixed>> $findings Findings first seen during the run.
	 * @return void
	 */
	private static function document( string $format, array $findings ): void {
		if ( 'json' !== $format ) {
			Utils\format_items( $format, Formatter::findings_rows( $findings ), Formatter::FINDINGS_COLUMNS );
			Attribution::print_footer( $findings, true );

			return;
		}

		$rows = Attribution::with_fields( array_map( [ self::class, 'decode_row' ], $findings ) );

		Utils\format_items( 'json', $rows, [] === $rows ? Formatter::FINDINGS_COLUMNS : array_keys( (array) reset( $rows ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $findings Findings first seen during the run.
	 * @return bool Whether any of them is alert-severity — exit code 1.
	 */
	private static function has_alerts( array $findings ): bool {
		foreach ( $findings as $finding ) {
			if ( 'alert' === (string) ( $finding['severity'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A raw findings row with its two JSON columns decoded, so
	 * `--format=json` hands out the finding model rather than embedded
	 * JSON strings.
	 *
	 * @param array<string, mixed> $row Raw findings-table row.
	 * @return array<string, mixed>
	 */
	private static function decode_row( array $row ): array {
		$row['signature_ids'] = Finding::decode_list( (string) ( $row['signature_ids'] ?? '[]' ) );
		$row['meta']          = Finding::decode_map( (string) ( $row['meta'] ?? '{}' ) );

		return $row;
	}

	/**
	 * WP-CLI's own global `--quiet` never reaches a command's arguments,
	 * so both spellings are consulted.
	 *
	 * @param array<string, mixed> $assoc Named arguments.
	 */
	private static function is_quiet( array $assoc ): bool {
		return (bool) Utils\get_flag_value( $assoc, 'quiet', false ) || (bool) WP_CLI::get_config( 'quiet' );
	}
}
