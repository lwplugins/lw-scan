<?php
/**
 * `wp lw-scan bundle <status|update|force-full>` — signature bundle maintenance.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Admin\Settings\BundleInfo;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\PackFetcher;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The Health tab's bundle card as a command (spec §11.2): `status` reads
 * the stored facts the admin shows (`Admin\Settings\BundleInfo`, the same
 * `lw_scan_state` reads, so a tab and a terminal never disagree), `update`
 * forces a backend check past the once-an-hour skip, and `force-full`
 * additionally ignores the ETag and the "already at this version"
 * short-circuit, always re-downloading and re-verifying the current pack
 * (`PackFetcher::force_full()`).
 *
 * Both writing sub-commands go through `RunGuard`: they would swap the
 * signature pack under a running scan.
 */
final class BundleCommand {

	/**
	 * Show or refresh the signature bundle.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : What to do.
	 * ---
	 * options:
	 *   - status
	 *   - update
	 *   - force-full
	 * ---
	 *
	 * [--force]
	 * : Run the update even while a scan is in progress.
	 *
	 * [--format=<format>]
	 * : Output format of `bundle status`.
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
	 *     wp lw-scan bundle status
	 *     wp lw-scan bundle update
	 *     wp lw-scan bundle force-full
	 *
	 * @param array<int, string>   $args  Positional arguments: the operation.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$operation = (string) ( $args[0] ?? '' );

		if ( 'status' === $operation ) {
			self::status( (string) Utils\get_flag_value( $assoc, 'format', 'table' ) );

			return;
		}

		if ( 'update' !== $operation && 'force-full' !== $operation ) {
			WP_CLI::error( sprintf( 'Unknown operation "%s". Use status, update or force-full.', $operation ) );
		}

		RunGuard::refuse_if_run_active( $assoc );

		$fetcher = new PackFetcher( new Client(), new Store() );

		self::report( 'force-full' === $operation ? $fetcher->force_full() : $fetcher->check( true ) );
	}

	/**
	 * @param string $format table|json|csv.
	 * @return void
	 */
	private static function status( string $format ): void {
		$rows = Formatter::metric_rows(
			[
				'version'    => (string) BundleInfo::version(),
				'signatures' => (string) BundleInfo::signature_count(),
				'checked'    => Formatter::timestamp( BundleInfo::checked_at() ),
			]
		);

		Utils\format_items( $format, $rows, Formatter::SUMMARY_COLUMNS );
	}

	/**
	 * @param array{status:string, version:int, message:string, new_ids:string[]} $result What the fetcher did.
	 * @return void
	 */
	private static function report( array $result ): void {
		$message = sprintf( '%s (bundle %d, %d new signatures)', $result['message'], $result['version'], count( $result['new_ids'] ) );

		if ( 'failed' === $result['status'] ) {
			WP_CLI::error( $message );
		}

		WP_CLI::success( $message );
	}
}
