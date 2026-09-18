<?php
/**
 * Flags request input that reaches a dangerous call inside the same file.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * The webshells that signatures miss are the ones written like ordinary
 * code: no `eval`, no base64, just `$_GET['path']` handed to `unlink()`.
 * This walks every function body — and the file scope, which is what links
 * a file-scope `$p = $_GET['path']` to a sink inside a function — and
 * reports one finding per sink call site, naming the input that reaches it.
 *
 * What counts as input lives in TaintMap and span navigation in
 * CallArguments; this class only decides what a dangerous call is and how
 * the finding reads. Because the taint model is shallow by design, every
 * finding is `suspicious`, never `infected`.
 */
final class SourceSinkAnalyzer {

	/**
	 * Calls that execute, write or delete something.
	 *
	 * @var array<int,string>
	 */
	private const SINKS = [
		'eval',
		'assert',
		'system',
		'exec',
		'passthru',
		'shell_exec',
		'proc_open',
		'popen',
		'pcntl_exec',
		'create_function',
		'call_user_func',
		'call_user_func_array',
		'preg_replace',
		'file_put_contents',
		'fwrite',
		'fputs',
		'move_uploaded_file',
		'copy',
		'rename',
		'unlink',
		'chmod',
		'mysqli_query',
		'mysql_query',
	];

	/**
	 * Sinks that are only dangerous through their first argument: the
	 * callable. `call_user_func($cb, $_POST)` is how half the plugin
	 * directory forwards a request to a hook, and is not a finding.
	 *
	 * @var array<int,string>
	 */
	private const CALLABLE_SINKS = [ 'call_user_func', 'call_user_func_array' ];

	/**
	 * Tokens that mean the following name is not a plain function call.
	 *
	 * @var array<int,int>
	 */
	private const NOT_A_CALL = [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ];

	/**
	 * Include constructs, which take no parentheses.
	 *
	 * @var array<int,int>
	 */
	private const INCLUDES = [ T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ];

	/**
	 * Normalised tokens of the file under analysis.
	 *
	 * @var array<int,array{0:int|null,1:string,2:int}>
	 */
	private array $tokens;

	/**
	 * Input tracker, rebuilt for each range that is scanned.
	 *
	 * @var TaintMap
	 */
	private TaintMap $taint;

	/**
	 * Bracket pairs of the whole file: `table[open] = close`.
	 *
	 * @var array<int,int>
	 */
	private array $match;

	/**
	 * Findings keyed by `<sink>|<line>`, so one call site is reported once.
	 *
	 * @var array<string,array{reason:string,line:int}>
	 */
	private array $findings = [];

	/**
	 * @param array<int,array{0:int|null,1:string,2:int}> $tokens Normalised tokens.
	 */
	private function __construct( array $tokens ) {
		$this->tokens = $tokens;
		$this->match  = CallArguments::match_table( $tokens );
		$this->taint  = new TaintMap( $tokens );
	}

	/**
	 * @param TokenStream $ts Token view of the file.
	 * @return array<int,array{reason:string,line:int}>
	 */
	public static function analyze( TokenStream $ts ): array {
		$tokens = $ts->tokens();

		if ( [] === $tokens ) {
			return [];
		}

		$analyzer = new self( $tokens );
		$scopes   = self::scopes( $ts );
		$seeds    = new ParameterSeeds( $tokens, $analyzer->match, $scopes );

		// Pre-pass: calls may precede their declaration, so every scope is
		// read for "this call hands input to that parameter" before any of
		// them is scanned for sinks.
		foreach ( $scopes as $scope ) {
			$analyzer->taint->build( $scope['spans'] );
			$seeds->collect( $scope['spans'], $analyzer->taint );
		}

		foreach ( $scopes as $position => $scope ) {
			$analyzer->scan_scope( $scope['spans'], $seeds->of( $position ) );
		}

		$findings = array_values( $analyzer->findings );

		usort(
			$findings,
			static function ( array $a, array $b ): int {
				return $a['line'] <=> $b['line'];
			}
		);

		return $findings;
	}

	/**
	 * The scopes of a file, function bodies first so a call site inside one
	 * keeps the more local attribution, top-level code last. Arrow
	 * functions are not scopes: they capture by value, so their bodies stay
	 * part of whatever encloses them.
	 *
	 * @param TokenStream $ts Token view of the file.
	 * @return array<int,array{name:string,params:array<int,string>,spans:array<int,array{0:int,1:int}>}>
	 */
	private static function scopes( TokenStream $ts ): array {
		$scopes = [];

		foreach ( $ts->functions() as $range ) {
			if ( TokenStream::GLOBAL_RANGE === $range['name'] || TokenStream::ARROW_RANGE === $range['name'] ) {
				continue;
			}

			$scopes[] = [
				'name'   => $range['name'],
				'params' => $range['params'],
				'spans'  => [ [ $range['start'], $range['end'] ] ],
			];
		}

		$scopes[] = [
			'name'   => TokenStream::GLOBAL_RANGE,
			'params' => [],
			'spans'  => $ts->top_level_spans(),
		];

		return $scopes;
	}

	/**
	 * @param array<int,array{0:int,1:int}> $spans Token index spans making up one scope.
	 * @param array<string,string>          $seeds Parameters tainted by a caller.
	 */
	private function scan_scope( array $spans, array $seeds ): void {
		$this->taint->build( $spans, $seeds );

		foreach ( $spans as $span ) {
			$this->scan_span( $span[0], $span[1] );
		}
	}

