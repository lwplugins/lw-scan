<?php
/**
 * `wp lw-scan stop` — asks the running scan to stop.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Run\ActiveRun;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\StopFlag;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Cooperative, like the admin's Stop button: this sets the flag
 * `Runner::tick()` polls between phase steps, it does not kill anything.
 * The stopped run keeps its cursor, so `wp lw-scan run --resume` picks it
 * up where it left off.
 */
final class StopCommand {

	/**
	 * Ask the scan in progress to stop at its next checkpoint.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-scan stop
	 *
	 * @param array<int, string>   $args  Positional arguments (unused).
	 * @param array<string, mixed> $assoc Named arguments (unused).
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		unset( $args, $assoc );

		if ( ! ActiveRun::run_active( Cursor::load() ) ) {
			WP_CLI::error( 'No scan is in progress.' );
		}

		StopFlag::request();

		WP_CLI::success( 'Stop requested. The scan stops at its next checkpoint; resume it with: wp lw-scan run --resume' );
	}
}
