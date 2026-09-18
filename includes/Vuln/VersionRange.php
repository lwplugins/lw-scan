<?php
/**
 * Matches an installed software version against vulnerability feed ranges.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Vuln;

defined( 'ABSPATH' ) || exit;

/**
 * A vulnerability record's `affected_versions` (spec §8.2) is a map of
 * arbitrary range keys (e.g. `"*-1.37"`) to bound descriptors. A version is
 * affected when it falls inside any one range — `from_version`/`to_version`
 * of `"*"` mean "unbounded on that side", and the `*_inclusive` flags
 * default to true when absent.
 */
final class VersionRange {

	/**
	 * @param string               $installed         Installed version string.
	 * @param array<string, mixed> $affected_versions Range descriptors (each expected to be an array), keyed by an arbitrary label.
	 * @return bool
	 */
	public static function affects( string $installed, array $affected_versions ): bool {
		$normalized_installed = self::normalize( $installed );

		foreach ( $affected_versions as $range ) {
			if ( is_array( $range ) && self::in_range( $normalized_installed, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lowercases and replaces `-` with `.` so pre-release suffixes
	 * (`5.9.3-beta`) compare consistently against the feed's own versions.
	 *
	 * @param string $v Version string.
	 * @return string
	 */
	public static function normalize( string $v ): string {
		return str_replace( '-', '.', strtolower( $v ) );
	}

	/**
	 * @param string               $normalized_installed Already-normalized installed version.
	 * @param array<string, mixed> $range                One `affected_versions` entry.
	 * @return bool
	 */
	private static function in_range( string $normalized_installed, array $range ): bool {
		$from = isset( $range['from_version'] ) ? (string) $range['from_version'] : '*';
		$to   = isset( $range['to_version'] ) ? (string) $range['to_version'] : '*';

		$from_inclusive = ! isset( $range['from_inclusive'] ) || (bool) $range['from_inclusive'];
		$to_inclusive   = ! isset( $range['to_inclusive'] ) || (bool) $range['to_inclusive'];

		if ( '*' !== $from ) {
			$op = $from_inclusive ? '>=' : '>';

			if ( ! version_compare( $normalized_installed, self::normalize( $from ), $op ) ) {
				return false;
			}
		}

		if ( '*' !== $to ) {
			$op = $to_inclusive ? '<=' : '<';

			if ( ! version_compare( $normalized_installed, self::normalize( $to ), $op ) ) {
				return false;
			}
		}

		return true;
	}
}
