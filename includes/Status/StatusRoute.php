<?php
/**
 * The secret-keyed, read-only status endpoint.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Status;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /wp-json/lw-scan/v1/status/<key>` for an external monitoring
 * service, modelled on HelloPack Client's status endpoint so sites without
 * HelloPack can be watched the same way.
 *
 * - The route exists only while the endpoint is on and a key is stored; a
 *   disabled endpoint is a route WordPress has never heard of.
 * - Only the path segment counts as the key. `get_param()` would let a
 *   `?key=` query argument override it.
 * - A wrong key answers with WordPress core's own `rest_no_route` error —
 *   same code, message and status — so it reads exactly like a URL that
 *   does not exist.
 * - `?http_status=1` answers 503 while the report is `crit`, for monitors
 *   that only look at the status code; `?fresh=1` skips the cached result.
 */
final class StatusRoute {

	public const NAMESPACE = 'lw-scan/v1';

	public const ROUTE = '/status/(?P<key>[a-f0-9]{32})';

	/** @var EndpointSettings Switch and key. */
	private EndpointSettings $settings;

	/** @var StatusReport What the route publishes. */
	private StatusReport $report;

	/**
	 * @param EndpointSettings|null $settings Endpoint settings.
	 * @param StatusReport|null     $report   Report builder.
	 */
	public function __construct( ?EndpointSettings $settings = null, ?StatusReport $report = null ) {
		$this->settings = $settings ?? new EndpointSettings();
		$this->report   = $report ?? new StatusReport( $this->settings );
	}

	/**
	 * Hooked from `Plugin::init_components()` on every request: REST
	 * requests are not admin requests. Nothing is read until
	 * `rest_api_init` actually fires.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'on_rest_api_init' ] );
	}

	public static function on_rest_api_init(): void {
		( new self() )->register_routes();
	}

	public function register_routes(): void {
		if ( ! $this->settings->is_enabled() || ! $this->settings->has_key() ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'status' ],
				'permission_callback' => '__return_true', // Read-only; the key in the path is the credential and a wrong one is a 404.
			]
		);
	}

	/**
	 * GET /status/<key>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$presented = $request->get_url_params()['key'] ?? '';

		if ( ! is_string( $presented ) || ! $this->settings->verify_key( $presented ) ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WP core's own string in its default text domain on purpose: the answer must be byte-for-byte what a non-existent route returns.
				[ 'status' => 404 ]
			);
		}

		$body = $this->report->wire( self::flag( $request, 'fresh' ) );
		$code = self::flag( $request, 'http_status' ) && 'crit' === $body['overall'] ? 503 : 200;

		$response = new WP_REST_Response( $body, $code );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	/**
	 * Whether a boolean-ish query parameter is set to a truthy value.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 */
	private static function flag( WP_REST_Request $request, string $name ): bool {
		$value = $request->get_param( $name );

		return is_scalar( $value ) && in_array( strtolower( (string) $value ), [ '1', 'true', 'yes' ], true );
	}
}
