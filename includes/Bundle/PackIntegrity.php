<?php
/**
 * Load-time integrity check for a signature pack (spec §4.2, §6).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * `Pack`'s accessors read without bounds checks, so everything they read is
 * proven here once, when the pack loads: header types, section lengths,
 * offset lists that start at 0, never decrease and end at their section's
 * end, every stored index below the count it points into, flag bytes in
 * range, and the shape of the JSON rows. A pack that passes cannot make an
 * accessor warn or throw for an index the pack itself hands out; one that
 * fails loads as null, which the loader reports as `bundle_missing`. The
 * sha256 check on download already rules out transport damage; this covers
 * a damaged file on disk and a backend bug.
 */
final class PackIntegrity {

	public const REQUIRED = [ 'format', 'version', 'count', 'sig_tier', 'regex_count', 'regex_patterns', 'regex_offsets', 'regex_sigs', 'regex_flags', 'regex_lits', 'regex_lit_offsets', 'targets', 'literal_count', 'literal_strings', 'literal_offsets', 'literal_word_counts', 'literal_pure', 'words', 'wordless_literals', 'plain_literals', 'hash', 'db', 'allowlist' ];

	private const INTS = [ 'format', 'version', 'count', 'regex_count', 'literal_count' ];

	private const STRINGS = [ 'regex_patterns', 'literal_strings' ];

	private const MAPS = [ 'targets', 'words', 'plain_literals', 'hash', 'db', 'allowlist' ];

	/**
	 * Before base64 decoding: every key present, integer headers are
	 * non-negative ints, text sections are strings, maps are arrays, and the
	 * format is one this plugin reads.
	 *
	 * @param array<string, mixed> $data `json_decode( pack.json, true )`.
	 */
	public static function shape( array $data ): bool {
		foreach ( self::REQUIRED as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				return false;
			}
		}

		foreach ( self::INTS as $key ) {
			if ( ! is_int( $data[ $key ] ) || $data[ $key ] < 0 ) {
				return false;
			}
		}

		foreach ( self::STRINGS as $key ) {
			if ( ! is_string( $data[ $key ] ) ) {
				return false;
			}
		}

		foreach ( self::MAPS as $key ) {
			if ( ! is_array( $data[ $key ] ) ) {
				return false;
			}
		}

