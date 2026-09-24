<?php
/**
 * Contract for the `lw-scan/v1` admin route groups.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * One controller per admin area. `Routes` adds the shared
 * `permission_callback` to every endpoint, so a controller only says which
 * paths it answers and with what.
 */
interface ControllerInterface {

	/**
	 * Route path (relative to the namespace) => list of endpoint
	 * definitions (`methods`, `callback`), as `register_rest_route()`
	 * takes them minus the permission callback.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function routes(): array;
}
