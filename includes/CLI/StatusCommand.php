<?php
/**
 * `wp lw-scan status` — where the scanner stands, plus health warnings.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Health\Environment;
use LightweightPlugins\Scan\Run\Runner;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only: the run snapshot `Runner::progress()` builds, the findings
 * counts, and the `Health\Environment` rows that are not `ok` (spec §12
 * — this is the CLI's doctor-style output). The health rows go out as
 * `WP_CLI::warning()`, i.e. to STDERR, so `--format=json` still produces a
 * parseable document on STDOUT.
 *
 * The report is the cached one: a plain status read must never fire the
 * cron loopback probe a fresh report is allowed to.
 */
final class StatusCommand {

	/** Health statuses worth interrupting a status read for. */
	private const LOUD = [ 'warning', 'critical' ];

	/**
	 * Show the current scan status, the findings counts and any health warnings.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-scan status
	 *     wp lw-scan status --format=json
	 *
	 * @param array<int, string>   $args  Positional arguments (unused).
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		unset( $args );

		$progress = ( new Runner() )->progress();
		$run_id   = (int) $progress['run_id'];
		$run      = $run_id > 0 ? RunsRepository::get( $run_id ) : RunsRepository::last();

		Utils\format_items(
			(string) Utils\get_flag_value( $assoc, 'format', 'table' ),
			Formatter::status( $progress, null === $run ? [] : $run, FindingsRepository::counts() ),
			Formatter::SUMMARY_COLUMNS
		);

		self::warn_about_health();
	}

	/**
	 * Repeats every health row that is not `ok` as a CLI warning.
	 *
	 * @return void
	 */
	private static function warn_about_health(): void {
		$report = Environment::report();

		foreach ( $report['rows'] as $row ) {
			if ( ! in_array( $row['status'], self::LOUD, true ) ) {
				continue;
			}

			WP_CLI::warning( sprintf( '%s: %s', $row['label'], $row['message'] ) );
		}
	}
}
