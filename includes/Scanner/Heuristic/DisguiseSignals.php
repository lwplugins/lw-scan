<?php
/**
 * Flags files that pretend to be something they are not.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * Where a file sits says as much as what it contains: a plugin header in
 * `wp-content/uploads` is a dropper, `@package WordPress` outside core is a
 * file dressed up as core, and a file that silences all errors on its first
 * lines before touching request input is hiding its own failures. None of
 * this proves anything by itself — every signal here is a reason for a
 * human to look, which is why the whole layer reports `suspicious`.
 */
final class DisguiseSignals {

	/**
	 * Origins where `@package WordPress` is out of place. Bundled themes
	 * carry it, and so does every child theme and starter theme copied from
	 * one, so `plugin:*`/`theme:*` would flood the queue with noise.
	 *
	 * @var array<int,string>
	 */
	private const PACKAGE_ORIGINS = [ 'uploads', 'mu', 'other' ];

	/**
	 * Lines an encoder header has to appear within.
	 */
	private const HEADER_LINES = 20;

	/**
	 * Lines within which silenced errors count as hiding something.
	 */
	private const SILENCE_LINES = 5;

	/**
	 * @param string $code       Raw file contents.
	 * @param string $origin     Where the file lives: `core|plugin:<slug>|theme:<slug>|mu|uploads|other`.
	 * @param bool   $known_good True when wp.org checksums vouch for this file.
	 * @return array<int,array{reason:string,line:int}>
	 */
	public static function analyze( string $code, string $origin, bool $known_good ): array {
		$findings = [];

		$header = self::plugin_header( $code, $origin, $known_good );

		if ( null !== $header ) {
			$findings[] = $header;
		}

		$package = self::locate( $code, '/@package\s+WordPress\b/' );

		if ( null !== $package && in_array( $origin, self::PACKAGE_ORIGINS, true ) ) {
			$findings[] = [
				'reason' => '@package WordPress outside core',
				'line'   => $package['line'],
			];
		}

		$encoder = self::locate( $code, '/Advanced Web Application Framework|Original size:|Encoded size:/i', self::HEADER_LINES );

		if ( null !== $encoder ) {
			$findings[] = [
				'reason' => 'encoder header: ' . $encoder['text'],
				'line'   => $encoder['line'],
			];
		}

		$silenced = self::silenced_errors( $code );

		if ( null !== $silenced ) {
			$findings[] = $silenced;
		}

		return $findings;
	}

	/**
	 * A plugin header only belongs where plugins live. In `mu-plugins` the
	 * loader stub that pulls in a real plugin from a subdirectory is the one
	 * legitimate exception, and checksum-verified files are never flagged.
	 *
	 * @param string $code       Raw file contents.
	 * @param string $origin     Origin of the file.
	 * @param bool   $known_good True when wp.org checksums vouch for this file.
	 * @return array{reason:string,line:int}|null
	 */
	private static function plugin_header( string $code, string $origin, bool $known_good ): ?array {
		$hit = self::locate( $code, '/Plugin Name\s*:/i' );

		if ( null === $hit ) {
			return null;
		}

		if ( 'uploads' === $origin ) {
			return [
				'reason' => 'plugin header in an uploads file',
				'line'   => $hit['line'],
			];
		}

		if ( 'mu' !== $origin || $known_good || self::is_mu_loader( $code ) ) {
			return null;
		}

		return [
			'reason' => 'plugin header in a must-use plugin',
			'line'   => $hit['line'],
		];
	}

	/**
	 * True when the file includes something from a subdirectory, which is
	 * what an `mu-plugins/loader.php` stub does.
	 *
	 * @param string $code Raw file contents.
	 */
	private static function is_mu_loader( string $code ): bool {
		if ( 0 === preg_match_all( '/(?:require|include)(?:_once)?[^;]{0,400};/i', $code, $statements ) ) {
			return false;
		}

		foreach ( $statements[0] as $statement ) {
			if ( 1 !== preg_match( '/[\'"]([^\'"]+)[\'"]/', $statement, $path ) ) {
				continue;
			}

			if ( false !== strpos( ltrim( $path[1], '/' ), '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `error_reporting(0)` plus a silenced `display_errors` at the very top
	 * of a file that also reads request input.
	 *
	 * @param string $code Raw file contents.
	 * @return array{reason:string,line:int}|null
	 */
	private static function silenced_errors( string $code ): ?array {
		$hit = self::locate( $code, '/error_reporting\s*\(\s*0\s*\)/i', self::SILENCE_LINES );

		if ( null === $hit ) {
			return null;
		}

		$head = self::head( $code, self::SILENCE_LINES );

		if ( 1 !== preg_match( '/@?ini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"]?(?:0|off|false)[\'"]?\s*\)/i', $head ) ) {
			return null;
		}

		if ( 1 !== preg_match( '/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\b|php:\/\/input/i', $code ) ) {
			return null;
		}

		return [
			'reason' => 'errors silenced at the top of a file that reads request input',
			'line'   => $hit['line'],
		];
	}

	/**
	 * First match of a pattern with its 1-based line, optionally restricted
	 * to the first `$max_line` lines of the file.
	 *
	 * @param string $code     Raw file contents.
	 * @param string $pattern  Regex to run against the file.
	 * @param int    $max_line Last line the match may appear on, or 0 for anywhere.
	 * @return array{line:int,text:string}|null
	 */
	private static function locate( string $code, string $pattern, int $max_line = 0 ): ?array {
		if ( 1 !== preg_match( $pattern, $code, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$line = substr_count( $code, "\n", 0, (int) $match[0][1] ) + 1;

		if ( $max_line > 0 && $line > $max_line ) {
			return null;
		}

		return [
			'line' => $line,
			'text' => (string) $match[0][0],
		];
	}

	/**
	 * @param string $code  Raw file contents.
	 * @param int    $lines Number of leading lines to return.
	 */
	private static function head( string $code, int $lines ): string {
		return implode( "\n", array_slice( explode( "\n", $code ), 0, $lines ) );
	}
}
