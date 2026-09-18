<?php
/**
 * Signature-bundle facts for the admin screens.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Three numbers the Scan and Health tabs both want: which bundle is
 * installed, how many rules it holds, and when the backend was last asked
 * about it. All three come from the `lw_scan_state` option, so rendering a
 * tab costs no disk reads.
 */
final class BundleInfo {

	public static function version(): int {
		return (int) State::get( 'bundle_version', 0 );
	}

	public static function checked_at(): int {
		return (int) State::get( 'bundle_checked_at', 0 );
	}

	/**
	 * `Remote\PackFetcher` stores the rule count alongside the version, so
	 * the admin screens never load the signature pack to print a number.
	 * There is deliberately no fall back to `Bundle\PackLoader::get()` for a
	 * pack stored without a count: this renders inside the Health and Scan
	 * tabs, on every admin pageview, and `PackLoader::get()` means reading
	 * and JSON-decoding the whole pack from disk (about 3.0 MB on a live
	 * signature set, about 3.6 MB resident once loaded) instead of one
	 * State option — a cost worth avoiding on every render, not one worth
	 * paying just to fill in a number. A count of zero until the next
	 * bundle check writes one is the cheaper wrong answer.
	 */
	public static function signature_count(): int {
		return (int) State::get( 'bundle_count', 0 );
	}

	/**
	 * "signatures 20260916045 (13 210 rules, checked 21:41)".
	 */
	public static function describe(): string {
		$version = self::version();

		if ( 0 === $version ) {
			return __( 'no signature bundle installed yet', 'lw-scan' );
		}

		return sprintf(
			/* translators: 1: bundle version. 2: rule count. 3: when the backend was last checked. */
			__( 'signatures %1$s (%2$s rules, checked %3$s)', 'lw-scan' ),
			(string) $version,
			Format::number( self::signature_count() ),
			Format::datetime( self::checked_at() )
		);
	}
}
