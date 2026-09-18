<?php
/**
 * Registers every `wp_ajax_lw_scan_*` admin endpoint.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked from `Plugin::init_components()` on admin requests only (spec
 * §11.1), next to `SettingsPage::register()`. One `register()` call per
 * handler; no handler registers itself on load.
 */
final class Handlers {

	public static function register(): void {
		StartHandler::register();
		TickHandler::register();
		StatusHandler::register();
		StopHandler::register();
		FindingStateHandler::register();
		ClearFindingsHandler::register();
		BundleHandler::register();
		IndexHandler::register();
		HealthHandler::register();
	}
}
