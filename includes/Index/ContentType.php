<?php
/**
 * Sniffs a file's head bytes (and extension) to decide what kind of content it is.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Pure, WordPress-free content sniffer. Only ever looks at the first bytes
 * of a file (the caller decides how much to read, spec calls for 4 KB) plus
 * its extension. Never touches the filesystem itself.
 */
final class ContentType {

	public const PHP     = 'php';
	public const JS      = 'js';
	public const HTML    = 'html';
	public const BINARY  = 'binary';
	public const ARCHIVE = 'archive';
	public const OTHER   = 'other';

	/**
	 * Extensions treated as JavaScript by extension alone.
	 *
	 * @var array<int,string>
	 */
	private const JS_EXTENSIONS = [ 'js', 'mjs' ];

	/**
	 * Extensions the server executes as PHP. Used twice: rule 5 (an empty or
	 * short PHP file without a literal `<?php` still counts as PHP, because
	 * signatures assume it) and rule 0, which never reclassifies one of these
	 * as an archive — a `shell.php` whose first bytes are ZIP magic is still
	 * a PHP file the server will run.
	 *
	 * @var array<int,string>
	 */
	private const PHP_EXTENSIONS = [ 'php', 'phtml', 'php5', 'php7', 'phar', 'inc' ];

	/**
	 * @param string $head Up to the first 4 KB of the file.
	 * @param string $ext  Lowercased extension without the dot, as returned by self::ext().
	 * @return string One of self::PHP|JS|HTML|BINARY|ARCHIVE|OTHER.
	 */
	public static function detect( string $head, string $ext ): string {
		// Rule 0: a container format is what it is, whatever its entries
		// contain. Plugin/theme ZIPs and backup tarballs carry PHP source,
		// and sniffing that source first classified an ordinary backup as a
		// PHP file disguised with a `.zip` extension. Never applied to a
		// server-executable extension: that would let a `.php` webshell opt
		// out of the PHP signals and the heuristic engine by prefixing
		// itself with archive magic.
		if ( self::is_archive( $head, $ext ) ) {
			return self::ARCHIVE;
		}

		// Rule 1: a literal PHP open tag anywhere in the head, or a short-echo
		// tag right at the start, wins over everything else.
		if ( false !== stripos( $head, '<?php' ) || false !== strpos( substr( $head, 0, 64 ), '<?=' ) ) {
			return self::PHP;
		}

		// Rule 2: base64-encoded PHP payload.
		if ( self::is_base64_encoded_php( $head ) ) {
			return self::PHP;
		}

		// Rule 3: well-known binary/executable signatures.
		if ( 0 === strpos( $head, "\x7fELF" ) || 0 === strpos( $head, 'MZ' ) || 0 === strpos( $head, '#!' ) ) {
			return self::BINARY;
		}

		// Rule 4: markup markers.
		if ( 1 === preg_match( '~<script|<html|<!doctype~i', $head ) ) {
			return self::HTML;
		}

		// Rule 5: fall back to the extension.
		if ( in_array( $ext, self::JS_EXTENSIONS, true ) ) {
			return self::JS;
		}

		if ( in_array( $ext, self::PHP_EXTENSIONS, true ) ) {
			return self::PHP;
		}

		// Rule 6: a high ratio of control bytes (images, fonts, other binary
		// data) that didn't match any of the above. Rule 1 already returned
		// above whenever a `<?php` tag is present, so reaching here means
		// none was found.
		if ( self::is_control_byte_ratio_high( $head ) ) {
			return self::BINARY;
		}

		return self::OTHER;
	}

	/**
	 * Container-format magic bytes. `ustar` sits at offset 257 in a POSIX
	 * tar header; every other marker is at offset 0. Detection is by magic
	 * only — an extension alone proves nothing, so a `.zip` whose bytes are
	 * not an archive keeps being sniffed like any other file — and never
	 * applies to something the server would execute as PHP.
	 *
	 * @param string $head Head bytes to inspect.
	 * @param string $ext  Lowercased extension without the dot.
	 * @return bool
	 */
	private static function is_archive( string $head, string $ext ): bool {
		if ( in_array( $ext, self::PHP_EXTENSIONS, true ) ) {
			return false;
		}

		$prefixes = [
			"PK\x03\x04",         // ZIP local file header.
			"PK\x05\x06",         // Empty ZIP (end of central directory).
			"PK\x07\x08",         // Spanned ZIP.
			"\x1f\x8b\x08",       // GZIP, deflate compression method.
			"7z\xbc\xaf\x27\x1c", // 7-Zip.
			"Rar!\x1a\x07",       // RAR.
		];

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $head, $prefix ) ) {
				return true;
			}
		}

		// BZIP2: "BZh" plus the block-size digit, so plain text starting
		// with "BZh" is not mistaken for an archive.
		if ( 1 === preg_match( '/^BZh[1-9]/', $head ) ) {
			return true;
		}

		return 'ustar' === substr( $head, 257, 5 );
	}

	/**
	 * Lowercased extension without the dot, or '' when the path has none.
	 *
	 * @param string $path Relative or absolute file path.
	 * @return string
	 */
	public static function ext( string $path ): string {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * True when the head is (mostly) base64 alphabet and decoding its first
	 * 64 characters reveals a PHP open tag.
	 *
	 * @param string $head Head bytes to inspect.
	 * @return bool
	 */
	private static function is_base64_encoded_php( string $head ): bool {
		if ( 1 !== preg_match( '~^[A-Za-z0-9+/=\s]{64,}$~', $head ) ) {
			return false;
		}

		$stripped = (string) preg_replace( '/\s+/', '', $head );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- detecting base64-obfuscated malware payloads is this method's job, not obfuscating our own code.
		$decoded = base64_decode( substr( $stripped, 0, 64 ), true );
		$decoded = false === $decoded ? '' : $decoded;

		return false !== stripos( $decoded, '<?php' ) || false !== strpos( $decoded, '<?=' );
	}

	/**
	 * True when more than 30% of the sampled bytes (up to 4 KB) are control
	 * bytes that don't belong in ordinary text/code.
	 *
	 * @param string $head Head bytes to inspect.
	 * @return bool
	 */
	private static function is_control_byte_ratio_high( string $head ): bool {
		$sample_len = min( 4096, strlen( $head ) );

		if ( 0 === $sample_len ) {
			return false;
		}

		$bad = 0;

		for ( $i = 0; $i < $sample_len; $i++ ) {
			$byte = ord( $head[ $i ] );

			if ( $byte < 0x09 || ( $byte > 0x0D && $byte < 0x20 ) || 0x7F === $byte ) {
				++$bad;
			}
		}

		return ( $bad / $sample_len ) > 0.3;
	}
}
