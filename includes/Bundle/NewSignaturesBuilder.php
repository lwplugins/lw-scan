<?php
/**
 * Diffs two pack metas into the `new-<v>.json` payload (spec §5.1, §5.2).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Everything `Remote\PackFetcher` needs to know about what a pack update
 * added: which rules are new, expressed the way `Pack` addresses them
 * (regex indexes, positions in `Pack::plain_literals()`, and one flag for
 * "a hash rule is new"), so `NewSignatures` can be built straight from the
 * result without re-deriving anything.
 */
final class NewSignaturesBuilder {

	private const HASH_KINDS = [ 'md5', 'sha256' ];

	/**
	 * @param Pack     $pack     The new pack.
	 * @param PackMeta $new_meta The new pack's meta.
	 * @param PackMeta $old_meta The previously stored pack's meta.
	 * @return array{version:int, since:int, regex:int[], literal:int[], hash:bool}|null Null when nothing is new.
	 */
	public static function build( Pack $pack, PackMeta $new_meta, PackMeta $old_meta ): ?array {
		$new_sigs = self::new_sig_set( $new_meta, $old_meta );

		if ( [] === $new_sigs ) {
			return null;
		}

		return [
			'version' => $new_meta->version(),
			'since'   => $old_meta->version(),
			'regex'   => self::new_regexes( $pack, $new_sigs ),
			'literal' => self::new_literals( $pack, $new_sigs ),
			'hash'    => self::has_new_hash( $new_meta, $new_sigs ),
		];
	}

	/**
	 * @param PackMeta $new_meta The new pack's meta.
	 * @param PackMeta $old_meta The previously stored pack's meta.
	 * @return string[] Ids in `$new_meta` but not in `$old_meta`, in sig order.
	 */
	public static function new_ids( PackMeta $new_meta, PackMeta $old_meta ): array {
		$old_ids = array_flip( $old_meta->ids() );
		$ids     = [];
		$count   = $new_meta->count();

		for ( $sig = 0; $sig < $count; $sig++ ) {
			$id = $new_meta->id( $sig );

			if ( ! isset( $old_ids[ $id ] ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param PackMeta $new_meta The new pack's meta.
	 * @param PackMeta $old_meta The previously stored pack's meta.
	 * @return array<int, true> Sig indexes whose id is not in `$old_meta`.
	 */
	private static function new_sig_set( PackMeta $new_meta, PackMeta $old_meta ): array {
		$old_ids  = array_flip( $old_meta->ids() );
		$new_sigs = [];
		$count    = $new_meta->count();

		for ( $sig = 0; $sig < $count; $sig++ ) {
			if ( ! isset( $old_ids[ $new_meta->id( $sig ) ] ) ) {
				$new_sigs[ $sig ] = true;
			}
		}

		return $new_sigs;
	}

	/**
	 * @param Pack             $pack     The new pack.
	 * @param array<int, true> $new_sigs Sig indexes that are new.
	 * @return int[] Regex indexes whose sig is in `$new_sigs`.
	 */
	private static function new_regexes( Pack $pack, array $new_sigs ): array {
		$regex = [];
		$count = $pack->regex_count();

		for ( $i = 0; $i < $count; $i++ ) {
			if ( isset( $new_sigs[ $pack->regex_sig( $i ) ] ) ) {
				$regex[] = $i;
			}
		}

		return $regex;
	}

	/**
	 * @param Pack             $pack     The new pack.
	 * @param array<int, true> $new_sigs Sig indexes that are new.
	 * @return int[] Positions in `Pack::plain_literals()` whose sig is in `$new_sigs`.
	 */
	private static function new_literals( Pack $pack, array $new_sigs ): array {
		$literal = [];

		foreach ( $pack->plain_literals() as $position => $row ) {
			if ( isset( $new_sigs[ $row[1] ] ) ) {
				$literal[] = $position;
			}
		}

		return $literal;
	}

	/**
	 * @param PackMeta         $new_meta The new pack's meta.
	 * @param array<int, true> $new_sigs Sig indexes that are new.
	 */
	private static function has_new_hash( PackMeta $new_meta, array $new_sigs ): bool {
		foreach ( array_keys( $new_sigs ) as $sig ) {
			if ( in_array( $new_meta->kind( $sig ), self::HASH_KINDS, true ) ) {
				return true;
			}
		}

		return false;
	}
}
