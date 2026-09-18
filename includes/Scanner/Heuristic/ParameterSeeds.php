<?php
/**
 * Carries request input one hop, from a call's arguments into the callee's parameters.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * Taint is tracked per scope, which is accurate but blind to the shape
 * every file-manager shell has: the input is read at the top of the file
 * and handed to a function that does the deleting. This closes exactly
 * that gap and no more — one hop, same file, positional arguments.
 *
 * The hop is computed from taint maps built *without* seeds, so a seeded
 * parameter can never seed another call: whatever this produces is at most
 * one call away from real input, which is the limit of what a
 * "suspicious"-only layer should claim.
 */
final class ParameterSeeds {

	/**
	 * Tokens that mean the following name is not a plain function call.
	 *
	 * @var array<int,int>
	 */
	private const NOT_A_CALL = [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ];

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
	 * Lowercased function name => scope position.
	 *
	 * @var array<string,int>
	 */
	private array $callable = [];

	/**
	 * Scope position => ordered parameter names.
	 *
	 * @var array<int,array<int,string>>
	 */
	private array $params = [];

	/**
	 * Scope position => parameter name => source text.
	 *
	 * @var array<int,array<string,string>>
	 */
	private array $seeds = [];

	/**
	 * @param array<int,array{0:int|null,1:string,2:int}>                                                $tokens Normalised tokens.
	 * @param array<int,int>                                                                             $match  Bracket match table.
	 * @param array<int,array{name:string,params:array<int,string>,spans:array<int,array{0:int,1:int}>}> $scopes Scopes in scan order.
	 */
	public function __construct( array $tokens, array $match, array $scopes ) {
		$this->tokens = $tokens;
		$this->match  = $match;

		foreach ( $scopes as $position => $scope ) {
			$name = strtolower( $scope['name'] );

			// Only named declarations can be called by name, and only ones
			// with parameters can receive anything.
			if ( [] === $scope['params'] || '' === $name || '{' === $name[0] || '(' === $name[0] ) {
				continue;
			}

			$this->callable[ $name ]   = $position;
			$this->params[ $position ] = $scope['params'];
		}
	}

	/**
	 * Records the seeds produced by the calls inside one scope.
	 *
	 * @param array<int,array{0:int,1:int}> $spans Spans of the calling scope.
	 * @param TaintMap                      $taint The calling scope's map, built without seeds.
	 */
	public function collect( array $spans, TaintMap $taint ): void {
		if ( [] === $this->callable ) {
			return;
		}

		foreach ( $spans as $span ) {
			for ( $i = $span[0]; $i <= $span[1]; $i++ ) {
				if ( T_STRING !== $this->tokens[ $i ][0] ) {
					continue;
				}

				$name = strtolower( $this->tokens[ $i ][1] );

				if ( ! isset( $this->callable[ $name ] ) ) {
					continue;
				}

				$open = $this->call_paren( $i );

				if ( $open < 0 ) {
					continue;
				}

				$this->seed_call( $this->callable[ $name ], $open, $this->match[ $open ] - 1, $taint );

				$i = $this->match[ $open ];
			}
		}
	}

	/**
	 * Parameter taint for one scope: parameter name => source text.
	 *
	 * @param int $position Scope position.
	 * @return array<string,string>
	 */
	public function of( int $position ): array {
		return isset( $this->seeds[ $position ] ) ? $this->seeds[ $position ] : [];
	}

	/**
	 * Index of the `(` of a real call at `$index`, or -1 when this name is
	 * a declaration, a method, a constant or not followed by a call at all.
	 *
	 * @param int $index Index of the name token.
	 */
	private function call_paren( int $index ): int {
		$prev = CallArguments::prev_significant( $this->tokens, $index - 1 );

		if ( $prev >= 0 && in_array( $this->tokens[ $prev ][0], self::NOT_A_CALL, true ) ) {
			return -1;
		}

		$open = CallArguments::next_significant( $this->tokens, $index + 1 );

		if ( $open < 0 || null !== $this->tokens[ $open ][0] || '(' !== $this->tokens[ $open ][1] ) {
			return -1;
		}

		return isset( $this->match[ $open ] ) ? $open : -1;
	}

	/**
	 * @param int      $position Scope position of the callee.
	 * @param int      $open     Index of the call's `(`.
	 * @param int      $close    Last index inside the call.
	 * @param TaintMap $taint    The calling scope's map.
	 */
	private function seed_call( int $position, int $open, int $close, TaintMap $taint ): void {
		$argument = 0;
		$from     = $open + 1;

		for ( $i = $from; $i <= $close; $i++ ) {
			if ( isset( $this->match[ $i ] ) ) {
				$i = $this->match[ $i ];

				continue;
			}

			if ( null !== $this->tokens[ $i ][0] || ',' !== $this->tokens[ $i ][1] ) {
				continue;
			}

			$this->seed_argument( $position, $argument, $from, $i - 1, $taint );

			++$argument;
			$from = $i + 1;
		}

		$this->seed_argument( $position, $argument, $from, $close, $taint );
	}

	/**
	 * @param int      $position Scope position of the callee.
	 * @param int      $argument Zero-based argument position.
	 * @param int      $from     First index of the argument.
	 * @param int      $to       Last index of the argument.
	 * @param TaintMap $taint    The calling scope's map.
	 */
	private function seed_argument( int $position, int $argument, int $from, int $to, TaintMap $taint ): void {
		if ( $from > $to || ! isset( $this->params[ $position ][ $argument ] ) ) {
			return;
		}

		$parameter = $this->params[ $position ][ $argument ];

		// The first call that passes input wins, so the reason text stays
		// the one a reader will find first.
		if ( isset( $this->seeds[ $position ][ $parameter ] ) || $this->is_named_argument( $from, $to ) ) {
			return;
		}

		$source = $taint->first_source( $from, $to );

		if ( null === $source ) {
			return;
		}

		$this->seeds[ $position ][ $parameter ] = $source;
	}

	/**
	 * `go( path: $_GET['p'] )` — positional mapping would attribute it to
	 * the wrong parameter, so it is skipped.
	 *
	 * @param int $from First index of the argument.
	 * @param int $to   Last index of the argument.
	 */
	private function is_named_argument( int $from, int $to ): bool {
		$name = CallArguments::next_significant( $this->tokens, $from );

		if ( $name < 0 || $name > $to || T_STRING !== $this->tokens[ $name ][0] ) {
			return false;
		}

		$colon = CallArguments::next_significant( $this->tokens, $name + 1 );

		return $colon >= 0 && $colon <= $to && null === $this->tokens[ $colon ][0] && ':' === $this->tokens[ $colon ][1];
	}
}
