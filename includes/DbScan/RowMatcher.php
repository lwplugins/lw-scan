<?php
/**
 * Runs a signature pack's db rules against one row value.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\DbScan;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Scanner\ChunkReader;
use LightweightPlugins\Scan\Scanner\MatchResult;

defined( 'ABSPATH' ) || exit;

/**
 * The db-scan counterpart of Scanner\RegexLayer (spec §7.1): no chunking,
 * no prefilter presence table, no first-chunk/skip-suspicious bookkeeping —
 * option/post/trigger values are small enough to check in one PCRE call
 * per rule. `preg_last_error()` is guarded the same way, so a catastrophic
 * pattern degrades to "no match" instead of a fatal.
 */
final class RowMatcher {

	/**
	 * Bytes searched from the start of `$value`; anything beyond this is ignored.
	 *
	 * @var int
	 */
	private const MAX_BYTES = 1048576;

	/**
	 * @param string                                              $value Row value to search (option_value, post_content, trigger Statement, …).
	 * @param array<int, array{sig:int, re:string, like:?string}> $rules Rules from Pack::db_rules(), e.g. `$signatures->pack()->db_rules( 'db_option' )`.
	 * @param Signatures                                          $s     Loaded signature set; its meta is read for every match.
	 * @return array<int,MatchResult>
	 */
	public static function match( string $value, array $rules, Signatures $s ): array {
		$value   = substr( $value, 0, self::MAX_BYTES );
		$matches = [];

		foreach ( $rules as $rule ) {
			$captures = [];
			$found    = preg_match( $rule['re'], $value, $captures, PREG_OFFSET_CAPTURE );

			if ( PREG_NO_ERROR !== preg_last_error() ) {
				continue;
			}

			if ( 1 !== $found ) {
				continue;
			}

			$offset = $captures[0][1];

			$matches[] = MatchResult::from_signatures( $s, $rule['sig'], 0, ChunkReader::excerpt( $value, $offset ), $offset );
		}

		return $matches;
	}
}
