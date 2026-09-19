<?php
/**
 * `admin-post.php` handlers for the Status tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Post;

use LightweightPlugins\Scan\Admin\SettingsPage;
use LightweightPlugins\Scan\Status\EndpointSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The Status tab's two writes: saving the switch and the reuse period,
 * and generating a new URL. They cannot go through the Settings tab's
 * `options.php` form — that form posts `lw_scan_options` and its
 * sanitizer, and this is a separate option whose key must never be
 * posted back from the browser — so each is a plain form POST to
 * `admin-post.php`.
 *
 * Every write checks its own nonce (`check_admin_referer()` ends the
 * request on a bad one) and `manage_options` before touching anything.
 * `save()`/`rotate()` return the URL to go back to, so the redirect and
 * the `exit` stay in the thin `handle_*()` wrappers WordPress calls.
 */
final class StatusEndpointHandler {

	public const SAVE_ACTION = 'lw_scan_status_save';

	public const SAVE_NONCE = 'lw_scan_status_nonce';

	public const ROTATE_ACTION = 'lw_scan_status_rotate';

	public const ROTATE_NONCE = 'lw_scan_rotate_nonce';

	/** Query argument the Status tab reads to show what just happened. */
	public const NOTICE_ARG = 'lw-scan-status';

	/**
	 * Hooked from `Plugin::init_components()` on admin requests only;
	 * `admin-post.php` is one.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, [ self::class, 'handle_save' ] );
		add_action( 'admin_post_' . self::ROTATE_ACTION, [ self::class, 'handle_rotate' ] );
	}

	public static function handle_save(): void {
		self::redirect( self::save( new EndpointSettings() ) );
	}

	public static function handle_rotate(): void {
		self::redirect( self::rotate( new EndpointSettings() ) );
	}

	/**
	 * Stores the switch and — when it is one the form offers — the reuse
	 * period. Switching on provisions a key if the site has none.
	 *
	 * @param EndpointSettings $settings Endpoint settings.
	 * @return string Where to send the browser.
	 */
	public static function save( EndpointSettings $settings ): string {
		self::guard( self::SAVE_ACTION, self::SAVE_NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by guard() above.
		$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_key( wp_unslash( $_POST['enabled'] ) );
		$ttl     = isset( $_POST['cache_ttl'] ) ? absint( wp_unslash( $_POST['cache_ttl'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$settings->set_enabled( $enabled );

		if ( in_array( $ttl, EndpointSettings::TTL_CHOICES, true ) ) {
			$settings->set_cache_ttl( $ttl );
		}

		if ( $enabled ) {
			$settings->ensure_key();
		}

		return self::back( 'saved' );
	}

	/**
	 * Replaces the key; the previous URL stops working at once.
	 *
	 * @param EndpointSettings $settings Endpoint settings.
	 * @return string Where to send the browser.
	 */
	public static function rotate( EndpointSettings $settings ): string {
		self::guard( self::ROTATE_ACTION, self::ROTATE_NONCE );

		$settings->generate_key();

		return self::back( 'rotated' );
	}

	/**
	 * @param string $action Nonce action.
	 * @param string $field  Nonce field name.
	 */
	private static function guard( string $action, string $field ): void {
		check_admin_referer( $action, $field );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to change the status endpoint.', 'lw-scan' ),
				'',
				[ 'response' => 403 ]
			);
		}
	}

	/**
	 * @param string $notice saved|rotated.
	 */
	private static function back( string $notice ): string {
		return add_query_arg( self::NOTICE_ARG, $notice, SettingsPage::tab_url( 'status' ) );
	}

	/**
	 * @param string $url Status tab URL.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
