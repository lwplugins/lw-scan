<?php
/**
 * Evaluates constant decoder chains and rescans what falls out of them.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * `eval(gzinflate(base64_decode('…')))` hides the payload from every
 * literal and regex signature, but the arguments are constants, so the
 * scanner can do exactly what PHP would do — minus the `eval`. Folding the
 * expression is ConstantChain's job; this class decides where to try it and
 * what the result means: a signature hit on the decoded text names that
 * signature, and PHP-looking output large enough to be a payload is a
 * finding on its own even when no signature knows it yet.
 */
final class StaticDecoder {

	/**
	 * Decoded bytes above which PHP-looking output is a finding on its own.
	 */
	private const MIN_PAYLOAD = 200;

	/**
	 * Rescans decoded text with the signature layers.
	 *
	 * @var callable(string):array<int,\LightweightPlugins\Scan\Scanner\MatchResult>
	 */
	private $rescan;

	/**
	 * @param callable $rescan Runs the signature layers over decoded text.
	 * @phpstan-param callable(string):array<int,\LightweightPlugins\Scan\Scanner\MatchResult> $rescan
	 */
	public function __construct( callable $rescan ) {
		$this->rescan = $rescan;
	}

	/**
	 * @param TokenStream $ts   Token view of the file.
	 * @param string      $code Raw file contents, used only to skip files that mention no decoder at all.
	 * @return array<int,array{reason:string,line:int,sig_ids:array<int,string>,category:string}>
	 */
	public function analyze( TokenStream $ts, string $code ): array {
		$tokens = $ts->tokens();

		if ( [] === $tokens || ! self::mentions_decoder( $code ) ) {
			return [];
		}

		$chains   = new ConstantChain( $tokens );
		$findings = [];
		$count    = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( T_STRING !== $tokens[ $i ][0] ) {
				continue;
			}

			$chain = $chains->at( $i );

			if ( null === $chain ) {
				continue;
			}

			$this->report( $chain, $tokens[ $i ][2], $findings );

			$i = $chain['end'];
		}

		return $findings;
	}

	/**
	 * @param array{value:string,chain:array<int,string>,end:int}                                $chain    Evaluated chain.
	 * @param int                                                                                $line     Line of the outermost call.
	 * @param array<int,array{reason:string,line:int,sig_ids:array<int,string>,category:string}> $findings Findings so far.
	 */
	private function report( array $chain, int $line, array &$findings ): void {
		$decoded = $chain['value'];
		$names   = implode( '→', $chain['chain'] );

		foreach ( call_user_func( $this->rescan, $decoded ) as $match ) {
			$findings[] = [
				'reason'   => $match->sig_id . ' matched after ' . $names . ' decoding',
				'line'     => $line,
				'sig_ids'  => [ $match->sig_id ],
				'category' => $match->category,
			];
		}

		$is_php = false !== strpos( $decoded, '<?php' ) || false !== strpos( $decoded, 'eval(' );

		if ( ! $is_php || strlen( $decoded ) < self::MIN_PAYLOAD ) {
			return;
		}

		$findings[] = [
			'reason'   => 'decoded PHP payload via ' . $names,
			'line'     => $line,
			'sig_ids'  => [ 'heur:decoder' ],
			'category' => 'obfuscation',
		];
	}

	/**
	 * Cheap pre-check: most files mention no decoder at all, and walking
	 * their tokens would be wasted work. It matches the bare name, never
	 * `name(` — `gzinflate (base64_decode ('…'))` is valid PHP and must not
	 * slip past the prefilter. The token walker is the real check.
	 *
	 * @param string $code Raw file contents.
	 */
	private static function mentions_decoder( string $code ): bool {
		foreach ( array_merge( Decoders::NAMES, [ 'chr' ] ) as $name ) {
			if ( false !== stripos( $code, $name ) ) {
				return true;
			}
		}

		return false;
	}
}
