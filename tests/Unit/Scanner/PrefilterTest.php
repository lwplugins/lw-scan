<?php
/**
 * Tests for Scanner\Prefilter.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Bundle\Pack;
use LightweightPlugins\Scan\Scanner\Prefilter;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;

/**
 * `presence()` lists only the literals that are present, so "absent" reads
 * as a missing key — exactly how RegexLayer consumes it (`empty()`).
 */
final class PrefilterTest extends MonkeyTestCase {

	private Pack $pack;

	protected function setUp(): void {
		parent::setUp();

		$this->pack = FixturePack::signatures( 'mini' )->pack();
	}

	/**
	 * Literal index of `$lc` in the mini fixture pack.
	 *
	 * @param string $lc Lowercase literal.
	 */
	private function index_of( string $lc ): int {
		for ( $i = 0; $i < $this->pack->literal_count(); $i++ ) {
			if ( $lc === $this->pack->literal( $i ) ) {
				return $i;
			}
		}

		$this->fail( 'fixture precondition: "' . $lc . '" must be a literal in the mini pack' );
	}

	public function test_ascii_lower_folds_only_az_and_leaves_every_other_byte_alone(): void {
		$input = 'AbC_123 XYZ' . "\xc3\x89" . "\xc9" . '!@#';

		$this->assertSame( 'abc_123 xyz' . "\xc3\x89" . "\xc9" . '!@#', Prefilter::ascii_lower( $input ) );
	}

