<?php
/**
 * Tests for Remote\ChecksumProvider.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Remote;

use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\ChecksumProvider;
use LightweightPlugins\Scan\Remote\FileCache;
use LightweightPlugins\Scan\Remote\NotFoundException;
use LightweightPlugins\Scan\Remote\UnavailableException;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use Mockery;

final class ChecksumProviderTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		ChecksumProvider::reset_requests();
		$this->dir = sys_get_temp_dir() . '/lw-scan-test-' . uniqid();
	}

	protected function tearDown(): void {
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

	public function test_core_returns_path_to_md5_map_on_success(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/checksums/core/6.6.2' )
			->andReturn(
				[
					'code'    => 200,
					'body'    => '{"checksums":{"wp-login.php":"abc123"}}',
					'headers' => [],
				]
			);

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertSame( [ 'wp-login.php' => [ 'abc123' ] ], $provider->core( '6.6.2' ) );
		$this->assertSame( 1, ChecksumProvider::$requests );
	}

	public function test_core_caches_result_and_does_not_request_again(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->andReturn(
				[
					'code'    => 200,
					'body'    => '{"checksums":{"wp-login.php":"abc123"}}',
					'headers' => [],
				]
			);

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$provider->core( '6.6.2' );
		$second = $provider->core( '6.6.2' );

		$this->assertSame( [ 'wp-login.php' => [ 'abc123' ] ], $second );
		$this->assertSame( 1, ChecksumProvider::$requests );
	}

	public function test_plugin_returns_path_to_md5_map_from_files_field(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/checksums/plugin/akismet/5.3' )
			->andReturn(
				[
					'code'    => 200,
					'body'    => '{"plugin":"akismet","version":"5.3","files":{"akismet.php":{"md5":"deadbeef","sha256":"..."}}}',
					'headers' => [],
				]
			);

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertSame( [ 'akismet.php' => [ 'deadbeef' ] ], $provider->plugin( 'akismet', '5.3' ) );
	}

	public function test_theme_returns_path_to_md5_map_from_files_field(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/checksums/theme/twentytwentyfive/1.2' )
			->andReturn(
				[
					'code'    => 200,
					'body'    => '{"theme":"twentytwentyfive","version":"1.2","files":{"style.css":{"md5":"cafef00d"}}}',
					'headers' => [],
				]
			);

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertSame( [ 'style.css' => [ 'cafef00d' ] ], $provider->theme( 'twentytwentyfive', '1.2' ) );
	}

	public function test_returns_null_and_caches_missing_on_404(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->andThrow( new NotFoundException( 'not found', 404 ) );

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertNull( $provider->plugin( 'ghost-plugin', '1.0' ) );

		// Second call within the 24h window must hit the cached miss marker,
		// not issue a second request.
		$this->assertNull( $provider->plugin( 'ghost-plugin', '1.0' ) );
		$this->assertSame( 1, ChecksumProvider::$requests );
	}

	public function test_returns_null_without_caching_on_unavailable(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->twice()
			->andThrow( new UnavailableException( 'down', 503 ) );

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertNull( $provider->core( '6.6.2' ) );
		// Not cached: a second call issues a second request.
		$this->assertNull( $provider->core( '6.6.2' ) );
		$this->assertSame( 2, ChecksumProvider::$requests );
	}

	public function test_returns_null_for_invalid_slug_without_requesting(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldNotReceive( 'get' );

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertNull( $provider->plugin( 'UPPER CASE!', '1.0' ) );
		$this->assertSame( 0, ChecksumProvider::$requests );
	}

	public function test_returns_null_for_invalid_version_without_requesting(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldNotReceive( 'get' );

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertNull( $provider->core( 'not a version!' ) );
		$this->assertSame( 0, ChecksumProvider::$requests );
	}

	public function test_plugin_keeps_every_md5_when_the_backend_returns_a_list(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->andReturn(
				[
					'code'    => 200,
					'body'    => '{"files":{"readme.txt":{"md5":["aaa111","bbb222"]},"seopress.php":{"md5":"ccc333"}}}',
					'headers' => [],
				]
			);

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		// A file that shipped with several valid contents carries an array of
		// md5s; casting it to string produced the literal "Array" plus a PHP
		// warning, and the file was reported as tampered with.
		$this->assertSame(
			[
				'readme.txt'    => [ 'aaa111', 'bbb222' ],
				'seopress.php'  => [ 'ccc333' ],
			],
			$provider->plugin( 'wp-seopress', '10.2' )
		);
	}

	public function test_core_keeps_every_md5_when_the_backend_returns_a_list(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->andReturn(
				[
					'code'    => 200,
					'body'    => '{"checksums":{"readme.html":["aaa111","bbb222"],"wp-login.php":"abc123"}}',
					'headers' => [],
				]
			);

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertSame(
			[
				'readme.html'  => [ 'aaa111', 'bbb222' ],
				'wp-login.php' => [ 'abc123' ],
			],
			$provider->core( '6.6.2' )
		);
	}

	public function test_non_string_checksums_are_dropped_instead_of_cast(): void {
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->andReturn(
				[
					'code'    => 200,
					'body'    => '{"files":{"a.php":{"md5":12345},"b.php":{"md5":true},"c.php":{"md5":[]},"d.php":{"md5":["ok111",99]}}}',
					'headers' => [],
				]
			);

		$provider = new ChecksumProvider( $client, new FileCache( $this->dir ) );

		$this->assertSame( [ 'd.php' => [ 'ok111' ] ], $provider->plugin( 'garbage', '1.0' ) );
	}

	public function test_a_cached_map_from_an_older_release_is_normalised_on_read(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'checksum-core-6.6.2', [ 'map' => [ 'wp-login.php' => 'abc123' ] ] );

		$client = Mockery::mock( Client::class );
		$client->shouldNotReceive( 'get' );

		$provider = new ChecksumProvider( $client, $cache );

		$this->assertSame( [ 'wp-login.php' => [ 'abc123' ] ], $provider->core( '6.6.2' ) );
	}

	public function test_stale_missing_marker_is_refetched(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'checksum-core-6.6.2', [ 'missing' => true, 'until' => time() - 10 ] );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->andReturn(
				[
					'code'    => 200,
					'body'    => '{"checksums":{"wp-login.php":"abc123"}}',
					'headers' => [],
				]
			);

		$provider = new ChecksumProvider( $client, $cache );

		$this->assertSame( [ 'wp-login.php' => [ 'abc123' ] ], $provider->core( '6.6.2' ) );
	}
}
