<?php
/**
 * The REST API's error responses.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use LightweightPlugins\Scan\Run\ActiveRun;
use LightweightPlugins\Scan\Run\Cursor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every error the `lw-scan/v1` admin routes answer with is a `WP_Error`
 * carrying an HTTP status, so `@wordpress/api-fetch` rejects with the code
 * and the message.
 *
 * `busy_if_run_active()` guards the writes that mutate shared state a live
 * (or stopped-but-resumable) run depends on: clearing findings, swapping
 * the signature pack, rebuilding the file index.
 */
final class Errors {

	public const BUSY = 'lw_scan_busy';

	/**
	 * The 409 refusal, or null when no run's cursor exists.
	 */
	public static function busy_if_run_active(): ?WP_Error {
		if ( ! ActiveRun::run_active( Cursor::load() ) ) {
			return null;
		}

		return new WP_Error(
			self::BUSY,
			__( 'A scan is in progress. Stop it or wait until it finishes, then try again.', 'lw-scan' ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * A 400 for input the route cannot act on.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 */
	public static function bad_request( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => 400 ] );
	}

	/**
	 * A `Run\Runner`/`Run\Starter` refusal with its HTTP status: 409 for
	 * `lw_scan_busy`, 400 for everything else.
	 *
	 * @param WP_Error $error Refusal without a status.
	 */
	public static function from_runner( WP_Error $error ): WP_Error {
		$code = (string) $error->get_error_code();

		return new WP_Error(
			$code,
			$error->get_error_message(),
			[ 'status' => self::BUSY === $code ? 409 : 400 ]
		);
	}
}
