<?php
/**
 * Evaluates a constant decoder expression found in the token stream.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one question for StaticDecoder: "starting at this token, is there
 * an expression PHP could fold to a constant, and what does it fold to?"
 * Only fully constant arguments qualify — a variable anywhere in the chain
 * means the payload is not in this file — and nesting is capped, so a
 * pathological `base64_decode(base64_decode(…))` tower cannot turn one file
 * into an unbounded amount of work.
 */
final class ConstantChain {

	/**
	 * Nested decoder calls evaluated per chain.
	 */
	private const MAX_DEPTH = 3;

	/**
	 * `chr()` terms a concatenation needs before it counts as encoding.
	 */
	private const MIN_CHR_TERMS = 8;

	/**
	 * Normalised tokens of the file under analysis.
	 *
	 * @var array<int,array{0:int|null,1:string,2:int}>
	 */
	private array $tokens;

	/**
	 * Bracket pairs of the whole file: `table[open] = close`.
	 *
	 * @var array<int,int>
	 */
	private array $match;

	/**
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 */
	public function __construct( array $tokens ) {
		$this->tokens = $tokens;
		$this->match  = CallArguments::match_table( $tokens );
	}

	/**
	 * Evaluates the chain starting at a decoder (or `chr`) name token.
	 *
	 * @param int $index Index of the function name token.
	 * @param int $depth Current nesting depth, starting at 1.
	 * @return array{value:string,chain:array<int,string>,end:int}|null
	 */
	public function at( int $index, int $depth = 1 ): ?array {
		if ( T_STRING !== $this->tokens[ $index ][0] ) {
			return null;
		}

		$name = strtolower( $this->tokens[ $index ][1] );

		if ( 'chr' === $name ) {
			return $this->chr_chain( $index );
		}

		return Decoders::knows( $name ) ? $this->decode_call( $index, $name, $depth ) : null;
	}

	/**
	 * @param int    $index Index of the decoder name token.
	 * @param string $name  Lowercased decoder name.
	 * @param int    $depth Current nesting depth.
	 * @return array{value:string,chain:array<int,string>,end:int}|null
	 */
	private function decode_call( int $index, string $name, int $depth ): ?array {
		$open = CallArguments::next_significant( $this->tokens, $index + 1 );

		if ( $depth > self::MAX_DEPTH || $open < 0 || null !== $this->tokens[ $open ][0] || '(' !== $this->tokens[ $open ][1] ) {
			return null;
		}

		$close = isset( $this->match[ $open ] ) ? $this->match[ $open ] : -1;

		if ( $close < 0 ) {
			return null;
		}

		$inner = 'pack' === $name
			? $this->pack_argument( $open + 1, $close - 1, $depth )
			: $this->evaluate( $open + 1, $close - 1, $depth );

		if ( null === $inner ) {
			return null;
		}

		$value = Decoders::apply( $name, $inner['value'] );

		if ( null === $value ) {
			return null;
		}

		return [
			'value' => $value,
			'chain' => array_merge( $inner['chain'], [ $name ] ),
			'end'   => $close,
		];
	}

	/**
	 * `pack('H*', '<hex>')` — the format is checked, the payload evaluated.
	 *
	 * @param int $from  First index of the argument span.
	 * @param int $to    Last index of the argument span.
	 * @param int $depth Current nesting depth.
	 * @return array{value:string,chain:array<int,string>}|null
	 */
	private function pack_argument( int $from, int $to, int $depth ): ?array {
		$format = CallArguments::next_significant( $this->tokens, $from );

		if ( $format < 0 || $format > $to || T_CONSTANT_ENCAPSED_STRING !== $this->tokens[ $format ][0] ) {
			return null;
		}

		if ( 'h*' !== strtolower( trim( $this->tokens[ $format ][1], '"\'' ) ) ) {
			return null;
		}

		$comma = CallArguments::next_significant( $this->tokens, $format + 1 );

		if ( $comma < 0 || $comma > $to || ',' !== $this->tokens[ $comma ][1] ) {
			return null;
		}

		return $this->evaluate( $comma + 1, $to, $depth );
	}

