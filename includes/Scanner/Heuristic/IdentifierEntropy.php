<?php
/**
 * Flags files whose identifiers look machine-generated rather than written.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * Obfuscators rename everything to noise — `$O0O0O0`, `$lUQwm8IdhV`,
 * `goto dcmeD` — while developers name things after what they hold.
 * Anything is reported either because the identifiers are high-entropy
 * *and* a large share of them have a shape no human picks, or because that
 * share alone is overwhelming. High entropy on its own is not enough — it
 * fires on minified and hashed code.
 */
final class IdentifierEntropy {

	/**
	 * Below this many identifiers the averages are meaningless.
	 */
	private const MIN_IDENTIFIERS = 8;

	/**
	 * Average Shannon entropy, in bits per character, above which the
	 * identifier set stops looking like written English.
	 */
	private const MIN_ENTROPY = 3.6;

	/**
	 * Share of identifiers that must also *look* generated for the entropy
	 * signal to count.
	 */
	private const MIN_OBFUSCATED_RATIO = 0.4;

	/**
	 * Share of generated-looking identifiers that is damning on its own.
	 * `goto`-obfuscated files are full of short, low-entropy filler
	 * (`$O0O0O0`, `$IlIlIl`) whose average entropy never reaches the bar,
	 * but whose *shape* no developer produces by accident.
	 */
	private const STRONG_OBFUSCATED_RATIO = 0.7;

	/**
	 * How many identifiers the reason quotes.
	 */
	private const MAX_LISTED = 4;

	/**
	 * @param TokenStream $ts Token view of the file.
	 * @return array<int,array{reason:string,line:int}>
	 */
	public static function analyze( TokenStream $ts ): array {
		$identifiers = $ts->identifiers();
		$total       = count( $identifiers );

		if ( $total < self::MIN_IDENTIFIERS ) {
			return [];
		}

		$entropy    = 0.0;
		$obfuscated = [];

		foreach ( $identifiers as $identifier ) {
			$entropy += self::shannon( $identifier );

			if ( self::looks_generated( $identifier ) ) {
				$obfuscated[] = $identifier;
			}
		}

		$ratio   = count( $obfuscated ) / $total;
		$average = $entropy / $total;

		$triggered = ( $average > self::MIN_ENTROPY && $ratio >= self::MIN_OBFUSCATED_RATIO )
			|| $ratio >= self::STRONG_OBFUSCATED_RATIO;

		if ( ! $triggered ) {
			return [];
		}

		$listed = array_slice( $obfuscated, 0, self::MAX_LISTED );
		$reason = 'obfuscated identifiers: $' . implode( ', $', $listed );

		if ( count( $obfuscated ) > self::MAX_LISTED ) {
			$reason .= ' …';
		}

		return [
			[
				'reason' => $reason,
				'line'   => self::first_line( $ts, $listed ),
			],
		];
	}

	/**
	 * Shannon entropy of a string in bits per character: 0 for a single
	 * repeated character, log2(n) when every character differs.
	 *
	 * @param string $value String to measure.
	 */
	public static function shannon( string $value ): float {
		$length = strlen( $value );

		if ( 0 === $length ) {
			return 0.0;
		}

		$entropy = 0.0;

		foreach ( count_chars( $value, 1 ) as $occurrences ) {
			$probability = $occurrences / $length;
			$entropy    -= $probability * log( $probability, 2 );
		}

		return $entropy;
	}

	/**
	 * `$O0O0O0`-style filler, long strings without a single vowel, or long
	 * ones where digits are sprinkled through letters.
	 *
	 * @param string $identifier Identifier without its sigil.
	 */
	private static function looks_generated( string $identifier ): bool {
		if ( 1 === preg_match( '/^[O0Il_]{4,}$/i', $identifier ) ) {
			return true;
		}

		if ( strlen( $identifier ) < 10 ) {
			return false;
		}

		if ( 0 === preg_match( '/[aeiou]/i', $identifier ) ) {
			return true;
		}

		$digit_runs = (int) preg_match_all( '/\d+/', $identifier );

		return $digit_runs >= 3 && 1 === preg_match( '/[a-z]/i', $identifier );
	}

	/**
	 * Line of the first quoted identifier, so the finding points somewhere
	 * useful instead of at line 1.
	 *
	 * @param TokenStream       $ts     Token view of the file.
	 * @param array<int,string> $names Identifiers quoted in the reason.
	 */
	private static function first_line( TokenStream $ts, array $names ): int {
		foreach ( $ts->tokens() as $token ) {
			if ( T_VARIABLE !== $token[0] && T_STRING !== $token[0] ) {
				continue;
			}

			if ( in_array( ltrim( $token[1], '$' ), $names, true ) ) {
				return $token[2];
			}
		}

		return 1;
	}
}
