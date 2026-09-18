<?php
/**
 * Tests for Bundle\Store.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Bundle;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class StoreTest extends MonkeyTestCase {

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

		foreach ( (array) glob( $dir . '/.htaccess' ) as $file ) {
			unlink( $file );
		}

		rmdir( $dir );
	}

	public function test_dir_returns_the_configured_directory(): void {
		$store = new Store( $this->dir );

		$this->assertSame( $this->dir, $store->dir() );
	}

	public function test_dir_strips_trailing_slash(): void {
		$store = new Store( $this->dir . '/' );

		$this->assertSame( $this->dir, $store->dir() );
	}

	public function test_constructor_defaults_to_lw_scan_storage_dir(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'lw_scan_storage_dir', WP_CONTENT_DIR . '/lw-scan' )
			->andReturn( $this->dir );

		$store = new Store();

		$this->assertSame( $this->dir, $store->dir() );
	}

	public function test_ensure_dir_creates_directory(): void {
		$this->assertDirectoryDoesNotExist( $this->dir );

		$store = new Store( $this->dir );

		$this->assertTrue( $store->ensure_dir() );
		$this->assertDirectoryExists( $this->dir );
	}

	public function test_ensure_dir_writes_index_php_guard(): void {
		$store = new Store( $this->dir );
		$store->ensure_dir();

		$this->assertFileExists( $this->dir . '/index.php' );
		$this->assertSame( '<?php // Silence is golden.', trim( (string) file_get_contents( $this->dir . '/index.php' ) ) );
	}

	public function test_ensure_dir_writes_htaccess_deny_all(): void {
		$store = new Store( $this->dir );
		$store->ensure_dir();

		$contents = (string) file_get_contents( $this->dir . '/.htaccess' );

		$this->assertStringContainsString( 'Require all denied', $contents );
		$this->assertStringContainsString( 'Deny from all', $contents );
	}

	public function test_ensure_dir_does_not_overwrite_existing_index_php(): void {
		mkdir( $this->dir, 0755, true );
		file_put_contents( $this->dir . '/index.php', '<?php // custom' );

		$store = new Store( $this->dir );
		$store->ensure_dir();

		$this->assertSame( '<?php // custom', file_get_contents( $this->dir . '/index.php' ) );
	}

	public function test_ensure_dir_returns_writable_state(): void {
		$store = new Store( $this->dir );

		$this->assertTrue( $store->ensure_dir() );
		$this->assertTrue( $store->is_writable() );
	}

	public function test_is_writable_is_false_when_dir_does_not_exist(): void {
		$store = new Store( $this->dir );

		$this->assertFalse( $store->is_writable() );
	}

	public function test_pack_path_format(): void {
		$store = new Store( $this->dir );

		$this->assertSame( $this->dir . '/pack-20260101001.json', $store->pack_path( 20260101001 ) );
	}

	public function test_meta_path_format(): void {
		$store = new Store( $this->dir );

		$this->assertSame( $this->dir . '/meta-20260101001.json', $store->meta_path( 20260101001 ) );
	}

	public function test_prune_except_removes_other_versions_including_legacy_bundle_files(): void {
		$store = new Store( $this->dir );
		mkdir( $this->dir, 0755, true );

		$store->write_atomic( $store->pack_path( 1 ), '{"a":1}' );
		$store->write_atomic( $store->meta_path( 1 ), '{"a":1}' );
		$store->write_atomic( $store->pack_path( 2 ), '{"a":2}' );
		$store->write_atomic( $store->meta_path( 2 ), '{"a":2}' );

		// A leftover from a plugin version predating the signature pack: not
		// written by anything any more, but still swept up on upgrade.
		file_put_contents( $this->dir . '/bundle-1.php', '<?php return [];' );
		file_put_contents( $this->dir . '/bundle-1.json.gz', 'x' );

		$removed = $store->prune_except( 2 );

		$this->assertSame( 4, $removed );
		$this->assertFalse( $store->has_pack( 1 ) );
		$this->assertFileDoesNotExist( $this->dir . '/bundle-1.php' );
		$this->assertFileDoesNotExist( $this->dir . '/bundle-1.json.gz' );
		$this->assertTrue( $store->has_pack( 2 ) );
	}

	public function test_prune_except_removes_legacy_bundle_files_even_at_the_kept_version(): void {
		// Nothing writes or reads bundle-<v>.php / bundle-<v>.json.gz any
		// more, regardless of version — a site that upgrades while the
		// backend still serves the same version number must not keep
		// carrying this legacy compiled bundle forever.
		$store = new Store( $this->dir );
		mkdir( $this->dir, 0755, true );

		file_put_contents( $this->dir . '/bundle-7.php', '<?php return [];' );
		file_put_contents( $this->dir . '/bundle-7.json.gz', 'x' );
		$store->write_atomic( $store->pack_path( 7 ), '{"a":7}' );
		$store->write_atomic( $store->meta_path( 7 ), '{"a":7}' );
		$store->write_atomic( $store->new_path( 7 ), '{"a":7}' );
		$store->write_atomic( $store->pack_path( 6 ), '{"a":6}' );
		file_put_contents( $this->dir . '/stray.tmp', 'x' );

		$removed = $store->prune_except( 7 );

		$this->assertSame( 4, $removed );
		$this->assertEqualsCanonicalizing(
			[ $store->pack_path( 7 ), $store->meta_path( 7 ), $store->new_path( 7 ) ],
			glob( $this->dir . '/{pack,meta,new}-*', GLOB_BRACE )
		);
		$this->assertFileDoesNotExist( $this->dir . '/bundle-7.php' );
		$this->assertFileDoesNotExist( $this->dir . '/bundle-7.json.gz' );
	}

	public function test_prune_except_returns_zero_when_nothing_to_remove(): void {
		$store = new Store( $this->dir );
		$store->write_atomic( $store->pack_path( 2 ), '{"a":2}' );
		$store->write_atomic( $store->meta_path( 2 ), '{"a":2}' );

		$this->assertSame( 0, $store->prune_except( 2 ) );
	}

	public function test_size_bytes_sums_stored_files(): void {
		$store = new Store( $this->dir );
		$store->ensure_dir();
		$store->write_atomic( $store->pack_path( 1 ), '{"a":1}' );
		$store->write_atomic( $store->meta_path( 1 ), '{"a":1}' );

		$expected = filesize( $store->pack_path( 1 ) ) + filesize( $store->meta_path( 1 ) )
			+ filesize( $this->dir . '/index.php' ) + filesize( $this->dir . '/.htaccess' );

		$this->assertSame( $expected, $store->size_bytes() );
	}

	public function test_size_bytes_is_zero_when_dir_missing(): void {
		$store = new Store( $this->dir );

		$this->assertSame( 0, $store->size_bytes() );
	}

	public function test_cache_dir_returns_cache_subdirectory(): void {
		$store = new Store( $this->dir );

		$this->assertSame( $this->dir . '/cache', $store->cache_dir() );
	}

	public function test_cache_dir_creates_the_directory(): void {
		$store = new Store( $this->dir );

		$this->assertDirectoryDoesNotExist( $this->dir . '/cache' );

		$store->cache_dir();

		$this->assertDirectoryExists( $this->dir . '/cache' );
	}

	public function test_pack_files_round_trip_and_prune_old_versions(): void {
		$store = new Store( $this->dir );

		$this->assertFalse( $store->has_pack( 2 ) );
		$this->assertTrue( $store->write_atomic( $store->pack_path( 2 ), '{"p":1}' ) );
		$this->assertFalse( $store->has_pack( 2 ), 'meta is still missing' );
		$this->assertTrue( $store->write_atomic( $store->meta_path( 2 ), '{"m":1}' ) );
		$this->assertTrue( $store->has_pack( 2 ) );
		$this->assertSame( [], glob( $this->dir . '/*.tmp' ) );

		file_put_contents( $this->dir . '/bundle-1.php', '<?php' );
		file_put_contents( $this->dir . '/bundle-1.json.gz', 'x' );
		file_put_contents( $store->pack_path( 1 ), '{}' );
		file_put_contents( $store->new_path( 1 ), '{}' );
		$store->write_atomic( $store->new_path( 2 ), '{}' );

		$this->assertSame( 4, $store->prune_except( 2 ) );
		$this->assertTrue( $store->has_pack( 2 ) );
		$this->assertFileExists( $store->new_path( 2 ) );

		$store->delete_version( 2 );
		$this->assertFalse( $store->has_pack( 2 ) );
		$this->assertFileDoesNotExist( $store->new_path( 2 ) );
	}

	public function test_write_atomic_fails_cleanly_outside_a_writable_dir(): void {
		$store = new Store( $this->dir . '/missing/deeper' );

		$this->assertFalse( @$store->write_atomic( $this->dir . '/missing/deeper/pack-1.json', 'x' ) );
	}

	public function test_write_atomic_does_not_collide_with_another_writers_fixed_tmp_name(): void {
		// Before the fix, every writer targeted the same "$path.tmp" name:
		// two overlapping fetches for the same version would share one tmp
		// file and could interleave through it. A file already sitting at
		// that old fixed name (as if another writer's in-flight tmp file)
		// must be left completely alone by this call.
		$store = new Store( $this->dir );
		$store->ensure_dir();

		$legacy_tmp = $store->pack_path( 1 ) . '.tmp';
		file_put_contents( $legacy_tmp, 'a concurrent writer, mid-flight' );

		$this->assertTrue( $store->write_atomic( $store->pack_path( 1 ), '{"p":1}' ) );

		$this->assertSame( '{"p":1}', file_get_contents( $store->pack_path( 1 ) ), 'this writer wrote its own data' );
		$this->assertSame( 'a concurrent writer, mid-flight', file_get_contents( $legacy_tmp ), "the other writer's tmp file was not touched or consumed" );
	}

	public function test_prune_except_removes_stray_tmp_files(): void {
		$store = new Store( $this->dir );
		$store->ensure_dir();

		file_put_contents( $store->pack_path( 2 ) . '.12345.abcdef.tmp', 'partial' );

		$removed = $store->prune_except( 2 );

		$this->assertSame( 1, $removed );
		$this->assertSame( [], glob( $this->dir . '/*.tmp' ) );
	}
}
