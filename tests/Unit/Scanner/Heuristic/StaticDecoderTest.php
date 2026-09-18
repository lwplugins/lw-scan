<?php
/**
 * Tests for Scanner\Heuristic\StaticDecoder.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner\Heuristic;

use LightweightPlugins\Scan\Scanner\Heuristic\StaticDecoder;
use LightweightPlugins\Scan\Scanner\Heuristic\TokenStream;
use LightweightPlugins\Scan\Scanner\MatchResult;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class StaticDecoderTest extends MonkeyTestCase {

	/**
	 * Decoded strings handed to the rescan callable, newest last.
	 *
	 * @var array<int,string>
	 */
	private array $seen = [];

	private string $fixture_path = '';

	protected function setUp(): void {
		parent::setUp();

		$this->seen = [];

		// The fixture is generated rather than committed: gzdeflate output is
		// zlib-version dependent, so the bytes are computed here and the file
		// is (re)written on every run.
		$this->fixture_path = dirname( __DIR__, 3 ) . '/Fixtures/heuristic/decoder.php';

		$blob = base64_encode( (string) gzdeflate( '<?php eval($_POST[1]);' ) );
		$code = "<?php die('inert lw-scan fixture'); ?>\n<?php\neval( gzinflate( base64_decode( '" . $blob . "' ) ) );\n";

		file_put_contents( $this->fixture_path, $code );
	}

	private function decoder(): StaticDecoder {
		return new StaticDecoder(
			function ( string $decoded ): array {
				$this->seen[] = $decoded;

				if ( false === strpos( $decoded, 'eval($_POST' ) ) {
					return [];
				}

				return [ new MatchResult( 0, 'test:sig:1', 'infected', 'backdoor', 'Eval on POST', 1, 'excerpt', 0 ) ];
			}
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function analyze( string $code ): array {
		return $this->decoder()->analyze( new TokenStream( $code ), $code );
	}

	private function fixture( string $name ): string {
		$path = dirname( __DIR__, 3 ) . '/Fixtures/heuristic/' . $name;
		$code = file_get_contents( $path );

		$this->assertIsString( $code, $name . ' must be readable' );

		return (string) $code;
	}

	public function test_a_nested_decoder_chain_reports_the_signature_that_matched_the_payload(): void {
		$findings = $this->analyze( $this->fixture( 'decoder.php' ) );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'test:sig:1 matched after base64_decode→gzinflate decoding', $findings[0]['reason'] );
		$this->assertSame( 3, $findings[0]['line'] );
		$this->assertSame( [ 'test:sig:1' ], $findings[0]['sig_ids'] );
		$this->assertSame( 'backdoor', $findings[0]['category'] );
	}

	public function test_the_decoded_payload_is_handed_to_the_rescan_callable(): void {
		$this->analyze( $this->fixture( 'decoder.php' ) );

		$this->assertSame( [ '<?php eval($_POST[1]);' ], $this->seen );
	}

	public function test_a_long_decoded_php_payload_is_reported_on_its_own(): void {
		$payload = '<?php ' . str_repeat( 'echo "padding padding"; ', 12 );

		$this->assertGreaterThanOrEqual( 200, strlen( $payload ) );

		$code     = "<?php\n\$x = base64_decode( '" . base64_encode( $payload ) . "' );\n";
		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'decoded PHP payload via base64_decode', $findings[0]['reason'] );
		$this->assertSame( [ 'heur:decoder' ], $findings[0]['sig_ids'] );
		$this->assertSame( 'obfuscation', $findings[0]['category'] );
		$this->assertSame( 2, $findings[0]['line'] );
	}

	public function test_a_short_decoded_payload_without_a_signature_hit_is_not_reported(): void {
		$code = "<?php\n\$x = base64_decode( '" . base64_encode( 'hello world' ) . "' );\n";

		$this->assertSame( [], $this->analyze( $code ) );
	}

	public function test_a_chr_chain_of_at_least_eight_terms_is_evaluated(): void {
		$parts = [];

		foreach ( str_split( 'eval($_POST' ) as $char ) {
			$parts[] = 'chr(' . ord( $char ) . ')';
		}

		$code     = "<?php\n\$x = " . implode( '.', $parts ) . ";\n";
		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'test:sig:1 matched after chr decoding', $findings[0]['reason'] );
	}

	public function test_a_short_chr_chain_is_ignored(): void {
		$code = "<?php\n\$x = chr(101).chr(118).chr(97).chr(108);\n";

		$this->assertSame( [], $this->analyze( $code ) );
		$this->assertSame( [], $this->seen );
	}

	public function test_pack_with_a_hex_format_is_evaluated(): void {
		$code     = "<?php\n\$x = pack( 'H*', '" . bin2hex( 'eval($_POST[1]);' ) . "' );\n";
		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'test:sig:1 matched after pack decoding', $findings[0]['reason'] );
	}

	public function test_chains_deeper_than_three_decoders_are_skipped(): void {
		$blob = base64_encode( base64_encode( base64_encode( base64_encode( 'eval($_POST[1]);' ) ) ) );
		$code = "<?php\n\$x = base64_decode( base64_decode( base64_decode( base64_decode( '" . $blob . "' ) ) ) );\n";

		$this->assertSame( [], $this->analyze( $code ) );
	}

	public function test_a_chain_of_exactly_three_decoders_is_evaluated(): void {
		$blob = base64_encode( str_rot13( base64_encode( 'eval($_POST[1]);' ) ) );
		$code = "<?php\n\$x = base64_decode( str_rot13( base64_decode( '" . $blob . "' ) ) );\n";

		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'test:sig:1 matched after base64_decode→str_rot13→base64_decode decoding', $findings[0]['reason'] );
	}

	public function test_a_non_constant_argument_stops_the_chain_at_the_constant_part(): void {
		$blob = base64_encode( 'eval($_POST[1]);' );
		$code = "<?php\n\$x = gzinflate( base64_decode( '" . $blob . "' ) . \$tail );\n";

		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'test:sig:1 matched after base64_decode decoding', $findings[0]['reason'] );
	}

	public function test_an_undecodable_payload_is_skipped_without_warnings(): void {
		$code = "<?php\n\$x = gzinflate( base64_decode( 'zzzzzzzzzzzz' ) );\n";

		$this->assertSame( [], $this->analyze( $code ) );
	}

	public function test_a_decoder_called_with_a_variable_is_ignored(): void {
		$code = "<?php\n\$x = base64_decode( \$_POST['p'] );\n";

		$this->assertSame( [], $this->analyze( $code ) );
		$this->assertSame( [], $this->seen );
	}

	public function test_an_ordinary_plugin_file_produces_no_findings(): void {
		$this->assertSame( [], $this->analyze( $this->fixture( 'clean-plugin.php' ) ) );
		$this->assertSame( [], $this->seen );
	}

	public function test_unparsable_code_yields_no_findings(): void {
		$this->assertSame( [], $this->analyze( '' ) );
	}

	public function test_a_deflate_bomb_is_skipped_without_inflating_it(): void {
		// ~1000:1. The compressed blob is 61 KB — comfortably inside the
		// input cap — but unbounded gzinflate() materialises 60 MB from it
		// (measured peak +122 MB), which on a 64M site is a fatal error no
		// try/catch can recover from.
		// Built through a stream so the test itself never holds the 60 MB it
		// would expand to.
		$context = deflate_init( ZLIB_ENCODING_RAW );
		$raw     = '';

		for ( $chunk = 0; $chunk < 60; $chunk++ ) {
			$raw .= deflate_add( $context, str_repeat( 'A', 1024 * 1024 ), ZLIB_NO_FLUSH );
		}

		$raw .= deflate_add( $context, '', ZLIB_FINISH );
		$blob = base64_encode( $raw );
		$code = "<?php\n\$x = gzinflate( base64_decode( '" . $blob . "' ) );\n";

		$before = memory_get_peak_usage( true );

		$findings = $this->analyze( $code );

		$delta = memory_get_peak_usage( true ) - $before;

		$this->assertSame( [], $findings );
		$this->assertLessThan( 4 * 1024 * 1024, $delta, sprintf( 'peak memory grew by %d bytes', $delta ) );
	}

	public function test_a_payload_within_the_output_cap_still_decodes(): void {
		$payload = '<?php ' . str_repeat( 'echo "still small"; ', 12 );
		$blob    = base64_encode( (string) gzdeflate( $payload ) );
		$code    = "<?php\n\$x = gzinflate( base64_decode( '" . $blob . "' ) );\n";

		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'decoded PHP payload via base64_decode→gzinflate', $findings[0]['reason'] );
	}

	public function test_whitespace_before_the_parentheses_does_not_hide_the_chain(): void {
		$blob = base64_encode( (string) gzdeflate( 'eval($_POST[1]);' ) );
		$code = "<?php\n\$x = gzinflate\n\t(base64_decode ( '" . $blob . "' ) );\n";

		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'test:sig:1 matched after base64_decode→gzinflate decoding', $findings[0]['reason'] );
	}

	public function test_a_binary_string_literal_is_decoded(): void {
		$code = "<?php\n\$x = base64_decode( b'" . base64_encode( 'eval($_POST[1]);' ) . "' );\n";

		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'test:sig:1 matched after base64_decode decoding', $findings[0]['reason'] );
	}

	public function test_a_chr_chain_written_in_hexadecimal_is_evaluated(): void {
		$parts = [];

		foreach ( str_split( 'eval($_POST' ) as $char ) {
			$parts[] = 'chr(0x' . dechex( ord( $char ) ) . ')';
		}

		$code     = "<?php\n\$x = " . implode( '.', $parts ) . ";\n";
		$findings = $this->analyze( $code );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'test:sig:1 matched after chr decoding', $findings[0]['reason'] );
	}
}
