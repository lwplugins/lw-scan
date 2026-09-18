<?php
/**
 * Scores a file's path/origin/kind against a fixed table of suspicion rules.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Pure, WordPress-free rule table (spec §6.2). Rules stack, the total score
 * is capped at 100. `$core_paths` is the compiled core (or plugin/theme)
 * checksum map (path => acceptable md5s); when it's `null` the
 * two rules that depend on it are skipped entirely rather than scored as
 * "unknown". `$known_good` is the caller's `KnownGood` checksum verdict for
 * this file (true only on a verified checksum match); `mu_plugin_unknown`
 * fires only for `origin === 'mu'` files that are NOT known-good.
 */
final class PathSignals {

	private const SCORE_CAP = 100;

	/**
	 * File extensions PHP content is allowed to carry without being flagged
	 * as disguised.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_PHP_EXTENSIONS = [ 'php', 'phtml', 'inc', 'phar' ];

	/**
	 * Media extensions core ships under `wp-admin/images/` and `wp-includes/images/`.
	 *
	 * @var array<int,string>
	 */
	private const MEDIA_EXTENSIONS = [ 'gif', 'png', 'jpg', 'jpeg', 'svg', 'ico', 'webp', 'bmp' ];

	/**
	 * Score contribution per reason key (spec §6.2 table).
	 *
	 * @var array<string,int>
	 */
	private const WEIGHTS = [
		'php_in_uploads'    => 60,
		'unknown_core_path' => 60,
		'nested_same_dir'   => 50,
		'random_root_dir'   => 40,
		'mu_plugin_unknown' => 30,
		'php_disguised'     => 40,
		'binary_in_content' => 50,
		'fake_core_asset'   => 50,
		'stray_htaccess'    => 20,
	];

	/**
	 * @param string                               $rel        Path relative to ABSPATH, no leading slash.
	 * @param string                               $origin     Result of Origin::of().
	 * @param string                               $kind       Result of ContentType::detect().
	 * @param array<string,array<int,string>>|null $core_paths Core (or package) checksum map, path => acceptable md5s, or null when unavailable.
	 * @param bool                                 $known_good Caller's KnownGood verdict for this file (checksum-verified match).
	 * @return array{score:int, reasons:array<int,string>}
	 */
	public static function score( string $rel, string $origin, string $kind, ?array $core_paths, bool $known_good = false ): array {
		$reasons = [];
		$score   = 0;

		foreach ( self::evaluate_rules( $rel, $origin, $kind, $core_paths, $known_good ) as $reason => $triggered ) {
			if ( $triggered ) {
				$score    += self::WEIGHTS[ $reason ];
				$reasons[] = $reason;
			}
		}

		return [
			'score'   => min( self::SCORE_CAP, $score ),
			'reasons' => $reasons,
		];
	}

	/**
	 * @param string                               $rel        Path relative to ABSPATH.
	 * @param string                               $origin     Result of Origin::of().
	 * @param string                               $kind       Result of ContentType::detect().
	 * @param array<string,array<int,string>>|null $core_paths Core checksum map, or null.
	 * @param bool                                 $known_good Caller's KnownGood verdict for this file.
	 * @return array<string,bool> Reason key => whether that rule fired.
	 */
	private static function evaluate_rules( string $rel, string $origin, string $kind, ?array $core_paths, bool $known_good ): array {
		return [
			'php_in_uploads'    => self::rule_php_in_uploads( $origin, $kind ),
			'unknown_core_path' => self::rule_unknown_core_path( $rel, $origin, $core_paths ),
			'nested_same_dir'   => self::has_nested_same_dir( $rel ),
			'random_root_dir'   => self::has_random_root_dir( $rel ),
			'mu_plugin_unknown' => self::rule_mu_plugin_unknown( $origin, $known_good ),
			'php_disguised'     => self::rule_php_disguised( $rel, $kind ),
			'binary_in_content' => self::rule_binary_in_content( $rel, $kind ),
			'fake_core_asset'   => null !== $core_paths && self::is_fake_core_asset( $rel, $core_paths ),
			'stray_htaccess'    => self::is_stray_htaccess( $rel ),
		];
	}

	/**
	 * Shannon entropy of a string, in bits per character.
	 *
	 * @param string $s Text to measure.
	 * @return float
	 */
	public static function entropy( string $s ): float {
		$len = strlen( $s );

		if ( 0 === $len ) {
			return 0.0;
		}

		$frequencies = [];

		for ( $i = 0; $i < $len; $i++ ) {
			$char                 = $s[ $i ];
			$frequencies[ $char ] = ( $frequencies[ $char ] ?? 0 ) + 1;
		}

		$entropy = 0.0;

		foreach ( $frequencies as $count ) {
			$probability = $count / $len;
			$entropy    -= $probability * log( $probability, 2 );
		}

		return $entropy;
	}

	/**
	 * @param string $origin Result of Origin::of().
	 * @param string $kind   Result of ContentType::detect().
	 * @return bool
	 */
	private static function rule_php_in_uploads( string $origin, string $kind ): bool {
		return 'php' === $kind && 'uploads' === $origin;
	}

	/**
	 * @param string                               $rel        Path relative to ABSPATH.
	 * @param string                               $origin     Result of Origin::of().
	 * @param array<string,array<int,string>>|null $core_paths Core checksum map, or null.
	 * @return bool
	 */
	private static function rule_unknown_core_path( string $rel, string $origin, ?array $core_paths ): bool {
		return null !== $core_paths
			&& 'core' === $origin
			&& self::is_under_checksummed_core_dir( $rel )
			&& ! isset( $core_paths[ $rel ] );
	}

