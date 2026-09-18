<?php
/**
 * Tests for Bundle\Pack.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Bundle;

use LightweightPlugins\Scan\Bundle\Pack;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;

final class PackTest extends MonkeyTestCase {

	private function builder(): PackBuilder {
		return ( new PackBuilder() )
			->sig( 0, 'infected' )->sig( 1, 'suspicious' )->sig( 2, 'info', 'literal' )->sig( 3, 'infected', 'md5' )->sig( 4, 'suspicious', 'sql_like_regex' )
			->regex( 0, '/eval\(\$_post/i', 'file_php', [ 'eval', '$_post' ] )
			->regex( 1, '/^<\?php \/x/', 'file_any', [], true )
			->plain_literal( 2, 'evil.example.com' )
			->hash( 'md5', 'aabb', 3 )
			->db( 'db_option', 4, '/cialis/i', '%cialis%' )
			->allow( 'abcdef' )->allow( 'abcO12' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function fixture( string $name ): array {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/' . $name ), true );

		return is_array( $data ) ? $data : [];
	}

	public function test_regex_lits_gives_each_regex_its_own_literals_in_any_call_order(): void {
		$pack = ( new PackBuilder() )
			->sig( 0 )
			->regex( 0, '/a/', 'file_php', [ 'aaa', 'bbb' ] )
			->regex( 0, '/b/' )
			->regex( 0, '/c/', 'file_php', [ 'ccc' ] )
			->regex( 0, '/d/', 'file_php', [ 'bbb', 'ddd', 'aaa' ] )
			->pack();

		$this->assertSame( [ 1, 3, 0 ], $pack->regex_lits( 3 ), 'the last regex first, in stored order' );
		$this->assertSame( [], $pack->regex_lits( 1 ) );
		$this->assertSame( [ 2 ], $pack->regex_lits( 2 ) );
		$this->assertSame( [ 0, 1 ], $pack->regex_lits( 0 ) );
		$this->assertSame( [ 1, 3, 0 ], $pack->regex_lits( 3 ), 'and again' );
	}

	public function test_accessors_read_the_packed_sections(): void {
		$pack = $this->builder()->pack();

		$this->assertSame( 1, $pack->version() );
		$this->assertSame( 5, $pack->count() );
		$this->assertSame( 'infected', $pack->sig_tier( 0 ) );
		$this->assertSame( 'suspicious', $pack->sig_tier( 1 ) );
		$this->assertSame( 'info', $pack->sig_tier( 2 ) );
		$this->assertSame( 2, $pack->regex_count() );
		$this->assertSame( '/eval\(\$_post/i', $pack->pattern( 0 ) );
		$this->assertSame( '/^<\?php \/x/', $pack->pattern( 1 ) );
		$this->assertSame( 0, $pack->regex_sig( 0 ) );
		$this->assertSame( 1, $pack->regex_sig( 1 ) );
		$this->assertFalse( $pack->regex_first( 0 ) );
		$this->assertTrue( $pack->regex_first( 1 ) );
		$this->assertSame( [ 0, 1 ], $pack->regex_lits( 0 ) );
		$this->assertSame( [], $pack->regex_lits( 1 ) );
		$this->assertSame( [ 0 ], $pack->target_regexes( 'file_php' ) );
		$this->assertSame( [ 1 ], $pack->target_regexes( 'file_any' ) );
		$this->assertSame( [], $pack->target_regexes( 'htaccess' ) );
		$this->assertSame( [], $pack->target_regexes( 'nope' ) );
		$this->assertSame( 3, $pack->literal_count() );
		$this->assertSame( 'eval', $pack->literal( 0 ) );
		$this->assertSame( '$_post', $pack->literal( 1 ) );
		$this->assertSame( 'evil.example.com', $pack->literal( 2 ) );
		$this->assertTrue( $pack->literal_pure( 0 ) );
		$this->assertFalse( $pack->literal_pure( 1 ) );
		$this->assertSame( 3, $pack->literal_word_count( 2 ) );
		$this->assertSame( [ 0 ], $pack->word_literals( 'eval' ) );
		$this->assertSame( [ 1 ], $pack->word_literals( '_post' ) );
		$this->assertSame( [], $pack->word_literals( 'missing' ) );
		$this->assertSame( [], $pack->wordless_literals() );
		$this->assertSame( [ [ 2, 2, 'file_any' ] ], $pack->plain_literals() );
		$this->assertSame( 3, $pack->hash_sig( 'md5', 'aabb' ) );
		$this->assertNull( $pack->hash_sig( 'sha256', 'aabb' ) );
		$this->assertNull( $pack->hash_sig( 'crc32', 'aabb' ) );
		$this->assertTrue( $pack->allowlisted( 'abcdef', 1 ) );
		$this->assertTrue( $pack->allowlisted( 'abc', 12 ) );
		$this->assertFalse( $pack->allowlisted( 'abc', 13 ) );
		$this->assertSame( [ [ 'sig' => 4, 're' => '/cialis/i', 'like' => '%cialis%' ] ], $pack->db_rules( 'db_option' ) );
		$this->assertSame( [], $pack->db_rules( 'db_post' ) );
		$this->assertSame( [], $pack->db_rules( 'nope' ) );
	}

	public function test_a_wordless_literal_is_listed(): void {
		$pack = ( new PackBuilder() )->sig( 0 )->plain_literal( 0, '<?=' )->plain_literal( 0, 'ab.cd' )->pack();

		$this->assertSame( [ 0, 1 ], $pack->wordless_literals() );
		$this->assertSame( 0, $pack->literal_word_count( 0 ) );
		$this->assertFalse( $pack->literal_pure( 1 ) );
	}

	public function test_a_numeric_word_is_found_although_php_turned_its_key_into_an_int(): void {
		$pack = ( new PackBuilder() )->sig( 0 )->regex( 0, '/x3600/', 'file_php', [ 'max 3600' ] )->pack();
		$this->assertSame( [ 0 ], $pack->word_literals( '3600' ) );
	}

	public function test_a_numeric_word_survives_the_json_round_trip(): void {
		$json = (string) json_encode( ( new PackBuilder() )->sig( 0 )->regex( 0, '/x3600/', 'file_php', [ 'max 3600' ] )->pack_array() );
		$pack = Pack::from_array( (array) json_decode( $json, true ) );

		$this->assertNotNull( $pack );
		$this->assertSame( [ 0 ], $pack->word_literals( '3600' ) );
		$this->assertSame( [ 0 ], $pack->word_literals( 'max' ) );
	}

	public function test_invalid_input_is_rejected(): void {
		$data = $this->builder()->pack_array();

		$this->assertNotNull( Pack::from_array( $data ) );
		$this->assertNull( Pack::from_array( array_merge( $data, [ 'format' => 2 ] ) ) );
		$missing = $data;
		unset( $missing['regex_sigs'] );
		$this->assertNull( Pack::from_array( $missing ) );
		$this->assertNull( Pack::from_array( array_merge( $data, [ 'regex_offsets' => '!!notbase64' ] ) ) );
		$this->assertNull( Pack::from_array( array_merge( $data, [ 'regex_count' => 3 ] ) ) );
		$this->assertNull( Pack::from_array( array_merge( $data, [ 'sig_tier' => base64_encode( "\x01" ) ] ) ) );
	}

	/**
	 * @dataProvider provide_inconsistent_sections
	 *
	 * @param array<string, mixed> $override Section(s) replaced in an otherwise valid pack.
	 */
	public function test_sections_that_disagree_with_their_offsets_are_rejected( array $override ): void {
		$data = array_merge( $this->builder()->pack_array(), $override );

		$this->assertNull( Pack::from_array( $data ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function provide_inconsistent_sections(): array {
		return [
			'patterns shorter than the offsets'  => [ [ 'regex_patterns' => 'short' ] ],
			'literals shorter than the offsets'  => [ [ 'literal_strings' => 'short' ] ],
			'regex_lits longer than the offsets' => [ [ 'regex_lits' => base64_encode( pack( 'V', 0 ) ) ] ],
			'patterns not a string'              => [ [ 'regex_patterns' => 42 ] ],
			'target list not whole uint32s'      => [ [ 'targets' => [ 'file_php' => base64_encode( 'abc' ) ] ] ],
			'word list not a string'             => [ [ 'words' => [ 'eval' => 7 ] ] ],
			'hash section not a map'             => [ [ 'hash' => 'x' ] ],
			'negative regex count'               => [ [ 'regex_count' => -1 ] ],
		];
	}

	public function test_the_generated_fixture_loads(): void {
		$pack = Pack::from_array( self::fixture( 'lw-pack.json' ) );

		$this->assertNotNull( $pack );
		$this->assertSame( 15, $pack->count() );
		$this->assertSame( 20260901001, $pack->version() );
		$this->assertGreaterThan( 0, $pack->regex_count() );
		$this->assertContains( 'info', array_map( [ $pack, 'sig_tier' ], range( 0, 14 ) ) );
		$this->assertSame( [], $pack->db_rules( 'db_option' ) );
		$this->assertNull( $pack->hash_sig( 'md5', 'd41d8cd98f00b204e9800998ecf8427e' ) );
	}

	public function test_the_generated_mini_fixture_exposes_every_section(): void {
		$pack = Pack::from_array( self::fixture( 'mini-pack.json' ) );

		$this->assertNotNull( $pack );
		$this->assertSame( 6, $pack->regex_count() );
		$this->assertSame( '/eval\s*\(\s*base64_decode\s*\(/i', $pack->pattern( 1 ) );
		$this->assertTrue( $pack->regex_first( 0 ) );
		$this->assertSame( [ 1, 2 ], $pack->regex_lits( 1 ) );
		$this->assertSame( [ 0, 1, 3, 4 ], $pack->target_regexes( 'file_php' ) );
		$this->assertSame( 'document.write', $pack->literal( 3 ) );
		$this->assertSame( [ 3 ], $pack->word_literals( 'write' ) );
		$this->assertSame( [ [ 0, 3, 'file_php' ], [ 0, 4, 'file_any' ] ], $pack->plain_literals() );
		$this->assertSame( 0, $pack->hash_sig( 'md5', 'aabbccddeeff00112233445566778899' ) );
		$this->assertSame( 2, $pack->hash_sig( 'sha256', str_repeat( 'ab', 32 ) ) );
		$this->assertTrue( $pack->allowlisted( 'abc', 123 ) );
		$this->assertTrue( $pack->allowlisted( 'd41d8cd98f00b204e9800998ecf8427e', 0 ) );
		$this->assertSame( [ [ 'sig' => 13, 're' => '/post_malicious_[a-z]+/i', 'like' => null ] ], $pack->db_rules( 'db_post' ) );
		$this->assertCount( 2, $pack->db_rules( 'db_option' ) );
	}

	/**
	 * Spec §7.3: a 13,000-rule pack is held in well under 8 MB, because the
	 * numeric sections stay packed as binary strings. Patterns are padded to
	 * the live pack's average (~150 bytes) so the figure tracks it.
	 */
	public function test_a_full_size_pack_is_retained_in_under_8_mb(): void {
		$builder = new PackBuilder();
		for ( $i = 0; $i < 13000; $i++ ) {
			$builder->sig( $i, 0 === $i % 3 ? 'suspicious' : 'infected' );
		}
		for ( $i = 0; $i < 11000; $i++ ) {
			$builder->regex(
				$i,
				str_pad( '/rule' . $i . '\\s*\\(\\s*', 148, '[a-z_]+\\s*' ) . '/i',
				0 === $i % 5 ? 'file_any' : 'file_php',
				[ 'word' . ( $i % 9000 ) . ' part', 'word' . ( ( $i * 7 + 3 ) % 9000 ) . ' part' ]
			);
		}
		for ( $i = 11000; $i < 12000; $i++ ) {
			$builder->plain_literal( $i, 'word' . ( $i % 9000 ) . ' part' );
		}
		for ( $i = 12000; $i < 13000; $i++ ) {
			$builder->hash( 'md5', md5( (string) $i ), $i );
		}
		$json = (string) json_encode( $builder->pack_array() );
		unset( $builder );
		gc_collect_cycles();

		$before = memory_get_usage();
		$pack   = Pack::from_array( json_decode( $json, true ) );
		gc_collect_cycles();
		$retained = memory_get_usage() - $before;

		$this->assertNotNull( $pack );
		$this->assertSame( 13000, $pack->count() );
		$this->assertSame( 11000, $pack->regex_count() );
		$this->assertSame( 9000, $pack->literal_count() );
		$this->assertLessThan( 8 * 1048576, $retained, sprintf( 'Pack retained %d bytes.', $retained ) );
	}
}
