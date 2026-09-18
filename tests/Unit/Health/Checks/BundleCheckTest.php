<?php
/**
 * Tests for Health\Checks\BundleCheck.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Health\Checks;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Health\Checks\BundleCheck;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * The Health tab renders on an ordinary admin request, so this check answers
 * "is the current signature pack's files there?" with nothing but `stat()`
 * calls — it must never decode the pack to answer that. Every case below
 * uses `Store::write_atomic()` to place raw bytes at `pack_path()`/
 * `meta_path()` rather than valid pack/meta JSON, and the "ok" cases assert
 * the check still answers `ok`: proof that presence and size are all it
 * looks at.
 */
final class BundleCheckTest extends MonkeyTestCase {

	private const VERSION = 20260916045;

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var string Temp directory standing in for wp-content/lw-scan/. */
	private string $storage_dir;

	protected function setUp(): void {
		parent::setUp();

		$this->storage_dir = sys_get_temp_dir() . '/lw-scan-bundlecheck-' . uniqid();
		mkdir( $this->storage_dir, 0755, true );

		$store = &$this->option_store;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default_value;
			}
		);

		Functions\stubTranslationFunctions();
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

	private function store(): Store {
		return new Store( $this->storage_dir );
	}

	/**
	 * Writes non-empty pack/meta files for `$version`. `$content` is
	 * deliberately allowed to be invalid JSON: the check must not care.
	 *
	 * @param int    $version Pack version.
	 * @param string $content Bytes to write to both files.
	 */
	private function write_pack_files( int $version, string $content = '{"ok":true}' ): void {
		$store = $this->store();
		$store->write_atomic( $store->pack_path( $version ), $content );
		$store->write_atomic( $store->meta_path( $version ), $content );
	}

	public function test_id_and_label(): void {
		$check = new BundleCheck( $this->store() );

		$this->assertSame( 'bundle', $check->id() );
		$this->assertSame( 'Signature bundle', $check->label() );
	}

	public function test_no_pack_yet_is_critical_but_never_blocks_the_first_scan(): void {
		// A fresh install has no pack by definition, and the first scan's
		// bundle phase is what downloads one. Blocking here would mean the
		// plugin could never start the run that would fix the condition.
		$this->state( [] );

		$result = ( new BundleCheck( $this->store() ) )->run();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertFalse( $result['blocking'] );
		$this->assertStringContainsString( 'first scan', $result['message'] );
	}

	public function test_a_missing_pack_is_critical_with_the_version_in_the_message_but_never_blocks(): void {
		$this->state( [ 'bundle_version' => self::VERSION ] );

		$result = ( new BundleCheck( $this->store() ) )->run();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertFalse( $result['blocking'], 'the next scan re-downloads the pack, so this must never block one' );
		$this->assertStringContainsString( (string) self::VERSION, $result['message'] );
	}

	public function test_pack_files_present_is_ok_and_reports_the_signature_count(): void {
		$this->state(
			[
				'bundle_version' => self::VERSION,
				'bundle_count'   => 13210,
			]
		);
		$this->write_pack_files( self::VERSION );

		$result = ( new BundleCheck( $this->store() ) )->run();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertFalse( $result['blocking'] );
		$this->assertStringContainsString( '13,210', $result['message'] );
	}

	public function test_invalid_json_in_the_pack_file_does_not_stop_it_being_reported_ok(): void {
		// The tripwire: has_pack() only stats the files. A check that parsed
		// them to answer "ok" would fail this the moment the content wasn't
		// valid pack JSON.
		$this->state(
			[
				'bundle_version' => self::VERSION,
				'bundle_count'   => 5,
			]
		);
		$this->write_pack_files( self::VERSION, 'not valid json{{{' );

		$result = ( new BundleCheck( $this->store() ) )->run();

		$this->assertSame( 'ok', $result['status'] );
	}
}
