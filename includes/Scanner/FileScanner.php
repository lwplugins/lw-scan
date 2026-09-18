<?php
/**
 * Runs one file through the hash, literal, regex and heuristic layers.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Index\ContentType;
use LightweightPlugins\Scan\Scanner\Heuristic\Gate;
use LightweightPlugins\Scan\Scanner\Heuristic\HeuristicScanner;

defined( 'ABSPATH' ) || exit;

/**
 * The per-file pipeline (design spec §6.6): allowlist short-circuit, whole-
 * file hash matching, the size/kind gates, the chunked literal+regex sweep
 * (§6.7/§6.8), and finally the heuristic layer on whatever Gate (§6.9)
 * allows. Every dependency (HashLayer, LiteralLayer, RegexLayer, Prefilter,
 * ChunkReader, OverlapDedup, Heuristic\*) is a pure/stateless (or, for
 * OverlapDedup, self-serializing; RegexWalks, a per-scanner memo) layer; this
 * class owns only the sequencing and the whole-file infected quota.
 *
 * A resumed call (`$resume` non-empty) is not a fresh scan continuing where
 * it left off in memory — it is a brand-new PHP request/tick, so steps 1–4
 * (allowlist/hash/size/binary) are skipped entirely and every other piece of
 * state a resumed chunk sweep needs (matches already found, the `infected`
 * flag that seeds `skip_suspicious`, and the overlap-dedup state) travels in
 * the resume token itself — see `ScanOutcome::to_resume()`/`from_resume()`.
 */
final class FileScanner {

	/**
	 * Spec §6.6 step 7: once this many infected-tier matches are found
	 * across the whole file, the scan stops (no further chunks are read).
	 */
	private const MAX_INFECTED_TOTAL = 10;

	/**
	 * Cap on stored heuristic findings per file, first-found order.
	 */
	private const MAX_HEURISTIC_FINDINGS = 10;

	/**
	 * Loaded signature set (pack, lazy meta, new rules).
	 *
	 * @var Signatures
	 */
	private Signatures $signatures;

	/**
	 * Regex walks per target set, selected once for `$signatures`.
	 *
	 * @var RegexWalks
	 */
	private RegexWalks $walks;

	/**
	 * `max_file_size` (bytes), `heuristics` (bool), `memory_limit` (bytes).
	 *
	 * @var array<string,mixed>
	 */
	private array $config;

	/**
	 * @var HeuristicScanner
	 */
	private HeuristicScanner $heuristics;

	/**
	 * @param Signatures            $signatures Loaded signature set.
	 * @param array<string,mixed>   $config     `max_file_size`, `heuristics`, `memory_limit`.
	 * @param HeuristicScanner|null $heuristics Injected for testing; defaults to one wired to this scanner's own rescan().
	 */
	public function __construct( Signatures $signatures, array $config, ?HeuristicScanner $heuristics = null ) {
		$this->signatures = $signatures;
		$this->walks      = new RegexWalks( $signatures );
		$this->config     = $config;
		$this->heuristics = $heuristics ?? new HeuristicScanner(
			function ( string $decoded ): array {
				return $this->rescan( $decoded );
			}
		);
	}

	/**
	 * Target set for a file's regex/literal sweep (spec §6.6 step 5). A
	 * `.htaccess` basename always wins over the content-detected kind.
	 *
	 * @param string $kind     Detected content kind (`php`, `js`, `html`, `binary`, `archive`, `other`).
	 * @param string $basename The file's basename.
	 * @return string[]
	 */
	public static function targets_for( string $kind, string $basename ): array {
		if ( '.htaccess' === strtolower( $basename ) ) {
			return [ 'htaccess', 'file_any' ];
		}

		switch ( $kind ) {
			case 'php':
				return [ 'file_php', 'file_code', 'file_any', 'file_js', 'file_html' ];
			case 'js':
				return [ 'file_js', 'file_code', 'file_any' ];
			case 'html':
				return [ 'file_html', 'file_any' ];
			default:
				return [ 'file_any' ];
		}
	}

