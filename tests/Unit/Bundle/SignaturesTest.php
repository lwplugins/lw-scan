<?php
/**
 * Tests for Bundle\Signatures.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Bundle;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;
use RuntimeException;

final class SignaturesTest extends MonkeyTestCase {

	private function builder(): PackBuilder {
		return ( new PackBuilder() )->sig( 0 )->sig( 1, 'suspicious' )->regex( 0, '/a/' )->regex( 1, '/b/' );
	}

	public function test_it_exposes_the_pack_and_new_signatures(): void {
		$builder = $this->builder();
		$pack    = $builder->pack();
		$new     = NewSignatures::from_array(
			[
				'version' => 1,
				'since'   => 0,
				'regex'   => [ 1 ],
				'literal' => [],
				'hash'    => false,
			]
		);

		$signatures = new Signatures( $pack, [ $builder, 'meta' ], $new );

		$this->assertSame( $pack, $signatures->pack() );
		$this->assertSame( $new, $signatures->new_signatures() );
		$this->assertSame( $pack->version(), $signatures->version() );
		$this->assertSame( 1, $signatures->version() );
	}

	public function test_meta_is_loaded_once(): void {
		$builder = $this->builder();
		$calls   = 0;
		$loader  = static function () use ( $builder, &$calls ): PackMeta {
			++$calls;
			return $builder->meta();
		};

		$signatures = new Signatures( $builder->pack(), $loader, NewSignatures::none() );

		$this->assertSame( 0, $calls );
		$first = $signatures->meta();
		$this->assertSame( $first, $signatures->meta() );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'test:1', $first->id( 1 ) );
	}

	public function test_a_meta_loader_returning_null_is_bundle_missing(): void {
		$calls      = 0;
		$signatures = new Signatures(
			$this->builder()->pack(),
			static function () use ( &$calls ): ?PackMeta {
				++$calls;
				return null;
			},
			NewSignatures::none()
		);

		foreach ( [ 1, 2 ] as $attempt ) {
			try {
				$signatures->meta();
				$this->fail( 'meta() should throw on attempt ' . $attempt );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'bundle_missing', $e->getMessage() );
			}
		}

		$this->assertSame( 1, $calls );
	}

	public function test_meta_for_another_pack_is_bundle_missing(): void {
		$other      = ( new PackBuilder() )->sig( 0 );
		$signatures = new Signatures( $this->builder()->pack(), [ $other, 'meta' ], NewSignatures::none() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'bundle_missing' );

		$signatures->meta();
	}
}
