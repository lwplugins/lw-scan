<?php
/**
 * Signature backend last-check status for the Health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §12: "last check result + time; does NOT make a fresh request" —
 * reads `State::get('bundle_checked_at')`, written whenever
 * `Remote\PackFetcher` last talked to the backend successfully. Never
 * calls `Remote\Client` itself.
 */
final class BackendCheck implements CheckInterface {

	public function id(): string {
		return 'backend';
	}

	public function label(): string {
		return __( 'Signature backend', 'lw-scan' );
	}

	public function run(): array {
		$checked_at = (int) State::get( 'bundle_checked_at', 0 );

		if ( 0 === $checked_at ) {
			return [
				'status'   => 'info',
				'message'  => __( 'The signature backend has not been checked yet.', 'lw-scan' ),
				'blocking' => false,
			];
		}

		return [
			'status'   => 'ok',
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			'message'  => sprintf( __( 'Last checked %s ago.', 'lw-scan' ), human_time_diff( $checked_at, time() ) ),
			'blocking' => false,
		];
	}
}
