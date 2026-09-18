<?php
/**
 * Tests for Run\BundleGuard.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Run\BundleGuard;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\RunStats;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use Mockery;
use RuntimeException;

/**
 * What a tick may carry on with. Most cases hand the Context its signatures
 * directly, the way `BundlePhase` does, so they are about the comparison;
 * the stored-pack case covers a later tick that loads it from disk itself.
 */
final class BundleGuardTest extends MonkeyTestCase {

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var string Temp directory standing in for wp-content/lw-scan/. */
	private string $storage_dir;

	private Store $store;

	protected function setUp(): void {
		parent::setUp();

		$this->storage_dir = sys_get_temp_dir() . '/lw-scan-guard-' . uniqid();
		mkdir( $this->storage_dir, 0755, true );

		$this->store = new Store( $this->storage_dir );
		$store       = &$this->option_store;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default_value;
			}
		);

		PackLoader::use_store( $this->store );
	}

	protected function tearDown(): void {
		PackLoader::use_store( null );

		foreach ( (array) glob( $this->storage_dir . '/*' ) as $file ) {
			unlink( $file );
		}

		rmdir( $this->storage_dir );

		parent::tearDown();
	}

	private function install_pack( int $version ): void {
		FixturePack::install( $this->store, 'lw', $version );

		$this->option_store[ State::OPTION_NAME ] = [ 'bundle_version' => $version ];
	}

	public function test_a_run_that_has_not_pinned_a_version_yet_is_left_alone(): void {
		$ctx = $this->context();

		BundleGuard::verify( $ctx );

		// Had verify() loaded, the Context would have memoized "nothing".
		$this->install_pack( 5 );

		$this->assertNotNull( $ctx->signatures(), 'the first tick must not be made to load the pack' );
	}

	public function test_a_tick_on_the_same_pack_carries_on(): void {
		$ctx = $this->context( 5, FixturePack::signatures_as( 'lw', 5 ) );

		BundleGuard::verify( $ctx );

		$this->assertSame( 5, $ctx->bundle_version() );
	}

	public function test_a_later_tick_that_loads_the_pinned_pack_from_storage_carries_on(): void {
		$this->install_pack( 5 );

		$ctx = $this->context( 5 );

		BundleGuard::verify( $ctx );

		$this->assertSame( 5, $ctx->bundle_version() );
	}

	public function test_a_tick_on_a_different_pack_fails_the_run(): void {
		$ctx = $this->context( 5, FixturePack::signatures_as( 'lw', 6 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'bundle_changed_mid_run' );

		BundleGuard::verify( $ctx );
	}

	public function test_a_tick_with_no_pack_at_all_says_so(): void {
		// Nothing on disk and nothing handed over: reporting that as
		// "bundle_changed_mid_run" (version 0 against the pinned 5) named
		// the wrong problem. BundlePhase calls the same thing
		// `bundle_missing`.
		$ctx = $this->context( 5 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'bundle_missing' );

		BundleGuard::verify( $ctx );
	}

	/**
	 * @param int             $pinned     Pack version the run pinned; 0 for none.
	 * @param Signatures|null $signatures Signatures the tick was handed, as BundlePhase would; null to leave loading to the Context.
	 */
	private function context( int $pinned = 0, ?Signatures $signatures = null ): Context {
		$cursor = Cursor::fresh( 42, 'changed', '', [ 'bundle', 'files' ] );

		if ( 0 !== $pinned ) {
			BundleGuard::record( $cursor, $pinned );
		}

		$ctx = new Context(
			$cursor,
			new RunStats(),
			[],
			Mockery::mock( FilesRepositoryInterface::class ),
			Mockery::mock( FindingsRepositoryInterface::class ),
			Mockery::mock( RunsRepositoryInterface::class ),
			[]
		);

		if ( null !== $signatures ) {
			$ctx->use_signatures( $signatures );
		}

		return $ctx;
	}
}