	public function test_ascii_lower_matches_the_pack_regardless_of_the_ctype_locale(): void {
		$previous = setlocale( LC_CTYPE, '0' );
		$locale   = setlocale( LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR.ISO8859-9', 'tr_TR', 'turkish' );

		if ( false === $locale ) {
			$this->markTestSkipped( 'No Turkish locale installed on this machine.' );
		}

		try {
			// A high-byte literal (0xC9), the way the Go builder's ASCIILower()
			// leaves it — untouched, never folded to anything else.
			$pack = ( new PackBuilder() )->sig( 0, 'suspicious', 'literal' )->plain_literal( 0, "marker\xc9end" )->pack();

			$lc = Prefilter::ascii_lower( "content MARKER\xc9END here" );

			$this->assertSame( "content marker\xc9end here", $lc );
			$this->assertTrue( Prefilter::presence( $lc, $pack )[0] ?? false );
		} finally {
			setlocale( LC_CTYPE, (string) $previous );
		}
	}

	public function test_word_set_flips_the_split_tokens(): void {
		$words = Prefilter::word_set( 'foo_bar(123, "baz")' );

		$this->assertArrayHasKey( 'foo_bar', $words );
		$this->assertArrayHasKey( '123', $words );
		$this->assertArrayHasKey( 'baz', $words );
		$this->assertArrayNotHasKey( 'foo', $words );
	}

	public function test_word_set_is_empty_for_a_string_with_no_word_characters(): void {
		$this->assertSame( [], Prefilter::word_set( '(((---)))' ) );
	}

	public function test_pure_word_literal_present_as_a_standalone_token_is_true(): void {
		$presence = Prefilter::presence( 'if (eval($x)) { return; }', $this->pack );

		$this->assertTrue( $presence[ $this->index_of( 'eval' ) ] );
	}

	public function test_pure_word_literal_only_present_as_a_substring_of_another_word_is_false(): void {
		// "eval" is a byte-substring of "myevaluate", but not a standalone
		// word token — a naive strpos() would wrongly report presence, so
		// this proves the word-set check is what decides the result.
		$presence = Prefilter::presence( 'the myevaluate function call', $this->pack );

		$this->assertArrayNotHasKey( $this->index_of( 'eval' ), $presence );
	}

	public function test_non_word_literal_absent_when_only_its_word_token_matches(): void {
		// "base64_decode" the word is present, but the literal itself is
		// "base64_decode(" — the space before the paren means the exact
		// substring is not there, so presence must be false.
		$presence = Prefilter::presence( 'call base64_decode ($x)', $this->pack );

		$this->assertArrayNotHasKey( $this->index_of( 'base64_decode(' ), $presence );
	}

	public function test_non_word_literal_present_only_when_the_exact_substring_is_there(): void {
		$presence = Prefilter::presence( 'call base64_decode($x)', $this->pack );

		$this->assertTrue( $presence[ $this->index_of( 'base64_decode(' ) ] );
	}

	public function test_multi_word_literal_requires_every_word_present(): void {
		$presence = Prefilter::presence( 'only document here, no writing', $this->pack );

		$this->assertArrayNotHasKey( $this->index_of( 'document.write' ), $presence );
	}

	public function test_multi_word_literal_true_when_the_exact_substring_is_there(): void {
		$presence = Prefilter::presence( 'x = document.write(y);', $this->pack );

		$this->assertTrue( $presence[ $this->index_of( 'document.write' ) ] );
	}

	public function test_presence_lists_only_the_literals_that_are_present(): void {
		$this->assertSame( [], Prefilter::presence( 'nothing interesting here', $this->pack ) );

		$presence = Prefilter::presence( 'eval(base64_decode($x)); document.write(y); malware_token', $this->pack );

		$this->assertCount( $this->pack->literal_count(), $presence );

		foreach ( $presence as $present ) {
			$this->assertTrue( $present );
		}
	}

	public function test_numeric_string_literal_present_as_a_standalone_token_is_true(): void {
		// A purely numeric literal ("3600") turns into an int array key both
		// in the chunk's word set and in the pack's word index; presence must
		// still find it, and treat it as the pure word it is.
		$pack = ( new PackBuilder() )->sig( 0, 'suspicious', 'literal' )->plain_literal( 0, '3600' )->pack();

		$presence = Prefilter::presence( 'set timeout to 3600 seconds', $pack );

		$this->assertTrue( $presence[0] );
	}

	public function test_numeric_string_literal_absent_when_not_a_standalone_token(): void {
		$pack = ( new PackBuilder() )->sig( 0, 'suspicious', 'literal' )->plain_literal( 0, '3600' )->pack();

		$presence = Prefilter::presence( 'set timeout to 36000 seconds', $pack );

		$this->assertArrayNotHasKey( 0, $presence );
	}

	public function test_pure_literal_with_a_numeric_word_among_other_words(): void {
		// "max_3600" is one pure word; "limit 3600" is two words, one numeric.
		$builder = new PackBuilder();
		$builder->sig( 0, 'suspicious', 'literal' )->plain_literal( 0, 'max_3600' );
		$builder->sig( 1, 'suspicious', 'literal' )->plain_literal( 1, 'limit 3600' );
		$pack = $builder->pack();

		$this->assertTrue( $pack->literal_pure( 0 ), 'fixture precondition: max_3600 is pure' );
		$this->assertFalse( $pack->literal_pure( 1 ), 'fixture precondition: "limit 3600" is not pure' );

		$this->assertSame( [ 0 => true ], Prefilter::presence( 'x = max_3600; limit  3600', $pack ) );
		$this->assertSame(
			[
				0 => true,
				1 => true,
			],
			Prefilter::presence( 'max_3600 limit 3600', $pack )
		);
	}

	public function test_non_pure_literal_with_every_word_present_but_not_the_exact_string_is_absent(): void {
		$pack = ( new PackBuilder() )->sig( 0, 'suspicious', 'literal' )->plain_literal( 0, 'wp_remote_get($_get' )->pack();

		$this->assertSame( 2, $pack->literal_word_count( 0 ), 'fixture precondition: the literal has two 3+ byte words' );

		$this->assertSame( [], Prefilter::presence( 'wp_remote_get( $_get )', $pack ), 'both words occur, the exact bytes do not' );
		$this->assertSame( [ 0 => true ], Prefilter::presence( 'x = wp_remote_get($_get[1]);', $pack ) );
	}

	public function test_short_pure_wordless_literal_is_present_regardless_of_content(): void {
		// Words shorter than 3 bytes are not indexed, so "ab" has none. The
		// array-based prefilter treated such a pure literal as present
		// without looking; the pack keeps that decision.
		$builder = new PackBuilder();
		$builder->sig( 0, 'suspicious', 'literal' )->plain_literal( 0, 'ab' );
		$builder->sig( 1, 'suspicious', 'literal' )->plain_literal( 1, '<?' );
		$pack = $builder->pack();

		$this->assertSame( [ 0, 1 ], $pack->wordless_literals(), 'fixture precondition: both literals are wordless' );

		$this->assertSame( [ 0 => true ], Prefilter::presence( 'nothing to see', $pack ), 'a pure wordless literal is present; a non-pure one needs its bytes' );
		$this->assertSame(
			[
				0 => true,
				1 => true,
			],
			Prefilter::presence( '<?php', $pack )
		);
	}
}