	private function scan_span( int $start, int $end ): void {
		for ( $i = $start; $i <= $end; $i++ ) {
			$token = $this->tokens[ $i ];

			if ( in_array( $token[0], self::INCLUDES, true ) ) {
				$stop = CallArguments::statement_end( $this->tokens, $i + 1, $end );

				$this->add( $this->taint->first_source( $i + 1, $stop ), strtolower( $token[1] ), $token[2] );

				continue;
			}

			if ( T_VARIABLE === $token[0] ) {
				$this->dynamic_call( $i, $end );

				continue;
			}

			if ( null === $token[0] && '$' === $token[1] ) {
				$this->variable_variable( $i, $end );

				continue;
			}

			if ( T_STRING === $token[0] || T_EVAL === $token[0] ) {
				$this->named_sink( $i, $end );
			}
		}
	}

	/**
	 * @param int $i   Index of the sink name token.
	 * @param int $end Last index of the range.
	 */
	private function named_sink( int $i, int $end ): void {
		$name = strtolower( $this->tokens[ $i ][1] );

		if ( ! in_array( $name, self::SINKS, true ) ) {
			return;
		}

		$line = $this->tokens[ $i ][2];

		// This call site is already reported; nested sinks would otherwise
		// re-read their (overlapping) argument spans for nothing.
		if ( isset( $this->findings[ $name . '|' . $line ] ) ) {
			return;
		}

		$prev = CallArguments::prev_significant( $this->tokens, $i - 1 );

		if ( $prev >= 0 && in_array( $this->tokens[ $prev ][0], self::NOT_A_CALL, true ) ) {
			return;
		}

		$open = CallArguments::next_significant( $this->tokens, $i + 1 );

		if ( $open < 0 || null !== $this->tokens[ $open ][0] || '(' !== $this->tokens[ $open ][1] ) {
			return;
		}

		$close = isset( $this->match[ $open ] ) ? $this->match[ $open ] - 1 : $end;

		// `preg_replace` is a sink through the `/e` modifier only — the
		// plain call is ordinary string work in every plugin ever written.
		if ( 'preg_replace' === $name ) {
			if ( $this->has_e_modifier( $open, $close ) ) {
				$this->findings[ $name . '|' . $line ] = [
					'reason' => 'preg_replace() /e modifier (line ' . $line . ')',
					'line'   => $line,
				];
			}

			return;
		}

		$last = in_array( $name, self::CALLABLE_SINKS, true )
			? CallArguments::first_argument_end( $this->tokens, $open, $close, $this->match )
			: $close;

		$this->add( $this->taint->first_source( $open + 1, $last ), $name, $line );
	}

	/**
	 * `$cmd( … )` where `$cmd` came from request input.
	 *
	 * @param int $i   Index of the variable token.
	 * @param int $end Last index of the range.
	 */
	private function dynamic_call( int $i, int $end ): void {
		$open = CallArguments::next_significant( $this->tokens, $i + 1 );

		if ( $open < 0 || $open > $end || null !== $this->tokens[ $open ][0] || '(' !== $this->tokens[ $open ][1] ) {
			return;
		}

		$name = ltrim( $this->tokens[ $i ][1], '$' );

		$this->add( $this->taint->of( $name ), '$' . $name, $this->tokens[ $i ][2] );
	}

	/**
	 * `${ … }` built from request input — the classic variable-variable call.
	 *
	 * @param int $i   Index of the `$` token.
	 * @param int $end Last index of the range.
	 */
	private function variable_variable( int $i, int $end ): void {
		$open = CallArguments::next_significant( $this->tokens, $i + 1 );

		if ( $open < 0 || $open > $end || null !== $this->tokens[ $open ][0] || '{' !== $this->tokens[ $open ][1] ) {
			return;
		}

		$close = isset( $this->match[ $open ] ) ? $this->match[ $open ] - 1 : $end;

		$this->add( $this->taint->first_source( $open + 1, $close ), '${...}', $this->tokens[ $i ][2], '' );
	}

	/**
	 * @param string|null $source Source description, or null when nothing reaches the sink.
	 * @param string      $sink   Sink name as it appears in the reason.
	 * @param int         $line   Line of the sink call.
	 * @param string      $call   Suffix after the sink name; empty for constructs that are not calls.
	 */
	private function add( ?string $source, string $sink, int $line, string $call = '()' ): void {
		$key = $sink . '|' . $line;

		if ( null === $source || isset( $this->findings[ $key ] ) ) {
			return;
		}

		$this->findings[ $key ] = [
			'reason' => $source . ' → ' . $sink . $call . ' (line ' . $line . ')',
			'line'   => $line,
		];
	}

	/**
	 * True when the first argument is a literal pattern carrying the
	 * (long removed, still exploited on old installs) `/e` modifier.
	 *
	 * @param int $open  Index of the call's `(`.
	 * @param int $close Last index inside the call.
	 */
	private function has_e_modifier( int $open, int $close ): bool {
		$first = CallArguments::next_significant( $this->tokens, $open + 1 );

		if ( $first < 0 || $first > $close || T_CONSTANT_ENCAPSED_STRING !== $this->tokens[ $first ][0] ) {
			return false;
		}

		$pattern = trim( $this->tokens[ $first ][1], '"\'' );

		if ( strlen( $pattern ) < 3 ) {
			return false;
		}

		$pairs     = [
			'(' => ')',
			'[' => ']',
			'{' => '}',
			'<' => '>',
		];
		$delimiter = $pattern[0];
		$closer    = isset( $pairs[ $delimiter ] ) ? $pairs[ $delimiter ] : $delimiter;
		$last      = strrpos( $pattern, $closer );

		if ( false === $last || 0 === $last ) {
			return false;
		}

		return false !== strpos( substr( $pattern, $last + 1 ), 'e' );
	}
}
