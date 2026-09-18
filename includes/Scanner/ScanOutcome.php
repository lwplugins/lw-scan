<?php
/**
 * Result of scanning one file through the signature and heuristic layers.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Plain value object `FileScanner::scan()` returns (design spec §6.6 step
 * 9). `$matches` holds every hash/literal/regex hit exactly as the layers
 * reported it — byte offsets on each `MatchResult` are chunk-relative,
 * because `MatchResult` is a shared value object the layers build with no
 * notion of "which chunk of which file" it came from. `$abs_offsets` is the
 * parallel, index-aligned array (`$abs_offsets[$i]` corresponds to
 * `$matches[$i]`) of those same offsets converted to absolute file byte
 * positions (`chunk.offset + match.offset`); it is a separate property
 * rather than a `MatchResult` field so that class stays chunk-agnostic.
 */
final class ScanOutcome {

	/**
	 * @var array<int,MatchResult>
	 */
	public array $matches = [];

	/**
	 * Absolute (whole-file) byte offset for each entry in `$matches`, at the
	 * same index. A hash-layer match (whole-file, not chunked) has offset 0.
	 *
	 * @var array<int,int>
	 */
	public array $abs_offsets = [];

	/**
	 * @var array<int,array{analyzer:string,category:string,reason:string,line:int,excerpt:string,sig_ids:array<int,string>}>
	 */
	public array $heuristic = [];

	/**
	 * Count of `preg_last_error()` failures across every regex evaluated.
	 *
	 * @var int
	 */
	public int $errors = 0;

	/**
	 * True when a scan-tick deadline cut this file's scan short.
	 *
	 * @var bool
	 */
	public bool $partial = false;

	/**
	 * Self-contained resume token for a partial scan: `to_resume()`'s output.
	 * A caller (e.g. Run\Runner) persists this verbatim (it round-trips
	 * through `json_encode`/`json_decode(…, true)`) and passes it back as
	 * `FileScanner::scan()`'s `$resume` argument on the next tick. Written
	 * once, by the one `to_resume()` call that builds it.
	 *
	 * @var array{chunk?:int,regex?:int,infected?:bool,errors?:int,matches?:array<int,array<string,mixed>>,heuristic?:array<int,array<string,mixed>>,prev?:array{offset:int,sigs:array<string,int>}}
	 */
	public array $resume = [];

	/**
	 * True when the file matched the allowlist (md5, or md5+size) and was
	 * never read past that check.
	 *
	 * @var bool
	 */
	public bool $clean = false;

	/**
	 * True when the file is PHP and exceeded `max_file_size`, so only a
	 * `skip:large_php` review finding was produced.
	 *
	 * @var bool
	 */
	public bool $large_php = false;

	/**
	 * True once any `infected`-tier match was found.
	 *
	 * @var bool
	 */
	public bool $infected = false;

	/**
	 * Wall-clock time this scan() call took, in milliseconds.
	 *
	 * @var float
	 */
	public float $elapsed_ms = 0.0;

	/**
	 * True when the file produced at least one signature or heuristic finding.
	 */
	public function has_findings(): bool {
		return [] !== $this->matches || [] !== $this->heuristic;
	}

	/**
	 * The highest-severity tier found: `infected` outranks `suspicious`,
	 * which outranks any other signature tier (e.g. `info`), which outranks
	 * a heuristic-only finding (heuristics are always suspicious-equivalent
	 * but never carry their own tier). `clean` means no finding at all.
	 */
	public function max_tier(): string {
		$rank      = [
			'infected'   => 3,
			'suspicious' => 2,
		];
		$best      = '';
		$best_rank = -1;

		foreach ( $this->matches as $match ) {
			$candidate_rank = $rank[ $match->tier ] ?? 1;

			if ( $candidate_rank > $best_rank ) {
				$best_rank = $candidate_rank;
				$best      = $match->tier;
			}
		}

		if ( [] !== $this->heuristic && $best_rank < 2 ) {
			$best_rank = 2;
			$best      = 'suspicious';
		}

		return '' === $best ? 'clean' : $best;
	}

	/**
	 * Array shape for storage/transport: each match row gains an
	 * `abs_offset` key (see class docblock) alongside `MatchResult::to_array()`.
	 *
	 * @return array{matches:array<int,array<string,mixed>>,heuristic:array<int,array<string,mixed>>,errors:int,partial:bool,resume:array<string,mixed>,clean:bool,large_php:bool,infected:bool,elapsed_ms:float}
	 */
	public function to_array(): array {
		return [
			'matches'    => self::match_rows( $this->matches, $this->abs_offsets ),
			'heuristic'  => $this->heuristic,
			'errors'     => $this->errors,
			'partial'    => $this->partial,
			'resume'     => $this->resume,
			'clean'      => $this->clean,
			'large_php'  => $this->large_php,
			'infected'   => $this->infected,
			'elapsed_ms' => $this->elapsed_ms,
		];
	}

