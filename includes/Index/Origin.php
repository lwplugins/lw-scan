<?php
/**
 * Classifies a relative file path into where in the WordPress install it belongs.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Pure, WordPress-free path classifier. Never touches the filesystem;
 * `plugin_slugs`/`theme_slugs` are the caller's list of *installed* slugs,
 * so an orphaned `wp-content/plugins/<slug>/` directory whose slug isn't
 * installed is treated as `other`, not `plugin:<slug>`.
 */
final class Origin {

	/**
	 * Root-level directory names that belong to WordPress core.
	 *
	 * @var array<int,string>
	 */
	private const CORE_ROOT_DIRS = [ 'wp-admin', 'wp-includes' ];

	/**
	 * Root-level files (besides `wp-*.php`) that belong to WordPress core.
	 *
	 * @var array<int,string>
	 */
	private const CORE_ROOT_FILES = [ 'index.php', 'xmlrpc.php' ];

	/**
	 * @param string            $rel          Path relative to ABSPATH, no leading slash.
	 * @param array<int,string> $plugin_slugs Installed plugin folder slugs.
	 * @param array<int,string> $theme_slugs  Installed theme folder slugs.
	 * @return string One of `core|plugin:<slug>|theme:<slug>|mu|uploads|other`.
	 */
	public static function of( string $rel, array $plugin_slugs, array $theme_slugs ): string {
		$rel = ltrim( $rel, '/' );

		if ( 1 === preg_match( '~^wp-content/plugins/([^/]+)/~', $rel, $matches ) ) {
			return in_array( $matches[1], $plugin_slugs, true ) ? 'plugin:' . $matches[1] : 'other';
		}

		if ( 1 === preg_match( '~^wp-content/themes/([^/]+)/~', $rel, $matches ) ) {
			return in_array( $matches[1], $theme_slugs, true ) ? 'theme:' . $matches[1] : 'other';
		}

		if ( 0 === strpos( $rel, 'wp-content/mu-plugins/' ) ) {
			return 'mu';
		}

		if ( 0 === strpos( $rel, 'wp-content/uploads/' ) ) {
			return 'uploads';
		}

		foreach ( self::CORE_ROOT_DIRS as $dir ) {
			if ( 0 === strpos( $rel, $dir . '/' ) ) {
				return 'core';
			}
		}

		if ( false === strpos( $rel, '/' ) && self::is_core_root_file( $rel ) ) {
			return 'core';
		}

		return 'other';
	}

	/**
	 * @param string $rel A root-level (no slash) file name.
	 * @return bool
	 */
	private static function is_core_root_file( string $rel ): bool {
		if ( in_array( $rel, self::CORE_ROOT_FILES, true ) ) {
			return true;
		}

		return 1 === preg_match( '~^wp-[^/]*\.php$~', $rel );
	}
}
