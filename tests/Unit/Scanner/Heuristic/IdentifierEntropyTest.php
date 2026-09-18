<?php
/**
 * Tests for Scanner\Heuristic\IdentifierEntropy.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner\Heuristic;

use LightweightPlugins\Scan\Scanner\Heuristic\IdentifierEntropy;
use LightweightPlugins\Scan\Scanner\Heuristic\TokenStream;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class IdentifierEntropyTest extends MonkeyTestCase {

	private function fixture( string $name ): string {
		$path = dirname( __DIR__, 3 ) . '/Fixtures/heuristic/' . $name;
		$code = file_get_contents( $path );

		$this->assertIsString( $code, $name . ' must be readable' );

		return (string) $code;
	}

	public function test_shannon_entropy_of_a_single_repeated_character_is_zero(): void {
		$this->assertSame( 0.0, IdentifierEntropy::shannon( 'aaaa' ) );
	}

	public function test_shannon_entropy_of_two_equally_frequent_characters_is_one_bit(): void {
		$this->assertEqualsWithDelta( 1.0, IdentifierEntropy::shannon( 'abab' ), 0.0001 );
	}

	public function test_shannon_entropy_of_an_empty_string_is_zero(): void {
		$this->assertSame( 0.0, IdentifierEntropy::shannon( '' ) );
	}

	public function test_shannon_entropy_of_all_distinct_characters_is_log2_of_the_length(): void {
		$this->assertEqualsWithDelta( 4.0, IdentifierEntropy::shannon( 'abcdefghijklmnop' ), 0.0001 );
	}

	public function test_the_goto_obfuscated_fixture_is_flagged_once(): void {
		$findings = IdentifierEntropy::analyze( new TokenStream( $this->fixture( 'entropy.php' ) ) );

		$this->assertCount( 1, $findings );
		$this->assertStringStartsWith( 'obfuscated identifiers: ', $findings[0]['reason'] );
		$this->assertArrayHasKey( 'line', $findings[0] );
	}

	public function test_the_reason_lists_at_most_four_identifiers(): void {
		$findings = IdentifierEntropy::analyze( new TokenStream( $this->fixture( 'entropy.php' ) ) );
		$listed   = substr( $findings[0]['reason'], strlen( 'obfuscated identifiers: ' ) );

		$this->assertLessThanOrEqual( 4, count( explode( ', ', rtrim( $listed, ' …' ) ) ) );
		$this->assertStringContainsString( '$', $listed, 'identifiers are shown with their sigil' );
	}

	public function test_an_ordinary_plugin_file_is_not_flagged(): void {
		$this->assertSame( [], IdentifierEntropy::analyze( new TokenStream( $this->fixture( 'clean-plugin.php' ) ) ) );
	}

	public function test_fewer_than_eight_identifiers_are_never_flagged(): void {
		$code = "<?php\n\$xTz9Kq4BvWm2RdFh = 1;\n\$Hj7YpLsN3cVkQbZx = 2;\n\$Zr5MgXwT8nFdCyJp = 3;\n";

		$this->assertSame( [], IdentifierEntropy::analyze( new TokenStream( $code ) ) );
	}

	public function test_readable_identifiers_are_not_flagged_even_when_numerous(): void {
		$names = [ 'post_id', 'options', 'settings', 'template', 'attachment', 'thumbnail', 'permalink', 'taxonomy', 'meta_value', 'transient' ];
		$code  = "<?php\n";

		foreach ( $names as $index => $name ) {
			$code .= '$' . $name . ' = ' . $index . ";\n";
		}

		$this->assertSame( [], IdentifierEntropy::analyze( new TokenStream( $code ) ) );
	}

	public function test_unparsable_code_yields_no_findings(): void {
		$this->assertSame( [], IdentifierEntropy::analyze( new TokenStream( '' ) ) );
	}

	public function test_the_goto_fixture_triggers_on_shape_alone_not_on_entropy(): void {
		$stream      = new TokenStream( $this->fixture( 'entropy.php' ) );
		$identifiers = $stream->identifiers();
		$entropy     = 0.0;

		foreach ( $identifiers as $identifier ) {
			$entropy += IdentifierEntropy::shannon( $identifier );
		}

		$average = $entropy / count( $identifiers );

		$this->assertGreaterThanOrEqual( 8, count( $identifiers ) );
		$this->assertLessThan( 3.6, $average, 'short O0/Il filler never reaches the entropy bar' );
		$this->assertCount( 1, IdentifierEntropy::analyze( $stream ), 'the shape ratio alone must trigger' );
	}

	public function test_high_entropy_alone_does_not_trigger(): void {
		$names = [
			'Abcdefghijklmnop',
			'Bcdefghijklmnopq',
			'Cdefghijklmnopqr',
			'Defghijklmnopqrs',
			'Efghijklmnopqrst',
			'Fghijklmnopqrstu',
			'Ghijklmnopqrstuv',
			'Hijklmnopqrstuvw',
			'Ijklmnopqrstuvwx',
			'Jklmnopqrstuvwxy',
		];
		$code  = "<?php\n";

		foreach ( $names as $index => $name ) {
			$code .= '$' . $name . ' = ' . $index . ";\n";
			$this->assertGreaterThan( 3.6, IdentifierEntropy::shannon( $name ) );
		}

		$this->assertSame( [], IdentifierEntropy::analyze( new TokenStream( $code ) ) );
	}

	public function test_a_minority_of_generated_names_does_not_trigger(): void {
		$code = "<?php\n\$O0O0O0 = 1;\n\$IlIlIl = 2;\n";

		foreach ( [ 'post_id', 'options', 'settings', 'template', 'attachment', 'thumbnail', 'permalink', 'taxonomy' ] as $index => $name ) {
			$code .= '$' . $name . ' = ' . $index . ";\n";
		}

		$this->assertSame( [], IdentifierEntropy::analyze( new TokenStream( $code ) ) );
	}
}
