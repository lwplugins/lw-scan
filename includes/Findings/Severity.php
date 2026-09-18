<?php
/**
 * Maps a finding's tier and path signal to a display severity.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * `$type` is accepted for callers that key their severity logic on it too
 * (e.g. vulnerability findings, computed separately per spec §8.3), but the
 * tier/signal rules below apply uniformly regardless of finding type.
 */
final class Severity {

	public const ALERT = 'alert';

	public const REVIEW = 'review';

	/**
	 * @param string $tier   One of integrity|infected|suspicious|info.
	 * @param int    $signal Path-signal score, 0..100.
	 * @param string $type   Finding type (file|integrity|db|vulnerability).
	 * @return string Severity::ALERT or Severity::REVIEW.
	 */
	public static function of( string $tier, int $signal, string $type ): string {
		unset( $type );

		if ( 'integrity' === $tier || 'infected' === $tier ) {
			return self::ALERT;
		}

		if ( 'suspicious' === $tier ) {
			return $signal >= 40 ? self::ALERT : self::REVIEW;
		}

		return self::REVIEW;
	}

	/**
	 * Numeric rank used to pick the higher of two tiers when merging.
	 *
	 * @param string $tier Tier name.
	 * @return int Higher is more severe; 0 for an unrecognized tier.
	 */
	public static function tier_rank( string $tier ): int {
		switch ( $tier ) {
			case 'integrity':
				return 4;
			case 'infected':
				return 3;
			case 'suspicious':
				return 2;
			case 'info':
				return 1;
			default:
				return 0;
		}
	}
}
