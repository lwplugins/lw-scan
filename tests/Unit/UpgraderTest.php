<?php
/**
 * Tests for Upgrader.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Remote\FileCache;
use LightweightPlugins\Scan\Upgrader;

require_once __DIR__ . '/Db/FakeWpdb.php';

final class UpgraderTest extends MonkeyTestCase {

	/** Return values for the three `SHOW TABLES LIKE` probes, all present. */
	private const TABLES_PRESENT = [ 'wp_lw_scan_files', 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var string[] Option names passed to delete_option(), in call order. */
	private array $deleted_options = [];

	/** @var string[] Transient names passed to delete_transient(), in call order. */
	private array $deleted_transients = [];

	private string $dir;

	private \wpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$this->dir                = sys_get_temp_dir() . '/lw-scan-test-' . uniqid();
		$this->option_store       = [];
		$this->deleted_options    = [];
		$this->deleted_transients = [];

		$options    = &$this->option_store;
		$deleted    = &$this->deleted_options;
		$transients = &$this->deleted_transients;
		$dir        = $this->dir;

		Functions\when( 'apply_filters' )->alias(
			static fn( $tag, $value ) => 'lw_scan_storage_dir' === $tag ? $dir : $value
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
			}
		);
		// Mirrors WordPress: add_option() refuses a name that already
		// exists, which is what makes it usable as the upgrade claim.
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value = '' ) use ( &$options ) {
				if ( array_key_exists( $name, $options ) ) {
					return false;
				}

				$options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$options, &$deleted ) {
				$deleted[] = $name;
				unset( $options[ $name ] );

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ) use ( &$transients ) {
				$transients[] = $name;

				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->wpdb            = new \wpdb();
		$this->wpdb->var_queue = self::TABLES_PRESENT;
		$GLOBALS['wpdb']       = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
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

	/**
	 * @param string $version Value for the stored `plugin_version` marker.
	 */
	private function seed_version( string $version ): void {
		$this->option_store[ Options::OPTION_NAME ] = [ Upgrader::VERSION_KEY => $version ];
	}

	private function cache(): FileCache {
		return new FileCache( $this->dir . '/cache' );
	}

	private function stored_version(): string {
		$options = (array) ( $this->option_store[ Options::OPTION_NAME ] ?? [] );

		return (string) ( $options[ Upgrader::VERSION_KEY ] ?? '' );
	}

	/**
	 * @return string[] Everything passed to $wpdb->query(), i.e. without the SHOW TABLES probes.
	 */
	private function writes(): array {
		return array_values(
			array_filter(
				$this->wpdb->queries,
				static fn( string $sql ): bool => 0 !== strpos( $sql, 'SHOW TABLES' )
			)
		);
	}

	public function test_maybe_upgrade_does_nothing_when_the_stored_version_matches(): void {
		$this->seed_version( LW_SCAN_VERSION );
		$cache = $this->cache();
		$cache->put( 'checksum-core-6.6.2', [ 'map' => [] ] );

		Actions\expectDone( 'lw_scan_upgraded' )->never();

		Upgrader::maybe_upgrade();

		// Not even the schema probe: the version compare is all an ordinary
		// request may cost.
		$this->assertSame( [], $this->wpdb->queries );
		$this->assertSame( [], $this->deleted_transients );
		$this->assertSame( [ 'map' => [] ], $cache->get( 'checksum-core-6.6.2', 60 ) );
	}

	public function test_maybe_upgrade_does_nothing_while_a_table_is_missing(): void {
		$this->seed_version( '0.9.0' );
		$this->wpdb->var_queue = [ null, 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];
		$cache                 = $this->cache();
		$cache->put( 'checksum-core-6.6.2', [ 'map' => [] ] );

		Actions\expectDone( 'lw_scan_upgraded' )->never();

		Upgrader::maybe_upgrade();

		$this->assertSame( [], $this->writes() );
		$this->assertSame( [ 'map' => [] ], $cache->get( 'checksum-core-6.6.2', 60 ) );
		// The marker must stay behind so the next request retries once the
		// schema install stops failing.
		$this->assertSame( '0.9.0', $this->stored_version() );
		$this->assertArrayNotHasKey( Upgrader::CLAIM_OPTION, $this->option_store );
	}

	public function test_maybe_upgrade_does_nothing_while_a_run_cursor_exists(): void {
		$this->seed_version( '0.9.0' );
		$this->option_store['lw_scan_state'] = [
			'run' => [
				'run_id' => 42,
				'phase'  => 'files',
			],
		];
		$cache = $this->cache();
		$cache->put( 'checksum-core-6.6.2', [ 'map' => [] ] );

		Actions\expectDone( 'lw_scan_upgraded' )->never();

		Upgrader::maybe_upgrade();

		$this->assertSame( [], $this->writes() );
		$this->assertSame( [ 'map' => [] ], $cache->get( 'checksum-core-6.6.2', 60 ) );
		$this->assertSame( '0.9.0', $this->stored_version() );
	}

	public function test_maybe_upgrade_does_nothing_while_another_request_holds_the_claim(): void {
		$this->seed_version( '0.9.0' );
		$this->option_store[ Upgrader::CLAIM_OPTION ] = time() - 5;
		$cache                                        = $this->cache();
		$cache->put( 'checksum-core-6.6.2', [ 'map' => [] ] );

		Actions\expectDone( 'lw_scan_upgraded' )->never();

		Upgrader::maybe_upgrade();

		$this->assertSame( [], $this->writes() );
		$this->assertSame( [ 'map' => [] ], $cache->get( 'checksum-core-6.6.2', 60 ) );
		$this->assertSame( '0.9.0', $this->stored_version() );
		// The other request's claim is left exactly as it was found.
		$this->assertSame( [], $this->deleted_options );
	}

	public function test_maybe_upgrade_takes_over_a_claim_left_behind_by_a_dead_request(): void {
		$this->seed_version( '0.9.0' );
		$this->option_store[ Upgrader::CLAIM_OPTION ] = time() - 601;

		Actions\expectDone( 'lw_scan_upgraded' )->once()->with( '0.9.0', LW_SCAN_VERSION );

		Upgrader::maybe_upgrade();

		$this->assertSame( LW_SCAN_VERSION, $this->stored_version() );
		$this->assertCount( 1, $this->writes() );
	}

	public function test_maybe_upgrade_releases_the_claim_when_it_is_done(): void {
		$this->seed_version( '0.9.0' );

		Upgrader::maybe_upgrade();

		$this->assertSame( [ Upgrader::CLAIM_OPTION ], $this->deleted_options );
		$this->assertArrayNotHasKey( Upgrader::CLAIM_OPTION, $this->option_store );
	}

	public function test_maybe_upgrade_leaves_behind_a_claim_another_request_has_taken_over(): void {
		$this->seed_version( '0.9.0' );

		$options  = &$this->option_store;
		$taken_at = time() + 1234;
		$flushed  = false;

		// An upgrade slower than the ten-minute TTL is taken over by another
		// request, which deletes this claim and writes its own. Releasing
		// unconditionally would delete that one, and a third request would
		// then start a second concurrent upgrade.
		//
		// Stands in for WordPress' request-level options cache: this request
		// wrote the claim itself, so until it drops the cached entry it only
		// ever sees its own timestamp and the takeover is invisible.
		Functions\when( 'wp_cache_delete' )->alias(
			static function () use ( &$flushed ): bool {
				$flushed = true;

				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$options, &$flushed, $taken_at ) {
				if ( Upgrader::CLAIM_OPTION === $name && $flushed ) {
					return $taken_at;
				}

				return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
			}
		);

		Upgrader::maybe_upgrade();

		$this->assertTrue( $flushed, 'the release must read past the options cache' );
		$this->assertSame( [], $this->deleted_options );
		$this->assertSame( LW_SCAN_VERSION, $this->stored_version(), 'the upgrade itself still ran' );
	}

	public function test_maybe_upgrade_purges_the_checksum_cache_and_keeps_other_entries(): void {
		$this->seed_version( '0.9.0' );
		$cache = $this->cache();
		$cache->put( 'checksum-core-6.6.2', [ 'map' => [] ] );
		$cache->put( 'checksum-plugin-x-1.0', [ 'map' => [] ] );
		$cache->put( 'vuln-plugin-x', [ 'items' => [] ] );

		Upgrader::maybe_upgrade();

		$this->assertNull( $cache->get( 'checksum-core-6.6.2', 60 ) );
		$this->assertNull( $cache->get( 'checksum-plugin-x-1.0', 60 ) );
		$this->assertSame( [ 'items' => [] ], $cache->get( 'vuln-plugin-x', 60 ) );
	}

	public function test_maybe_upgrade_does_not_create_the_storage_dir_when_it_is_not_there(): void {
		$this->seed_version( '0.9.0' );
		$this->assertDirectoryDoesNotExist( $this->dir );

		Upgrader::maybe_upgrade();

		// A fresh install has nothing cached, and an ordinary front-end
		// request must not create the tree just to look.
		$this->assertDirectoryDoesNotExist( $this->dir );
		$this->assertSame( LW_SCAN_VERSION, $this->stored_version() );
	}

	public function test_maybe_upgrade_resets_the_stored_hashes_and_known_good_flags(): void {
		$this->seed_version( '0.9.0' );

		Upgrader::maybe_upgrade();

		$writes = $this->writes();

		$this->assertCount( 1, $writes );
		$this->assertStringStartsWith( 'UPDATE ', $writes[0] );
		$this->assertStringContainsString( "md5 = ''", $writes[0] );
		$this->assertStringContainsString( 'known_good = 0', $writes[0] );
	}

	public function test_maybe_upgrade_invalidates_the_health_report_cache(): void {
		$this->seed_version( '0.9.0' );

		Upgrader::maybe_upgrade();

		$this->assertContains( 'lw_scan_health', $this->deleted_transients );
	}

	public function test_maybe_upgrade_stores_the_running_version_in_the_autoloaded_options(): void {
		$this->seed_version( '0.9.0' );

		Upgrader::maybe_upgrade();

		$this->assertSame( LW_SCAN_VERSION, $this->stored_version() );
		// Not in State: its option is not autoloaded, and this marker is
		// read on every request.
		$this->assertArrayNotHasKey( 'lw_scan_state', $this->option_store );
	}

	public function test_maybe_upgrade_runs_on_the_first_request_after_a_fresh_install(): void {
		$this->option_store[ Options::OPTION_NAME ] = Options::get_defaults();
		$cache                                      = $this->cache();
		$cache->put( 'checksum-core-6.6.2', [ 'map' => [] ] );

		Actions\expectDone( 'lw_scan_upgraded' )->once()->with( '', LW_SCAN_VERSION );

		Upgrader::maybe_upgrade();

		$this->assertNull( $cache->get( 'checksum-core-6.6.2', 60 ) );
		$this->assertSame( LW_SCAN_VERSION, $this->stored_version() );
	}

	public function test_maybe_upgrade_fires_the_upgraded_action_with_both_versions(): void {
		$this->seed_version( '0.9.0' );

		Actions\expectDone( 'lw_scan_upgraded' )->once()->with( '0.9.0', LW_SCAN_VERSION );

		Upgrader::maybe_upgrade();
	}

	public function test_maybe_upgrade_is_a_noop_on_the_second_call(): void {
		$this->seed_version( '0.9.0' );

		Upgrader::maybe_upgrade();

		$this->wpdb->queries      = [];
		$this->deleted_options    = [];
		$this->deleted_transients = [];

		Upgrader::maybe_upgrade();

		$this->assertSame( [], $this->wpdb->queries );
		$this->assertSame( [], $this->deleted_options );
		$this->assertSame( [], $this->deleted_transients );
	}
}
