<?php
/**
 * Tests for Bundle\PackMeta.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Bundle;

use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class PackMetaTest extends MonkeyTestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function fixture(): array {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/lw-meta.json' ), true );

		return is_array( $data ) ? $data : [];
	}

	public function test_the_generated_fixture_loads(): void {
		$meta = PackMeta::from_array( self::fixture() );

		$this->assertNotNull( $meta );
		$this->assertSame( 20260901001, $meta->version() );
		$this->assertSame( 15, $meta->count() );
		$this->assertCount( 15, $meta->ids() );
		$this->assertSame( 'lw:0001', $meta->id( 0 ) );
		$this->assertSame( 'eval() on request input', $meta->name( 0 ) );
		$this->assertSame( 'backdoor', $meta->category( 0 ) );
		$this->assertSame( 'regex', $meta->kind( 0 ) );
		$this->assertSame( 'lw:0009', $meta->ids()[14] );
	}

	public function test_an_out_of_range_sig_reads_as_empty(): void {
		$meta = PackMeta::from_array( self::fixture() );

		$this->assertNotNull( $meta );
		$this->assertSame( '', $meta->id( 15 ) );
		$this->assertSame( '', $meta->name( -1 ) );
	}

	public function test_a_count_mismatch_is_rejected(): void {
		$this->assertNull( PackMeta::from_array( array_merge( self::fixture(), [ 'count' => 14 ] ) ) );

		$data          = self::fixture();
		$data['kinds'] = array_slice( $data['kinds'], 1 );
		$this->assertNull( PackMeta::from_array( $data ) );
	}

	public function test_another_format_is_rejected(): void {
		$this->assertNull( PackMeta::from_array( array_merge( self::fixture(), [ 'format' => 2 ] ) ) );
	}

	/**
	 * @dataProvider provide_mistyped_headers
	 *
	 * @param array<string, mixed> $override Header replaced in the valid fixture.
	 */
	public function test_a_header_that_is_not_an_int_is_rejected( array $override ): void {
		$this->assertNull( PackMeta::from_array( array_merge( self::fixture(), $override ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function provide_mistyped_headers(): array {
		return [
			'format a numeric string' => [ [ 'format' => '1' ] ],
			'version a string'        => [ [ 'version' => '20260901001' ] ],
			'count a numeric string'  => [ [ 'count' => '15' ] ],
			'count a float'           => [ [ 'count' => 15.0 ] ],
		];
	}

	public function test_a_missing_list_or_non_string_entry_is_rejected(): void {
		$data = self::fixture();
		unset( $data['names'] );
		$this->assertNull( PackMeta::from_array( $data ) );

		$data           = self::fixture();
		$data['ids'][3] = 7;
		$this->assertNull( PackMeta::from_array( $data ) );

		$this->assertNull( PackMeta::from_array( [] ) );
	}
}
