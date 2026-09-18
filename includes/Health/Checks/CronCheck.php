<?php
/**
 * WP-Cron and loopback connectivity check for the Health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §12: whether `DISABLE_WP_CRON` is set, and — only when `$probe` is
 * true — whether the server can reach its own `wp-cron.php` (a 3-second
 * `wp_remote_post()`, mirroring `lw-img`'s `Health\CronChecks`).
 * `Environment` passes `$probe = true` only when building a fresh report
 * for the Health tab (spec: "only on opening the Health tab, cached 10
 * minutes"), never for `blocking_issue()` or a cached read, so this check
 * never fires an HTTP request on every page load.
 */
final class CronCheck implements CheckInterface {

	/**
	 * @var bool
	 */
	private bool $probe;

	public function __construct( bool $probe = false ) {
		$this->probe = $probe;
	}

	public function id(): string {
		return 'cron';
	}

	public function label(): string {
		return __( 'WP-Cron', 'lw-scan' );
	}

	public function run(): array {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( ! $this->probe ) {
			return [
				'status'   => 'info',
				'message'  => $disabled
					? __( 'DISABLE_WP_CRON is set — a system cron runner is expected to trigger scheduled scans.', 'lw-scan' )
					: __( 'Built-in WP-Cron (triggered by site visits).', 'lw-scan' ),
				'blocking' => false,
			];
		}

		$response = wp_remote_post(
			site_url( 'wp-cron.php' ),
			[
				'timeout'   => 3,
				'sslverify' => false,
			]
		);

		$ok = ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 400;

		return [
			'status'   => $ok ? 'ok' : 'warning',
			'message'  => $ok
				? __( 'The server can reach its own site for WP-Cron.', 'lw-scan' )
				: __( 'The server cannot reach its own site — scheduled scans only progress via site traffic, WP-CLI, or a system cron runner.', 'lw-scan' ),
			'blocking' => false,
		];
	}
}
