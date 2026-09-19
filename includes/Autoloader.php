<?php
/**
 * The plugin's own PSR-4 autoloader.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the `LightweightPlugins\Scan\` namespace prefix to `includes/`.
 *
 * The release ZIP no longer ships `vendor/` (see lw-scan.php), so it can no
 * longer rely on Composer's generated autoloader. This is its dependency-free
 * replacement: it only ever resolves classes under this plugin's own
 * namespace, ignores everything else, and never requires a path that does
 * not exist on disk.
 */
final class Autoloader {

	/**
	 * The namespace prefix this autoloader is responsible for.
	 */
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Registers this autoloader with the SPL autoload stack.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( [ self::class, 'autoload' ] );
	}

	/**
	 * SPL autoload callback: requires the file for a class in this plugin's
	 * namespace, if it exists. Every other class is silently ignored, as
	 * required by the SPL autoload contract.
	 *
	 * @param string $class Fully-qualified class, interface, trait or enum name.
	 * @return void
	 */
	public static function autoload( string $class ): void {
		$path = self::path_for( $class );

		if ( null !== $path && is_file( $path ) ) {
			require $path;
		}
	}

	/**
	 * The PSR-4 file path for a class name, or null when the class is not in
	 * this plugin's namespace. Does not check the filesystem.
	 *
	 * @param string $class Fully-qualified class, interface, trait or enum name.
	 * @return string|null Absolute path, or null when `$class` is not `self::PREFIX`-prefixed.
	 */
	public static function path_for( string $class ): ?string {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return null;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );

		return __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
	}
}
