<?php
/**
 * Builds SQL LIKE prefilter fragments from the signature pack's db rules.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\DbScan;

defined( 'ABSPATH' ) || exit;

/**
 * Every `db_*` rule in the signature pack carries an optional `like` value
 * (spec §7): a literal SQL LIKE pattern that a matching row's column must
 * satisfy before the (expensive) regex ever runs. `groups()` turns the
 * rule list into ready-to-use `(<column> LIKE %s OR …)` fragments over every
 * column the caller matches, chunked so a single fragment never needs more
 * than 50 placeholders. A rule with `like === null` has no such shortcut
 * (its regex can match anything), so the whole column must be scanned
 * unfiltered — signalled by a single group with an empty `sql`.
 */
final class LikePrefilter {

	/**
	 * Max LIKE placeholders per fragment.
	 *
	 * @var int
	 */
	private const CHUNK = 50;

	/**
	 * @param array<int, array{sig:int, re:string, like:?string}> $rules      Db rules from the pack, e.g. `Pack::db_rules( 'db_option' )`.
	 * @param string                                              ...$columns Columns the LIKE fragment applies to — every column the caller feeds to RowMatcher::match().
	 * @return array<int, array{sql:string, args:array<int,string>}>
	 */
	public static function groups( array $rules, string ...$columns ): array {
		if ( [] === $columns ) {
			return [];
		}

		$likes = [];

		foreach ( $rules as $rule ) {
			if ( null === $rule['like'] ) {
				return [
					[
						'sql'  => '',
						'args' => [],
					],
				];
			}

			$likes[] = $rule['like'];
		}

		if ( [] === $likes ) {
			return [];
		}

		return self::chunk( $likes, $columns );
	}

	/**
	 * @param array<int,string> $likes   LIKE patterns, one per rule.
	 * @param array<int,string> $columns Columns to OR the patterns over.
	 * @return array<int, array{sql:string, args:array<int,string>}>
	 */
	private static function chunk( array $likes, array $columns ): array {
		$per_chunk = (int) max( 1, intdiv( self::CHUNK, count( $columns ) ) );
		$groups    = [];

		foreach ( array_chunk( $likes, $per_chunk ) as $chunk ) {
			$fragments = [];
			$args      = [];

			foreach ( $columns as $column ) {
				foreach ( $chunk as $like ) {
					$fragments[] = "{$column} LIKE %s";
					$args[]      = $like;
				}
			}

			$groups[] = [
				'sql'  => '(' . implode( ' OR ', $fragments ) . ')',
				'args' => $args,
			];
		}

		return $groups;
	}
}
