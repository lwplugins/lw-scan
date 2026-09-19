<?php
/**
 * Tests for Uninstall\Uninstaller.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Uninstall;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Uninstall\Uninstaller;
use wpdb;

require_once dirname( __DIR__ ) . '/Db/FakeWpdb.php';

final class UninstallerTest extends MonkeyTestCase {

	private const CONTENT_DIR = '/var/www/html/wp-content';

	/** @var string Temp content dir for the filesystem tests. */
	private string $base;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$this->base = sys_get_temp_dir() . '/lw-scan-uninstall-' . uniqid();
		mkdir( $this->base, 0755, true );
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->base );
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_dir( $path );
				continue;
			}

			unlink( $path );
		}

		rmdir( $dir );
	}

	/**
	 * Builds a storage dir the way the plugin does: a guard file, an
	 * .htaccess, a compiled bundle and a cache subdirectory.
	 *
	 * @return string Absolute path to the storage dir.
	 */
	private function make_storage_dir(): string {
		$dir = $this->base . '/lw-scan';

		mkdir( $dir . '/cache', 0755, true );
		file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
		file_put_contents( $dir . '/.htaccess', 'Deny from all' );
		file_put_contents( $dir . '/bundle-1.php', '<?php return [];' );
		file_put_contents( $dir . '/cache/vuln-plugin-acme.json', '{}' );

		return $dir;
	}

	public function test_safe_to_delete_accepts_the_default_storage_dir(): void {
		$this->assertTrue( Uninstaller::safe_to_delete( self::CONTENT_DIR . '/lw-scan', self::CONTENT_DIR ) );
	}

	public function test_safe_to_delete_accepts_a_nested_storage_dir(): void {
		$this->assertTrue( Uninstaller::safe_to_delete( self::CONTENT_DIR . '/private/lw-scan', self::CONTENT_DIR ) );
	}

	public function test_safe_to_delete_ignores_trailing_slashes_and_backslashes(): void {
		$this->assertTrue( Uninstaller::safe_to_delete( 'C:\\www\\wp-content\\lw-scan\\', 'C:\\www\\wp-content' ) );
	}

	public function test_safe_to_delete_rejects_another_basename(): void {
		$this->assertFalse( Uninstaller::safe_to_delete( self::CONTENT_DIR . '/uploads', self::CONTENT_DIR ) );
	}

	public function test_safe_to_delete_rejects_a_path_outside_the_content_dir(): void {
		$this->assertFalse( Uninstaller::safe_to_delete( '/tmp/lw-scan', self::CONTENT_DIR ) );
	}

	public function test_safe_to_delete_rejects_a_sibling_dir_sharing_the_prefix(): void {
		$this->assertFalse( Uninstaller::safe_to_delete( '/var/www/html/wp-content-backup/lw-scan', self::CONTENT_DIR ) );
	}

	public function test_safe_to_delete_rejects_the_content_dir_itself(): void {
		$this->assertFalse( Uninstaller::safe_to_delete( '/var/www/html/lw-scan', '/var/www/html/lw-scan' ) );
	}

	public function test_safe_to_delete_rejects_a_traversal_segment(): void {
		$this->assertFalse( Uninstaller::safe_to_delete( self::CONTENT_DIR . '/../../etc/lw-scan', self::CONTENT_DIR ) );
	}

	public function test_safe_to_delete_rejects_an_empty_dir(): void {
		$this->assertFalse( Uninstaller::safe_to_delete( '', self::CONTENT_DIR ) );
	}

	public function test_safe_to_delete_rejects_an_empty_content_dir(): void {
		$this->assertFalse( Uninstaller::safe_to_delete( self::CONTENT_DIR . '/lw-scan', '' ) );
	}

	public function test_safe_to_delete_rejects_the_filesystem_root_as_content_dir(): void {
		$this->assertFalse( Uninstaller::safe_to_delete( '/lw-scan', '/' ) );
	}

	public function test_delete_storage_dir_removes_the_whole_tree(): void {
		$dir = $this->make_storage_dir();

		$this->assertTrue( Uninstaller::delete_storage_dir( $dir, $this->base ) );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertDirectoryExists( $this->base );
	}

	public function test_delete_storage_dir_refuses_a_directory_it_must_not_touch(): void {
		$dir = $this->base . '/uploads';
		mkdir( $dir, 0755, true );
		file_put_contents( $dir . '/photo.jpg', 'x' );

		$this->assertFalse( Uninstaller::delete_storage_dir( $dir, $this->base ) );
		$this->assertFileExists( $dir . '/photo.jpg' );
	}

	public function test_delete_storage_dir_reports_success_when_there_is_nothing_to_delete(): void {
		$this->assertTrue( Uninstaller::delete_storage_dir( $this->base . '/lw-scan', $this->base ) );
	}

	public function test_delete_storage_dir_does_not_follow_a_symlinked_directory(): void {
		$dir     = $this->make_storage_dir();
		$outside = $this->base . '/outside';

		mkdir( $outside, 0755, true );
		file_put_contents( $outside . '/precious.txt', 'keep me' );
		symlink( $outside, $dir . '/linked' );

		$this->assertTrue( Uninstaller::delete_storage_dir( $dir, $this->base ) );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertFileExists( $outside . '/precious.txt' );
	}

	public function test_run_deletes_every_option_transient_and_cron_hook(): void {
		$options    = [];
		$transients = [];
		$cleared    = [];
		$unhooked   = [];

		$this->stub_run_environment( $options, $transients, $cleared, $unhooked );

		Uninstaller::run();

		// The version option is deleted twice: once by name here, once more
		// by Schema::drop() as part of dropping the tables it tracks.
		$this->assertSame(
			[ 'lw_scan_options', 'lw_scan_state', 'lw_scan_db_version', 'lw_scan_upgrading', 'lw_scan_status_endpoint', 'lw_scan_db_version' ],
			$options
		);
		$this->assertSame( [ 'lw_scan_health', 'lw_scan_lock', 'lw_scan_catchup', 'lw_scan_install_retry', 'lw_scan_status_report' ], $transients );
		$this->assertSame( [ 'lw_scan_scheduled', 'lw_scan_tick' ], $cleared );
		$this->assertSame( [ 'lw_scan_scheduled', 'lw_scan_tick' ], $unhooked );
	}

	public function test_run_drops_the_three_tables(): void {
		$options    = [];
		$transients = [];
		$cleared    = [];
		$unhooked   = [];

		$wpdb = $this->stub_run_environment( $options, $transients, $cleared, $unhooked );

		Uninstaller::run();

		$this->assertSame(
			[
				'DROP TABLE IF EXISTS wp_lw_scan_files',
				'DROP TABLE IF EXISTS wp_lw_scan_findings',
				'DROP TABLE IF EXISTS wp_lw_scan_runs',
			],
			$wpdb->queries
		);
	}

	/**
	 * Stubs everything `run()` reaches for: the WordPress functions it
	 * calls (recorded into the referenced arrays) and a fake $wpdb for
	 * `Schema::drop()`. The storage dir is pointed at a path under
	 * WP_CONTENT_DIR that does not exist, so `run()` exercises the guard
	 * without deleting anything on the machine running the tests.
	 *
	 * @param array<int, string> $options    Recorded delete_option() names.
	 * @param array<int, string> $transients Recorded delete_transient() names.
	 * @param array<int, string> $cleared    Recorded wp_clear_scheduled_hook() names.
	 * @param array<int, string> $unhooked   Recorded wp_unschedule_hook() names.
	 * @return wpdb The fake $wpdb the run wrote to.
	 */
	private function stub_run_environment( array &$options, array &$transients, array &$cleared, array &$unhooked ): wpdb {
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$options ): bool {
				$options[] = $name;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ) use ( &$transients ): bool {
				$transients[] = $name;

				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $name ) use ( &$cleared ): int {
				$cleared[] = $name;

				return 0;
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			static function ( $name ) use ( &$unhooked ): int {
				$unhooked[] = $name;

				return 0;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( $tag, $value ) => 'lw_scan_storage_dir' === $tag ? WP_CONTENT_DIR . '/lw-scan' : $value
		);

		$wpdb            = new wpdb();
		$GLOBALS['wpdb'] = $wpdb;

		return $wpdb;
	}
}
