<?php
/**
 * Decides whether a file is worth running the heuristic layer on.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * Heuristics are the expensive, noisy end of the scanner: they tokenise the
 * whole file in memory and they can only ever say "suspicious". So they run
 * on the narrow slice where they pay off — unverified PHP small enough to
 * tokenise, on a file no signature has already convicted — and only while
 * there is enough memory headroom for the token array, which for PHP source
 * runs several times the file size.
 */
final class Gate {

	/**
	 * Largest file (in bytes) worth tokenising.
	 */
	private const MAX_SIZE = 524288;

	/**
	 * Memory to keep free per byte of source, for the token array.
	 */
	private const MEMORY_FACTOR = 6;

	/**
	 * @param array<string,mixed> $file_row     Row from the files table (`kind`, `known_good`, `size`).
	 * @param bool                $has_infected True when a signature already convicted this file.
	 * @param array<string,mixed> $config       `heuristics` (bool) and `memory_limit` (bytes, -1 for unlimited).
	 */
	public static function allows( array $file_row, bool $has_infected, array $config ): bool {
		if ( $has_infected || empty( $config['heuristics'] ) ) {
			return false;
		}

		if ( 'php' !== ( isset( $file_row['kind'] ) ? (string) $file_row['kind'] : '' ) ) {
			return false;
		}

		if ( 0 !== ( isset( $file_row['known_good'] ) ? (int) $file_row['known_good'] : 0 ) ) {
			return false;
		}

		$size = isset( $file_row['size'] ) ? (int) $file_row['size'] : 0;

		if ( $size >= self::MAX_SIZE ) {
			return false;
		}

		$limit = isset( $config['memory_limit'] ) ? (int) $config['memory_limit'] : -1;

		if ( $limit <= 0 ) {
			return true;
		}

		return ( memory_get_usage( true ) + ( self::MEMORY_FACTOR * $size ) ) < $limit;
	}

	/**
	 * `memory_limit` in bytes, with `-1` (and anything unparsable) reported
	 * as "no limit" so the gate never blocks on a missing ini value.
	 */
	public static function memory_limit_bytes(): int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw ) {
			return PHP_INT_MAX;
		}

		$units = [
			'g' => 1073741824,
			'm' => 1048576,
			'k' => 1024,
		];
		$unit  = strtolower( substr( $raw, -1 ) );
		$bytes = (int) $raw * ( isset( $units[ $unit ] ) ? $units[ $unit ] : 1 );

		return $bytes > 0 ? $bytes : PHP_INT_MAX;
	}
}