		return Pack::FORMAT === $data['format'];
	}

	/**
	 * After base64 decoding (every b64 section is a binary string, every
	 * `targets`/`words` entry a whole number of uint32s). Ordered so no
	 * offset is read from a section whose length is wrong.
	 *
	 * @param array<string, mixed> $d Pack that passed shape(), sections decoded.
	 */
	public static function consistent( array $d ): bool {
		return self::lengths( $d )
			&& self::offsets( $d )
			&& self::bytes( $d )
			&& self::indexes( $d )
			&& self::plain_rows( $d )
			&& self::db_rows( $d )
			&& self::hash_maps( $d );
	}

	/**
	 * @param array<string, mixed> $d Decoded pack.
	 */
	private static function lengths( array $d ): bool {
		$regexes  = $d['regex_count'];
		$literals = $d['literal_count'];

		return strlen( $d['sig_tier'] ) === $d['count']
			&& strlen( $d['regex_offsets'] ) === 4 * ( $regexes + 1 )
			&& strlen( $d['regex_sigs'] ) === 4 * $regexes
			&& strlen( $d['regex_flags'] ) === $regexes
			&& strlen( $d['regex_lit_offsets'] ) === 4 * ( $regexes + 1 )
			&& 0 === strlen( $d['regex_lits'] ) % 4
			&& strlen( $d['literal_offsets'] ) === 4 * ( $literals + 1 )
			&& strlen( $d['literal_word_counts'] ) === $literals
			&& strlen( $d['literal_pure'] ) === $literals
			&& 0 === strlen( $d['wordless_literals'] ) % 4;
	}

	/**
	 * @param array<string, mixed> $d Decoded pack with valid lengths.
	 */
	private static function offsets( array $d ): bool {
		return self::offset_list( $d['regex_offsets'], strlen( $d['regex_patterns'] ) )
			&& self::offset_list( $d['regex_lit_offsets'], intdiv( strlen( $d['regex_lits'] ), 4 ) )
			&& self::offset_list( $d['literal_offsets'], strlen( $d['literal_strings'] ) );
	}

	/**
	 * @param string $bin Non-empty uint32 offset list.
	 * @param int    $end Length of the section it indexes.
	 */
	private static function offset_list( string $bin, int $end ): bool {
		$previous = 0;

		foreach ( self::u32s( $bin ) as $i => $offset ) {
			if ( ( 0 === $i && 0 !== $offset ) || $offset < $previous ) {
				return false;
			}

			$previous = $offset;
		}

		return $end === $previous;
	}

	/**
	 * Tier 1 infected, 2 suspicious, 3 info; flags bit0 anchored, bit1 first; pure 0 or 1.
	 *
	 * @param array<string, mixed> $d Decoded pack.
	 */
	private static function bytes( array $d ): bool {
		return strspn( $d['sig_tier'], "\x01\x02\x03" ) === strlen( $d['sig_tier'] )
			&& strspn( $d['regex_flags'], "\x00\x01\x02\x03" ) === strlen( $d['regex_flags'] )
			&& strspn( $d['literal_pure'], "\x00\x01" ) === strlen( $d['literal_pure'] );
	}

	/**
	 * @param array<string, mixed> $d Decoded pack.
	 */
	private static function indexes( array $d ): bool {
		if ( ! self::all_below( $d['regex_sigs'], $d['count'] )
			|| ! self::all_below( $d['regex_lits'], $d['literal_count'] )
			|| ! self::all_below( $d['wordless_literals'], $d['literal_count'] ) ) {
			return false;
		}

		foreach ( $d['targets'] as $list ) {
			if ( ! self::all_below( $list, $d['regex_count'] ) ) {
				return false;
			}
		}

		foreach ( $d['words'] as $list ) {
			if ( ! self::all_below( $list, $d['literal_count'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * `plain_literals`: `[literal index, sig, target]` rows.
	 *
	 * @param array<string, mixed> $d Decoded pack.
	 */
	private static function plain_rows( array $d ): bool {
		foreach ( $d['plain_literals'] as $row ) {
			if ( ! self::is_row( $row ) || ! self::is_index( $row[0], $d['literal_count'] ) || ! self::is_index( $row[1], $d['count'] ) || ! is_string( $row[2] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * `db`: target => list of `[sig, php regex, sql like or null]` rows.
	 *
	 * @param array<string, mixed> $d Decoded pack.
	 */
	private static function db_rows( array $d ): bool {
		foreach ( $d['db'] as $rules ) {
			if ( ! is_array( $rules ) ) {
				return false;
			}

			foreach ( $rules as $row ) {
				if ( ! self::is_row( $row ) || ! self::is_index( $row[0], $d['count'] ) || ! is_string( $row[1] ) || '' === $row[1] || ( null !== $row[2] && ! is_string( $row[2] ) ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * `hash`: kind => (hex => sig).
	 *
	 * @param array<string, mixed> $d Decoded pack.
	 */
	private static function hash_maps( array $d ): bool {
		foreach ( $d['hash'] as $map ) {
			if ( ! is_array( $map ) ) {
				return false;
			}

			foreach ( $map as $sig ) {
				if ( ! self::is_index( $sig, $d['count'] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @param string $bin   Little-endian uint32 list (may be empty).
	 * @param int    $limit Exclusive upper bound.
	 */
	private static function all_below( string $bin, int $limit ): bool {
		$values = self::u32s( $bin );

		return [] === $values || max( $values ) < $limit;
	}

	/**
	 * @param mixed $row Candidate JSON row.
	 */
	private static function is_row( $row ): bool {
		return is_array( $row ) && [ 0, 1, 2 ] === array_keys( $row );
	}

	/**
	 * @param mixed $value Candidate index.
	 * @param int   $limit Exclusive upper bound.
	 */
	private static function is_index( $value, int $limit ): bool {
		return is_int( $value ) && $value >= 0 && $value < $limit;
	}

	/**
	 * @param string $bin Little-endian uint32 list.
	 * @return int[]
	 */
	private static function u32s( string $bin ): array {
		$values = '' === $bin ? false : unpack( 'V*', $bin );

		return false === $values ? [] : array_values( $values );
	}
}
