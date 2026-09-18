<?php
/**
 * `wp lw-scan findings` — the stored findings, filtered and paginated.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Db\FindingsRepository;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The Findings tab's list, on the terminal: the same
 * `FindingsRepository::list()` filters and paging, printed through
 * `Formatter::findings_rows()`. `--format=json` prints the full rows the
 * repository returns (JSON columns already decoded) instead of the seven
 * summary columns, so scripts get the whole finding model (spec §11.2).
 */
final class FindingsCommand {

	/** Rows per page, matching the admin table's page size. */
	private const PER_PAGE = 50;

	/**
	 * List findings.
	 *
	 * ## OPTIONS
	 *
	 * [--severity=<severity>]
	 * : Only findings of this severity.
	 * ---
	 * options:
	 *   - alert
	 *   - review
	 * ---
	 *
	 * [--type=<type>]
	 * : Only findings of this type.
	 * ---
	 * options:
	 *   - file
	 *   - integrity
	 *   - db
	 *   - vulnerability
	 * ---
	 *
	 * [--state=<state>]
	 * : Only findings in this state.
	 * ---
	 * options:
	 *   - new
	 *   - acknowledged
	 *   - ignored
	 * ---
	 *
	 * [--page=<number>]
	 * : Page to print.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--per-page=<number>]
	 * : Findings per page.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. json prints the full finding rows; json and csv
	 * print one parseable document and no prose.
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
	 *     wp lw-scan findings
	 *     wp lw-scan findings --severity=alert --state=new
	 *     wp lw-scan findings --type=vulnerability --format=json
	 *
	 * @param array<int, string>   $args  Positional arguments (unused).
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		unset( $args );

		$format = (string) Utils\get_flag_value( $assoc, 'format', 'table' );
		$page   = max( 1, (int) Utils\get_flag_value( $assoc, 'page', 1 ) );
		$per    = max( 1, (int) Utils\get_flag_value( $assoc, 'per-page', self::PER_PAGE ) );

		$result = FindingsRepository::list( self::filters( $assoc ), $page, $per );
		$items  = $result['items'];

		// json/csv print one document and nothing else — an empty page is
		// an empty document, not prose, so scripts can parse either.
		if ( 'table' !== $format ) {
			self::document( $format, $items );

			return;
		}

		if ( [] === $items ) {
			WP_CLI::log( 'No findings match.' );

			return;
		}

		Utils\format_items( 'table', Formatter::findings_rows( $items ), Formatter::FINDINGS_COLUMNS );

		WP_CLI::log( sprintf( 'Page %d of %d (%d findings).', $page, (int) ceil( $result['total'] / $per ), $result['total'] ) );
	}

	/**
	 * @param string                           $format json|csv.
	 * @param array<int, array<string, mixed>> $items  Findings rows from the repository, JSON columns decoded.
	 * @return void
	 */
	private static function document( string $format, array $items ): void {
		if ( 'json' !== $format ) {
			Utils\format_items( $format, Formatter::findings_rows( $items ), Formatter::FINDINGS_COLUMNS );

			return;
		}

		Utils\format_items( 'json', $items, [] === $items ? Formatter::FINDINGS_COLUMNS : array_keys( (array) reset( $items ) ) );
	}

	/**
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return array{severity?:string, type?:string, state?:string} Only the filters actually passed.
	 */
	private static function filters( array $assoc ): array {
		$filters = [];

		foreach ( [ 'severity', 'type', 'state' ] as $key ) {
			$value = (string) Utils\get_flag_value( $assoc, $key, '' );

			if ( '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		return $filters;
	}
}