	/**
	 * Self-contained resume token (design spec §6.8, "Költségvetés"): the
	 * `{chunk, regex, prev}` position `FileScanner` hands in says where to
	 * pick up, and everything around it is the pipeline state a resumed
	 * `scan()` call would otherwise lose — `steps 1–4`
	 * (allowlist/hash/size/binary) never re-run on a resumed call, so their
	 * findings (already in `$matches`), the `infected` flag
	 * (`skip_suspicious` seed) and the chunk-overlap dedup state all have to
	 * travel in the token instead.
	 *
	 * @param array{chunk?:int,regex?:int,prev?:array{offset:int,sigs:array<string,int>}} $position Where the interrupted chunk sweep stopped.
	 * @return array{chunk:int,regex:int,infected:bool,errors:int,matches:array<int,array<string,mixed>>,heuristic:array<int,array<string,mixed>>,prev:array{offset:int,sigs:array<string,int>}}
	 */
	public function to_resume( array $position ): array {
		return [
			'chunk'     => isset( $position['chunk'] ) ? (int) $position['chunk'] : 0,
			'regex'     => isset( $position['regex'] ) ? (int) $position['regex'] : 0,
			'infected'  => $this->infected,
			'errors'    => $this->errors,
			'matches'   => self::match_rows( $this->matches, $this->abs_offsets ),
			'heuristic' => $this->heuristic,
			'prev'      => isset( $position['prev'] ) ? (array) $position['prev'] : [
				'offset' => 0,
				'sigs'   => [],
			],
		];
	}

	/**
	 * Rebuilds the outcome `to_resume()` produced: the `infected`/`errors`/
	 * `matches`/`heuristic` state a resumed `scan()` call continues from.
	 * `partial` and `resume` are left at their defaults (`false`/`[]`) —
	 * they describe this call's own outcome, not the one being resumed from.
	 * `chunk`/`regex`/`prev` are not restored onto the outcome itself; they
	 * are read directly from the same token by `FileScanner::scan_chunks()`.
	 *
	 * @param array{chunk?:int,regex?:int,infected?:bool,errors?:int,matches?:array<int,array<string,mixed>>,heuristic?:array<int,array<string,mixed>>,prev?:array{offset?:int,sigs?:array<string,int>}} $token `to_resume()`'s output.
	 */
	public static function from_resume( array $token ): ScanOutcome {
		$outcome = new self();

		$outcome->infected  = ! empty( $token['infected'] );
		$outcome->errors    = isset( $token['errors'] ) ? (int) $token['errors'] : 0;
		$outcome->heuristic = isset( $token['heuristic'] ) ? (array) $token['heuristic'] : [];

		foreach ( (array) ( $token['matches'] ?? [] ) as $row ) {
			$row                    = (array) $row;
			$match                  = MatchResult::from_array( $row );
			$outcome->matches[]     = $match;
			$outcome->abs_offsets[] = isset( $row['abs_offset'] ) ? (int) $row['abs_offset'] : $match->offset;
		}

		return $outcome;
	}

	/**
	 * The matches a token already records for one particular chunk.
	 *
	 * `FileScanner` needs these when it resumes a chunk: `OverlapDedup`
	 * wants every layer's kept matches for the chunk just finished, and a
	 * resumed chunk only re-runs its regex sweep — the literal layer ran in
	 * the tick that was interrupted, and its hits would otherwise be found
	 * again by the next chunk in the bytes the two share.
	 *
	 * A row belongs to `$chunk_offset` when its absolute offset less the
	 * chunk-relative offset recorded on the match is that chunk's own start.
	 * A match from any other chunk — the previous one's overlap tail
	 * included, whose absolute offset falls inside this chunk — fails that
	 * test and stays out, because its recorded offset is relative to a
	 * different chunk and would land somewhere else entirely.
	 *
	 * @param array<string,mixed> $token        `to_resume()`'s output.
	 * @param int                 $chunk_offset Absolute byte offset the chunk starts at.
	 * @return array<int,MatchResult>
	 */
	public static function matches_in_chunk( array $token, int $chunk_offset ): array {
		$matches = [];

		foreach ( (array) ( $token['matches'] ?? [] ) as $row ) {
			$row   = (array) $row;
			$match = MatchResult::from_array( $row );
			$abs   = isset( $row['abs_offset'] ) ? (int) $row['abs_offset'] : $match->offset;

			if ( $abs - $match->offset === $chunk_offset ) {
				$matches[] = $match;
			}
		}

		return $matches;
	}

	/**
	 * @param array<int,MatchResult> $matches     Matches to convert.
	 * @param array<int,int>         $abs_offsets Absolute offset for each entry in `$matches`, same index.
	 * @return array<int,array<string,mixed>>
	 */
	private static function match_rows( array $matches, array $abs_offsets ): array {
		$rows = [];

		foreach ( $matches as $i => $match ) {
			$row               = $match->to_array();
			$row['abs_offset'] = $abs_offsets[ $i ] ?? $match->offset;
			$rows[]            = $row;
		}

		return $rows;
	}
}
