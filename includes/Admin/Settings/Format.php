<?php
/**
 * Small display formatters shared by the admin tabs.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pure presentation helpers: timestamps, durations and counts as the
 * mockup writes them. No escaping happens here — every caller escapes the
 * returned string itself.
 */
final class Format {

	/**
	 * A timestamp in the site's timezone, "today HH:MM" for the last 24 h.
	 *
	 * @param int $ts Unix timestamp; 0 renders an em dash.
	 */
	public static function datetime( int $ts ): string {
		if ( $ts <= 0 ) {
			return '—';
		}

		$today = wp_date( 'Y-m-d' ) === wp_date( 'Y-m-d', $ts );

		return $today
			/* translators: %s: time of day, e.g. 03:12. */
			? sprintf( __( 'today %s', 'lw-scan' ), (string) wp_date( 'H:i', $ts ) )
			: (string) wp_date( 'M j, H:i', $ts );
	}

	/**
	 * A duration in the mockup's "38 s" / "2 m 11 s" / "1 h 4 m" style.
	 *
	 * @param int $seconds Elapsed seconds; negative renders an em dash.
	 */
	public static function duration( int $seconds ): string {
		if ( $seconds < 0 ) {
			return '—';
		}

		if ( $seconds < 60 ) {
			return sprintf( '%d s', $seconds );
		}

		if ( $seconds < 3600 ) {
			return sprintf( '%d m %d s', intdiv( $seconds, 60 ), $seconds % 60 );
		}

		return sprintf( '%d h %d m', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	/**
	 * A clock-style elapsed counter ("0:41", "12:07") for the live run bar.
	 *
	 * @param int $seconds Elapsed seconds.
	 */
	public static function clock( int $seconds ): string {
		$seconds = max( 0, $seconds );

		return sprintf( '%d:%02d', intdiv( $seconds, 60 ), $seconds % 60 );
	}

	/**
	 * A localized integer.
	 *
	 * @param int $value Number to format.
	 */
	public static function number( int $value ): string {
		return (string) number_format_i18n( $value );
	}
}
