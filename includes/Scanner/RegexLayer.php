<?php
/**
 * Prefiltered PCRE signature matching against the signature pack's regex table.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

use LightweightPlugins\Scan\Bundle\Signatures;

defined( 'ABSPATH' ) || exit;

/**
 * The hot path (design spec §6.8): runs after HashLayer, LiteralLayer and
 * Prefilter narrow the field down. Every call target (`file_php`, `htaccess`,
 * …) is resolved to a priority-ordered list of the pack's regex indexes via
 * `indexes_for()`, and `match()` walks that list, honouring the "first
 * chunk only" flag on anchored patterns, the literal-presence prefilter and
 * an "infected found, stop running suspicious patterns" short-circuit. It
 * also owns the one-time PCRE runtime tuning (`configure_pcre()`) that keeps
 * a single catastrophic pattern from blocking a scan batch.
 */
final class RegexLayer {

	/**
	 * Default number of infected matches after which `match()` stops early.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_INFECTED = 10;

	/**
	 * Loop positions between `$deadline` checks.
	 *
	 * @var int
	 */
	private const DEADLINE_CHECK_EVERY = 50;

	/**
	 * Whether `configure_pcre()` has already run in this process.
	 *
	 * @var bool
	 */
	private static bool $configured = false;

	/**
	 * Whether the `pcre.backtrack_limit` ini_set() call succeeded.
	 *
	 * @var bool
	 */
	private static bool $backtrack_ok = false;

	/**
	 * Whether the `pcre.recursion_limit` ini_set() call succeeded.
	 *
	 * @var bool
	 */
	private static bool $recursion_ok = false;

