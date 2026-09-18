<?php
/**
 * Exact-substring signature matching against the pack's plain-literal rules.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

use LightweightPlugins\Scan\Bundle\Signatures;

defined( 'ABSPATH' ) || exit;

/**
 * Runs after Prefilter narrows candidates down (see FileScanner §6.6 step
 * 6): each `literal`-kind signature is one `strpos()` against the current
 * chunk. Unlike Prefilter's deduplicated literal table (used only for
 * presence bits), this walks the pack's per-rule `plain_literals()` list so
 * every literal signature is reported individually, filtered by the file's
 * target set and, for incremental scans, by "new since the previous pack".
 */
final class LiteralLayer {

	/**
	 * @param string     $lc        Lowercased chunk to search.
	 * @param Signatures $s         Loaded signature set; uses `plain_literals()` (and the new rules when `$only_new`).
	 * @param string[]   $targets   Target names this file is scanned against (e.g. `file_php`, `file_any`).
	 * @param int        $line_base Accumulated newline count before this chunk (0 for the first chunk).
	 * @param string     $raw_chunk Original (non-lowercased) chunk bytes, used only for the excerpt.
	 * @param bool       $only_new  Restrict to plain-literal rules flagged as new.
	 * @return array<int,MatchResult>
	 */
	public static function match( string $lc, Signatures $s, array $targets, int $line_base, string $raw_chunk, bool $only_new = false ): array {
		$pack    = $s->pack();
		$new     = $s->new_signatures();
		$matches = [];

		foreach ( $pack->plain_literals() as $position => [ $lit, $sig, $target ] ) {
			if ( ! in_array( $target, $targets, true ) ) {
				continue;
			}

			if ( $only_new && ! $new->has_literal( $position ) ) {
				continue;
			}

			$pos = strpos( $lc, $pack->literal( $lit ) );

			if ( false === $pos ) {
				continue;
			}

			$line    = substr_count( $lc, "\n", 0, $pos ) + $line_base + 1;
			$excerpt = ChunkReader::excerpt( $raw_chunk, $pos );

			$matches[] = MatchResult::from_signatures( $s, $sig, $line, $excerpt, $pos );
		}

		return $matches;
	}
}
