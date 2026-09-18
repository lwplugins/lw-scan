<?php
/**
 * `wp lw-scan index <rebuild|stats>` — file-index maintenance.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\FindingsRepository;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * `rebuild` does exactly what the Health tab's button does
 * (`Admin\Ajax\IndexHandler`): empties the file index and the findings
 * derived purely from it — `file` and `integrity` — so the next scan
 * starts from a clean slate. `db` and `vulnerability` findings are not
 * file-index derived and are left alone.
 *
 * Refused while a run's cursor exists (`RunGuard`): a live files phase
 * points at the very rows this deletes.
 */
final class IndexCommand {

	/**
	 * Rebuild the file index, or show what is in it.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : What to do.
	 * ---
	 * options:
	 *   - rebuild
	 *   - stats
	 * ---
	 *
	 * [--force]
	 * : Rebuild even while a scan is in progress.
	 *
	 * [--format=<format>]
	 * : Output format of `index stats`.
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
	 *     wp lw-scan index stats
	 *     wp lw-scan index rebuild
	 *
	 * @param array<int, string>   $args  Positional arguments: the operation.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$operation = (string) ( $args[0] ?? '' );

		if ( 'stats' === $operation ) {
			self::stats( (string) Utils\get_flag_value( $assoc, 'format', 'table' ) );

			return;
		}

		if ( 'rebuild' !== $operation ) {
			WP_CLI::error( sprintf( 'Unknown operation "%s". Use rebuild or stats.', $operation ) );
		}

		RunGuard::refuse_if_run_active( $assoc );

		( new FilesRepository() )->truncate();
		FindingsRepository::truncate_type( 'file' );
		FindingsRepository::truncate_type( 'integrity' );

		WP_CLI::success( 'File index emptied. The next scan re-indexes and re-scans every file.' );
	}

	/**
	 * @param string $format table|json|csv.
	 * @return void
	 */
	private static function stats( string $format ): void {
		$stats = ( new FilesRepository() )->stats();

		$values = [
			'files'      => (string) $stats['total'],
			'known good' => (string) $stats['known_good'],
		];

		foreach ( $stats['kinds'] as $kind => $total ) {
			$values[ sprintf( 'kind: %s', '' === (string) $kind ? 'unknown' : (string) $kind ) ] = (string) $total;
		}

		Utils\format_items( $format, Formatter::metric_rows( $values ), Formatter::SUMMARY_COLUMNS );
	}
}
