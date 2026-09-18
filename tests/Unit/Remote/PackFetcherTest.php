<?php
/**
 * Tests for Remote\PackFetcher.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Remote;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\PackFetcher;
use LightweightPlugins\Scan\Remote\UnavailableException;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;
use Mockery;

final class PackFetcherTest extends MonkeyTestCase {

	private const NEW_VERSION = 20260901001;

	private string $dir;

	private string $pack_json;

	private string $meta_json;

	private string $pack_sha;

	private string $meta_sha;

	/**
	 * @var array<string, mixed>
	 */
	private array $state = [];

	protected function setUp(): void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/lw-scan-test-' . uniqid();

		$fixtures        = dirname( __DIR__, 2 ) . '/Fixtures';
		$this->pack_json = (string) file_get_contents( $fixtures . '/lw-pack.json' );
		$this->meta_json = (string) file_get_contents( $fixtures . '/lw-meta.json' );
		$this->pack_sha  = hash( 'sha256', $this->pack_json );
		$this->meta_sha  = hash( 'sha256', $this->meta_json );

		Functions\when( 'delete_transient' )->justReturn( true );

		PackLoader::reset();
		PackLoader::use_store( null );
	}

	protected function tearDown(): void {
		PackLoader::reset();
		PackLoader::use_store( null );
		$this->remove_dir( $this->dir );
		parent::tearDown();
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/{*,.htaccess}', GLOB_BRACE ) as $file ) {
			is_dir( $file ) ? $this->remove_dir( $file ) : unlink( $file );
		}

		rmdir( $dir );
	}

	private function store(): Store {
		return new Store( $this->dir );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function stub_state( array $state ): void {
		$this->state = $state;

		Functions\when( 'get_option' )->alias(
			function () {
				return $this->state;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value ) {
				unset( $name );
				$this->state = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				unset( $name );
				$this->state = $value;
				return true;
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pointer( int $version, ?string $pack_sha = null, ?string $meta_sha = null ): array {
		return [
			'version'         => $version,
			'format'          => 1,
			'signature_count' => 15,
			'pack'            => [
				'url'    => '/v1/pack/' . $version,
				'sha256' => $pack_sha ?? $this->pack_sha,
			],
			'meta'            => [
				'url'    => '/v1/pack/' . $version . '/meta',
				'sha256' => $meta_sha ?? $this->meta_sha,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function meta_array( PackMeta $meta ): array {
		$ids        = [];
		$names      = [];
		$categories = [];
		$kinds      = [];

		for ( $i = 0; $i < $meta->count(); $i++ ) {
			$ids[]        = $meta->id( $i );
			$names[]      = $meta->name( $i );
			$categories[] = $meta->category( $i );
			$kinds[]      = $meta->kind( $i );
		}

		return [
			'format'     => 1,
			'version'    => $meta->version(),
			'count'      => $meta->count(),
			'ids'        => $ids,
			'names'      => $names,
			'categories' => $categories,
			'kinds'      => $kinds,
		];
	}

	public function test_fresh_install_downloads_and_stores_the_pack(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();
		PackLoader::use_store( $store );

		$pointer = $this->pointer( self::NEW_VERSION );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->pack_json, 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION . '/meta', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->meta_json, 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'updated', $result['status'] );
		$this->assertSame( self::NEW_VERSION, $result['version'] );
		$this->assertTrue( $store->has_pack( self::NEW_VERSION ) );
		$this->assertSame( self::NEW_VERSION, $this->state['bundle_version'] );
		$this->assertSame( 15, $this->state['bundle_count'] );
		$this->assertArrayNotHasKey( 'bundle_format', $this->state, 'the pointer format is validated at download time; nothing reads this key back' );

		$this->assertNotNull( PackLoader::get() );
	}

	public function test_throttled_with_files_present_skips_without_an_http_call(): void {
		$store = $this->store();
		$store->ensure_dir();
		$store->write_atomic( $store->pack_path( self::NEW_VERSION ), $this->pack_json );
		$store->write_atomic( $store->meta_path( self::NEW_VERSION ), $this->meta_json );

		$this->stub_state( [ 'bundle_version' => self::NEW_VERSION, 'bundle_checked_at' => time() ] );

		$client = Mockery::mock( Client::class );
		$client->shouldNotReceive( 'get' );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'skipped', $result['status'] );
		$this->assertSame( self::NEW_VERSION, $result['version'] );
		$this->assertSame( [], $result['new_ids'] );
	}

	public function test_304_with_files_present_is_unchanged(): void {
		$store = $this->store();
		$store->ensure_dir();
		$store->write_atomic( $store->pack_path( self::NEW_VERSION ), $this->pack_json );
		$store->write_atomic( $store->meta_path( self::NEW_VERSION ), $this->meta_json );

		// A dead legacy bundle file, plus a stale older-version pack: an
		// "unchanged" outcome must sweep the storage directory too, not just
		// a real update — this is what left 9 MB of dead files on croco2.
		file_put_contents( $store->dir() . '/bundle-' . self::NEW_VERSION . '.php', '<?php return [];' );
		$store->write_atomic( $store->pack_path( self::NEW_VERSION - 1 ), '{}' );

		$this->stub_state( [ 'bundle_version' => self::NEW_VERSION, 'bundle_checked_at' => 0 ] );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 304, 'body' => '', 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'unchanged', $result['status'] );
		$this->assertSame( self::NEW_VERSION, $result['version'] );
		$this->assertArrayHasKey( 'bundle_checked_at', $this->state );
		$this->assertFileDoesNotExist( $store->dir() . '/bundle-' . self::NEW_VERSION . '.php' );
		$this->assertFileDoesNotExist( $store->pack_path( self::NEW_VERSION - 1 ) );
		$this->assertTrue( $store->has_pack( self::NEW_VERSION ) );
	}

	public function test_304_without_files_present_falls_through_and_downloads(): void {
		$store = $this->store();

		$this->stub_state( [ 'bundle_version' => self::NEW_VERSION, 'bundle_checked_at' => 0 ] );

		$pointer = $this->pointer( self::NEW_VERSION );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 304, 'body' => '', 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest' )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->pack_json, 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION . '/meta', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->meta_json, 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'updated', $result['status'] );
		$this->assertSame( self::NEW_VERSION, $result['version'] );
	}

	public function test_same_version_with_files_present_is_unchanged_without_downloading_blobs(): void {
		$store = $this->store();
		$store->ensure_dir();
		$store->write_atomic( $store->pack_path( self::NEW_VERSION ), $this->pack_json );
		$store->write_atomic( $store->meta_path( self::NEW_VERSION ), $this->meta_json );

		// Same defect as the 304 case: a same-version "unchanged" outcome
		// must sweep dead legacy bundle and stale older-version files too.
		file_put_contents( $store->dir() . '/bundle-' . self::NEW_VERSION . '.php', '<?php return [];' );
		$store->write_atomic( $store->pack_path( self::NEW_VERSION - 1 ), '{}' );

		$this->stub_state( [ 'bundle_version' => self::NEW_VERSION, 'bundle_checked_at' => 0 ] );

		$pointer = $this->pointer( self::NEW_VERSION );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [ 'etag' => '"abc"' ] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'unchanged', $result['status'] );
		$this->assertSame( self::NEW_VERSION, $result['version'] );
		$this->assertSame( '"abc"', $this->state['bundle_etag'] );
		$this->assertFileDoesNotExist( $store->dir() . '/bundle-' . self::NEW_VERSION . '.php' );
		$this->assertFileDoesNotExist( $store->pack_path( self::NEW_VERSION - 1 ) );
		$this->assertTrue( $store->has_pack( self::NEW_VERSION ) );
	}

	public function test_force_full_redownloads_the_same_version(): void {
		$store = $this->store();
		$store->ensure_dir();
		$store->write_atomic( $store->pack_path( self::NEW_VERSION ), $this->pack_json );
		$store->write_atomic( $store->meta_path( self::NEW_VERSION ), $this->meta_json );

		$this->stub_state( [ 'bundle_version' => self::NEW_VERSION, 'bundle_checked_at' => time() ] );

		$pointer = $this->pointer( self::NEW_VERSION );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', [ 'headers' => [] ] )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->pack_json, 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION . '/meta', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->meta_json, 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->force_full();

		$this->assertSame( 'updated', $result['status'] );
		$this->assertSame( self::NEW_VERSION, $result['version'] );
	}

	public function test_pointer_with_unknown_format_fails(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();

		$pointer           = $this->pointer( self::NEW_VERSION );
		$pointer['format'] = 2;

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'The signature pack uses format 2, which this version of LW Scan does not understand. Update the plugin.', $result['message'] );
		$this->assertFalse( $store->has_pack( self::NEW_VERSION ) );
	}

	public function test_absolute_pack_url_is_refused(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();

		$pointer                = $this->pointer( self::NEW_VERSION );
		$pointer['pack']['url'] = 'https://evil.example/pack';

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldNotReceive( 'get' )->with( 'https://evil.example/pack', Mockery::any() );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertFalse( $store->has_pack( self::NEW_VERSION ) );
	}

	public function test_sha256_mismatch_fails_verification(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();

		$pointer = $this->pointer( self::NEW_VERSION, str_repeat( 'a', 64 ) );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->pack_json, 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'The signature pack failed verification.', $result['message'] );
		$this->assertFalse( $store->has_pack( self::NEW_VERSION ) );
		$this->assertSame( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ], $this->state );
	}

	public function test_gzip_body_is_accepted(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();

		$gz = (string) gzencode( $this->pack_json, 6 );

		$pointer = $this->pointer( self::NEW_VERSION );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $gz, 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION . '/meta', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->meta_json, 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'updated', $result['status'] );
		$this->assertTrue( $store->has_pack( self::NEW_VERSION ) );
	}

	public function test_write_failure_leaves_state_and_files_untouched(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$blocker = sys_get_temp_dir() . '/lw-scan-test-blocker-' . uniqid();
		file_put_contents( $blocker, 'not a directory' );
		$store = new Store( $blocker );

		$pointer = $this->pointer( self::NEW_VERSION );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->pack_json, 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION . '/meta', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->meta_json, 'headers' => [] ] );

		try {
			$result = ( new PackFetcher( $client, $store ) )->check();
		} finally {
			unlink( $blocker );
		}

		$this->assertSame( 'failed', $result['status'] );
		$this->assertStringContainsString( 'wp-content/lw-scan is writable', $result['message'] );
		$this->assertSame( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ], $this->state );
	}

	public function test_remote_exception_on_latest_returns_failed_with_its_message(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andThrow( new UnavailableException( 'backend down', 503 ) );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'backend down', $result['message'] );
	}

	public function test_upgrade_from_older_version_records_new_signatures_and_prunes_old(): void {
		$old_version = 20260801001;

		$this->stub_state( [ 'bundle_version' => $old_version, 'bundle_checked_at' => 0 ] );

		$store = $this->store();
		$store->ensure_dir();

		$old_meta = ( new PackBuilder() )->sig( 0 )->sig( 1 )->meta();
		$store->write_atomic( $store->pack_path( $old_version ), '{}' );
		$store->write_atomic( $store->meta_path( $old_version ), (string) json_encode( $this->meta_array( $old_meta ) ) );

		$this->assertTrue( is_file( $store->pack_path( $old_version ) ) );

		$pointer = $this->pointer( self::NEW_VERSION );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->pack_json, 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION . '/meta', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->meta_json, 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'updated', $result['status'] );
		$this->assertNotEmpty( $result['new_ids'] );
		$this->assertTrue( is_file( $store->new_path( self::NEW_VERSION ) ) );
		$this->assertFalse( is_file( $store->pack_path( $old_version ) ), 'the old pack file is pruned' );
	}

	public function test_pointer_version_mismatching_the_packs_own_version_fails(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();

		// The fixture's own embedded `version` is self::NEW_VERSION; the
		// pointer claims a different one — sha256 still matches (the body
		// is untouched), so only the version cross-check can catch this.
		$mismatched_version = self::NEW_VERSION + 1;
		$pointer             = $this->pointer( $mismatched_version );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . $mismatched_version, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->pack_json, 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . $mismatched_version . '/meta', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->meta_json, 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'The signature pack failed verification.', $result['message'] );
		$this->assertFalse( $store->has_pack( $mismatched_version ) );
		$this->assertSame( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ], $this->state );
	}

	public function test_a_pack_body_that_fails_to_load_is_rejected_even_when_its_sha256_matches(): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();

		// A body whose own sha256 verifies fine, but that PackIntegrity
		// rejects: regex_count no longer matches the length of the binary
		// sections it counts.
		$corrupt = json_decode( $this->pack_json, true );
		$this->assertIsArray( $corrupt );
		$corrupt['regex_count'] += 1;
		$corrupt_body = (string) json_encode( $corrupt );
		$corrupt_sha  = hash( 'sha256', $corrupt_body );

		$pointer = $this->pointer( self::NEW_VERSION, $corrupt_sha );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION, Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $corrupt_body, 'headers' => [] ] );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/' . self::NEW_VERSION . '/meta', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => $this->meta_json, 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'The signature pack failed verification.', $result['message'] );
		$this->assertFalse( $store->has_pack( self::NEW_VERSION ) );
		$this->assertSame( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ], $this->state );
	}

	/**
	 * @dataProvider provide_malformed_pointers
	 */
	public function test_malformed_pointer_variants_fail( callable $mutate ): void {
		$this->stub_state( [ 'bundle_version' => 0, 'bundle_checked_at' => 0 ] );

		$store = $this->store();

		$pointer = $mutate( $this->pointer( self::NEW_VERSION ) );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )->once()
			->with( '/v1/pack/latest', Mockery::any() )
			->andReturn( [ 'code' => 200, 'body' => (string) json_encode( $pointer ), 'headers' => [] ] );

		$result = ( new PackFetcher( $client, $store ) )->check();

		$this->assertSame( 'failed', $result['status'] );
	}

	/**
	 * @return array<string, array{0:callable}>
	 */
	public static function provide_malformed_pointers(): array {
		return [
			'missing signature_count' => [
				static function ( array $pointer ): array {
					unset( $pointer['signature_count'] );
					return $pointer;
				},
			],
			'pack is not an object'   => [
				static function ( array $pointer ): array {
					$pointer['pack'] = '/v1/pack/1';
					return $pointer;
				},
			],
			'uppercase sha256'        => [
				static function ( array $pointer ): array {
					$pointer['pack']['sha256'] = strtoupper( $pointer['pack']['sha256'] );
					return $pointer;
				},
			],
			'63-char sha256'          => [
				static function ( array $pointer ): array {
					$pointer['pack']['sha256'] = substr( $pointer['pack']['sha256'], 0, 63 );
					return $pointer;
				},
			],
			'protocol-relative url'   => [
				static function ( array $pointer ): array {
					$pointer['pack']['url'] = '//evil.example/pack';
					return $pointer;
				},
			],
		];
	}
}
