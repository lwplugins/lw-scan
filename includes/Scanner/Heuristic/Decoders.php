<?php
/**
 * Applies a single decoder function to an already-constant value.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * The scanner runs these against strings taken out of hostile files, so
 * malformed input is the normal case, not an exception: every call is
 * error-suppressed and guarded, and anything that does not come back as a
 * non-empty string is simply "not decodable". Output is capped so a zip
 * bomb in a `gzinflate()` argument cannot blow the memory budget the file
 * scanner allocated for this file.
 */
final class Decoders {

	/**
	 * Single-argument decoders that can be applied to a constant.
	 *
	 * @var array<int,string>
	 */
	public const NAMES = [
		'base64_decode',
		'str_rot13',
		'gzinflate',
		'gzuncompress',
		'gzdecode',
		'hex2bin',
		'strrev',
		'urldecode',
		'rawurldecode',
		'pack',
	];

	/**
	 * Bytes of decoded output kept per step.
	 */
	public const MAX_OUTPUT = 262144;

	/**
	 * @param string $name Lowercased function name.
	 */
	public static function knows( string $name ): bool {
		return in_array( $name, self::NAMES, true );
	}

	/**
	 * Decoded value, or null when this decoder cannot make sense of the input.
	 *
	 * The size cap is handed to the zlib decoders rather than applied to
	 * their result: a 61 KB deflate blob expands to 60 MB before any
	 * post-check could run, and that allocation is a fatal error no
	 * try/catch can recover from. With the bound, zlib returns false
	 * instead of allocating, and an over-sized payload is simply "not
	 * decodable".
	 *
	 * @param string $name  Decoder function name.
	 * @param string $value Input for the decoder.
	 */
	public static function apply( string $name, string $value ): ?string {
		if ( strlen( $value ) > self::MAX_OUTPUT ) {
			return null;
		}

		try {
			$out = self::call( $name, $value );
		} catch ( \Throwable $error ) {
			unset( $error );

			return null;
		}

		if ( ! is_string( $out ) || '' === $out ) {
			return null;
		}

		return strlen( $out ) > self::MAX_OUTPUT ? substr( $out, 0, self::MAX_OUTPUT ) : $out;
	}

	/**
	 * Value of a quoted string literal token, including the `b"…"` binary
	 * string form.
	 *
	 * @param string $token Raw token text, quotes included.
	 */
	public static function literal( string $token ): string {
		if ( '' !== $token && 'b' === strtolower( $token[0] ) ) {
			$token = substr( $token, 1 );
		}

		if ( strlen( $token ) < 2 ) {
			return '';
		}

		$body = substr( $token, 1, -1 );

		if ( '"' === $token[0] ) {
			return stripcslashes( $body );
		}

		return str_replace( [ '\\\\', "\\'" ], [ '\\', "'" ], $body );
	}

	/**
	 * @param string $name  Decoder function name.
	 * @param string $value Input for the decoder.
	 * @return string|false
	 */
	private static function call( string $name, string $value ) {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions -- decoding hostile constants: malformed input is the expected case here and must not surface as a PHP warning, and the obfuscation-capable decoders are the very thing this scanner has to reproduce.
		switch ( $name ) {
			case 'base64_decode':
				return base64_decode( $value, true );
			case 'str_rot13':
				return str_rot13( $value );
			case 'gzinflate':
				return @gzinflate( $value, self::MAX_OUTPUT );
			case 'gzuncompress':
				return @gzuncompress( $value, self::MAX_OUTPUT );
			case 'gzdecode':
				return @gzdecode( $value, self::MAX_OUTPUT );
			case 'hex2bin':
				return @hex2bin( $value );
			case 'strrev':
				return strrev( $value );
			case 'urldecode':
				return urldecode( $value );
			case 'rawurldecode':
				return rawurldecode( $value );
			case 'pack':
				return @pack( 'H*', $value );
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions

		return false;
	}
}
