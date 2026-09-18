<?php
/**
 * Tracks which variables in a token range carry attacker-controlled input.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * One responsibility: what counts as request input, and how far it is
 * followed. The model is deliberately shallow — an assignment whose
 * right-hand side mentions a source (or an already tainted variable) marks
 * the assigned variable for the rest of the range, and nothing else is
 * modelled: no aliasing, no sanitiser awareness, no cross-range flow other
 * than the file-scope pseudo-range. Everything it reports is therefore a
 * hint for a human, never proof.
 *
 * Both passes are linear in the range: statements are cut once in a single
 * forward walk, and `first_source()` jumps through a prebuilt index of the
 * only tokens that can ever answer it, instead of re-reading whole argument
 * spans for every nested call.
 */
final class TaintMap {

	/**
	 * Superglobals that carry attacker-controlled data.
	 *
	 * @var array<int,string>
	 */
	private const SOURCES = [ '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES' ];

	/**
	 * Normalised tokens of the file under analysis.
	 *
	 * @var array<int,array{0:int|null,1:string,2:int}>
	 */
	private array $tokens;

	/**
	 * Ascending indexes of the only tokens `first_source()` can return on.
	 *
	 * @var array<int,int>
	 */
	private array $candidates;

	/**
	 * Variable name (no `$`) => the source text it was assigned from.
	 *
	 * @var array<string,string>
	 */
	private array $taint = [];

	/**
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 */
	public function __construct( array $tokens ) {
		$this->tokens     = $tokens;
		$this->candidates = self::index_candidates( $tokens );
	}

	/**
	 * Rebuilds the map for one scope, discarding the previous one so taint
	 * never leaks from a function body into the next. A scope is a list of
	 * spans rather than one range because top-level code is everything
	 * *between* the function bodies of a file.
	 *
	 * @param array<int,array{0:int,1:int}> $spans Token index spans making up the scope.
	 * @param array<string,string>          $seeds Parameters already tainted by a caller (see ParameterSeeds).
	 */
	public function build( array $spans, array $seeds = [] ): void {
		$this->taint = $seeds;

		foreach ( $spans as $span ) {
			$statement = $span[0];

			for ( $i = $span[0]; $i <= $span[1]; $i++ ) {
				if ( ! self::ends_statement( $this->tokens[ $i ] ) ) {
					continue;
				}

				$this->taint_statement( $statement, $i );
				$statement = $i + 1;
			}

			if ( $statement <= $span[1] ) {
				$this->taint_statement( $statement, $span[1] );
			}
		}
	}

	/**
	 * The source a variable was assigned from, or null when it is clean.
	 *
	 * @param string $name Variable name without the `$`.
	 */
	public function of( string $name ): ?string {
		return isset( $this->taint[ $name ] ) ? $this->taint[ $name ] : null;
	}