	/**
	 * A constant string (possibly concatenated), a nested decoder call, or a
	 * long `chr()` chain — anything else is not constant enough.
	 *
	 * @param int $from  First index of the span.
	 * @param int $to    Last index of the span.
	 * @param int $depth Current nesting depth.
	 * @return array{value:string,chain:array<int,string>}|null
	 */
	private function evaluate( int $from, int $to, int $depth ): ?array {
		$first = CallArguments::next_significant( $this->tokens, $from );

		if ( $first < 0 || $first > $to ) {
			return null;
		}

		if ( T_STRING !== $this->tokens[ $first ][0] ) {
			return $this->concatenated_string( $first, $to );
		}

		$nested = $this->at( $first, $depth + 1 );

		if ( null === $nested ) {
			return null;
		}

		// Anything left in the span means the argument is more than this
		// call (`base64_decode('x') . $tail`), so it is not constant.
		$after = CallArguments::next_significant( $this->tokens, $nested['end'] + 1 );

		if ( $after >= 0 && $after <= $to ) {
			return null;
		}

		return [
			'value' => $nested['value'],
			'chain' => $nested['chain'],
		];
	}

	/**
	 * `'a' . 'b' . 'c'`.
	 *
	 * @param int $from First index of the span.
	 * @param int $to   Last index of the span.
	 * @return array{value:string,chain:array<int,string>}|null
	 */
	private function concatenated_string( int $from, int $to ): ?array {
		$value  = '';
		$expect = true;

		for ( $i = $from; $i >= 0 && $i <= $to; $i = CallArguments::next_significant( $this->tokens, $i + 1 ) ) {
			if ( $expect ) {
				if ( T_CONSTANT_ENCAPSED_STRING !== $this->tokens[ $i ][0] ) {
					return null;
				}

				$value .= Decoders::literal( $this->tokens[ $i ][1] );
				$expect = false;

				continue;
			}

			if ( null !== $this->tokens[ $i ][0] || '.' !== $this->tokens[ $i ][1] ) {
				return null;
			}

			$expect = true;
		}

		if ( '' === $value || $expect ) {
			return null;
		}

		return [
			'value' => $value,
			'chain' => [],
		];
	}

	/**
	 * `chr(101) . chr(118) . …` — only long chains count; two or three
	 * `chr()` calls are ordinary code.
	 *
	 * @param int $index Index of the first `chr` token.
	 * @return array{value:string,chain:array<int,string>,end:int}|null
	 */
	private function chr_chain( int $index ): ?array {
		$value = '';
		$terms = 0;
		$end   = $index;

		while ( $index >= 0 && T_STRING === $this->tokens[ $index ][0] && 'chr' === strtolower( $this->tokens[ $index ][1] ) ) {
			$open   = CallArguments::next_significant( $this->tokens, $index + 1 );
			$number = $open < 0 ? -1 : CallArguments::next_significant( $this->tokens, $open + 1 );
			$close  = $number < 0 ? -1 : CallArguments::next_significant( $this->tokens, $number + 1 );

			if ( $close < 0 || '(' !== $this->tokens[ $open ][1] || T_LNUMBER !== $this->tokens[ $number ][0] || ')' !== $this->tokens[ $close ][1] ) {
				break;
			}

			$value .= chr( intval( $this->tokens[ $number ][1], 0 ) );
			$end    = $close;
			++$terms;

			$dot = CallArguments::next_significant( $this->tokens, $close + 1 );

			if ( $dot < 0 || null !== $this->tokens[ $dot ][0] || '.' !== $this->tokens[ $dot ][1] ) {
				break;
			}

			$index = CallArguments::next_significant( $this->tokens, $dot + 1 );
		}

		if ( $terms < self::MIN_CHR_TERMS ) {
			return null;
		}

		return [
			'value' => $value,
			'chain' => [ 'chr' ],
			'end'   => $end,
		];
	}
}
