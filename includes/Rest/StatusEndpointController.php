<?php
/**
 * `/status-endpoint*` — the Status tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use LightweightPlugins\Scan\Status\EndpointSettings;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only status endpoint's switch, secret URL and reuse period.
 * This is a separate option from `lw_scan_options`, and its key is never
 * accepted from the browser: the only way to change it is `rotate`.
 *
 * Reading it is the admin-side half of key provisioning, as opening the
 * classic tab was: a site active before the endpoint existed never ran
 * the activation hook that gives it a key, so the first read does. A
 * public request never creates one.
 */
final class StatusEndpointController implements ControllerInterface {

	/** @var EndpointSettings Switch, key and TTL. */
	private EndpointSettings $settings;

	/**
	 * @param EndpointSettings|null $settings Endpoint settings.
	 */
	public function __construct( ?EndpointSettings $settings = null ) {
		$this->settings = $settings ?? new EndpointSettings();
	}

	public function routes(): array {
		return [
			'/status-endpoint'        => [
				[
					'methods'  => 'GET',
					'callback' => [ $this, 'show' ],
				],
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'save' ],
				],
			],
			'/status-endpoint/rotate' => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'rotate' ],
				],
			],
		];
	}

	/**
	 * GET /status-endpoint.
	 *
	 * @return array<string, mixed>
	 */
	public function show(): array {
		$this->settings->ensure_key();

		return $this->view();
	}

	/**
	 * POST /status-endpoint — `enabled` and/or `cache_ttl`. A TTL that is
	 * not one of the offered choices is ignored; switching on provisions a
	 * key if the site has none.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function save( WP_REST_Request $request ): array {
		if ( Params::has( $request, 'enabled' ) ) {
			$this->settings->set_enabled( Params::bool( $request, 'enabled' ) );
		}

		$ttl = Params::int( $request, 'cache_ttl', 0, 0, PHP_INT_MAX );

		if ( in_array( $ttl, EndpointSettings::TTL_CHOICES, true ) ) {
			$this->settings->set_cache_ttl( $ttl );
		}

		if ( $this->settings->is_enabled() ) {
			$this->settings->ensure_key();
		}

		return $this->view();
	}

	/**
	 * POST /status-endpoint/rotate — the previous URL stops working at once.
	 *
	 * @return array<string, mixed>
	 */
	public function rotate(): array {
		$this->settings->generate_key();

		return $this->view();
	}

	/**
	 * @return array{enabled:bool, url:string, key_set_at:int, cache_ttl:int, ttl_choices:int[]}
	 */
	private function view(): array {
		return [
			'enabled'     => $this->settings->is_enabled(),
			'url'         => $this->settings->status_url(),
			'key_set_at'  => $this->settings->key_set_at(),
			'cache_ttl'   => $this->settings->cache_ttl(),
			'ttl_choices' => EndpointSettings::TTL_CHOICES,
		];
	}
}