	/**
	 * @param array<string,mixed>     $file_row Row from the files table (`md5`, `sha256`, `size`, `kind`, `path`, `origin`, `known_good`).
	 * @param string                  $abs_path Absolute path of the file on disk.
	 * @param bool                    $only_new Restrict literal/regex matching to signatures new since the previous pack.
	 * @param (callable(): bool)|null $deadline Returns true once the scan tick's time budget is exhausted.
	 * @param array<string,mixed>     $resume   Resume token from a previous partial scan's `ScanOutcome::to_resume()` (or `[]` for a fresh scan).
	 */
	public function scan( array $file_row, string $abs_path, bool $only_new = false, ?callable $deadline = null, array $resume = [] ): ScanOutcome {
		$started  = microtime( true );
		$resuming = [] !== $resume;
		$outcome  = $resuming ? ScanOutcome::from_resume( $resume ) : new ScanOutcome();

		RegexLayer::configure_pcre();

		$kind = isset( $file_row['kind'] ) ? (string) $file_row['kind'] : '';

		if ( ! $resuming && $this->initial_gates( $outcome, $file_row, $kind ) ) {
			return $this->finish( $outcome, $started );
		}

		$basename = basename( (string) ( $file_row['path'] ?? $abs_path ) );
		$targets  = self::targets_for( $kind, $basename );

		$content = $this->scan_chunks( $outcome, $abs_path, $targets, $only_new, $deadline, $resume );

		if ( ! $outcome->partial ) {
			$this->run_heuristics( $outcome, $file_row, $content );
		}

		return $this->finish( $outcome, $started );
	}

	/**
	 * Spec §6.6 steps 1–4: allowlist short-circuit, whole-file hash match
	 * (any kind/size), the `max_file_size` gate (PHP → `skip:large_php`
	 * review finding), and the opaque-bytes short-circuit for `binary` and
	 * `archive`. Only ever run for a fresh scan — see class docblock.
	 *
	 * @param ScanOutcome         $outcome  Outcome being built.
	 * @param array<string,mixed> $file_row Row from the files table.
	 * @param string              $kind     Detected content kind.
	 * @return bool True when the scan is already finished (no chunk sweep needed).
	 */
	private function initial_gates( ScanOutcome $outcome, array $file_row, string $kind ): bool {
		$md5    = isset( $file_row['md5'] ) ? (string) $file_row['md5'] : '';
		$sha256 = isset( $file_row['sha256'] ) ? (string) $file_row['sha256'] : '';
		$size   = isset( $file_row['size'] ) ? (int) $file_row['size'] : 0;

		if ( HashLayer::allowlisted( $md5, $size, $this->signatures->pack() ) ) {
			$outcome->clean = true;

			return true;
		}

		foreach ( HashLayer::match( $md5, $sha256, $this->signatures ) as $match ) {
			$this->append( $outcome, $match, 0 );
		}

		$max_size = isset( $this->config['max_file_size'] ) ? (int) $this->config['max_file_size'] : PHP_INT_MAX;

		if ( $size > $max_size ) {
			if ( 'php' === $kind ) {
				$outcome->large_php = true;
				$this->append( $outcome, self::large_php_finding(), 0 );
			}

			return true;
		}

		// Compressed or otherwise opaque bytes: the literal/regex layers can
		// only burn time on them, and the hash layer above has already had
		// its say.
		return in_array( $kind, [ ContentType::BINARY, ContentType::ARCHIVE ], true );
	}

