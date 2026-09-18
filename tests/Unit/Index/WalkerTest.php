<?php
/**
 * Tests for Index\Walker.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Index;

use Brain\Monkey\Filters;
use LightweightPlugins\Scan\Index\Walker;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class WalkerTest extends MonkeyTestCase {

	private string $dir;

	/** @var string What the `lw_scan_storage_dir` filter answers for this test. */
	private string $storage_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/lw-scan-test-' . uniqid();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		// The walker asks where the plugin's storage directory is rather than
		// assuming `wp-content/lw-scan`, so the tests have to answer.
		$this->storage_dir = ABSPATH . 'wp-content/lw-scan';
		$storage           = &$this->storage_dir;

		Filters\expectApplied( 'lw_scan_storage_dir' )->zeroOrMoreTimes()->andReturnUsing(
			static function () use ( &$storage ): string {
				return $storage;
			}
		);
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->dir );
		parent::tearDown();
	}

	private function remove_dir( string $dir ): void {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			unlink( $dir );
			return;
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$this->remove_dir( $dir . '/' . $entry );
		}

		rmdir( $dir );
	}

	private function write_file( string $rel ): void {
		$path = $this->dir . '/' . $rel;
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		file_put_contents( $path, 'x' );
	}

	/**
	 * @param Walker $walker Walker under test.
	 * @return array<int,string>
	 */
	private function paths( Walker $walker ): array {
		$paths = [];

		foreach ( $walker->walk() as $entry ) {
			$paths[] = $entry['path'];
		}

		sort( $paths );

		return $paths;
	}

	public function test_walk_yields_files_and_skips_excluded_paths(): void {
		$this->write_file( 'a/b/x.php' );
		$this->write_file( 'wp-content/cache/y.php' );
		$this->write_file( 'wp-content/lw-scan/z.php' );
		$this->write_file( 'node_modules/m.js' );
		$this->write_file( '.git/config' );
		$this->write_file( 'keep/k.txt' );

		$walker = new Walker( $this->dir, '', [ 'wp-content/cache' ] );

		$this->assertSame( [ 'a/b/x.php', 'keep/k.txt' ], $this->paths( $walker ) );
	}

	public function test_walk_skips_the_storage_directory_wherever_the_site_put_it(): void {
		// A site that moves the storage directory with `lw_scan_storage_dir`
		// must not have the signature bundle scanned as if it were site
		// content — and `wp-content/lw-scan`, where nothing lives any more,
		// is ordinary content again.
		$this->storage_dir = ABSPATH . 'wp-content/uploads/private/scan-store';

		$this->write_file( 'wp-content/uploads/private/scan-store/bundle-7.php' );
		$this->write_file( 'wp-content/lw-scan/z.php' );
		$this->write_file( 'keep/k.txt' );

		$walker = new Walker( $this->dir, '', [] );

		$this->assertSame( [ 'keep/k.txt', 'wp-content/lw-scan/z.php' ], $this->paths( $walker ) );
	}

	public function test_walk_excludes_always_excluded_segments_at_any_depth(): void {
		$this->write_file( 'deep/nested/node_modules/m.js' );
		$this->write_file( 'deep/nested/.git/config' );
		$this->write_file( 'deep/nested/keep.txt' );

		$walker = new Walker( $this->dir, '', [] );

		$this->assertSame( [ 'deep/nested/keep.txt' ], $this->paths( $walker ) );
	}

	public function test_walk_yields_size_and_mtime(): void {
		$this->write_file( 'a.txt' );

		$walker = new Walker( $this->dir, '', [] );
		$entries = iterator_to_array( $walker->walk(), false );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'a.txt', $entries[0]['path'] );
		$this->assertSame( filesize( $this->dir . '/a.txt' ), $entries[0]['size'] );
		$this->assertSame( filemtime( $this->dir . '/a.txt' ), $entries[0]['mtime'] );
	}

	public function test_walk_returns_nothing_for_missing_root(): void {
		$walker = new Walker( $this->dir . '/does-not-exist', '', [] );

		$this->assertSame( [], $this->paths( $walker ) );
	}

	public function test_walk_prefixes_rel_base_for_path_scope(): void {
		$this->write_file( 'wp-content/uploads/a.txt' );

		$walker = new Walker( $this->dir . '/wp-content/uploads', 'wp-content/uploads', [] );

		$this->assertSame( [ 'wp-content/uploads/a.txt' ], $this->paths( $walker ) );
	}

	public function test_walk_skips_symlinked_directory_by_default(): void {
		$this->write_file( 'real/inside.txt' );
		symlink( $this->dir . '/real', $this->dir . '/link' );

		$walker = new Walker( $this->dir, '', [] );

		$this->assertSame( [ 'real/inside.txt' ], $this->paths( $walker ) );
	}

	public function test_walk_follows_symlinked_directory_when_enabled(): void {
		$this->write_file( 'real/inside.txt' );
		symlink( $this->dir . '/real', $this->dir . '/link' );

		$walker = new Walker( $this->dir, '', [], true );

		$this->assertSame( [ 'link/inside.txt', 'real/inside.txt' ], $this->paths( $walker ) );
	}

	public function test_is_excluded_matches_directory_itself_and_descendants(): void {
		$this->assertTrue( Walker::is_excluded( 'wp-content/cache', [ 'wp-content/cache' ] ) );
		$this->assertTrue( Walker::is_excluded( 'wp-content/cache/y.php', [ 'wp-content/cache' ] ) );
	}

	public function test_is_excluded_matches_wildcard_pattern(): void {
		$this->assertTrue( Walker::is_excluded( 'wp-content/backup-2026/x', [ 'wp-content/backup*' ] ) );
	}

	public function test_is_excluded_is_case_insensitive(): void {
		$this->assertTrue( Walker::is_excluded( 'WP-CONTENT/CACHE/y.php', [ 'wp-content/cache' ] ) );
	}

	public function test_is_excluded_returns_false_when_nothing_matches(): void {
		$this->assertFalse( Walker::is_excluded( 'keep/k.txt', [ 'wp-content/cache' ] ) );
	}
}
