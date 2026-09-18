<?php
/**
 * `lw_scan_bundle` — Health tab bundle maintenance (check / force full).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\PackFetcher;

defined( 'ABSPATH' ) || exit;

/**
 * `op` selects the sub-command — not `action`, which admin-ajax.php already
 * uses to route the request (see task-21-report.md). `check` forces a fresh
 * backend check (bypassing the "checked within the last hour" skip);
 * `full` (`PackFetcher::force_full()`) additionally ignores the ETag and the
 * "already at this version" short-circuit, always re-downloading and
 * re-verifying the current pack.
 *
 * Refused outright while a run's cursor exists: swapping the signature pack
 * mid-run would invalidate a regex index a scan phase already captured for
 * this tick.
 */
final class BundleHandler {

	use AjaxGuardTrait;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_bundle', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();
		self::refuse_if_run_active();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AjaxGuardTrait::guard() above.
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		$fetcher = new PackFetcher( new Client(), new Store() );

		if ( 'full' === $op ) {
			wp_send_json_success( $fetcher->force_full() );
		}

		if ( 'check' === $op ) {
			wp_send_json_success( $fetcher->check( true ) );
		}

		wp_send_json_error( [ 'message' => __( 'Unknown bundle operation.', 'lw-scan' ) ] );
	}
}
