<?php
/**
 * Tests for Activator.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit;

use Brain\Monkey\Functions;
use DateTimeZone;
use LightweightPlugins\Scan\Activator;
use LightweightPlugins\Scan\Db\Schema;
use RuntimeException;

require_once __DIR__ . '/Db/FakeWpdb.php';

/**
 * Activation is the one moment a site owner is watching, so it is where the
 * signature pack is fetched: the first scan then has one already, and a
 * fresh install can start scanning without a round trip to the backend
 * standing between it and its first answer.
 *
 * What these cover is that the fetch happens at all, and that it can never
 * be the reason an activation fails — a backend that is down, a host with
 * no outbound HTTP, a storage directory that is not writable yet.
 */
final class ActivatorTest extends MonkeyTestCase {

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var string Temp directory standing in for wp-content/lw-scan/. */
	private string $storage_dir;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$this->option_store = [];
		$this->storage_dir  = sys_get_temp_dir() . '/lw-scan-activator-' . uniqid();
		mkdir( $this->storage_dir, 0755, true );

		Schema::reset_exists_cache();

		$this->stub_options();
		$this->stub_schema();
		$this->stub_scheduler();

		$storage_dir = $this->storage_dir;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $storage_dir ) {
				return 'lw_scan_storage_dir' === $tag ? $storage_dir : $value;
			}
		);
		Functions\stubTranslationFunctions();
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Schema::reset_exists_cache();

		foreach ( (array) glob( $this->storage_dir . '/*' ) as $file ) {
			unlink( $file );
		}

		rmdir( $this->storage_dir );

		parent::tearDown();
	}

	public function test_activation_asks_the_backend_for_the_signature_pack(): void {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://scan-data.lwplugins.com/v1/pack/latest', \Mockery::type( 'array' ) )
			->andReturn( [ 'response' => [ 'code' => 503 ] ] );

		Activator::activate();
	}

	public function test_activation_survives_a_backend_that_blows_up(): void {
		// Not a RemoteException, so nothing inside Remote\PackFetcher
		// catches it: an HTTP transport (or a filter on one of WordPress'
		// own hooks) throwing has to stop at Activator, or the plugin never
		// finishes activating.
		Functions\when( 'wp_remote_get' )->alias(
			static function (): array {
				throw new RuntimeException( 'transport exploded' );
			}
		);

		Activator::activate();

		$this->assertArrayHasKey( 'lw_scan_options', $this->option_store, 'activation still wrote its options' );
	}

	public function test_activation_still_installs_the_schema_and_the_options(): void {
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 503 ] ] );

		Activator::activate();

		$this->assertArrayHasKey( 'lw_scan_options', $this->option_store );
		$this->assertSame( Schema::VERSION, (int) $this->option_store[ Schema::VERSION_OPTION ] );
	}

	private function stub_options(): void {
		$store = &$this->option_store;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;

				return true;
			}
		);
	}

	/**
	 * `Schema::install()` against a wpdb double: dbDelta does nothing and
	 * the three existence probes all answer "there".
	 */
	private function stub_schema(): void {
		$wpdb            = new \wpdb();
		$wpdb->var_queue = [ 'wp_lw_scan_files', 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'dbDelta' )->justReturn( [] );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	private function stub_scheduler(): void {
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
	}
}
