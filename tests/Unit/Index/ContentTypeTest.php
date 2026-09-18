<?php
/**
 * Tests for Index\ContentType.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Index;

use LightweightPlugins\Scan\Index\ContentType;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class ContentTypeTest extends MonkeyTestCase {

	public function test_detects_php_tag_deep_in_a_4kb_head(): void {
		$head = str_repeat( 'A', 3000 ) . '<?php' . str_repeat( 'B', 1091 );

		$this->assertSame( strlen( $head ), 4096 );
		$this->assertSame( ContentType::PHP, ContentType::detect( $head, '' ) );
	}

	public function test_detects_php_tag_after_a_gif_header(): void {
		$head = 'GIF89a' . str_repeat( 'x', 10 ) . '<?php echo 1;';

		$this->assertSame( ContentType::PHP, ContentType::detect( $head, 'gif' ) );
	}

	public function test_detects_base64_encoded_php_payload(): void {
		$payload = '<?php echo 1;' . str_repeat( 'A', 60 );
		$head    = base64_encode( $payload );

		$this->assertGreaterThanOrEqual( 64, strlen( $head ) );
		$this->assertSame( ContentType::PHP, ContentType::detect( $head, 'txt' ) );
	}

	public function test_detects_elf_binary(): void {
		$head = "\x7fELF" . str_repeat( "\x01\x02\x03", 20 );

		$this->assertSame( ContentType::BINARY, ContentType::detect( $head, 'so' ) );
	}

	public function test_detects_mz_binary(): void {
		$head = 'MZ' . str_repeat( "\x00\x01", 20 );

		$this->assertSame( ContentType::BINARY, ContentType::detect( $head, 'exe' ) );
	}

	public function test_detects_shebang_as_binary(): void {
		$head = "#!/bin/sh\necho hi\n";

		$this->assertSame( ContentType::BINARY, ContentType::detect( $head, 'sh' ) );
	}

	public function test_detects_html_doctype(): void {
		$head = "<!DOCTYPE html>\n<html><body>hi</body></html>";

		$this->assertSame( ContentType::HTML, ContentType::detect( $head, 'html' ) );
	}

	public function test_detects_js_by_extension(): void {
		$head = 'var x=1';

		$this->assertSame( ContentType::JS, ContentType::detect( $head, 'js' ) );
	}

	public function test_empty_php_file_is_still_php(): void {
		$this->assertSame( ContentType::PHP, ContentType::detect( '', 'php' ) );
	}

	public function test_detects_binary_by_control_byte_ratio(): void {
		$head = "\x89PNG\r\n\x1a\n" . str_repeat( "\x00\x01\x02\x03", 300 );

		$this->assertSame( ContentType::BINARY, ContentType::detect( $head, 'png' ) );
	}

	public function test_plain_text_is_other(): void {
		$head = str_repeat( 'The quick brown fox jumps over the lazy dog. ', 5 );

		$this->assertSame( ContentType::OTHER, ContentType::detect( $head, '' ) );
	}

	public function test_ext_returns_lowercased_extension_without_dot(): void {
		$this->assertSame( 'php', ContentType::ext( 'wp-content/uploads/x.PHP' ) );
	}

	public function test_ext_returns_empty_string_when_no_extension(): void {
		$this->assertSame( '', ContentType::ext( 'wp-content/uploads/noext' ) );
	}

	/**
	 * @dataProvider provide_archive_heads
	 */
	public function test_archive_magic_wins_over_the_php_sniff( string $head, string $ext ): void {
		// A plugin/theme ZIP under uploads carries PHP source inside; sniffing
		// `<?php` first classified it as a disguised PHP file and alerted on
		// an ordinary backup.
		$this->assertSame( ContentType::ARCHIVE, ContentType::detect( $head, $ext ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function provide_archive_heads(): array {
		$php_inside = '<?php echo 1;' . str_repeat( "\x01\x02", 20 );

		return [
			'zip local header'    => [ "PK\x03\x04" . $php_inside, 'zip' ],
			'empty zip'           => [ "PK\x05\x06" . str_repeat( "\x00", 18 ), 'zip' ],
			'spanned zip'         => [ "PK\x07\x08" . $php_inside, 'zip' ],
			'gzip'                => [ "\x1f\x8b\x08" . $php_inside, 'gz' ],
			'bzip2'               => [ 'BZh9' . $php_inside, 'bz2' ],
			'7-zip'               => [ "7z\xbc\xaf\x27\x1c" . $php_inside, '7z' ],
			'rar'                 => [ "Rar!\x1a\x07\x00" . $php_inside, 'rar' ],
		];
	}

	public function test_detects_a_tar_by_its_ustar_magic_at_offset_257(): void {
		$head = str_pad( 'backup.php', 257, "\x00" ) . "ustar\x0000" . str_repeat( "\x00", 100 ) . '<?php echo 1;';

		$this->assertSame( ContentType::ARCHIVE, ContentType::detect( $head, 'tar' ) );
	}

	public function test_a_zip_extension_without_archive_magic_is_still_sniffed(): void {
		$this->assertSame( ContentType::PHP, ContentType::detect( '<?php echo 1;', 'zip' ) );
	}

	public function test_a_real_php_file_is_still_php(): void {
		$this->assertSame( ContentType::PHP, ContentType::detect( "<?php\n// plugin bootstrap\n", 'php' ) );
	}

	/**
	 * @dataProvider provide_server_executable_extensions
	 */
	public function test_archive_magic_never_reclassifies_a_server_executable_file( string $ext ): void {
		// A webshell that prefixes itself with ZIP magic must not be able to
		// opt out of the PHP signals and the heuristic engine.
		$this->assertSame( ContentType::PHP, ContentType::detect( "PK\x03\x04" . str_repeat( 'x', 40 ), $ext ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function provide_server_executable_extensions(): array {
		return [
			'php'   => [ 'php' ],
			'phtml' => [ 'phtml' ],
			'php5'  => [ 'php5' ],
			'php7'  => [ 'php7' ],
			'phar'  => [ 'phar' ],
			'inc'   => [ 'inc' ],
		];
	}

	public function test_a_php_shell_with_a_zip_header_still_reaches_the_php_rules(): void {
		$head = "PK\x03\x04" . str_repeat( "\x01", 8 ) . '<?php eval($_POST[0]); ?>';

		$this->assertSame( ContentType::PHP, ContentType::detect( $head, 'php' ) );
	}

	/**
	 * @dataProvider provide_near_miss_magics
	 */
	public function test_a_near_miss_magic_is_not_an_archive( string $head, string $ext ): void {
		$this->assertNotSame( ContentType::ARCHIVE, ContentType::detect( $head, $ext ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function provide_near_miss_magics(): array {
		return [
			'gzip without the deflate method byte' => [ "\x1f\x8bZZ" . str_repeat( 'text ', 20 ), 'gz' ],
			'text starting with BZh'               => [ 'BZhX is a variable name in this note', 'txt' ],
		];
	}
}