	/**
	 * Kept in one method over the 50-line limit: the resume-token state
	 * machine must see every variable it saves.
	 *
	 * Spec §6.6 step 6: streams the file in overlapping chunks, running
	 * Prefilter + LiteralLayer + RegexLayer on each. Stops entirely once the
	 * whole-file infected quota is reached (step 7) or a chunk's regex sweep
	 * hits the caller's deadline (returns partial + a full resume token).
	 * Returns the file's full content when it fit in a single chunk (used
	 * for step 8's heuristic pass), or null otherwise.
	 *
	 * @param ScanOutcome             $outcome  Outcome being built; matches/errors/partial/resume are appended/set directly.
	 * @param string                  $abs_path Absolute path of the file on disk.
	 * @param string[]                $targets  Target names this file is scanned against.
	 * @param bool                    $only_new Restrict matching to signatures new since the previous pack.
	 * @param (callable(): bool)|null $deadline Returns true once the scan tick's time budget is exhausted.
	 * @param array<string,mixed>     $resume   Resume token, or `[]` for a fresh scan.
	 */
	private function scan_chunks( ScanOutcome $outcome, string $abs_path, array $targets, bool $only_new, ?callable $deadline, array $resume ): ?string {
		$regex_indexes = $this->walks->indexes( $targets, $only_new );
		$resuming      = [] !== $resume;
		$resume_chunk  = isset( $resume['chunk'] ) ? (int) $resume['chunk'] : 0;
		$resume_regex  = isset( $resume['regex'] ) ? (int) $resume['regex'] : 0;
		$stride        = ChunkReader::CHUNK - ChunkReader::OVERLAP;

		$dedup           = $resuming ? OverlapDedup::from_array( (array) ( $resume['prev'] ?? [] ) ) : new OverlapDedup();
		$skip_suspicious = $outcome->infected;
		$sole_chunk      = null;

		foreach ( ( new ChunkReader( $abs_path ) )->chunks() as $chunk ) {
			if ( $resuming && $chunk['index'] < $resume_chunk ) {
				continue;
			}

			if ( $chunk['first'] && $chunk['last'] ) {
				$sole_chunk = $chunk['data'];
			}

			if ( self::count_infected( $outcome->matches ) >= self::MAX_INFECTED_TOTAL ) {
				break;
			}

			$is_resume_chunk = $resuming && $resume_chunk === $chunk['index'];
			$recorded        = $is_resume_chunk ? ScanOutcome::matches_in_chunk( $resume, $chunk['offset'] ) : [];
			$lc              = Prefilter::ascii_lower( $chunk['data'] );
			$presence        = Prefilter::presence( $lc, $this->signatures->pack() );

			$literal_matches = $is_resume_chunk ? [] : LiteralLayer::match( $lc, $this->signatures, $targets, $chunk['line_base'], $chunk['data'], $only_new );
			$literal_matches = $dedup->filter( $chunk, $literal_matches, $stride );

			foreach ( $literal_matches as $match ) {
				$this->append( $outcome, $match, $chunk['offset'] );

				if ( 'infected' === $match->tier ) {
					$skip_suspicious = true;
				}
			}

			// Fold this chunk's own literal-layer infected hits into the
			// budget before asking RegexLayer for more, so the two layers
			// together can't push the whole-file total past the quota.
			$remaining = max( 0, self::MAX_INFECTED_TOTAL - self::count_infected( $outcome->matches ) );

			$regex_result = RegexLayer::match(
				$this->signatures,
				$regex_indexes,
				$chunk['data'],
				$chunk['first'],
				$chunk['line_base'],
				$presence,
				$skip_suspicious,
				$is_resume_chunk ? $resume_regex : 0,
				$deadline,
				$remaining
			);

			$outcome->errors += $regex_result['errors'];

			$regex_matches = $dedup->filter( $chunk, $regex_result['matches'], $stride );

			foreach ( $regex_matches as $match ) {
				$this->append( $outcome, $match, $chunk['offset'] );

				if ( 'infected' === $match->tier ) {
					$skip_suspicious = true;
				}
			}

			if ( null !== $regex_result['stopped_at'] ) {
				$outcome->partial = true;
				$outcome->resume  = $outcome->to_resume(
					[
						'chunk' => $chunk['index'],
						'regex' => $regex_result['stopped_at'],
						'prev'  => $dedup->to_array(),
					]
				);

				return $sole_chunk;
			}

			// `$recorded` is what the interrupted tick already found here; see
			// ScanOutcome::matches_in_chunk() for why absorb() needs it.
			$dedup->absorb( $chunk, array_merge( $recorded, $literal_matches, $regex_matches ) );

			if ( $chunk['last'] ) {
				break;
			}
		}

		return $sole_chunk;
	}

