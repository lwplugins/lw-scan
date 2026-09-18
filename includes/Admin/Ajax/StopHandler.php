<?php
/**
 * `lw_scan_stop` — requests the in-progress run stop at its next chance.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Run\StopFlag;

defined( 'ABSPATH' ) || exit;

/**
 * Cooperative: sets the flag `Runner::tick()` polls between phase steps.
 * The next `lw_scan_status` poll reports the stop taking effect.
 */
final class StopHandler {

	use AjaxGuardTrait;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_stop', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();

		StopFlag::request();

		wp_send_json_success();
	}
}
