<?php
/**
 * Chunk-overlap match de-duplication state (design spec §6.8).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Consecutive chunks share their last/first OVERLAP bytes (ChunkReader), so
 * a match landing in that shared window is found once by each chunk. This
 * class is FileScanner's per-file, mutable dedup state: `filter()` drops a
 * repeat find in a non-first chunk's overlap region when the same signature
 * already matched there in the previous chunk (at an absolute offset at or
 * past that chunk's own non-overlap boundary), and `absorb()` advances the
 * state to the chunk just finished. `to_array()`/`from_array()` let
 * `FileScanner` carry this state across a scan-tick resume, since it would
 * otherwise be lost the moment a new `scan()` call starts a fresh instance.
 */
final class OverlapDedup {

	/**
	 * Absolute byte offset the previous chunk started at, or null before any
	 * chunk has been absorbed.
	 *
	 * @var int|null
	 */
	private ?int $prev_chunk_offset;

	/**
	 * Maps a signature id to the absolute offset of its match in the
	 * previous chunk (the highest one, when a signature matched more than
	 * once — sufficient for the "at or past the boundary" check below).
	 *
	 * @var array<string,int>
	 */
	private array $prev_matches;

	/**
	 * @param int|null          $prev_chunk_offset Absolute byte offset the previous chunk started at, or null when there is none yet.
	 * @param array<string,int> $prev_matches      sig_id => absolute offset matched in the previous chunk.
	 */
	public function __construct( ?int $prev_chunk_offset = null, array $prev_matches = [] ) {
		$this->prev_chunk_offset = $prev_chunk_offset;
		$this->prev_matches      = $prev_matches;
	}

	/**
	 * Rebuilds state from a resume token's `prev` entry.
	 *
	 * @param array{offset?:int,sigs?:array<string,int>} $data Resume token `prev` entry (`ScanOutcome::to_resume()`'s shape).
	 */
	public static function from_array( array $data ): self {
		$offset = isset( $data['offset'] ) ? (int) $data['offset'] : 0;

		return new self( $offset, isset( $data['sigs'] ) ? (array) $data['sigs'] : [] );
	}

	/**
	 * Snapshot for a resume token's `prev` entry. `offset` defaults to 0
	 * when no chunk has been absorbed yet (only possible while still on the
	 * file's first chunk, where `filter()` never consults it anyway).
	 *
	 * @return array{offset:int,sigs:array<string,int>}
	 */
	public function to_array(): array {
		return [
			'offset' => $this->prev_chunk_offset ?? 0,
			'sigs'   => $this->prev_matches,
		];
	}

	/**
	 * Drops a match found in the shared overlap bytes of a non-first chunk
	 * when the same signature already matched there — i.e. at an absolute
	 * offset at or past the previous chunk's own non-overlap boundary
	 * (`prev_offset + stride`). Pure: does not advance the state (see
	 * `absorb()`), so it can be called once per layer (literal, then regex)
	 * against the same previous-chunk snapshot within one chunk.
	 *
	 * @param array{index:int,data:string,offset:int,first:bool,last:bool,line_base:int} $chunk  The current chunk, as yielded by ChunkReader.
	 * @param array<int,MatchResult>                                                     $matches Matches found in the current chunk (this layer only).
	 * @param int                                                                        $stride Bytes between the start of consecutive chunks (CHUNK - OVERLAP).
	 * @return array<int,MatchResult>
	 */
	public function filter( array $chunk, array $matches, int $stride ): array {
		if ( $chunk['first'] || null === $this->prev_chunk_offset ) {
			return $matches;
		}

		$prev_boundary = $this->prev_chunk_offset + $stride;
		$kept          = [];

		foreach ( $matches as $match ) {
			if ( $match->offset >= ChunkReader::OVERLAP || ! $this->already_seen( $match, $prev_boundary ) ) {
				$kept[] = $match;
			}
		}

		return $kept;
	}

	/**
	 * Advances the state to the chunk just finished, for the next chunk's
	 * `filter()` calls. Called with the union of every layer's *kept*
	 * (post-`filter()`) matches for that chunk.
	 *
	 * @param array{index:int,data:string,offset:int,first:bool,last:bool,line_base:int} $chunk   The chunk just finished, as yielded by ChunkReader.
	 * @param array<int,MatchResult>                                                     $matches This chunk's kept matches, across every layer.
	 */
	public function absorb( array $chunk, array $matches ): void {
		$grouped = [];

		foreach ( $matches as $match ) {
			$abs = $chunk['offset'] + $match->offset;

			if ( ! isset( $grouped[ $match->sig_id ] ) || $abs > $grouped[ $match->sig_id ] ) {
				$grouped[ $match->sig_id ] = $abs;
			}
		}

		$this->prev_chunk_offset = $chunk['offset'];
		$this->prev_matches      = $grouped;
	}

	private function already_seen( MatchResult $match, int $prev_boundary ): bool {
		return isset( $this->prev_matches[ $match->sig_id ] ) && $this->prev_matches[ $match->sig_id ] >= $prev_boundary;
	}
}
