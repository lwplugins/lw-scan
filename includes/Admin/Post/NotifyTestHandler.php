<?php
/**
 * `admin-post.php` handler for the Notifications tab's test e-mail.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Post;

use LightweightPlugins\Scan\Admin\SettingsPage;
use LightweightPlugins\Scan\Notify\Mailer;
use LightweightPlugins\Scan\Notify\Preferences;
use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * "Send a test e-mail" is a write (it sends something), not a settings
 * save, so it posts to `admin-post.php` behind its own nonce and a
 * `manage_options` check rather than riding along with the options form.
 *
 * It mails the *stored* recipients: the button submits the separate form
 * the Notifications tab renders after the settings form, so unsaved edits in
 * the recipients field are not part of the question being asked. The tab's
 * help text says so.
 *
 * `send()` returns the URL to go back to, so the redirect and the `exit`
 * stay in the thin `handle()` wrapper WordPress calls — the same shape as
 * `StatusEndpointHandler`.
 */
final class NotifyTestHandler {

	public const ACTION = 'lw_scan_notify_test';

	public const NONCE = 'lw_scan_notify_nonce';

	/** Query argument the Notifications tab reads to show what happened. */
	public const NOTICE_ARG = 'lw-scan-notify';

	/**
	 * Hooked from `Plugin::init_components()` on admin requests only;
	 * `admin-post.php` is one.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		$url = self::send();

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Mails the configured recipients, or says why it could not.
	 *
	 * @return string Where to send the browser.
	 */
	public static function send(): string {
		check_admin_referer( self::ACTION, self::NONCE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to send LW Scan e-mails.', 'lw-scan' ),
				'',
				[ 'response' => 403 ]
			);
		}

		$options = Options::all();

		if ( [] === ( new Preferences( $options ) )->recipients() ) {
			return self::back( 'norecipients' );
		}

		return self::back( Mailer::send_test( $options ) ? 'sent' : 'failed' );
	}

	/**
	 * @param string $notice sent|failed|norecipients.
	 */
	private static function back( string $notice ): string {
		return add_query_arg( self::NOTICE_ARG, $notice, SettingsPage::tab_url( 'notifications' ) );
	}
}
