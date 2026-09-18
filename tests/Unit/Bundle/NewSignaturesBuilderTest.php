<?php
/**
 * Tests for Bundle\NewSignaturesBuilder.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Bundle;

use LightweightPlugins\Scan\Bundle\NewSignaturesBuilder;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;

final class NewSignaturesBuilderTest extends MonkeyTestCase {

	public function test_build_collects_the_new_regex_and_hash_flag_but_no_literal(): void {
		$old_meta = ( new PackBuilder() )
			->sig( 0 )
			->sig( 1 )
			->meta();

		$builder = ( new PackBuilder() )
			->sig( 0 )
			->sig( 1 )
			->sig( 2, 'infected', 'regex' )
			->sig( 3, 'infected', 'md5' )
			->regex( 2, '/evil/', 'file_php' );

		$new_pack = $builder->pack();
		$new_meta = $builder->meta();

		$diff = NewSignaturesBuilder::build( $new_pack, $new_meta, $old_meta );

		$this->assertNotNull( $diff );
		$this->assertSame( [ 0 ], $diff['regex'], 'the only regex rule, which belongs to the new sig 2' );
		$this->assertSame( [], $diff['literal'] );
		$this->assertTrue( $diff['hash'] );
		$this->assertSame( $new_meta->version(), $diff['version'] );
		$this->assertSame( $old_meta->version(), $diff['since'] );

		$this->assertSame( [ 'test:2', 'test:3' ], NewSignaturesBuilder::new_ids( $new_meta, $old_meta ) );
	}

	public function test_build_returns_null_when_nothing_is_new(): void {
		$builder = ( new PackBuilder() )->sig( 0 )->sig( 1 );
		$pack    = $builder->pack();
		$meta    = $builder->meta();

		$this->assertNull( NewSignaturesBuilder::build( $pack, $meta, $meta ) );
		$this->assertSame( [], NewSignaturesBuilder::new_ids( $meta, $meta ) );
	}
}
