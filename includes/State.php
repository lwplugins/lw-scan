<?php
/**
 * Runtime state management.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's non-autoloaded runtime state option.
 */
final class State {

	public const OPTION_NAME = 'lw_scan_state';

	/**
	 * All stored state values.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		return (array) get_option( self::OPTION_NAME, [] );
	}

	/**
	 * @param string $key     State key.
	 * @param mixed  $default Fallback value when the key is missing.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * @param string $key   State key.
	 * @param mixed  $value Value to store.
	 */
	public static function set( string $key, $value ): void {
		self::merge( [ $key => $value ] );
	}

	/**
	 * Merges the given values into the stored state and saves, always
	 * non-autoloaded.
	 *
	 * WordPress' update_option() ignores the $autoload argument when the
	 * row already exists, so the first write must go through add_option()
	 * to pin autoload=no from the start.
	 *
	 * @param array<string, mixed> $values Values to merge in.
	 */
	public static function merge( array $values ): void {
		$existing = get_option( self::OPTION_NAME );
		$current  = false === $existing ? [] : (array) $existing;
		$new      = array_merge( $current, $values );

		if ( false === $existing ) {
			add_option( self::OPTION_NAME, $new, '', false );
		} else {
			update_option( self::OPTION_NAME, $new, false );
		}
	}

	public static function reset(): void {
		delete_option( self::OPTION_NAME );
	}
}
