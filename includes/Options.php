<?php
/**
 * Options management.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's autoloaded settings option.
 *
 * Three of the keys are not settings at all: `next_due`/`last_auto_run` are
 * the scheduler's bookkeeping, and `plugin_version` is the marker
 * `Upgrader` compares against `LW_SCAN_VERSION`. They live here rather than
 * in `State` because all three are read on ordinary requests, and this
 * option is autoloaded while `State`'s is not — a marker that costs a query
 * per request to check would cost more than the check is worth.
 * `Admin\SettingsSanitizer` knows none of them come from the settings form.
 */
final class Options {

	public const OPTION_NAME = 'lw_scan_options';

	private const VALID_SCOPES = [ 'changed', 'full', 'db' ];

	/**
	 * Default option values.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'schedule'           => 'daily',
			'schedule_hour'      => 3,
			'scope'              => 'changed',
			'bundle_auto_update' => true,
			'heuristics'         => true,
			'max_file_size'      => 2097152,
			'excluded_paths'     => [
				'wp-content/cache',
				'wp-content/backup*',
				'wp-content/upgrade',
				'wp-content/upgrade-temp-backup',
				'wp-content/uploads/lw-img-backups',
				'wp-content/ai1wm-backups',
				'wp-content/updraft',
			],
			'follow_symlinks'    => false,
			'notify_enabled'     => true,
			'notify_emails'      => [],
			'notify_level'       => 'alert',
			'notify_limit'       => 20,
			'admin_notice'       => false,
			'next_due'           => 0,
			'last_auto_run'      => 0,
			'plugin_version'     => '',
		];
	}

	/**
	 * All option values, defaults merged under the stored values, then
	 * passed through the `lw_scan_options` filter — the runtime override
	 * `wp lw-scan run --no-heuristics` uses to turn a setting off for one
	 * run without touching what is stored (spec §11.2).
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		/**
		 * Filters the plugin's effective options for this request.
		 *
		 * @param array<string, mixed> $options Defaults merged under the stored values.
		 */
		return (array) apply_filters( 'lw_scan_options', self::stored() );
	}

	/**
	 * The stored values merged with the defaults, with no runtime filter
	 * applied — for writers only. Anything that reads options to *act* on
	 * them wants `all()`; anything that reads them as the base of what it
	 * is about to save wants this, so a runtime `lw_scan_options` filter
	 * can never leak into the database (`update()` and
	 * `Admin\SettingsSanitizer` both do).
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		return array_merge( self::get_defaults(), (array) get_option( self::OPTION_NAME, [] ) );
	}

	/**
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback value when the key is missing.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Merges the given values over the current options and saves.
	 *
	 * @param array<string, mixed> $partial Values to merge in.
	 */
	public static function update( array $partial ): void {
		update_option( self::OPTION_NAME, array_merge( self::stored(), $partial ), true );
	}

	/**
	 * Normalized excluded-path patterns, always including the plugin's own
	 * storage directory so it never scans itself. The directory is asked for
	 * (`lw_scan_storage_exclusion()`) rather than written out, so a site that
	 * moves it with the `lw_scan_storage_dir` filter is still covered — and
	 * `Index\Walker` reads the same answer.
	 *
	 * @return string[]
	 */
	public static function excluded_paths(): array {
		$stored = (array) self::get( 'excluded_paths', [] );

		$normalized = [];
		foreach ( $stored as $path ) {
			$trimmed = trim( str_replace( '\\', '/', (string) $path ), " \t/" );
			if ( '' !== $trimmed ) {
				$normalized[] = $trimmed;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );

		$storage = lw_scan_storage_exclusion();

		if ( '' !== $storage && ! in_array( $storage, $normalized, true ) ) {
			$normalized[] = $storage;
		}

		return $normalized;
	}

	/**
	 * The configured scan scope, validated against the known set.
	 */
	public static function scope(): string {
		$scope = (string) self::get( 'scope', 'changed' );

		return in_array( $scope, self::VALID_SCOPES, true ) ? $scope : 'changed';
	}
}
