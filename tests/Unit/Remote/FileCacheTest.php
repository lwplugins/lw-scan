<?php
/**
 * Tests for Remote\FileCache.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Remote;

use LightweightPlugins\Scan\Remote\FileCache;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class FileCacheTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
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

	public function test_put_then_get_round_trips_data(): void {
		$cache = new FileCache( $this->dir );

		$this->assertTrue( $cache->put( 'foo', [ 'a' => 1 ] ) );
		$this->assertSame( [ 'a' => 1 ], $cache->get( 'foo', 60 ) );
	}

	public function test_get_returns_null_when_missing(): void {
		$cache = new FileCache( $this->dir );

		$this->assertNull( $cache->get( 'missing', 60 ) );
	}

	public function test_get_returns_null_when_expired(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'foo', [ 'a' => 1 ] );

		touch( $cache->path( 'foo' ), time() - 100 );

		$this->assertNull( $cache->get( 'foo', 50 ) );
	}

	public function test_get_returns_null_on_invalid_json(): void {
		$cache = new FileCache( $this->dir );

		mkdir( $this->dir, 0755, true );
		file_put_contents( $cache->path( 'foo' ), 'not json{{{' );

		$this->assertNull( $cache->get( 'foo', 60 ) );
	}

	public function test_path_sanitizes_key(): void {
		$cache = new FileCache( $this->dir );

		$this->assertSame( $this->dir . '/a_b_c.json', $cache->path( 'a/b?c' ) );
	}

	public function test_delete_removes_file(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'foo', [ 'a' => 1 ] );

		$cache->delete( 'foo' );

		$this->assertNull( $cache->get( 'foo', 60 ) );
		$this->assertFileDoesNotExist( $cache->path( 'foo' ) );
	}

	public function test_delete_is_a_noop_when_file_missing(): void {
		$cache = new FileCache( $this->dir );

		$cache->delete( 'missing' );

		$this->assertFileDoesNotExist( $cache->path( 'missing' ) );
	}

	public function test_size_bytes_sums_cache_files(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'a', [ 'x' => 1 ] );
		$cache->put( 'b', [ 'x' => 2 ] );

		$expected = filesize( $cache->path( 'a' ) ) + filesize( $cache->path( 'b' ) );

		$this->assertSame( $expected, $cache->size_bytes() );
	}

	public function test_size_bytes_is_zero_when_dir_missing(): void {
		$cache = new FileCache( $this->dir );

		$this->assertSame( 0, $cache->size_bytes() );
	}

	public function test_clear_removes_all_files_and_returns_count(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'a', [ 'x' => 1 ] );
		$cache->put( 'b', [ 'x' => 2 ] );

		$count = $cache->clear();

		$this->assertSame( 2, $count );
		$this->assertNull( $cache->get( 'a', 60 ) );
		$this->assertNull( $cache->get( 'b', 60 ) );
	}

	public function test_put_creates_directory_when_missing(): void {
		$this->assertDirectoryDoesNotExist( $this->dir );

		$cache = new FileCache( $this->dir );
		$cache->put( 'foo', [ 'a' => 1 ] );

		$this->assertDirectoryExists( $this->dir );
	}

	public function test_purge_prefix_removes_only_matching_entries_and_returns_the_count(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'checksum-core-6.6.2', [ 'map' => [] ] );
		$cache->put( 'checksum-plugin-x-1.0', [ 'map' => [] ] );
		$cache->put( 'vuln-plugin-x', [ 'items' => [] ] );

		$count = $cache->purge_prefix( 'checksum-' );

		$this->assertSame( 2, $count );
		$this->assertNull( $cache->get( 'checksum-core-6.6.2', 60 ) );
		$this->assertNull( $cache->get( 'checksum-plugin-x-1.0', 60 ) );
		$this->assertSame( [ 'items' => [] ], $cache->get( 'vuln-plugin-x', 60 ) );
	}

	public function test_purge_prefix_returns_zero_when_nothing_matches(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'vuln-plugin-x', [ 'items' => [] ] );

		$this->assertSame( 0, $cache->purge_prefix( 'checksum-' ) );
		$this->assertSame( [ 'items' => [] ], $cache->get( 'vuln-plugin-x', 60 ) );
	}

	public function test_purge_prefix_returns_zero_when_dir_missing(): void {
		$cache = new FileCache( $this->dir );

		$this->assertSame( 0, $cache->purge_prefix( 'checksum-' ) );
	}

	public function test_purge_prefix_sanitizes_the_prefix_like_a_key(): void {
		$cache = new FileCache( $this->dir );
		// path() turns 'checksum/core' into 'checksum_core'; purge_prefix()
		// must apply the same mapping or it would never match its own files.
		$cache->put( 'checksum/core-6.6.2', [ 'map' => [] ] );

		$this->assertSame( 1, $cache->purge_prefix( 'checksum/' ) );
	}

	public function test_purge_prefix_with_an_empty_prefix_clears_everything(): void {
		$cache = new FileCache( $this->dir );
		$cache->put( 'checksum-core', [ 'map' => [] ] );
		$cache->put( 'vuln-plugin-x', [ 'items' => [] ] );

		$this->assertSame( 2, $cache->purge_prefix( '' ) );
	}
}
