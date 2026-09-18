<?php
/**
 * Tests for FilesRepository::upsert_stat_batch()'s generated SQL.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Db;

use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

require_once __DIR__ . '/FakeWpdb.php';

final class FilesRepositoryTest extends MonkeyTestCase {

	/** The shared IF() condition every reset branch must use. */
	private const RESET_CONDITION = 'size <> VALUES(size) OR mtime <> VALUES(mtime)';

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_upsert_stat_batch_resets_every_hash_field_on_either_size_or_mtime_change(): void {
		$wpdb = new \wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo = new FilesRepository( $wpdb );

		$repo->upsert_stat_batch(
			[
				[
					'path'        => 'wp-content/plugins/x/x.php',
					'size'        => 10,
					'mtime'       => 100,
					'path_signal' => 0,
					'origin'      => 'plugin:x',
				],
			],
			1
		);

		$this->assertCount( 1, $wpdb->prepared_queries );
		$sql = $wpdb->prepared_queries[0];

		// (b) the shared condition fragment appears in every reset branch:
		// md5, sha256, kind, known_good, scanned_bundle.
		$this->assertSame( 5, substr_count( $sql, self::RESET_CONDITION ) );
	}

	public function test_upsert_stat_batch_assigns_size_and_mtime_after_every_reset_branch(): void {
		$wpdb = new \wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo = new FilesRepository( $wpdb );

		$repo->upsert_stat_batch(
			[
				[
					'path'        => 'wp-content/plugins/x/x.php',
					'size'        => 10,
					'mtime'       => 100,
					'path_signal' => 0,
					'origin'      => 'plugin:x',
				],
			],
			1
		);

		$sql = $wpdb->prepared_queries[0];

		$last_reset_pos = strrpos( $sql, 'scanned_bundle = IF(' . self::RESET_CONDITION . ', 0, scanned_bundle)' );
		$size_pos       = strpos( $sql, 'size = VALUES(size)' );
		$mtime_pos      = strpos( $sql, 'mtime = VALUES(mtime)' );

		$this->assertIsInt( $last_reset_pos );
		$this->assertIsInt( $size_pos );
		$this->assertIsInt( $mtime_pos );

		// (a) size/mtime are assigned last, so every reset branch above still
		// reads their pre-update values when MySQL evaluates the SET list
		// left to right.
		$this->assertGreaterThan( $last_reset_pos, $size_pos );
		$this->assertGreaterThan( $last_reset_pos, $mtime_pos );

		// size/mtime are unconditional VALUES() assignments now, not IF()s:
		// whether or not the stat changed, VALUES(size) is the correct value
		// to store (it equals the old value when nothing changed).
		$this->assertStringNotContainsString( 'size = IF(', $sql );
		$this->assertStringNotContainsString( 'mtime = IF(', $sql );
	}

	public function test_reset_hashes_clears_the_hashes_known_good_and_scan_cursor_for_every_row(): void {
		$wpdb            = new \wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo            = new FilesRepository( $wpdb );

		$repo->reset_hashes();

		$this->assertCount( 1, $wpdb->queries );
		$sql = $wpdb->queries[0];

		$this->assertStringContainsString( "md5 = ''", $sql );
		$this->assertStringContainsString( "sha256 = ''", $sql );
		$this->assertStringContainsString( 'known_good = 0', $sql );
		$this->assertStringContainsString( 'scanned_bundle = 0', $sql );
		$this->assertStringStartsWith( 'UPDATE ', $sql );
	}

	public function test_queue_for_scan_matches_a_row_stamped_above_the_current_version(): void {
		// A pack downgrade (backend withdraws a bad version and re-serves an
		// older one) leaves rows stamped above the new current version;
		// `<>` (not `<`) is what re-queues them for a rescan.
		$wpdb            = new \wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo            = new FilesRepository( $wpdb );

		$repo->queue_for_scan( 2, 0, 50 );

		$this->assertCount( 1, $wpdb->prepared_queries );
		$this->assertStringContainsString( 'scanned_bundle <> %d', $wpdb->prepared_queries[0] );
		$this->assertStringNotContainsString( 'scanned_bundle < %d', $wpdb->prepared_queries[0] );
		$this->assertSame( [ 2, 0, 50 ], $wpdb->prepared_args[0][0] );
	}

	public function test_count_queue_matches_a_row_stamped_above_the_current_version(): void {
		$wpdb            = new \wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo            = new FilesRepository( $wpdb );

		$repo->count_queue( 2 );

		$this->assertCount( 1, $wpdb->prepared_queries );
		$this->assertStringContainsString( 'scanned_bundle <> %d', $wpdb->prepared_queries[0] );
		$this->assertStringNotContainsString( 'scanned_bundle < %d', $wpdb->prepared_queries[0] );
	}

	public function test_reset_hashes_keeps_the_index_rows_and_their_stat_data(): void {
		$wpdb            = new \wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo            = new FilesRepository( $wpdb );

		$repo->reset_hashes();

		$sql = $wpdb->queries[0];

		// The walk stays incremental: path/path_hash/size/mtime are never
		// touched, and no row is deleted.
		$this->assertStringNotContainsString( 'DELETE', $sql );
		$this->assertStringNotContainsString( 'TRUNCATE', $sql );
		$this->assertStringNotContainsString( 'path_hash', $sql );
		$this->assertStringNotContainsString( 'size', $sql );
		$this->assertStringNotContainsString( 'mtime', $sql );
		$this->assertStringNotContainsString( 'WHERE', $sql );
	}
}