	/**
	 * @param string $origin     Result of Origin::of().
	 * @param bool   $known_good Caller's KnownGood verdict for this file.
	 * @return bool
	 */
	private static function rule_mu_plugin_unknown( string $origin, bool $known_good ): bool {
		return 'mu' === $origin && ! $known_good;
	}

	/**
	 * @param string $rel  Path relative to ABSPATH.
	 * @param string $kind Result of ContentType::detect().
	 * @return bool
	 */
	private static function rule_php_disguised( string $rel, string $kind ): bool {
		return 'php' === $kind && ! in_array( ContentType::ext( $rel ), self::ALLOWED_PHP_EXTENSIONS, true );
	}

	/**
	 * @param string $rel  Path relative to ABSPATH.
	 * @param string $kind Result of ContentType::detect().
	 * @return bool
	 */
	private static function rule_binary_in_content( string $rel, string $kind ): bool {
		// Archives count here too: they are opaque binary blobs to the
		// scanner, and before they were recognised as such they reached this
		// rule as `binary` whenever their head held no PHP source.
		return in_array( $kind, [ ContentType::BINARY, ContentType::ARCHIVE ], true )
			&& 0 === strpos( $rel, 'wp-content/' );
	}

	/**
	 * @param string $rel Path relative to ABSPATH.
	 * @return bool
	 */
	private static function is_under_checksummed_core_dir( string $rel ): bool {
		return 0 === strpos( $rel, 'wp-admin/' ) || 0 === strpos( $rel, 'wp-includes/' );
	}

	/**
	 * @param string $rel Path relative to ABSPATH.
	 * @return bool
	 */
	private static function has_nested_same_dir( string $rel ): bool {
		$segments = explode( '/', $rel );
		array_pop( $segments ); // Drop the file name; only directories count.

		$run = 1;

		for ( $i = 1, $count = count( $segments ); $i < $count; $i++ ) {
			if ( '' !== $segments[ $i ] && $segments[ $i ] === $segments[ $i - 1 ] ) {
				++$run;

				if ( $run >= 3 ) {
					return true;
				}
			} else {
				$run = 1;
			}
		}

		return false;
	}

	/**
	 * Root-level directory looks randomly generated. Two independent
	 * signals, gated by length:
	 * - length >= 8: no vowel at all, or high (>3.5 bit/char) entropy.
	 * - 5 <= length < 8: at least 2 case transitions between adjacent
	 *   letters (e.g. `TpAEMU` has T->p and p->A). This branch is
	 *   deliberately restricted to short names: at length >= 8 a normal
	 *   multi-word identifier (e.g. `MyPlugin`, itself 3 transitions) can
	 *   easily clear a "2 transitions" bar, which is why that length range
	 *   is only ever judged by the vowel/entropy signal above.
	 *
	 * @param string $rel Path relative to ABSPATH.
	 * @return bool
	 */
	private static function has_random_root_dir( string $rel ): bool {
		$segments = explode( '/', $rel );

		if ( count( $segments ) < 2 ) {
			return false;
		}

		$root = $segments[0];

		if ( in_array( $root, [ 'wp-admin', 'wp-includes', 'wp-content' ], true ) ) {
			return false;
		}

		$len = strlen( $root );

		if ( $len >= 8 ) {
			$has_vowel = 1 === preg_match( '~[aeiou]~i', $root );

			return ! $has_vowel || self::entropy( $root ) > 3.5;
		}

		if ( $len >= 5 ) {
			return self::count_case_transitions( $root ) >= 2;
		}

		return false;
	}

	/**
	 * Number of case switches (upper->lower or lower->upper) between
	 * adjacent letters; non-letters are skipped and don't themselves
	 * count as, or break, a transition across them.
	 *
	 * @param string $s Text to measure.
	 * @return int
	 */
	private static function count_case_transitions( string $s ): int {
		$count          = 0;
		$prev_is_letter = false;
		$prev_is_upper  = false;

		for ( $i = 0, $len = strlen( $s ); $i < $len; $i++ ) {
			$char = $s[ $i ];

			if ( 1 !== preg_match( '~[a-z]~i', $char ) ) {
				$prev_is_letter = false;
				continue;
			}

			$is_upper = 1 === preg_match( '~[A-Z]~', $char );

			if ( $prev_is_letter && $is_upper !== $prev_is_upper ) {
				++$count;
			}

			$prev_is_letter = true;
			$prev_is_upper  = $is_upper;
		}

		return $count;
	}

	/**
	 * @param string                          $rel        Path relative to ABSPATH.
	 * @param array<string,array<int,string>> $core_paths Core checksum map, path => acceptable md5s.
	 * @return bool
	 */
	private static function is_fake_core_asset( string $rel, array $core_paths ): bool {
		if ( 1 !== preg_match( '~^(?:wp-admin|wp-includes)/(?:.*/)?images/[^/]+$~', $rel ) ) {
			return false;
		}

		if ( ! in_array( ContentType::ext( $rel ), self::MEDIA_EXTENSIONS, true ) ) {
			return false;
		}

		return ! isset( $core_paths[ $rel ] );
	}

	/**
	 * @param string $rel Path relative to ABSPATH.
	 * @return bool
	 */
	private static function is_stray_htaccess( string $rel ): bool {
		if ( '.htaccess' !== basename( $rel ) ) {
			return false;
		}

		if ( '.htaccess' === $rel ) {
			return false; // Root.
		}

		if ( 0 === strpos( $rel, 'wp-admin/' ) || 0 === strpos( $rel, 'wp-content/uploads/' ) ) {
			return false;
		}

		return true;
	}
}
