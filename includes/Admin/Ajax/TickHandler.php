<?php
/**
 * `lw_scan_tick` — one assist tick, fired when the poll loop notices the
 * cron-driven relay has stalled.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Run\Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.1: assist bursts stay short — capped at 8 seconds even though
 * `Runner::budget()` may allow more, so a stalled poll loop never turns
 * into a slow admin request.
 */
final class TickHandler {

	use AjaxGuardTrait;

	/** Hard cap on an assist tick's budget, seconds. */
	private const MAX_BUDGET = 8.0;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_tick', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();

		wp_send_json_success( ( new Runner() )->tick( min( Runner::budget(), self::MAX_BUDGET ) ) );
	}
}
