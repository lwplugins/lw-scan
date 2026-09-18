<?php
/**
 * Tests for Bundle\PackLoader.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Bundle;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class PackLoaderTest extends MonkeyTestCase {

	private string $dir;

	private string $fixtures;

	private Store $store;

	/**
	 * @var array<string, mixed>
	 */
	private array $state = [];

	protected function setUp(): void {
		parent::setUp();

		$this->dir      = sys_get_temp_dir() . '/lw-scan-test-' . uniqid();
		$this->fixtures = dirname( __DIR__, 2 ) . '/Fixtures';

		mkdir( $this->dir, 0755, true );

		$this->store = new Store( $this->dir );

		Functions\when( 'get_option' )->alias(
			function () {
				return $this->state;
			}
		);

		PackLoader::use_store( $this->store );
	}

	protected function tearDown(): void {
		PackLoader::use_store( null );
		$this->remove_dir( $this->dir );
		parent::tearDown();
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			is_dir( $file ) ? $this->remove_dir( $file ) : unlink( $file );
		}

		rmdir( $dir );
	}

	public function test_loads_the_fixture_pack_for_the_stored_version(): void {
		$this->state = [ 'bundle_version' => 20260901001 ];
		copy( $this->fixtures . '/lw-pack.json', $this->store->pack_path( 20260901001 ) );
		copy( $this->fixtures . '/lw-meta.json', $this->store->meta_path( 20260901001 ) );

		$pack = PackLoader::get();

		$this->assertNotNull( $pack );
		$this->assertSame( 20260901001, $pack->version() );
		$this->assertSame( $pack, PackLoader::get(), 'memoized' );
		$this->assertSame( 'lw:0001', PackLoader::meta()->id( 0 ) );
		$this->assertTrue( PackLoader::new_signatures()->is_empty() );
		$this->assertSame( 20260901001, PackLoader::signatures()->version() );
	}

	public function test_null_for_missing_corrupt_or_mismatched_files(): void {
		$this->state = [ 'bundle_version' => 0 ];
		$this->assertNull( PackLoader::get() );

		PackLoader::reset();
		$this->state = [ 'bundle_version' => 7 ];
		$this->assertNull( PackLoader::get() );
		$this->assertNull( PackLoader::signatures() );

		PackLoader::reset();
		file_put_contents( $this->store->pack_path( 7 ), '{not json' );
		$this->assertNull( PackLoader::get() );

		PackLoader::reset();
		copy( $this->fixtures . '/lw-pack.json', $this->store->pack_path( 7 ) ); // fixture says version 20260901001
		$this->assertNull( PackLoader::get(), 'a file whose version differs from state is not trusted' );
	}

	public function test_new_signatures_come_from_the_new_file(): void {
		$this->state = [ 'bundle_version' => 20260901001 ];
		file_put_contents( $this->store->new_path( 20260901001 ), (string) json_encode( [ 'version' => 20260901001, 'since' => 20260801001, 'regex' => [ 3 ], 'literal' => [], 'hash' => false ] ) );

		$this->assertTrue( PackLoader::new_signatures()->has_regex( 3 ) );
	}
}
