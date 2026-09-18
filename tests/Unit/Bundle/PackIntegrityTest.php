<?php
/**
 * Tests for Bundle\PackIntegrity, through Pack::from_array().
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Bundle;

use LightweightPlugins\Scan\Bundle\Pack;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;

/**
 * Every value a Pack accessor later reads as an index, offset or flag is
 * corrupted one at a time. Each corruption has the right section lengths
 * and valid base64, so only the integrity pass can catch it; the pack must
 * then be rejected at load, with no warning or error (phpunit.xml.dist
 * fails the run on warnings).
 */
final class PackIntegrityTest extends MonkeyTestCase {

	/**
	 * Three sigs, two regexes, four literals (one wordless), one of every
	 * other section, so each rule below has a value to corrupt.
	 *
	 * @return array<string, mixed>
	 */
	private static function valid(): array {
		return ( new PackBuilder() )
			->sig( 0 )->sig( 1, 'suspicious' )->sig( 2, 'info', 'literal' )
			->regex( 0, '/eval\(\$_post/i', 'file_php', [ 'eval', '$_post' ] )
			->regex( 1, '/^<\?php/', 'file_any', [], true )
			->plain_literal( 2, 'evil.example.com' )
			->plain_literal( 2, '<?=' )
			->hash( 'md5', 'aabb', 0 )
			->hash( 'sha256', 'ccdd', 1 )
			->db( 'db_option', 1, '/cialis/i', '%cialis%' )
			->db( 'db_post', 2, '/viagra/i' )
			->allow( 'abcdef' )
			->pack_array();
	}

	private static function u32( int ...$values ): string {
		return base64_encode( pack( 'V*', ...$values ) );
	}

	private static function u8( int ...$values ): string {
		return base64_encode( pack( 'C*', ...$values ) );
	}

	/**
	 * @param string $b64   A b64u32 section.
	 * @param int    $i     Element to replace.
	 * @param int    $value New value.
	 */
	private static function set_u32( string $b64, int $i, int $value ): string {
		$values       = array_values( (array) unpack( 'V*', (string) base64_decode( $b64, true ) ) );
		$values[ $i ] = $value;

		return self::u32( ...$values );
	}

	public function test_the_uncorrupted_pack_loads(): void {
		$pack = Pack::from_array( self::valid() );

		$this->assertNotNull( $pack );
		$this->assertSame( [ 3 ], $pack->wordless_literals() );
		$this->assertSame( 4, $pack->literal_count() );
	}