	/**
	 * First attacker-controlled value inside a token span, described the way
	 * it is written in the file (`$_GET['path']`, `php://input`, `getenv`).
	 *
	 * @param int $from First index of the span.
	 * @param int $to   Last index of the span.
	 */
	public function first_source( int $from, int $to ): ?string {
		$to    = min( $to, count( $this->tokens ) - 1 );
		$total = count( $this->candidates );

		for ( $k = $this->first_candidate( $from ); $k < $total; $k++ ) {
			$i = $this->candidates[ $k ];

			if ( $i > $to ) {
				return null;
			}

			$source = $this->source_at( $i );

			if ( null !== $source ) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * Taints every variable in one statement that is assigned from input.
	 * The statement is read once into two ascending lists — assignment
	 * targets and inputs — which are then walked together, so each target
	 * takes the first input that appears after its `=`. That is what makes
	 * `if ( isset( $_GET['c'] ) ) $cmd = $_GET['c'];` work: the guard's
	 * input sits before the `=` and is skipped, the right-hand side's is not.
	 *
	 * @param int $start First index of the statement.
	 * @param int $end   Last index of the statement.
	 */
	private function taint_statement( int $start, int $end ): void {
		if ( $start > $end ) {
			return;
		}

		$targets = [];
		$sources = [];

		for ( $i = $start; $i <= $end; $i++ ) {
			if ( T_VARIABLE === $this->tokens[ $i ][0] ) {
				$operator = CallArguments::next_significant( $this->tokens, $i + 1 );

				if ( $operator >= 0 && $operator <= $end && self::assigns( $this->tokens[ $operator ] ) ) {
					$targets[] = [ ltrim( $this->tokens[ $i ][1], '$' ), $operator ];

					// The target of an assignment is written, not read, so it
					// is not itself input — `$a = $b = $_GET['x']` taints both.
					$i = $operator;

					continue;
				}
			}

			$source = $this->source_at( $i );

			if ( null !== $source ) {
				$sources[] = [ $i, $source ];
			}
		}

		$next  = 0;
		$total = count( $sources );

		foreach ( $targets as $target ) {
			while ( $next < $total && $sources[ $next ][0] < $target[1] ) {
				++$next;
			}

			if ( $next >= $total ) {
				return;
			}

			$this->taint[ $target[0] ] = $sources[ $next ][1];
		}
	}

	/**
	 * The input this token stands for, or null when it is not one.
	 *
	 * @param int $i Token index.
	 */
	private function source_at( int $i ): ?string {
		$token = $this->tokens[ $i ];

		if ( T_VARIABLE === $token[0] ) {
			if ( in_array( $token[1], self::SOURCES, true ) ) {
				return $this->source_text( $i );
			}

			$name = ltrim( $token[1], '$' );

			return isset( $this->taint[ $name ] ) ? $this->taint[ $name ] : null;
		}

		if ( T_STRING === $token[0] && 'getenv' === strtolower( $token[1] ) ) {
			return 'getenv';
		}

		if ( T_CONSTANT_ENCAPSED_STRING === $token[0] && false !== stripos( $token[1], 'php://input' ) ) {
			return 'php://input';
		}

		return null;
	}

	/**
	 * `$_GET` plus its literal index, when it has one.
	 *
	 * @param int $i Index of the superglobal token.
	 */
	private function source_text( int $i ): string {
		$open = CallArguments::next_significant( $this->tokens, $i + 1 );

		if ( $open < 0 || null !== $this->tokens[ $open ][0] || '[' !== $this->tokens[ $open ][1] ) {
			return $this->tokens[ $i ][1];
		}

		$key   = CallArguments::next_significant( $this->tokens, $open + 1 );
		$close = $key < 0 ? -1 : CallArguments::next_significant( $this->tokens, $key + 1 );

		if ( $key < 0 || $close < 0 || T_CONSTANT_ENCAPSED_STRING !== $this->tokens[ $key ][0] || ']' !== $this->tokens[ $close ][1] ) {
			return $this->tokens[ $i ][1];
		}

		return $this->tokens[ $i ][1] . "['" . trim( $this->tokens[ $key ][1], '"\'' ) . "']";
	}

	/**
	 * Position in the candidate index of the first entry at or after a
	 * token index (binary search — spans are visited out of order).
	 *
	 * @param int $from Token index.
	 */
	private function first_candidate( int $from ): int {
		$low  = 0;
		$high = count( $this->candidates );

		while ( $low < $high ) {
			$middle = intdiv( $low + $high, 2 );

			if ( $this->candidates[ $middle ] < $from ) {
				$low = $middle + 1;

				continue;
			}

			$high = $middle;
		}

		return $low;
	}

	/**
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @return array<int,int>
	 */
	private static function index_candidates( array $tokens ): array {
		$candidates = [];
		$count      = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$id = $tokens[ $i ][0];

			if ( T_VARIABLE === $id ) {
				$candidates[] = $i;

				continue;
			}

			if ( T_STRING === $id && 'getenv' === strtolower( $tokens[ $i ][1] ) ) {
				$candidates[] = $i;

				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $id && false !== stripos( $tokens[ $i ][1], 'php://input' ) ) {
				$candidates[] = $i;
			}
		}

		return $candidates;
	}

	/**
	 * `;` and block braces both end a statement for taint purposes.
	 *
	 * @param array{0:int|null,1:string,2:int} $token Normalised token.
	 */
	private static function ends_statement( array $token ): bool {
		if ( in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) {
			return true;
		}

		return null === $token[0] && in_array( $token[1], [ ';', '{', '}' ], true );
	}

	/**
	 * `=` and `.=` both move input into a variable.
	 *
	 * @param array{0:int|null,1:string,2:int} $token Normalised token.
	 */
	private static function assigns( array $token ): bool {
		if ( T_CONCAT_EQUAL === $token[0] ) {
			return true;
		}

		return null === $token[0] && '=' === $token[1];
	}
}
