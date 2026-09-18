<?php
/**
 * Token-span navigation shared by the heuristic analyzers.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * Finding "the argument list of this call", "the end of this statement" or
 * "the body of this function" is the same bracket-matching problem in every
 * analyzer, and getting it wrong is how a heuristic starts reading past the
 * construct it thinks it is looking at. It lives here once, so
 * SourceSinkAnalyzer, StaticDecoder and TokenStream all walk spans the same
 * way.
 *
 * All methods take the normalised token list from TokenStream and return
 * token indexes, or `-1` when the span is unterminated.
 */
final class CallArguments {

	/**
	 * Token ids that carry no syntax: whitespace, comments and attributes'
	 * doc-blocks.
	 *
	 * @var array<int,int>
	 */
	private const INSIGNIFICANT = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];

	/**
	 * Index of the next token that is not whitespace or a comment, or -1.
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @param int                                         $from   Index to start from.
	 */
	public static function next_significant( array $tokens, int $from ): int {
		$count = count( $tokens );

		for ( $i = max( 0, $from ); $i < $count; $i++ ) {
			if ( ! in_array( $tokens[ $i ][0], self::INSIGNIFICANT, true ) ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * Index of the previous token that is not whitespace or a comment, or -1.
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @param int                                         $from   Index to search back from.
	 */
	public static function prev_significant( array $tokens, int $from ): int {
		for ( $i = min( $from, count( $tokens ) - 1 ); $i >= 0; $i-- ) {
			if ( ! in_array( $tokens[ $i ][0], self::INSIGNIFICANT, true ) ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * Index of the bracket closing the one at `$open`, or -1 when the file
	 * ends first. `{` also matches the two interpolation tokens PHP emits
	 * inside double-quoted strings, which close with a plain `}`.
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @param int                                         $open   Index of the opening bracket.
	 * @param string                                      $opener Opening bracket character.
	 * @param string                                      $closer Closing bracket character.
	 */
	public static function matching( array $tokens, int $open, string $opener = '(', string $closer = ')' ): int {
		$count = count( $tokens );
		$depth = 0;

		for ( $i = $open; $i < $count; $i++ ) {
			if ( self::opens( $tokens[ $i ], $opener ) ) {
				++$depth;

				continue;
			}

			if ( null === $tokens[ $i ][0] && $closer === $tokens[ $i ][1] ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return -1;
	}

	/**
	 * Bracket pairs for the whole file, built in one pass: `table[open] =
	 * close`. Callers that ask for the same spans repeatedly (a sink inside
	 * a sink inside a sink) would otherwise re-walk each enclosing span,
	 * which is quadratic on deliberately nested files.
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @return array<int,int> Opening bracket index => closing bracket index.
	 */
	public static function match_table( array $tokens ): array {
		$stack = [];
		$table = [];
		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( in_array( $tokens[ $i ][0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) {
				$stack[] = $i;

				continue;
			}

			if ( null !== $tokens[ $i ][0] ) {
				continue;
			}

			if ( in_array( $tokens[ $i ][1], [ '(', '[', '{' ], true ) ) {
				$stack[] = $i;

				continue;
			}

			if ( in_array( $tokens[ $i ][1], [ ')', ']', '}' ], true ) && [] !== $stack ) {
				$table[ (int) array_pop( $stack ) ] = $i;
			}
		}

		return $table;
	}

	/**
	 * Last index of a call's first argument: the token before the first
	 * top-level `,`, or the last token inside the call when it has one
	 * argument. Nested brackets are skipped through the match table, so the
	 * cost is the length of the first argument, not of the whole call.
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @param int                                         $open   Index of the call's `(`.
	 * @param int                                         $close  Last index inside the call.
	 * @param array<int,int>                              $table  Bracket match table.
	 */
	public static function first_argument_end( array $tokens, int $open, int $close, array $table ): int {
		for ( $i = $open + 1; $i <= $close; $i++ ) {
			if ( isset( $table[ $i ] ) ) {
				$i = $table[ $i ];

				continue;
			}

			if ( null === $tokens[ $i ][0] && ',' === $tokens[ $i ][1] ) {
				return $i - 1;
			}
		}

		return $close;
	}

	/**
	 * Index of the `;` ending the statement that starts at `$from`, or
	 * `$limit` when there is none inside the span.
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @param int                                         $from   Index to start from.
	 * @param int                                         $limit  Last index that may be inspected.
	 */
	public static function statement_end( array $tokens, int $from, int $limit ): int {
		$limit = min( $limit, count( $tokens ) - 1 );
		$depth = 0;

		for ( $i = $from; $i <= $limit; $i++ ) {
			$depth = self::adjust_depth( $tokens[ $i ], $depth );

			if ( $depth <= 0 && null === $tokens[ $i ][0] && ';' === $tokens[ $i ][1] ) {
				return $i;
			}
		}

		return $limit;
	}

	/**
	 * Index of the last token of the expression starting at `$from`: it
	 * ends at the first `;` or `,` outside brackets, or where an enclosing
	 * bracket closes. Used for arrow-function bodies, which have no braces.
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @param int                                         $from   Index to start from.
	 */
	public static function expression_end( array $tokens, int $from ): int {
		$count = count( $tokens );
		$depth = 0;

		for ( $i = $from; $i < $count; $i++ ) {
			$before = $depth;
			$depth  = self::adjust_depth( $tokens[ $i ], $depth );

			if ( $depth < $before && $depth < 0 ) {
				return $i - 1;
			}

			if ( $depth > 0 || null !== $tokens[ $i ][0] ) {
				continue;
			}

			if ( ';' === $tokens[ $i ][1] || ',' === $tokens[ $i ][1] ) {
				return $i - 1;
			}
		}

		return $count - 1;
	}

	/**
	 * Index of the `{` opening a function body declared at/after `$from`,
	 * skipping the parameter list, `use (…)` clause and return type. Returns
	 * -1 for bodiless declarations (interfaces, abstract methods).
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @param int                                         $from   Index of the function name (or its `(`).
	 */
	public static function find_body_open( array $tokens, int $from ): int {
		$count = count( $tokens );

		for ( $i = $from; $i < $count; $i++ ) {
			if ( null !== $tokens[ $i ][0] ) {
				continue;
			}

			if ( '(' === $tokens[ $i ][1] ) {
				$close = self::matching( $tokens, $i );

				if ( $close < 0 ) {
					return -1;
				}

				$i = $close;

				continue;
			}

			if ( '{' === $tokens[ $i ][1] ) {
				return $i;
			}

			if ( ';' === $tokens[ $i ][1] ) {
				return -1;
			}
		}

		return -1;
	}

	/**
	 * Index of the `=>` belonging to the arrow function at `$from`, or -1.
	 *
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 * @param int                                         $from   Index of the `fn` token.
	 */
	public static function find_double_arrow( array $tokens, int $from ): int {
		$count = count( $tokens );

		for ( $i = $from; $i < $count; $i++ ) {
			if ( null === $tokens[ $i ][0] && '(' === $tokens[ $i ][1] ) {
				$close = self::matching( $tokens, $i );

				if ( $close < 0 ) {
					return -1;
				}

				$i = $close;

				continue;
			}

			if ( T_DOUBLE_ARROW === $tokens[ $i ][0] ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * @param array{0:int|null,1:string,2:int} $token  Normalised token.
	 * @param string                           $opener Opening bracket character.
	 */
	private static function opens( array $token, string $opener ): bool {
		if ( null === $token[0] ) {
			return $opener === $token[1];
		}

		return '{' === $opener && in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true );
	}

	/**
	 * @param array{0:int|null,1:string,2:int} $token Normalised token.
	 * @param int                              $depth Current bracket depth.
	 */
	private static function adjust_depth( array $token, int $depth ): int {
		if ( in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) {
			return $depth + 1;
		}

		if ( null !== $token[0] ) {
			return $depth;
		}

		if ( in_array( $token[1], [ '(', '[', '{' ], true ) ) {
			return $depth + 1;
		}

		if ( in_array( $token[1], [ ')', ']', '}' ], true ) ) {
			return $depth - 1;
		}

		return $depth;
	}
}
