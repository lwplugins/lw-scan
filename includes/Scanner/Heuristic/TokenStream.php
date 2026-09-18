<?php
/**
 * Normalised `token_get_all()` view of one PHP file for the heuristic analyzers.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * The heuristic layer never works on raw text: every analyzer reads this
 * token view, so a comment mentioning `unlink( $_GET['x'] )` can never
 * produce a finding. Tokenising is deliberately forgiving — malware is
 * routinely syntactically broken (truncated uploads, appended payloads),
 * and a file that cannot be tokenised at all simply yields no tokens, so
 * the analyzers return nothing instead of erroring the whole scan.
 */
final class TokenStream {

	/**
	 * Name of the pseudo-range covering the whole file.
	 */
	public const GLOBAL_RANGE = '(global)';

	/**
	 * Name given to arrow-function bodies. They capture the enclosing scope
	 * by value, so they are *not* a scope of their own for taint purposes.
	 */
	public const ARROW_RANGE = '{arrow}';

	/**
	 * Variable names that carry no information about how a file was written.
	 *
	 * @var array<int,string>
	 */
	private const SKIP_IDENTIFIERS = [ 'this', 'GLOBALS', '_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_FILES', '_SESSION', '_ENV' ];

	/**
	 * Normalised tokens: `[id|null, text, line]`.
	 *
	 * @var array<int,array{0:int|null,1:string,2:int}>
	 */
	private array $tokens;

	/**
	 * Lazily built function body ranges.
	 *
	 * @var array<int,array{name:string,start:int,end:int,params:array<int,string>}>|null
	 */
	private ?array $functions = null;

	/**
	 * Lazily built identifier list.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $identifiers = null;

	public function __construct( string $code ) {
		$this->tokens = self::tokenize( $code );
	}

	/**
	 * @return array<int,array{0:int|null,1:string,2:int}>
	 */
	public function tokens(): array {
		return $this->tokens;
	}

	/**
	 * Token index ranges of every function body in the file (named
	 * functions, methods, closures and arrow functions), plus a final
	 * `(global)` pseudo-range covering the whole file. Callers that need
	 * *scope* rather than coverage use `top_level_spans()`, which is the
	 * same file with those bodies cut out.
	 *
	 * @return array<int,array{name:string,start:int,end:int,params:array<int,string>}>
	 */
	public function functions(): array {
		if ( null !== $this->functions ) {
			return $this->functions;
		}

		$ranges = [];
		$count  = count( $this->tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$id = $this->tokens[ $i ][0];

			if ( T_FUNCTION !== $id && T_FN !== $id ) {
				continue;
			}

			$range = T_FN === $id ? $this->arrow_range( $i ) : $this->body_range( $i );

			if ( null !== $range ) {
				$ranges[] = $range;
			}
		}

		if ( $count > 0 ) {
			$ranges[] = [
				'name'   => self::GLOBAL_RANGE,
				'start'  => 0,
				'end'    => $count - 1,
				'params' => [],
			];
		}

		$this->functions = $ranges;

		return $ranges;
	}

	/**
	 * The spans of the file that sit outside every function body — "global"
	 * in the scope sense, not "the whole file". A variable named `$args`
	 * inside one function says nothing about an `$args` inside another, so
	 * the top-level scan must not walk through function bodies on its way
	 * past them.
	 *
	 * @return array<int,array{0:int,1:int}> Ascending, non-overlapping token index spans.
	 */
	public function top_level_spans(): array {
		$count = count( $this->tokens );

		if ( 0 === $count ) {
			return [];
		}

		$bodies = [];

		foreach ( $this->functions() as $range ) {
			if ( self::GLOBAL_RANGE !== $range['name'] && self::ARROW_RANGE !== $range['name'] ) {
				$bodies[] = [ $range['start'], $range['end'] ];
			}
		}

		usort(
			$bodies,
			static function ( array $a, array $b ): int {
				return $a[0] <=> $b[0];
			}
		);

		$spans  = [];
		$cursor = 0;

		foreach ( $bodies as $body ) {
			if ( $body[0] > $cursor ) {
				$spans[] = [ $cursor, $body[0] - 1 ];
			}

			// Nested bodies are already covered by their parent.
			$cursor = max( $cursor, $body[1] + 1 );
		}

		if ( $cursor < $count ) {
			$spans[] = [ $cursor, $count - 1 ];
		}

		return $spans;
	}

	/**
	 * Every variable name (without the `$`), declared function name and
	 * `goto` label in the file, de-duplicated and in source order.
	 *
	 * @return array<int,string>
	 */
	public function identifiers(): array {
		if ( null !== $this->identifiers ) {
			return $this->identifiers;
		}

		$names = [];
		$count = count( $this->tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $this->tokens[ $i ];

			if ( T_VARIABLE === $token[0] ) {
				$name = ltrim( $token[1], '$' );

				if ( '' !== $name && ! in_array( $name, self::SKIP_IDENTIFIERS, true ) ) {
					$names[ $name ] = $name;
				}

				continue;
			}

			if ( T_FUNCTION !== $token[0] && T_GOTO !== $token[0] ) {
				continue;
			}

			$next = CallArguments::next_significant( $this->tokens, $i + 1 );

			if ( $next >= 0 && T_STRING === $this->tokens[ $next ][0] ) {
				$names[ $this->tokens[ $next ][1] ] = $this->tokens[ $next ][1];
			}
		}

		$this->identifiers = array_values( $names );

		return $this->identifiers;
	}

