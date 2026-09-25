<?php
/**
 * The e-mail's line for a vulnerability finding, in the plugin's own words.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Notify;

use LightweightPlugins\Scan\Admin\Settings\VulnRecord;
use LightweightPlugins\Scan\Findings\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * A vulnerability finding's `reason` is the feed's record titles, which is
 * Wordfence Intelligence content, and its licence would require the record
 * link, copyright designation and licence text beside every copy — none of
 * which belongs in a notification e-mail. So the e-mail says only what this
 * site's own data says: which package, which version is installed, and the
 * version to update to. The Findings tab, which the e-mail links to, shows
 * the record itself with its attribution.
 */
final class VulnLine {

	/**
	 * "Elementor 4.3.0: known vulnerability, update to 4.3.2".
	 *
	 * @param array<string, mixed> $f Findings-table row, `meta` raw or decoded.
	 */
	public static function describe( array $f ): string {
		if ( ! is_array( $f['meta'] ?? null ) ) {
			$f['meta'] = Finding::decode_map( (string) ( $f['meta'] ?? '{}' ) );
		}

		$package = trim( VulnRecord::software_name( $f ) . ' ' . (string) ( $f['meta']['installed_version'] ?? '' ) );
		$patched = VulnRecord::patched_version( $f );

		if ( '' === $patched ) {
			return sprintf(
				/* translators: %s: plugin, theme or WordPress name and installed version. */
				__( '%s: known vulnerability, no fixed version yet', 'lw-scan' ),
				$package
			);
		}

		return sprintf(
			/* translators: 1: plugin, theme or WordPress name and installed version. 2: version that fixes it. */
			__( '%1$s: known vulnerability, update to %2$s', 'lw-scan' ),
			$package,
			$patched
		);
	}
}
