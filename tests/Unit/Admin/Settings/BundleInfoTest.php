<?php
/**
 * Tests for Admin\Settings\BundleInfo.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Admin\Settings\BundleInfo;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * These three numbers are printed on the Scan and Health tabs, on top of a
 * full wp-admin request — so they have to come out of the stored state and
 * nothing else. A pack file at the stored version's path is deliberately
 * invalid JSON: if `BundleInfo` ever read and decoded it to answer a count,
 * `json_decode()` would hand back null and the assertions below would fail
 * instead of quietly passing on an empty pack.
 */
final class BundleInfoTest extends MonkeyTestCase {

	private const VERSION = 20260916045;

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var string Temp directory standing in for wp-content/lw-scan/. */
	private string $storage_dir;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$this->storage_dir = sys_get_temp_dir() . '/lw-scan-bundleinfo-' . uniqid();
		mkdir( $this->storage_dir, 0755, true );

		file_put_contents( $this->storage_dir . '/pack-' . self::VERSION . '.json', 'not valid json{{{' );

		$storage_dir = $this->storage_dir;
		$store       = &$this->option_store;

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $storage_dir ) {
				return 'lw_scan_storage_dir' === $tag ? $storage_dir : $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default_value;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->storage_dir . '/*' ) as $file ) {
			unlink( $file );
		}

		rmdir( $this->storage_dir );

		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $state Values for the stored state option.
	 */
	private function state( array $state ): void {
		$this->option_store[ State::OPTION_NAME ] = $state;
	}

	public function test_signature_count_comes_from_stored_state(): void {
		$this->state(
			[
				'bundle_version' => self::VERSION,
				'bundle_count'   => 13298,
			]
		);

		$this->assertSame( 13298, BundleInfo::signature_count() );
	}

	public function test_signature_count_never_loads_the_pack_to_answer(): void {
		$this->state( [ 'bundle_version' => self::VERSION ] );

		$this->assertSame( 0, BundleInfo::signature_count() );
	}
}