	/**
	 * Spec §6.6 step 8: only reachable when the file fit in a single chunk
	 * (Gate's own size ceiling is well under one chunk, so a multi-chunk
	 * file never gets here regardless).
	 *
	 * @param ScanOutcome         $outcome  Outcome being built; `heuristic` is set directly.
	 * @param array<string,mixed> $file_row Row from the files table, passed through to HeuristicScanner.
	 * @param string|null         $content  The file's full content, or null when it did not fit in a single chunk.
	 */
	private function run_heuristics( ScanOutcome $outcome, array $file_row, ?string $content ): void {
		if ( null === $content || ! Gate::allows( $file_row, $outcome->infected, $this->config ) ) {
			return;
		}

		$findings = $this->heuristics->analyze( $content, $file_row );

		$outcome->heuristic = array_slice( $findings, 0, self::MAX_HEURISTIC_FINDINGS );
	}

	/**
	 * The heuristic layer's `StaticDecoder` rescan callback (spec §6.9):
	 * runs the literal + regex layers over decoded text as a single,
	 * unbounded, first chunk against the php+any targets.
	 *
	 * @param string $decoded Decoded text to rescan.
	 * @return array<int,MatchResult>
	 */
	private function rescan( string $decoded ): array {
		$targets  = [ 'file_php', 'file_any' ];
		$lc       = Prefilter::ascii_lower( $decoded );
		$presence = Prefilter::presence( $lc, $this->signatures->pack() );

		$matches = LiteralLayer::match( $lc, $this->signatures, $targets, 0, $decoded, false );
		$indexes = $this->walks->indexes( $targets, false );
		$result  = RegexLayer::match( $this->signatures, $indexes, $decoded, true, 0, $presence, false, 0, null );

		return array_merge( $matches, $result['matches'] );
	}

	/**
	 * Spec §6.6 step 3: the review finding recorded when a PHP file exceeds
	 * `max_file_size` and is skipped rather than scanned. Not backed by a
	 * pack signature, so it is built directly rather than via
	 * `MatchResult::from_signatures()`. `category` is `unknown` — `skip:large_php`
	 * isn't a signature category, and severity is computed later by
	 * `Severity::of()`, not carried on the match itself.
	 */
	private static function large_php_finding(): MatchResult {
		return new MatchResult( -1, 'skip:large_php', 'suspicious', 'unknown', 'Large PHP file skipped (exceeds max_file_size)', 0, '', 0 );
	}

	/**
	 * Appends a match and its absolute offset to the outcome, and flags the
	 * outcome infected when the match's tier is `infected`.
	 *
	 * @param ScanOutcome $outcome      Outcome being built; `matches`/`abs_offsets`/`infected` are updated.
	 * @param MatchResult $match        The match to record.
	 * @param int         $chunk_offset Absolute byte offset the match's chunk started at (0 for hash matches).
	 */
	private function append( ScanOutcome $outcome, MatchResult $match, int $chunk_offset ): void {
		$outcome->matches[]     = $match;
		$outcome->abs_offsets[] = $chunk_offset + $match->offset;

		if ( 'infected' === $match->tier ) {
			$outcome->infected = true;
		}
	}

	/**
	 * @param array<int,MatchResult> $matches Matches to count.
	 */
	private static function count_infected( array $matches ): int {
		$count = 0;

		foreach ( $matches as $match ) {
			if ( 'infected' === $match->tier ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Stamps elapsed time and returns the finished outcome.
	 *
	 * @param ScanOutcome $outcome Outcome being finalized.
	 * @param float       $started `microtime(true)` at the start of scan().
	 */
	private function finish( ScanOutcome $outcome, float $started ): ScanOutcome {
		$outcome->elapsed_ms = ( microtime( true ) - $started ) * 1000;

		return $outcome;
	}
}