	/**
	 * @dataProvider provide_corruptions
	 *
	 * @param callable $corrupt Takes the valid pack array, returns it with one value broken.
	 */
	public function test_a_corrupted_value_rejects_the_pack( callable $corrupt ): void {
		$this->assertNull( Pack::from_array( $corrupt( self::valid() ) ) );
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	public static function provide_corruptions(): array {
		return array_merge( self::header_corruptions(), self::index_corruptions(), self::offset_and_byte_corruptions(), self::row_corruptions() );
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	private static function header_corruptions(): array {
		return [
			'format a numeric string'  => [ static fn( array $d ): array => array_merge( $d, [ 'format' => '1' ] ) ],
			'version a string'         => [ static fn( array $d ): array => array_merge( $d, [ 'version' => '1' ] ) ],
			'count a float'            => [ static fn( array $d ): array => array_merge( $d, [ 'count' => 3.0 ] ) ],
			'regex count a string'     => [ static fn( array $d ): array => array_merge( $d, [ 'regex_count' => '2' ] ) ],
			'literal count null'       => [ static fn( array $d ): array => array_merge( $d, [ 'literal_count' => null ] ) ],
			'literal strings an array' => [ static fn( array $d ): array => array_merge( $d, [ 'literal_strings' => [] ] ) ],
			'regex patterns null'      => [ static fn( array $d ): array => array_merge( $d, [ 'regex_patterns' => null ] ) ],
		];
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	private static function index_corruptions(): array {
		return [
			'regex sig beyond count'                => [ static fn( array $d ): array => array_merge( $d, [ 'regex_sigs' => self::u32( 0, 3 ) ] ) ],
			'regex literal beyond literal count'    => [ static fn( array $d ): array => array_merge( $d, [ 'regex_lits' => self::u32( 0, 4 ) ] ) ],
			'target regex beyond regex count'       => [
				static function ( array $d ): array {
					$d['targets']['file_php'] = self::u32( 2 );
					return $d;
				},
			],
			'word literal beyond literal count'     => [
				static function ( array $d ): array {
					$d['words']['eval'] = self::u32( 4 );
					return $d;
				},
			],
			'wordless literal beyond literal count' => [ static fn( array $d ): array => array_merge( $d, [ 'wordless_literals' => self::u32( 4 ) ] ) ],
			'huge index (flipped high byte)'        => [ static fn( array $d ): array => array_merge( $d, [ 'wordless_literals' => self::u32( 0xFF000003 ) ] ) ],
		];
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	private static function offset_and_byte_corruptions(): array {
		return [
			'regex offsets not starting at 0'         => [ static fn( array $d ): array => array_merge( $d, [ 'regex_offsets' => self::set_u32( $d['regex_offsets'], 0, 1 ) ] ) ],
			'regex offsets decreasing'                => [ static fn( array $d ): array => array_merge( $d, [ 'regex_offsets' => self::set_u32( $d['regex_offsets'], 1, strlen( $d['regex_patterns'] ) + 1 ) ] ) ],
			'regex literal offsets not starting at 0' => [ static fn( array $d ): array => array_merge( $d, [ 'regex_lit_offsets' => self::set_u32( $d['regex_lit_offsets'], 0, 1 ) ] ) ],
			'regex literal offsets decreasing'        => [ static fn( array $d ): array => array_merge( $d, [ 'regex_lit_offsets' => self::set_u32( $d['regex_lit_offsets'], 1, 3 ) ] ) ],
			'literal offsets not starting at 0'       => [ static fn( array $d ): array => array_merge( $d, [ 'literal_offsets' => self::set_u32( $d['literal_offsets'], 0, 1 ) ] ) ],
			'literal offsets decreasing'              => [ static fn( array $d ): array => array_merge( $d, [ 'literal_offsets' => self::set_u32( $d['literal_offsets'], 1, 0x7FFFFFFF ) ] ) ],
			'sig tier byte 0'                         => [ static fn( array $d ): array => array_merge( $d, [ 'sig_tier' => self::u8( 1, 0, 3 ) ] ) ],
			'sig tier byte 4'                         => [ static fn( array $d ): array => array_merge( $d, [ 'sig_tier' => self::u8( 1, 2, 4 ) ] ) ],
			'regex flags byte 4'                      => [ static fn( array $d ): array => array_merge( $d, [ 'regex_flags' => self::u8( 0, 4 ) ] ) ],
			'literal pure byte 2'                     => [ static fn( array $d ): array => array_merge( $d, [ 'literal_pure' => self::u8( 1, 0, 2, 0 ) ] ) ],
		];
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	private static function row_corruptions(): array {
		return [
			'plain literal index beyond literal count' => [ static fn( array $d ): array => self::edit( $d, [ 'plain_literals', 0, 0 ], 4 ) ],
			'plain literal index a string'             => [ static fn( array $d ): array => self::edit( $d, [ 'plain_literals', 0, 0 ], '2' ) ],
			'plain literal sig beyond count'           => [ static fn( array $d ): array => self::edit( $d, [ 'plain_literals', 0, 1 ], 3 ) ],
			'plain literal target not a string'        => [ static fn( array $d ): array => self::edit( $d, [ 'plain_literals', 0, 2 ], 5 ) ],
			'plain literal row too short'              => [ static fn( array $d ): array => self::edit( $d, [ 'plain_literals', 0 ], [ 2, 2 ] ) ],
			'plain literal row not an array'           => [ static fn( array $d ): array => self::edit( $d, [ 'plain_literals', 0 ], 'x' ) ],
			'db target not a list'                     => [ static fn( array $d ): array => self::edit( $d, [ 'db', 'db_option' ], 'x' ) ],
			'db row not an array'                      => [ static fn( array $d ): array => self::edit( $d, [ 'db', 'db_option', 0 ], 1 ) ],
			'db row too short'                         => [ static fn( array $d ): array => self::edit( $d, [ 'db', 'db_option', 0 ], [ 1, '/x/' ] ) ],
			'db sig beyond count'                      => [ static fn( array $d ): array => self::edit( $d, [ 'db', 'db_option', 0, 0 ], 3 ) ],
			'db pattern empty'                         => [ static fn( array $d ): array => self::edit( $d, [ 'db', 'db_option', 0, 1 ], '' ) ],
			'db pattern not a string'                  => [ static fn( array $d ): array => self::edit( $d, [ 'db', 'db_post', 0, 1 ], 7 ) ],
			'db like neither string nor null'          => [ static fn( array $d ): array => self::edit( $d, [ 'db', 'db_option', 0, 2 ], 5 ) ],
			'hash kind not a map'                      => [ static fn( array $d ): array => self::edit( $d, [ 'hash', 'md5' ], 'x' ) ],
			'hash sig beyond count'                    => [ static fn( array $d ): array => self::edit( $d, [ 'hash', 'sha256', 'ccdd' ], 3 ) ],
			'hash sig a string'                        => [ static fn( array $d ): array => self::edit( $d, [ 'hash', 'md5', 'aabb' ], '0' ) ],
			'allowlist not an array'                   => [ static fn( array $d ): array => self::edit( $d, [ 'allowlist' ], 'x' ) ],
		];
	}

	/**
	 * @param array<string, mixed>  $data  Pack array.
	 * @param array<int, int|string> $path Keys down to the value to replace.
	 * @param mixed                  $value Replacement.
	 * @return array<string, mixed>
	 */
	private static function edit( array $data, array $path, $value ): array {
		$ref = &$data;
		foreach ( $path as $key ) {
			$ref = &$ref[ $key ];
		}
		$ref = $value;
		unset( $ref );

		return $data;
	}
}
