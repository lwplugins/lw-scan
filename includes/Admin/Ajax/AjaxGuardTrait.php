<?php
/**
 * Shared nonce + capability guard for every LW Scan AJAX endpoint.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Admin\SettingsPage;
use LightweightPlugins\Scan\Run\Cursor;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §13: every admin AJAX handler starts with `guard()`.
 * `check_ajax_referer()` dies on its own on a bad/missing nonce (its
 * default `$die = true`), so only the capability check needs an explicit
 * response here.
 *
 * `refuse_if_run_active()` is a second, opt-in guard for handlers that
 * mutate shared state a live (or stopped-but-resumable) run depends on —
 * `IndexHandler` truncating the file index a `FilesPhase` cursor points
 * into, `BundleHandler` swapping the signature pack a captured regex
 * index was built from. Only those two call it; the rest are safe to run
 * alongside a scan.
 */
trait AjaxGuardTrait {

	/**
	 * Verifies the shared nonce and the `manage_options` capability. Ends
	 * the request with a 403 JSON error when the capability check fails.
	 *
	 * @return void
	 */
	private static function guard(): void {
		check_ajax_referer( SettingsPage::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
	}

	/**
	 * Refuses the request with `lw_scan_busy` while a run's cursor still
	 * exists — running, or stopped but left resumable. The pure predicate
	 * behind this lives in `AjaxGuard::run_active()` so it is unit-testable
	 * without a live cursor/DB.
	 *
	 * @return void
	 */
	private static function refuse_if_run_active(): void {
		if ( ! AjaxGuard::run_active( Cursor::load() ) ) {
			return;
		}

		wp_send_json_error(
			[
				'code'    => 'lw_scan_busy',
				'message' => __( 'A scan is in progress. Stop it or wait until it finishes, then try again.', 'lw-scan' ),
			]
		);
	}
}
