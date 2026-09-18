<?php
/**
 * Public helper functions.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan;

// `return`, not `exit`, in this one file: Composer pulls it in through
// autoload.files, so the autoloader itself would be killed by an exit here —
// silently taking PHPUnit and every other vendor binary with it. Returning
// leaves a direct request with an empty response just the same.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! function_exists( __NAMESPACE__ . '\\lw_scan_storage_dir' ) ) {
	/**
	 * Absolute path to the plugin's private storage directory.
	 *
	 * @return string
	 */
	function lw_scan_storage_dir(): string {
		/**
		 * Filters the plugin's private storage directory.
		 *
		 * @param string $dir Absolute path, no trailing slash.
		 */
		$dir = (string) apply_filters( 'lw_scan_storage_dir', WP_CONTENT_DIR . '/lw-scan' );

		return rtrim( str_replace( '\\', '/', $dir ), '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\lw_scan_relative_path' ) ) {
	/**
	 * ABSPATH-relative version of an absolute path, forward-slashed.
	 *
	 * @param string $abs Absolute path.
	 * @return string Path relative to ABSPATH, no leading slash.
	 */
	function lw_scan_relative_path( string $abs ): string {
		$abs  = str_replace( '\\', '/', $abs );
		$root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';

		return 0 === strpos( $abs, $root ) ? substr( $abs, strlen( $root ) ) : ltrim( $abs, '/' );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\lw_scan_storage_exclusion' ) ) {
	/**
	 * The plugin's own storage directory as the ABSPATH-relative path the
	 * file index must never walk into.
	 *
	 * One source for the two places that need it — `Options::excluded_paths()`
	 * and `Index\Walker`'s always-excluded list — and derived from
	 * `lw_scan_storage_dir()` rather than written out, so a site that moves
	 * the directory with the `lw_scan_storage_dir` filter does not end up
	 * scanning the signature bundle it just downloaded while excluding a
	 * `wp-content/lw-scan` that is not there.
	 *
	 * @return string Path relative to ABSPATH, no leading slash.
	 */
	function lw_scan_storage_exclusion(): string {
		return lw_scan_relative_path( lw_scan_storage_dir() );
	}
}
