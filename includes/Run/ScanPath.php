<?php
/**
 * Validation for the `scope=path` argument.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * A path-scoped scan takes a directory from the user (admin form, WP-CLI,
 * REST), so it has to resolve — symlinks and `..` included — to a real
 * directory inside ABSPATH before anything walks it. Everything downstream
 * (Walker root, `path LIKE` prefix, finding locators) works with the
 * ABSPATH-relative form this returns.
 */
final class ScanPath {

	/**
	 * @param string $path User-supplied path, absolute or ABSPATH-relative.
	 * @return string|null The ABSPATH-relative path ('' for ABSPATH itself), or null when it is not a directory inside ABSPATH.
	 */
	public static function relative( string $path ): ?string {
		$path = trim( str_replace( '\\', '/', $path ) );
		$root = rtrim( str_replace( '\\', '/', (string) realpath( ABSPATH ) ), '/' );

		$candidate = 0 === strpos( $path, '/' ) ? $path : $root . '/' . ltrim( $path, '/' );
		$resolved  = realpath( $candidate );

		if ( false === $resolved || ! is_dir( $resolved ) ) {
			return null;
		}

		$resolved = rtrim( str_replace( '\\', '/', $resolved ), '/' );

		if ( $resolved === $root ) {
			return '';
		}

		if ( 0 !== strpos( $resolved, $root . '/' ) ) {
			return null;
		}

		return substr( $resolved, strlen( $root ) + 1 );
	}
}
