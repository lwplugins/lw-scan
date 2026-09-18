<?php
/**
 * Tests for Index\FileIndexer.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Index;

use Brain\Monkey\Filters;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Index\FileIndexer;
use LightweightPlugins\Scan\Index\KnownGoodInterface;
use LightweightPlugins\Scan\Index\Walker;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use Mockery;

/**
 * `Walker`, `FilesRepository`, `FindingsRepository` and `KnownGood` are all
 * `final`, so none of them can be Mockery-mocked directly. FileIndexer
 * type-hints `Db\FilesRepositoryInterface`, `Db\FindingsRepositoryInterface`
 * and `Index\KnownGoodInterface` for exactly this reason, which these tests
 * mock; `Walker` has no such seam (spec gives it no interface), so
 * stat_pass() tests use a real Walker over a real temp directory instead.
 */
final class FileIndexerTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		// A real Walker resolves the storage directory it must never enter
		// when it is built, so the filter behind it has to answer here.
		Filters\expectApplied( 'lw_scan_storage_dir' )->zeroOrMoreTimes()->andReturn( ABSPATH . 'wp-content/lw-scan' );

		$this->dir = 'fileindexer-test-' . uniqid();
		mkdir( ABSPATH . $this->dir, 0755, true );
	}

	protected function tearDown(): void {
		$this->remove_dir( ABSPATH . $this->dir );
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

	/**
	 * @param string $content File content.
	 * @param string $ext     File extension (no dot).
	 * @return string The ABSPATH-relative path, rooted under $this->dir.
	 */
	private function write_file( string $content, string $ext = 'php' ): string {
		$rel  = $this->dir . '/' . uniqid( 'file', true ) . '.' . $ext;
		$path = ABSPATH . $rel;
		$sub  = dirname( $path );

		if ( ! is_dir( $sub ) ) {
			mkdir( $sub, 0755, true );
		}

		file_put_contents( $path, $content );

		return $rel;
	}

	/**
	 * @return array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>
	 */
	private function software(): array {
		return [
			[
				'kind'    => 'core',
				'slug'    => 'wordpress',
				'version' => '6.6.2',
				'name'    => 'WordPress',
				'active'  => true,
			],
		];
	}

	/**
	 * @return array{0:FilesRepositoryInterface, 1:KnownGoodInterface, 2:FindingsRepositoryInterface}
	 */
	private function mocks(): array {
		return [
			Mockery::mock( FilesRepositoryInterface::class ),
			Mockery::mock( KnownGoodInterface::class ),
			Mockery::mock( FindingsRepositoryInterface::class ),
		];
	}

	// -- stat_pass() -----------------------------------------------------

	public function test_stat_pass_counts_indexed_and_unreadable_and_upserts_a_batch(): void {
		[ $files, $known_good, $findings ] = $this->mocks();

		$this->write_file( '<?php echo 1;' );
		// A symlink to a nonexistent target: Walker's stat() catches the
		// getSize()/getMTime() failure and yields size 0 plus an unreadable
		// flag for it.
		symlink( ABSPATH . $this->dir . '/does-not-exist', ABSPATH . $this->dir . '/broken.php' );

		$captured = [];
		$files->shouldReceive( 'upsert_stat_batch' )
			->once()
			->with(
				Mockery::on(
					function ( array $rows ) use ( &$captured ): bool {
						$captured = $rows;
						return true;
					}
				),
				7
			);

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$walker  = new Walker( ABSPATH . $this->dir, $this->dir, [] );

		$result = $indexer->stat_pass( $walker, 7 );

		$this->assertSame( [ 'indexed' => 2, 'unreadable' => 1 ], $result );
		$this->assertCount( 2, $captured );

		$broken = current(
			array_filter( $captured, static fn( array $row ): bool => false !== strpos( $row['path'], 'broken.php' ) )
		);
		$this->assertSame( 0, $broken['size'], 'the files table stores size unsigned; a negative sentinel fails the whole batch insert under strict mode' );
		$this->assertArrayHasKey( 'path_signal', $broken );
		$this->assertArrayHasKey( 'origin', $broken );
	}

	public function test_stat_pass_flushes_in_batches_of_200(): void {
		[ $files, $known_good, $findings ] = $this->mocks();

		for ( $i = 0; $i < 250; $i++ ) {
			file_put_contents( ABSPATH . $this->dir . "/file{$i}.php", '<?php' );
		}

		$batch_sizes = [];
		$files->shouldReceive( 'upsert_stat_batch' )
			->twice()
			->with(
				Mockery::on(
					function ( array $rows ) use ( &$batch_sizes ): bool {
						$batch_sizes[] = count( $rows );
						return true;
					}
				),
				1
			);

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$walker  = new Walker( ABSPATH . $this->dir, $this->dir, [] );

		$result = $indexer->stat_pass( $walker, 1 );

		$this->assertSame( [ 'indexed' => 250, 'unreadable' => 0 ], $result );
		sort( $batch_sizes );
		$this->assertSame( [ 50, 200 ], $batch_sizes );
	}

	// -- hash_pass(): kinds and hashes ------------------------------------

	public function test_hash_pass_hashes_a_php_file_and_matches_md5_and_sha256(): void {
		[ $files, $known_good, $findings ] = $this->mocks();

		$rel = $this->write_file( "<?php\necho 'hi';\n" );
		$abs = ABSPATH . $rel;

		$known_good->shouldReceive( 'check' )->once()->with( $rel, 'other', md5_file( $abs ) )->andReturn(
			[
				'status'   => 'unknown',
				'expected' => '',
				'package'  => '',
				'version'  => '',
			]
		);
		$known_good->shouldReceive( 'core_paths' )->once()->andReturn( null );

		$files->shouldReceive( 'next_unhashed' )->once()->with( 0, 10 )->andReturn(
			[
				[
					'id'     => 5,
					'path'   => $rel,
					'size'   => 20,
					'origin' => 'other',
				],
			]
		);

		$updated = [];
		$files->shouldReceive( 'update_hashed' )
			->once()
			->with(
				5,
				Mockery::on(
					function ( array $fields ) use ( &$updated ): bool {
						$updated = $fields;
						return true;
					}
				)
			);

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 10, static fn(): bool => false );

		$this->assertSame( 'php', $updated['kind'] );
		$this->assertSame( md5_file( $abs ), $updated['md5'] );
		$this->assertSame( hash_file( 'sha256', $abs ), $updated['sha256'] );
		$this->assertSame( 0, $updated['known_good'] );
		$this->assertSame(
			[
				'last_id'    => 5,
				'hashed'     => 1,
				'known_good' => 0,
				'integrity'  => 0,
				'unreadable' => 0,
				'done'       => true,
			],
			$result
		);
	}

	public function test_hash_pass_marks_binary_content_as_binary_kind(): void {
		[ $files, $known_good, $findings ] = $this->mocks();

		// PNG signature bytes followed by filler, well over the 30% control-byte ratio.
		$binary = "\x89PNG\x0d\x0a\x1a\x0a" . str_repeat( "\x00\x01\x02\x03", 200 );
		$rel    = $this->write_file( $binary, 'png' );
		$abs    = ABSPATH . $rel;

		$known_good->shouldReceive( 'check' )->once()->andReturn(
			[
				'status'   => 'unknown',
				'expected' => '',
				'package'  => '',
				'version'  => '',
			]
		);
		$known_good->shouldReceive( 'core_paths' )->once()->andReturn( null );

		$files->shouldReceive( 'next_unhashed' )->once()->andReturn(
			[
				[
					'id'     => 9,
					'path'   => $rel,
					'size'   => strlen( $binary ),
					'origin' => 'other',
				],
			]
		);

		$updated = [];
		$files->shouldReceive( 'update_hashed' )->once()->with(
			9,
			Mockery::on(
				function ( array $fields ) use ( &$updated ): bool {
					$updated = $fields;
					return true;
				}
			)
		);

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$indexer->hash_pass( 0, 10, static fn(): bool => false );

		$this->assertSame( 'binary', $updated['kind'] );
		$this->assertSame( md5_file( $abs ), $updated['md5'] );
		$this->assertSame( hash_file( 'sha256', $abs ), $updated['sha256'] );
	}

	public function test_hash_pass_marks_a_file_that_is_no_longer_there_as_unreadable(): void {
		[ $files, $known_good, $findings ] = $this->mocks();
		$known_good->shouldNotReceive( 'check' );
		$known_good->shouldNotReceive( 'core_paths' );

		$files->shouldReceive( 'next_unhashed' )->once()->andReturn(
			[
				[
					'id'     => 3,
					'path'   => 'wp-content/uploads/ghost.php',
					'size'   => 0,
					'origin' => 'uploads',
				],
			]
		);

		$files->shouldReceive( 'update_hashed' )->once()->with(
			3,
			[
				'kind'   => 'unreadable',
				'md5'    => '-',
				'sha256' => '-',
			]
		);

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 10, static fn(): bool => false );

		$this->assertSame( 1, $result['unreadable'] );
		$this->assertSame( 0, $result['known_good'] );
		$this->assertSame( 0, $result['integrity'] );
	}

	public function test_hash_pass_marks_unopenable_file_as_unreadable(): void {
		if ( function_exists( 'posix_getuid' ) && 0 === posix_getuid() ) {
			$this->markTestSkipped( 'chmod 000 has no effect for root.' );
		}

		[ $files, $known_good, $findings ] = $this->mocks();
		$known_good->shouldNotReceive( 'check' );

		$rel = $this->write_file( 'irrelevant' );
		chmod( ABSPATH . $rel, 0000 );

		$files->shouldReceive( 'next_unhashed' )->once()->andReturn(
			[
				[
					'id'     => 4,
					'path'   => $rel,
					'size'   => 10,
					'origin' => 'other',
				],
			]
		);
		$files->shouldReceive( 'update_hashed' )->once()->with(
			4,
			[
				'kind'   => 'unreadable',
				'md5'    => '-',
				'sha256' => '-',
			]
		);

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 10, static fn(): bool => false );

		$this->assertSame( 1, $result['unreadable'] );

		chmod( ABSPATH . $rel, 0644 ); // Restore so tearDown() can delete it.
	}

	// -- hash_pass(): known-good / integrity ------------------------------

	public function test_hash_pass_upserts_integrity_finding_on_mismatch(): void {
		[ $files, $known_good, $findings ] = $this->mocks();

		$rel = $this->write_file( "<?php\n// tampered\n" );
		$abs = ABSPATH . $rel;
		$md5 = md5_file( $abs );

		$known_good->shouldReceive( 'check' )->once()->andReturn(
			[
				'status'   => 'mismatch',
				'expected' => 'deadbeef',
				'package'  => 'plugin:akismet',
				'version'  => '5.3',
			]
		);
		$known_good->shouldReceive( 'core_paths' )->once()->andReturn( null );

		$files->shouldReceive( 'next_unhashed' )->once()->andReturn(
			[
				[
					'id'     => 11,
					'path'   => $rel,
					'size'   => 20,
					'origin' => 'plugin:akismet',
				],
			]
		);
		$files->shouldReceive( 'update_hashed' )->once();

		$captured = null;
		$findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					function ( Finding $finding ) use ( &$captured ): bool {
						$captured = $finding;
						return true;
					}
				)
			)
			->andReturn(
				[
					'id'      => 1,
					'created' => true,
					'changed' => true,
				]
			);

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 10, static fn(): bool => false );

		$this->assertInstanceOf( Finding::class, $captured );
		$this->assertSame( 'integrity', $captured->type );
		$this->assertSame( $rel, $captured->locator );
		$this->assertSame( 'integrity', $captured->tier );
		$this->assertSame( 'alert', $captured->severity );
		$this->assertSame( 'integrity', $captured->category );
		$this->assertSame( 11, $captured->file_id );
		$this->assertSame( [ 'integrity:checksum' ], $captured->signature_ids );
		$this->assertSame( 'Modified akismet 5.3 file (checksum mismatch)', $captured->reason );
		$this->assertSame(
			[
				'expected_md5' => 'deadbeef',
				'actual_md5'   => $md5,
				'package'      => 'plugin:akismet',
				'version'      => '5.3',
			],
			$captured->meta
		);
		$this->assertSame( 1, $result['integrity'] );
	}

	public function test_hash_pass_uses_wordpress_label_for_core_mismatch(): void {
		[ $files, $known_good, $findings ] = $this->mocks();

		$rel = $this->write_file( "<?php\n// tampered core\n" );

		$known_good->shouldReceive( 'check' )->once()->andReturn(
			[
				'status'   => 'mismatch',
				'expected' => 'aaa',
				'package'  => 'core',
				'version'  => '6.6.2',
			]
		);
		$known_good->shouldReceive( 'core_paths' )->once()->andReturn( null );

		$files->shouldReceive( 'next_unhashed' )->once()->andReturn(
			[
				[
					'id'     => 12,
					'path'   => $rel,
					'size'   => 20,
					'origin' => 'core',
				],
			]
		);
		$files->shouldReceive( 'update_hashed' )->once();

		$captured = null;
		$findings->shouldReceive( 'upsert' )->once()->with(
			Mockery::on(
				function ( Finding $finding ) use ( &$captured ): bool {
					$captured = $finding;
					return true;
				}
			)
		)->andReturn(
			[
				'id'      => 1,
				'created' => true,
				'changed' => true,
			]
		);

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$indexer->hash_pass( 0, 10, static fn(): bool => false );

		$this->assertSame( 'Modified WordPress 6.6.2 file (checksum mismatch)', $captured->reason );
	}

	public function test_hash_pass_deletes_findings_on_match(): void {
		[ $files, $known_good, $findings ] = $this->mocks();

		$rel = $this->write_file( "<?php\n// clean\n" );

		$known_good->shouldReceive( 'check' )->once()->andReturn(
			[
				'status'   => 'match',
				'expected' => 'x',
				'package'  => 'core',
				'version'  => '6.6.2',
			]
		);
		$known_good->shouldReceive( 'core_paths' )->once()->andReturn( null );

		$files->shouldReceive( 'next_unhashed' )->once()->andReturn(
			[
				[
					'id'     => 13,
					'path'   => $rel,
					'size'   => 20,
					'origin' => 'core',
				],
			]
		);

		$updated = [];
		$files->shouldReceive( 'update_hashed' )->once()->with(
			13,
			Mockery::on(
				function ( array $fields ) use ( &$updated ): bool {
					$updated = $fields;
					return true;
				}
			)
		);

		$findings->shouldNotReceive( 'upsert' );
		$findings->shouldReceive( 'delete_by_locator' )->once()->with( 'integrity', $rel )->andReturn( 1 );
		$findings->shouldReceive( 'delete_by_locator' )->once()->with( 'file', $rel )->andReturn( 1 );

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 10, static fn(): bool => false );

		$this->assertSame( 1, $updated['known_good'] );
		$this->assertSame( 1, $result['known_good'] );
		$this->assertSame( 0, $result['integrity'] );
	}

	// -- hash_pass(): cursor / done / deadline ----------------------------

	public function test_hash_pass_done_true_when_fewer_rows_than_limit(): void {
		[ $files, $known_good, $findings ] = $this->mocks();

		$files->shouldReceive( 'next_unhashed' )->once()->with( 0, 10 )->andReturn( [] );

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 10, static fn(): bool => false );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $result['last_id'] );
	}

	public function test_hash_pass_done_false_when_rows_equal_limit(): void {
		[ $files, $known_good, $findings ] = $this->mocks();
		$known_good->shouldReceive( 'check' )->andReturn(
			[
				'status'   => 'unknown',
				'expected' => '',
				'package'  => '',
				'version'  => '',
			]
		);
		$known_good->shouldReceive( 'core_paths' )->andReturn( null );

		$rel = $this->write_file( "<?php\n" );

		$files->shouldReceive( 'next_unhashed' )->once()->with( 0, 1 )->andReturn(
			[
				[
					'id'     => 21,
					'path'   => $rel,
					'size'   => 5,
					'origin' => 'other',
				],
			]
		);
		$files->shouldReceive( 'update_hashed' )->once();

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 1, static fn(): bool => false );

		$this->assertFalse( $result['done'] );
		$this->assertSame( 21, $result['last_id'] );
	}

	public function test_hash_pass_stops_early_every_20_files_when_deadline_hits(): void {
		[ $files, $known_good, $findings ] = $this->mocks();
		$known_good->shouldNotReceive( 'check' );

		$rows = [];
		for ( $i = 1; $i <= 30; $i++ ) {
			$rows[] = [
				'id'     => $i,
				'path'   => "ghost{$i}.php",
				'size'   => 0,
				'origin' => 'other',
			];
		}

		$files->shouldReceive( 'next_unhashed' )->once()->with( 0, 30 )->andReturn( $rows );
		$files->shouldReceive( 'update_hashed' )->times( 20 );

		$calls    = 0;
		$deadline = function () use ( &$calls ): bool {
			++$calls;
			return true;
		};

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 30, $deadline );

		$this->assertSame( 1, $calls );
		$this->assertSame( 20, $result['hashed'] );
		$this->assertSame( 20, $result['unreadable'] );
		$this->assertSame( 20, $result['last_id'] );
		$this->assertFalse( $result['done'] );
	}

	public function test_hash_pass_checks_deadline_only_every_20_files_when_not_triggered(): void {
		[ $files, $known_good, $findings ] = $this->mocks();
		$known_good->shouldNotReceive( 'check' );

		$rows = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$rows[] = [
				'id'     => $i,
				'path'   => "ghost{$i}.php",
				'size'   => 0,
				'origin' => 'other',
			];
		}

		$files->shouldReceive( 'next_unhashed' )->once()->with( 0, 30 )->andReturn( $rows );
		$files->shouldReceive( 'update_hashed' )->times( 25 );

		$calls    = 0;
		$deadline = function () use ( &$calls ): bool {
			++$calls;
			return false;
		};

		$indexer = new FileIndexer( $files, $known_good, $findings, $this->software() );
		$result  = $indexer->hash_pass( 0, 30, $deadline );

		$this->assertSame( 1, $calls ); // Only the 20th file triggers a check.
		$this->assertSame( 25, $result['hashed'] );
		$this->assertTrue( $result['done'] );
	}
}
