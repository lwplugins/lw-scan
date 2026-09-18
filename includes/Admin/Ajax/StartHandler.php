<?php
/**
 * `lw_scan_start` — opens a new scan run and kicks the tick relay.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Run\Runner;
use LightweightPlugins\Scan\Run\Scheduler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.1: `scope`, `path` (scope=path only), `heuristics` and `resume`
 * from the Start bar. Validating the scope here — before anything else
 * happens — keeps a doomed request (unknown scope) from persisting the
 * heuristics toggle or touching Runner::start() at all. Runner::start()
 * (via Run\Starter) still validates the scope again on its own path; that
 * is defense in depth, not this handler's only line of defence.
 *
 * The heuristics toggle is persisted to the plugin's options — not passed
 * as a one-off Runner::start() argument — so the pipeline's own
 * Options::all() read picks it up for this and every later run.
 */
final class StartHandler {

	use AjaxGuardTrait;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_start', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AjaxGuardTrait::guard() above.
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AjaxGuardTrait::guard() above.
		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AjaxGuardTrait::guard() above.
		$heuristics = isset( $_POST['heuristics'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['heuristics'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AjaxGuardTrait::guard() above.
		$resume = isset( $_POST['resume'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['resume'] ) );

		if ( ! Phases::valid_scope( $scope ) ) {
			wp_send_json_error(
				[
					'code'    => 'lw_scan_bad_scope',
					'message' => __( 'Unknown scan scope.', 'lw-scan' ),
				]
			);
		}

		Options::update( [ 'heuristics' => $heuristics ] );

		$result = ( new Runner() )->start( 'manual', $scope, $path, $resume );

		if ( $result instanceof WP_Error ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				]
			);
		}

		Scheduler::kick( 0 );

		wp_send_json_success( [ 'run_id' => $result ] );
	}
}
