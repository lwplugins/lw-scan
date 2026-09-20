<?php
/**
 * Renders the "new alerts" admin notice.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Notify;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §10.8: an `admin_notices` notice, shown only to `manage_options`
 * users, only when `Options::get('admin_notice')` is on, and only while
 * there is at least one new alert-severity finding. Not dismissible — the
 * Findings tab's acknowledge/ignore actions are what makes it go away.
 *
 * While the newest run is the site's baseline scan (`Notify\Baseline`), the
 * notice also says so: that run deliberately mailed nothing, and an admin
 * who sees alerts but no e-mail deserves to know why.
 */
final class AdminNotice {

	/**
	 * Hooks the notice into `admin_notices`.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	/**
	 * Prints the notice, if all its conditions are met.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Options::get( 'admin_notice' ) ) {
			return;
		}

		$counts = FindingsRepository::counts();
		$alerts = (int) ( $counts['state']['new']['alert'] ?? 0 );

		if ( $alerts <= 0 ) {
			return;
		}

		$message = sprintf(
			/* translators: %d: number of new alert-level findings. */
			_n( '%d new alert found by LW Scan.', '%d new alerts found by LW Scan.', $alerts, 'lw-scan' ),
			$alerts
		);

		if ( ( new Baseline() )->pending_review() ) {
			$message .= ' ' . __( 'This was the first scan, so these are a baseline of what is already on the site — no e-mail was sent. Later scans report only what is new.', 'lw-scan' );
		}

		printf(
			'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( admin_url( 'admin.php?page=lw-scan&tab=findings' ) ),
			esc_html__( 'Review findings', 'lw-scan' )
		);
	}
}
