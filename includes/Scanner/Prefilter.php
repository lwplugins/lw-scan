<?php
/**
 * Literal-presence check for a chunk against the signature pack.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

use LightweightPlugins\Scan\Bundle\Pack;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once per chunk, ahead of LiteralLayer and RegexLayer: turns the
 * chunk into a word set and asks the pack's word → literal index only about
 * the words that actually occur, so the work grows with the chunk's
 * vocabulary rather than with the (up to several thousand) literals in the
 * pack. A literal whose every 3+ byte word is present is then decided by its
 * pack-computed "pure" flag (a bare `[a-z0-9_]+` word is fully answered by
 * word membership) or, failing that, by `strpos()` — the only
 * chunk-length-dependent step, reached only for those few candidates.
 */
final class Prefilter {

	private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

	/**
	 * Lowercases only ASCII `A`-`Z`, exactly mirroring the Go pack builder's
	 * `ASCIILower()`: every plain literal in the pack was folded that way, not
	 * with the C library's locale-aware `tolower()`, so this is the only
	 * lowering that stays in sync with it. `strtolower()` is locale-sensitive
	 * (PHP's manual notes this is fixed only from 8.2 on; this plugin's floor
	 * is 8.0): under a `setlocale(LC_CTYPE, …)` in effect it can leave a plain
	 * `A`-`Z` byte unchanged or fold a high byte the pack never touched,
	 * either of which makes the chunk's `strpos()` against a pack literal
	 * miss a real match. `strtr()` with two equal-length strings does a plain
	 * byte substitution, unaffected by locale.
	 *
	 * @param string $s Chunk (or any string) to lowercase for pack matching.
	 */
	public static function ascii_lower( string $s ): string {
		return strtr( $s, self::UPPER, self::LOWER );
	}

	/**
	 * Splits `$lc` into its `[a-z0-9_]+` tokens and flips them into a set
	 * (`word => original split index`) for O(1) membership checks.
	 *
	 * @param string $lc Lowercased chunk (or any lowercased string).
	 * @return array<string,int>
	 */
	public static function word_set( string $lc ): array {
		$parts = preg_split( '/[^a-z0-9_]+/', $lc, -1, PREG_SPLIT_NO_EMPTY );
		$parts = false === $parts ? [] : $parts;

		return array_flip( $parts );
	}

	/**
	 * Which literal indexes are present in a lowercased chunk — the same
	 * decision the array-based prefilter made, driven by the pack's
	 * word → literal index so only words that occur in the chunk are visited.
	 *
	 * @param string $lc   Lowercased chunk.
	 * @param Pack   $pack Signature pack.
	 * @return array<int, true>
	 */
	public static function presence( string $lc, Pack $pack ): array {
		$presence = [];
		$hits     = [];

		foreach ( self::word_set( $lc ) as $word => $unused ) {
			foreach ( $pack->word_literals( (string) $word ) as $index ) {
				$hits[ $index ] = ( $hits[ $index ] ?? 0 ) + 1;
			}
		}

		foreach ( $hits as $index => $count ) {
			if ( $count < $pack->literal_word_count( $index ) ) {
				continue;
			}

			if ( $pack->literal_pure( $index ) || false !== strpos( $lc, $pack->literal( $index ) ) ) {
				$presence[ $index ] = true;
			}
		}

		// Literals too short to contain a 3-byte word: a pure one was always
		// treated as present by the old prefilter, the rest need strpos.
		foreach ( $pack->wordless_literals() as $index ) {
			if ( $pack->literal_pure( $index ) || false !== strpos( $lc, $pack->literal( $index ) ) ) {
				$presence[ $index ] = true;
			}
		}

		return $presence;
	}
}
