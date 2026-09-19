<?php
/**
 * Deciding logic behind `wp lw-scan endpoint …`.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Status\EndpointSettings;

defined( 'ABSPATH' ) || exit;

/**
 * What each `endpoint` sub-command does to `Status\EndpointSettings` and
 * what it should report — with no `WP_CLI` in it, the same way `Formatter`
 * is the presentation half of the rest of the CLI surface with none in it.
 * `EndpointCommand` is the only caller; it turns these plain result arrays
 * into `WP_CLI::success()`/`::error()`/`::line()` calls.
 *
 * Every write goes through the one `EndpointSettings` instance this class
 * was built with — the same object `Admin\Post\StatusEndpointHandler` and
 * `Admin\Settings\TabStatus` use — so a CLI call and an admin-screen click
 * leave the site in identical state. An authenticated CLI call is the same
 * trust level as the admin screen, so `url()` and `enable()` provision a
 * missing key exactly as opening the Status tab does; a public request
 * never may (`EndpointSettings::ensure_key()`'s own contract).
 */
final class EndpointCli {

	/** Minutes the Status tab's TTL select offers, e.g. "1, 5, 15, 30, 60". */
	private const ALLOWED_MINUTES_LIST = '1, 5, 15, 30, 60';

	/** @var EndpointSettings Switch, key and reuse period. */
	private EndpointSettings $settings;

	public function __construct( ?EndpointSettings $settings = null ) {
		$this->settings = $settings ?? new EndpointSettings();
	}

	/**
	 * The status URL, or why there is none. Creates a key when the
	 * endpoint is on and has none yet; never while it is off.
	 *
	 * @return array{ok:bool, url:string, message:string}
	 */
	public function url(): array {
		if ( ! $this->settings->is_enabled() ) {
			return [
				'ok'      => false,
				'url'     => '',
				'message' => 'The status endpoint is off. Enable it first: wp lw-scan endpoint enable',
			];
		}

		$had_key = $this->settings->has_key();
		$this->settings->ensure_key();

		return [
			'ok'      => true,
			'url'     => $this->settings->status_url(),
			'message' => $had_key ? '' : 'Created a new key.',
		];
	}

	/**
	 * Switches the endpoint on and provisions a key if it has none,
	 * whether or not it was already on.
	 *
	 * @return array{changed:bool, message:string}
	 */
	public function enable(): array {
		$already = $this->settings->is_enabled();
		$had_key = $this->settings->has_key();

		$this->settings->set_enabled( true );
		$this->settings->ensure_key();

		$prefix = $already ? 'Already enabled.' : 'Enabled.';

		return [
			'changed' => ! $already,
			'message' => $had_key ? $prefix : $prefix . ' Created a new key.',
		];
	}

	/**
	 * Switches the endpoint off.
	 *
	 * @return array{changed:bool, message:string}
	 */
	public function disable(): array {
		$already_off = ! $this->settings->is_enabled();

		$this->settings->set_enabled( false );

		return [
			'changed' => ! $already_off,
			'message' => $already_off ? 'Already disabled.' : 'Disabled.',
		];
	}

	/**
	 * Replaces the key. The previous URL stops working the moment this
	 * returns (`EndpointSettings::generate_key()` drops the cached report
	 * with it).
	 *
	 * @return array{url:string}
	 */
	public function rotate(): array {
		$this->settings->generate_key();

		return [ 'url' => $this->settings->status_url() ];
	}

	/**
	 * Reads or sets the reuse period.
	 *
	 * @param int|null $minutes New reuse period in minutes, or null to only read the current one.
	 * @return array{ok:bool, minutes:int, message:string}
	 */
	public function ttl( ?int $minutes ): array {
		if ( null === $minutes ) {
			return [
				'ok'      => true,
				'minutes' => intdiv( $this->settings->cache_ttl(), MINUTE_IN_SECONDS ),
				'message' => '',
			];
		}

		if ( ! in_array( $minutes, self::allowed_minutes(), true ) ) {
			return [
				'ok'      => false,
				'minutes' => 0,
				'message' => sprintf( 'Allowed values (minutes): %s.', self::ALLOWED_MINUTES_LIST ),
			];
		}

		$this->settings->set_cache_ttl( $minutes * MINUTE_IN_SECONDS );

		return [
			'ok'      => true,
			'minutes' => $minutes,
			'message' => '',
		];
	}

	/**
	 * The switch, the reuse period and the key's presence and age.
	 *
	 * @return array{enabled:bool, ttl_minutes:int, has_key:bool, key_set_at:int}
	 */
	public function status(): array {
		return [
			'enabled'     => $this->settings->is_enabled(),
			'ttl_minutes' => intdiv( $this->settings->cache_ttl(), MINUTE_IN_SECONDS ),
			'has_key'     => $this->settings->has_key(),
			'key_set_at'  => $this->settings->key_set_at(),
		];
	}

	/**
	 * @return array<int, int> The Status tab's TTL choices, in minutes, derived from `EndpointSettings::TTL_CHOICES`.
	 */
	private static function allowed_minutes(): array {
		return array_map(
			static fn ( int $seconds ): int => intdiv( $seconds, MINUTE_IN_SECONDS ),
			EndpointSettings::TTL_CHOICES
		);
	}
}
