<?php
/**
 * Registers the `lw-scan/v1` admin REST routes.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use LightweightPlugins\Scan\Status\StatusRoute;

defined( 'ABSPATH' ) || exit;

/**
 * The backend of the React admin screen (docs/react-admin-api.md). Every
 * route requires `manage_options`; the React app authenticates with the
 * REST cookie + `X-WP-Nonce` that `@wordpress/api-fetch` sends, and core
 * verifies that nonce before the permission callback runs.
 *
 * Registered from `Plugin::init_components()` on every request type: REST
 * requests are not admin requests. Nothing is built until
 * `rest_api_init` fires. The public `GET /status/<key>` route in the same
 * namespace stays `Status\StatusRoute`'s.
 */
final class Routes {

	public const NAMESPACE = StatusRoute::NAMESPACE;

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		foreach ( self::controllers() as $controller ) {
			foreach ( $controller->routes() as $route => $endpoints ) {
				register_rest_route( self::NAMESPACE, $route, array_map( [ self::class, 'guarded' ], $endpoints ) );
			}
		}
	}

	/**
	 * Every admin route is for site administrators only.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param array<string, mixed> $endpoint Endpoint definition.
	 * @return array<string, mixed>
	 */
	private static function guarded( array $endpoint ): array {
		$endpoint['permission_callback'] = [ self::class, 'can_manage' ];

		return $endpoint;
	}

	/**
	 * @return ControllerInterface[]
	 */
	private static function controllers(): array {
		return [
			new ScanController(),
			new FindingsController(),
			new HealthController(),
			new SettingsController(),
			new StatusEndpointController(),
		];
	}
}