	/**
	 * `TOKEN_PARSE` gives correct context-sensitive tokens but throws on
	 * invalid code, so a broken file falls back to the lenient tokenizer
	 * and, if even that fails, to no tokens at all.
	 *
	 * @param string $code Raw file contents.
	 * @return array<int,array{0:int|null,1:string,2:int}>
	 */
	private static function tokenize( string $code ): array {
		try {
			return self::normalize( token_get_all( $code, TOKEN_PARSE ) );
		} catch ( \Throwable $parse_error ) {
			unset( $parse_error );
		}

		try {
			return self::normalize( token_get_all( $code ) );
		} catch ( \Throwable $lenient_error ) {
			unset( $lenient_error );
		}

		return [];
	}

	/**
	 * @param array<int,array{0:int,1:string,2:int}|string> $raw Raw `token_get_all()` output.
	 * @return array<int,array{0:int|null,1:string,2:int}>
	 */
	private static function normalize( array $raw ): array {
		$out  = [];
		$line = 1;

		foreach ( $raw as $token ) {
			if ( is_array( $token ) ) {
				$line  = (int) $token[2];
				$out[] = [ (int) $token[0], (string) $token[1], $line ];
				$line += substr_count( (string) $token[1], "\n" );

				continue;
			}

			$out[] = [ null, $token, $line ];
		}

		return $out;
	}

	/**
	 * Parameter variable names of a declaration, in order, so a call's
	 * arguments can be lined up with them. Default values are ignored: only
	 * the first variable of each comma-separated part is a parameter.
	 *
	 * @param int $index Index of the declaration's name (or its `(`).
	 * @return array<int,string> Parameter names without the `$`.
	 */
	private function parameter_names( int $index ): array {
		$open = null === $this->tokens[ $index ][0] && '(' === $this->tokens[ $index ][1]
			? $index
			: CallArguments::next_significant( $this->tokens, $index + 1 );

		if ( $open < 0 || null !== $this->tokens[ $open ][0] || '(' !== $this->tokens[ $open ][1] ) {
			return [];
		}

		$close = CallArguments::matching( $this->tokens, $open );

		if ( $close < 0 ) {
			return [];
		}

		$names = [];
		$taken = false;
		$depth = 0;

		for ( $i = $open + 1; $i < $close; $i++ ) {
			$token = $this->tokens[ $i ];

			if ( null === $token[0] && in_array( $token[1], [ '(', '[' ], true ) ) {
				++$depth;
			} elseif ( null === $token[0] && in_array( $token[1], [ ')', ']' ], true ) ) {
				--$depth;
			} elseif ( 0 === $depth && null === $token[0] && ',' === $token[1] ) {
				$taken = false;
			} elseif ( 0 === $depth && ! $taken && T_VARIABLE === $token[0] ) {
				$names[] = ltrim( $token[1], '$' );
				$taken   = true;
			}
		}

		return $names;
	}

	/**
	 * Body range of a `function` declaration: from its `{` to the matching
	 * `}`. Abstract/interface declarations have no body and yield null.
	 *
	 * @param int $index Index of the `function` token.
	 * @return array{name:string,start:int,end:int,params:array<int,string>}|null
	 */
	private function body_range( int $index ): ?array {
		$next = CallArguments::next_significant( $this->tokens, $index + 1 );

		if ( $next < 0 ) {
			return null;
		}

		if ( null === $this->tokens[ $next ][0] && '&' === $this->tokens[ $next ][1] ) {
			$next = CallArguments::next_significant( $this->tokens, $next + 1 );
		}

		if ( $next < 0 ) {
			return null;
		}

		$name = T_STRING === $this->tokens[ $next ][0] ? $this->tokens[ $next ][1] : '{closure}';
		$open = CallArguments::find_body_open( $this->tokens, $next );

		if ( $open < 0 ) {
			return null;
		}

		$close = CallArguments::matching( $this->tokens, $open, '{', '}' );

		return [
			'name'   => $name,
			'start'  => $open,
			'end'    => $close < 0 ? count( $this->tokens ) - 1 : $close,
			'params' => $this->parameter_names( $next ),
		];
	}

	/**
	 * Body range of an arrow function: everything between `=>` and the end
	 * of the expression it belongs to.
	 *
	 * @param int $index Index of the `fn` token.
	 * @return array{name:string,start:int,end:int,params:array<int,string>}|null
	 */
	private function arrow_range( int $index ): ?array {
		$arrow = CallArguments::find_double_arrow( $this->tokens, $index );

		if ( $arrow < 0 ) {
			return null;
		}

		$end = CallArguments::expression_end( $this->tokens, $arrow + 1 );

		if ( $end < $arrow + 1 ) {
			return null;
		}

		return [
			'name'   => self::ARROW_RANGE,
			'start'  => $arrow + 1,
			'end'    => $end,
			'params' => $this->parameter_names( $index ),
		];
	}
}