	/**
	 * Tunes the PCRE engine once per process so a single catastrophic
	 * pattern (or a very large chunk) can't run away with the scan batch's
	 * time or memory budget: `preg_match()` then fails fast with a
	 * `preg_last_error()` code instead of hanging or exhausting the C
	 * stack. Idempotent — a second call is a no-op, so callers on every
	 * scan tick don't need to track whether this already ran.
	 */
	public static function configure_pcre(): void {
		if ( self::$configured ) {
			return;
		}

		self::$configured = true;

		// phpcs:ignore WordPress.PHP.IniSet.Risky -- deliberately tuning PCRE runtime limits for the regex layer; see class docblock.
		self::$backtrack_ok = false !== ini_set( 'pcre.backtrack_limit', '250000' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- deliberately tuning PCRE runtime limits for the regex layer; see class docblock.
		self::$recursion_ok = false !== ini_set( 'pcre.recursion_limit', '100000' );
	}

	/**
	 * Reports whether the PCRE tuning applied and whether the JIT is on,
	 * for the Health report (spec §12): a failed `ini_set()` (locked down
	 * host) or a disabled JIT changes how much the regex layer can be
	 * trusted to stay within its time budget.
	 *
	 * @return array{backtrack:bool, recursion:bool, jit:bool}
	 */
	public static function pcre_configured(): array {
		$jit = ini_get( 'pcre.jit' );

		return [
			'backtrack' => self::$backtrack_ok,
			'recursion' => self::$recursion_ok,
			'jit'       => false !== $jit && '' !== $jit && '0' !== $jit,
		];
	}

	/**
	 * Union of the pack's regex list for every `$targets` entry, deduped
	 * and sorted ascending — pack order is priority order (§5.3:
	 * prefiltered+anchored, prefiltered, anchored, neither; then pattern
	 * length), so this is also the order `match()` should walk them in. The
	 * sort is not cosmetic: a pack's per-target lists are range-checked at
	 * load, not order-checked.
	 *
	 * @param Signatures $s        Loaded signature set; uses the pack's target lists and, when `$only_new`, the new rules.
	 * @param string[]   $targets  Target names to union, e.g. `['file_php', 'file_code']`.
	 * @param bool       $only_new Restrict to regex indexes flagged as new.
	 * @return int[]
	 */
	public static function indexes_for( Signatures $s, array $targets, bool $only_new = false ): array {
		$pack  = $s->pack();
		$new   = $s->new_signatures();
		$union = [];

		foreach ( $targets as $target ) {
			foreach ( $pack->target_regexes( $target ) as $index ) {
				if ( ! $only_new || $new->has_regex( $index ) ) {
					$union[ $index ] = true;
				}
			}
		}

		$indexes = array_keys( $union );

		sort( $indexes );

		return $indexes;
	}

	/**
	 * Walks `$regex_indexes` from position `$start_at` (a position into that
	 * array, not a regex index) and runs each eligible pattern against
	 * `$chunk`. `$start_at` and the returned `stopped_at` let a caller pause
	 * and resume mid-target-set when a scan tick's time budget runs out.
	 * Owns loop control, deadline cadence and the infected quota only — the
	 * per-entry skip/run/match decision is `evaluate()`.
	 *
	 * @param Signatures              $s               Loaded signature set; uses the pack's regex table and sig tiers.
	 * @param int[]                   $regex_indexes   Regex indexes to run, in priority order (see indexes_for()).
	 * @param string                  $chunk            Chunk bytes to search (not lowercased — patterns match the original case).
	 * @param bool                    $first_chunk      Whether `$chunk` is the file's first chunk.
	 * @param int                     $line_base        Accumulated newline count before this chunk (0 for the first chunk).
	 * @param array<int,bool>         $presence         Prefilter::presence() result for this chunk: litIndex => present (absent = not present).
	 * @param bool                    $skip_suspicious  Skip `suspicious`-tier signatures (an earlier chunk already found an infected match).
	 * @param int                     $start_at         Position into `$regex_indexes` to resume from.
	 * @param (callable(): bool)|null $deadline      Returns true once the scan tick's time budget is exhausted. Checked before the first iteration and every DEADLINE_CHECK_EVERY positions after.
	 * @param int                     $max_infected     Stop once this many infected-tier matches have been found.
	 * @return array{matches: array<int,MatchResult>, errors:int, stopped_at:?int, infected:bool}
	 */
	public static function match(
		Signatures $s,
		array $regex_indexes,
		string $chunk,
		bool $first_chunk,
		int $line_base,
		array $presence,
		bool $skip_suspicious,
		int $start_at = 0,
		?callable $deadline = null,
		int $max_infected = self::DEFAULT_MAX_INFECTED
	): array {
		$matches        = [];
		$errors         = 0;
		$infected       = false;
		$infected_count = 0;
		$total          = count( $regex_indexes );

		for ( $pos = $start_at; $pos < $total; $pos++ ) {
			if ( self::deadline_exceeded( $deadline, $pos - $start_at ) ) {
				return self::result( $matches, $errors, $pos, $infected );
			}

			$match = self::evaluate( $s, $regex_indexes[ $pos ], $chunk, $first_chunk, $line_base, $presence, $skip_suspicious, $errors );

			if ( null === $match ) {
				continue;
			}

			$matches[] = $match;

			if ( 'infected' !== $match->tier ) {
				continue;
			}

			$skip_suspicious = true;
			$infected        = true;
			++$infected_count;

			if ( $infected_count >= $max_infected ) {
				return self::result( $matches, $errors, null, $infected );
			}
		}

		return self::result( $matches, $errors, null, $infected );
	}

	/**
	 * Runs the skip checks (first-chunk anchoring, skip_suspicious, literal
	 * presence) and, if none apply, `preg_match()` for a single regex entry.
	 *
	 * @param Signatures      $s               Loaded signature set; uses the pack's regex table and sig tiers.
	 * @param int             $regex_index     Regex index in the pack.
	 * @param string          $chunk           Chunk bytes to search.
	 * @param bool            $first_chunk     Whether `$chunk` is the file's first chunk.
	 * @param int             $line_base       Accumulated newline count before this chunk.
	 * @param array<int,bool> $presence        Prefilter::presence() result for this chunk.
	 * @param bool            $skip_suspicious Skip `suspicious`-tier signatures.
	 * @param int             $errors          By reference: incremented on a `preg_last_error()` other than `PREG_NO_ERROR`.
	 */
	private static function evaluate(
		Signatures $s,
		int $regex_index,
		string $chunk,
		bool $first_chunk,
		int $line_base,
		array $presence,
		bool $skip_suspicious,
		int &$errors
	): ?MatchResult {
		$pack = $s->pack();

		// Pure filters, cheapest first: most entries are ruled out by the
		// flags byte or the literal prefilter before their sig is unpacked.
		if ( $pack->regex_first( $regex_index ) && ! $first_chunk ) {
			return null;
		}

		if ( ! self::lits_present( $pack->regex_lits( $regex_index ), $presence ) ) {
			return null;
		}

		$sig = $pack->regex_sig( $regex_index );

		if ( $skip_suspicious && 'suspicious' === $pack->sig_tier( $sig ) ) {
			return null;
		}

		$captures = [];
		$found    = preg_match( $pack->pattern( $regex_index ), $chunk, $captures, PREG_OFFSET_CAPTURE );

		if ( PREG_NO_ERROR !== preg_last_error() ) {
			++$errors;
			return null;
		}

		if ( 1 !== $found ) {
			return null;
		}

		$offset = $captures[0][1];
		$line   = $line_base + substr_count( $chunk, "\n", 0, $offset ) + 1;

		return MatchResult::from_signatures( $s, $sig, $line, ChunkReader::excerpt( $chunk, $offset ), $offset );
	}

	/**
	 * True once the scan tick's time budget is exhausted: checked at
	 * `$evaluated = 0` (the position about to run, i.e. "before starting"
	 * that position) and then every DEADLINE_CHECK_EVERY positions after,
	 * so a deadline already past is caught immediately without a separate
	 * pre-loop check.
	 *
	 * @param (callable(): bool)|null $deadline  Returns true once the time budget is exhausted, or null when unbounded.
	 * @param int                     $evaluated Positions evaluated so far in this call (`$pos - $start_at`).
	 */
	private static function deadline_exceeded( ?callable $deadline, int $evaluated ): bool {
		if ( null === $deadline ) {
			return false;
		}

		if ( $evaluated > 0 && 0 !== $evaluated % self::DEADLINE_CHECK_EVERY ) {
			return false;
		}

		return $deadline();
	}

	/**
	 * @param int[]           $lits     Literal indexes the regex requires present (empty = no prefilter).
	 * @param array<int,bool> $presence Prefilter::presence() result: litIndex => present.
	 */
	private static function lits_present( array $lits, array $presence ): bool {
		foreach ( $lits as $lit_index ) {
			if ( empty( $presence[ $lit_index ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int,MatchResult> $matches    Matches found so far.
	 * @param int                    $errors     PCRE error count so far.
	 * @param int|null               $stopped_at Position to resume from, or null when the walk ran to completion (or stopped on the infected quota).
	 * @param bool                   $infected   Whether at least one infected-tier match was found.
	 * @return array{matches: array<int,MatchResult>, errors:int, stopped_at:?int, infected:bool}
	 */
	private static function result( array $matches, int $errors, ?int $stopped_at, bool $infected ): array {
		return [
			'matches'    => $matches,
			'errors'     => $errors,
			'stopped_at' => $stopped_at,
			'infected'   => $infected,
		];
	}
}
