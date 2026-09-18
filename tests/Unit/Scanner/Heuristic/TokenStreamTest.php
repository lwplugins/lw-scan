<?php
/**
 * Tests for Scanner\Heuristic\TokenStream.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner\Heuristic;

use LightweightPlugins\Scan\Scanner\Heuristic\TokenStream;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class TokenStreamTest extends MonkeyTestCase {

	public function test_tokens_are_normalised_to_id_text_line_triples(): void {
		$ts = new TokenStream( "<?php\n\$a = 1;" );

		$tokens = $ts->tokens();

		$this->assertNotSame( [], $tokens );

		foreach ( $tokens as $token ) {
			$this->assertCount( 3, $token );
			$this->assertTrue( null === $token[0] || is_int( $token[0] ) );
			$this->assertIsString( $token[1] );
			$this->assertIsInt( $token[2] );
		}
	}

	public function test_string_tokens_get_a_null_id_and_the_current_line(): void {
		$ts = new TokenStream( "<?php\n\$a = 1;\n\$b = 2;" );

		$semicolons = [];

		foreach ( $ts->tokens() as $token ) {
			if ( null === $token[0] && ';' === $token[1] ) {
				$semicolons[] = $token[2];
			}
		}

		$this->assertSame( [ 2, 3 ], $semicolons );
	}

	public function test_empty_code_yields_no_tokens(): void {
		$ts = new TokenStream( '' );

		$this->assertSame( [], $ts->tokens() );
	}

	public function test_unparsable_code_falls_back_instead_of_throwing(): void {
		$ts = new TokenStream( '<?php $a = ;' );

		$this->assertNotSame( [], $ts->tokens(), 'the lenient tokenizer still produces tokens' );
	}

	public function test_functions_lists_named_functions_closures_and_a_global_pseudo_range(): void {
		$code = "<?php\nfunction alpha() {\n\techo 1;\n}\n\$c = function () {\n\techo 2;\n};\n";

		$names = array_column( ( new TokenStream( $code ) )->functions(), 'name' );

		$this->assertContains( 'alpha', $names );
		$this->assertContains( '{closure}', $names );
		$this->assertContains( '(global)', $names );
	}

	public function test_the_global_pseudo_range_spans_every_token(): void {
		$ts = new TokenStream( "<?php\nfunction alpha() {\n\techo 1;\n}\n" );

		$ranges = $ts->functions();
		$global = end( $ranges );

		$this->assertSame( '(global)', $global['name'] );
		$this->assertSame( 0, $global['start'] );
		$this->assertSame( count( $ts->tokens() ) - 1, $global['end'] );
	}

	public function test_a_function_range_covers_only_its_own_body(): void {
		$code = "<?php\nfunction alpha() {\n\techo 1;\n}\necho 2;\n";
		$ts     = new TokenStream( $code );
		$tokens = $ts->tokens();

		$alpha = null;

		foreach ( $ts->functions() as $range ) {
			if ( 'alpha' === $range['name'] ) {
				$alpha = $range;
			}
		}

		$this->assertIsArray( $alpha );

		$body = '';

		for ( $i = $alpha['start']; $i <= $alpha['end']; $i++ ) {
			$body .= $tokens[ $i ][1];
		}

		$this->assertStringContainsString( 'echo 1', $body );
		$this->assertStringNotContainsString( 'echo 2', $body );
	}

	public function test_identifiers_collects_variables_function_names_and_goto_labels(): void {
		$code = "<?php\nfunction alpha( \$beta ) {\n\tgoto gamma;\n\tgamma:\n\treturn \$beta;\n}\n";

		$identifiers = ( new TokenStream( $code ) )->identifiers();

		$this->assertContains( 'alpha', $identifiers );
		$this->assertContains( 'beta', $identifiers );
		$this->assertContains( 'gamma', $identifiers );
	}

	public function test_identifiers_are_unique_and_exclude_superglobals_and_this(): void {
		$code = "<?php\nclass A { public function b() { \$x = \$this; \$x = \$_GET['q']; \$x = 2; } }\n";

		$identifiers = ( new TokenStream( $code ) )->identifiers();

		$this->assertSame( array_values( array_unique( $identifiers ) ), $identifiers );
		$this->assertNotContains( 'this', $identifiers );
		$this->assertNotContains( '_GET', $identifiers );
		$this->assertContains( 'x', $identifiers );
	}
}
