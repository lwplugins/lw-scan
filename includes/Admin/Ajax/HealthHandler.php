<?php
/**
 * `lw_scan_health` — forces a fresh environment health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Health\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * `$fresh = true` bypasses the 10-minute transient cache and re-runs every
 * check, including the cron loopback probe (spec §12) — this is the only
 * caller that is allowed to do that outside opening the Health tab.
 */
final class HealthHandler {

	use AjaxGuardTrait;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_health', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();

		wp_send_json_success( Environment::report( true ) );
	}
}
