<?php
/**
 * Tests for Run\Context.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\RunStats;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use Mockery;
use RuntimeException;

/**
 * Only the signature accessors are covered here — the rest of the class is
 * memoized `new`s with no behaviour of their own. `Bundle\PackLoader` reads
 * a real temp store, because what these tests are about is exactly the
 * moment the Context goes to disk, and how often.
 */
final class ContextTest extends MonkeyTestCase {

	private const VERSION = 20260901001;

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var string Temp directory standing in for wp-content/lw-scan/. */
	private string $storage_dir;

	private Store $store;

	protected function setUp(): void {
		parent::setUp();

		$this->storage_dir = sys_get_temp_dir() . '/lw-scan-context-' . uniqid();
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

	/**
	 * Writes the lw fixture pack to the store and points the state at it.
	 *
	 * @param int $version Pack version to install.
	 */
	private function install_pack( int $version ): void {
		FixturePack::install( $this->store, 'lw', $version );

		$this->option_store[ State::OPTION_NAME ] = [ 'bundle_version' => $version ];
	}

	private function context(): Context {
		return new Context(
			Cursor::fresh( 42, 'changed', '', [ 'bundle', 'files' ] ),
			new RunStats(),
			[],
			Mockery::mock( FilesRepositoryInterface::class ),
			Mockery::mock( FindingsRepositoryInterface::class ),
			Mockery::mock( RunsRepositoryInterface::class ),
			[]
		);
	}

	public function test_signatures_returns_what_the_bundle_phase_handed_over(): void {
		$this->install_pack( self::VERSION );

		$ctx       = $this->context();
		$handed_in = FixturePack::signatures_as( 'lw', 999 );

		$ctx->use_signatures( $handed_in );

		$this->assertSame( $handed_in, $ctx->signatures() );
		$this->assertSame( 999, $ctx->bundle_version() );
	}

	public function test_signatures_loads_the_stored_pack_when_no_phase_handed_one_over(): void {
		$this->install_pack( self::VERSION );

		$ctx = $this->context();

		$this->assertNotNull( $ctx->signatures() );
		$this->assertSame( self::VERSION, $ctx->signatures()->version() );
		$this->assertSame( self::VERSION, $ctx->bundle_version() );
	}

	public function test_signatures_remembers_that_there_was_nothing_to_load(): void {
		$ctx = $this->context();

		$this->assertNull( $ctx->signatures(), 'no stored pack version means no signatures' );

		// A pack installed behind the Context's back — a second look would
		// find it, but the Context is a per-tick snapshot and must not change
		// what the phases after it scan against.
		$this->install_pack( self::VERSION );
		PackLoader::reset();

		$this->assertNull( $ctx->signatures(), 'the null result must be memoized too' );
		$this->assertSame( 0, $ctx->bundle_version() );
	}

	public function test_handing_over_new_signatures_rebuilds_the_file_scanner(): void {
		$ctx = $this->context();

		$ctx->use_signatures( FixturePack::signatures_as( 'lw', 1 ) );
		$first = $ctx->file_scanner();

		$this->assertSame( $first, $ctx->file_scanner(), 'memoized while the signatures stay the same' );

		$ctx->use_signatures( FixturePack::signatures_as( 'lw', 2 ) );

		$this->assertNotSame( $first, $ctx->file_scanner(), 'a scanner built against the previous signatures must not be reused' );
	}

	public function test_file_scanner_without_signatures_is_bundle_missing(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'bundle_missing' );

		$this->context()->file_scanner();
	}
}
