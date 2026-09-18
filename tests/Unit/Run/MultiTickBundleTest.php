<?php
/**
 * Multi-process tick tests: the signature pack across ticks.
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
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Lock;
use LightweightPlugins\Scan\Run\Phase\BundlePhase;
use LightweightPlugins\Scan\Run\Phase\FilesPhase;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use Mockery;

/**
 * Every tick of a real scan is its own PHP process: a fresh `Runner`, a
 * fresh `Context` and an empty `Bundle\PackLoader` memo, with only the
 * stored cursor carried over. These tests model that literally — one
 * `Runner` instance and one `PackLoader::reset()` per tick — over the real
 * `BundlePhase` and `FilesPhase`, which is the only way the "tick 2 scans
 * nothing" defect is visible at all (under WP-CLI a single tick does the
 * whole run).
 */
final class MultiTickBundleTest extends MonkeyTestCase {

	private const RUN_ID = 42;

	/** Version the mini fixture pack is installed as. */
	private const VERSION = 20260101001;

	/** A budget every deadline check is already past, so tick boundaries are deterministic. */
	private const SPENT_BUDGET = 1e-9;

	/** Safety net for the tick loop; the pipeline under test needs six. */
	private const MAX_TICKS = 12;

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var string Temp directory standing in for wp-content/lw-scan/. */
	private string $storage_dir;

	/** @var string ABSPATH-relative directory holding this test's scanned files. */
	private string $scan_dir;

	/** @var Store Pack storage in the temp directory. */
	private Store $store;

	/** @var FilesRepositoryInterface&\Mockery\MockInterface */
	private $files;

	/** @var FindingsRepositoryInterface&\Mockery\MockInterface */
	private $findings;

	/** @var RunsRepositoryInterface&\Mockery\MockInterface */
	private $runs;

	/** @var string The process's own memory_limit, restored after each test. */
	private string $memory_limit;

	protected function setUp(): void {
		parent::setUp();

		// `Gate::memory_limit_bytes()` reads the process's real memory_limit;
		// these tests are about the pack, not about memory, so the suite's
		// own footprint is taken out of it.
		$this->memory_limit = (string) ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '-1' );

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$this->storage_dir = sys_get_temp_dir() . '/lw-scan-multitick-' . uniqid();
		mkdir( $this->storage_dir, 0755, true );

		$this->scan_dir = 'wp-content/uploads/lwscan-multitick-' . uniqid();
		mkdir( ABSPATH . $this->scan_dir, 0755, true );

		$this->option_store = [];
		$this->stub_options();
		$this->stub_wordpress();

		$this->store = new Store( $this->storage_dir );
		PackLoader::use_store( $this->store );

		$this->install_pack( self::VERSION );

		$GLOBALS['wpdb'] = new FakeLockWpdb( array_fill( 0, 2 * self::MAX_TICKS, '1' ) );

		$this->files    = Mockery::mock( FilesRepositoryInterface::class );
		$this->findings = Mockery::mock( FindingsRepositoryInterface::class );
		$this->runs     = Mockery::mock( RunsRepositoryInterface::class );

		$this->findings->shouldReceive( 'new_since' )->andReturn( [] );
		$this->findings->shouldReceive( 'delete_by_locator' )->andReturn( 0 );
		$this->runs->shouldReceive( 'update' )->andReturnNull();
		$this->runs->shouldReceive( 'get' )->with( self::RUN_ID )->andReturn(
			[
				'id'         => self::RUN_ID,
				'status'     => 'running',
				'started_at' => time(),
				'stats'      => [],
			]
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Lock::reset();
		PackLoader::use_store( null );
		$this->remove_dir( $this->storage_dir );
		$this->remove_dir( ABSPATH . $this->scan_dir );
		ini_set( 'memory_limit', $this->memory_limit );
		parent::tearDown();
	}

	/**
	 * Writes the pack into the storage directory and points the stored state
	 * at its version, exactly as `Remote\PackFetcher` would.
	 *
	 * @param int $version Pack version to install.
	 */
	private function install_pack( int $version ): void {
		FixturePack::install_data( $this->store, self::pack_without_regex(), FixturePack::meta_data( 'mini' ), $version );

		$state                                    = (array) ( $this->option_store[ State::OPTION_NAME ] ?? [] );
		$state['bundle_version']                  = $version;
		$this->option_store[ State::OPTION_NAME ] = $state;
	}

	/**
	 * The mini fixture pack with its regex table emptied. `Scanner\RegexLayer`
	 * is the one place a file scan consults the tick deadline, and under the
	 * spent budget these tests run on it would report every single file as a
	 * partially scanned one — the run would never move. What is under test
	 * here is which pack a tick scans with, not regex sweeping, so the
	 * fixture keeps only its hash and literal layers.
	 *
	 * @return array<string, mixed>
	 */
	private static function pack_without_regex(): array {
		$pack  = FixturePack::pack_data( 'mini' );
		$empty = base64_encode( pack( 'V', 0 ) );

		$pack['regex_count']       = 0;
		$pack['regex_patterns']    = '';
		$pack['regex_offsets']     = $empty;
		$pack['regex_sigs']        = '';
		$pack['regex_flags']       = '';
		$pack['regex_lits']        = '';
		$pack['regex_lit_offsets'] = $empty;
		$pack['targets']           = array_fill_keys( array_keys( (array) $pack['targets'] ), '' );

		return $pack;
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
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$store ) {
				unset( $store[ $name ] );

				return true;
			}
		);

		$this->option_store[ Options::OPTION_NAME ] = [
			'bundle_auto_update' => false,
			'heuristics'         => false,
		];
	}

	/**
	 * The WordPress surface `Runner` reaches through a real pipeline:
	 * the storage-directory filter, and the plugin/theme inventory
	 * `Context::software()` builds on the first tick.
	 */
	private function stub_wordpress(): void {
		$storage_dir = $this->storage_dir;

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $storage_dir ) {
				return 'lw_scan_storage_dir' === $tag ? $storage_dir : $value;
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\stubTranslationFunctions();

		// InstalledSoftware::list(): an empty core version keeps
		// Index\KnownGood::core_paths() from ever asking the backend.
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_plugins' )->justReturn( [] );
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'wp_get_themes' )->justReturn( [] );
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {

				public function get_stylesheet(): string {
					return '';
				}
			}
		);
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $entry ) {
			is_dir( $entry ) ? $this->remove_dir( $entry ) : unlink( $entry );
		}

		foreach ( (array) glob( $dir . '/.htaccess' ) as $entry ) {
			unlink( $entry );
		}

		rmdir( $dir );
	}

	/**
	 * @param string $name Basename of the file to create.
	 * @return string ABSPATH-relative path.
	 */
	private function write_file( string $name ): string {
		$rel = $this->scan_dir . '/' . $name;

		file_put_contents( ABSPATH . $rel, "<?php\necho 'hello world';\n" );

		return $rel;
	}

	/**
	 * @param int    $id  Row id.
	 * @param string $rel ABSPATH-relative path.
	 * @return array<string, mixed>
	 */
	private function row( int $id, string $rel ): array {
		$abs = ABSPATH . $rel;

		return [
			'id'             => $id,
			'path'           => $rel,
			'size'           => (int) filesize( $abs ),
			'md5'            => (string) md5_file( $abs ),
			'sha256'         => '',
			'kind'           => 'php',
			'origin'         => 'uploads',
			'known_good'     => 0,
			'path_signal'    => 60,
			'scanned_bundle' => 0,
		];
	}


	/**
	 * Saves the cursor a `start()` would have left behind, on a pipeline
	 * trimmed to the phases these tests care about.
	 */
	private function save_cursor(): void {
		Cursor::fresh( self::RUN_ID, 'changed', '', [ 'bundle', 'files', 'finalize' ] )->save();
	}

	/**
	 * One tick, in its own "process": a brand-new Runner over brand-new
	 * phase instances, with the PackLoader memo cleared the way a fresh PHP
	 * process starts it.
	 *
	 * The budget is deliberately unreachably small, so every deadline check
	 * is already past it: the bundle phase hands the tick back the moment it
	 * completes, and the files phase after exactly one file. That makes the
	 * tick boundaries a property of the pipeline rather than of how fast the
	 * machine running the test happens to be.
	 *
	 * @param callable|null $finalize Side effect for the finalize double.
	 * @return array<string, mixed>
	 */
	private function tick( ?callable $finalize = null ): array {
		PackLoader::reset();

		$runner = new InjectedRunner( $this->files, $this->findings, $this->runs );
		$runner->set_phases(
			[
				'bundle'   => new BundlePhase(),
				'files'    => new FilesPhase(),
				'finalize' => new FakePhase( true, null, $finalize ),
			]
		);

		return $runner->tick( self::SPENT_BUDGET );
	}

	public function test_a_run_keeps_scanning_files_in_the_ticks_after_the_bundle_phase(): void {
		$version = self::VERSION;

		$rows = [
			$this->row( 1, $this->write_file( 'one.php' ) ),
			$this->row( 2, $this->write_file( 'two.php' ) ),
			$this->row( 3, $this->write_file( 'three.php' ) ),
		];

		$asked   = [];
		$scanned = [];

		$this->files->shouldReceive( 'queue_for_scan' )->andReturnUsing(
			static function ( $bundle_version, $after_id, $limit, $prefix ) use ( $rows, $version, &$asked ) {
				unset( $limit, $prefix );

				$asked[] = [ (int) $bundle_version, (int) $after_id ];

				// What `FilesRepository::queue_for_scan()` does on a real
				// site: `scanned_bundle < <version>` matches nothing at all
				// for version 0, so the queue comes back empty.
				if ( (int) $bundle_version !== $version ) {
					return [];
				}

				return array_values(
					array_filter(
						$rows,
						static function ( array $row ) use ( $after_id ): bool {
							return (int) $row['id'] > (int) $after_id;
						}
					)
				);
			}
		);

		$this->files->shouldReceive( 'mark_scanned' )->andReturnUsing(
			static function ( $id, $bundle_version ) use ( &$scanned ): void {
				$scanned[] = [ (int) $id, (int) $bundle_version ];
			}
		);

		$this->save_cursor();

		$captured = [];
		$finalize = static function ( $ctx ) use ( &$captured ): void {
			$captured = $ctx->cursor->to_array();
			Cursor::clear();
		};

		$ticks = $this->tick_until_finished( $finalize );

		$this->assertTrue( $ticks[ count( $ticks ) - 1 ]['finished'], 'the run must reach its end' );

		$this->assertSame(
			[
				[ 1, $version ],
				[ 2, $version ],
				[ 3, $version ],
			],
			$scanned,
			'every queued file must be scanned against the run pack, one per tick'
		);

		$this->assertSame(
			[
				[ $version, 0 ],
				[ $version, 1 ],
				[ $version, 2 ],
				[ $version, 3 ],
			],
			$asked,
			'each tick must resume the queue from the cursor file_id, at the run pack version'
		);

		$this->assertSame( 3, $captured['files_done'] );
		$this->assertSame( $version, $captured['bundle_version'], 'the run must carry the pack version it started on' );
	}

	public function test_a_pack_that_changed_mid_run_fails_the_run(): void {
		$this->files->shouldReceive( 'queue_for_scan' )->andReturn( [] );

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with( self::RUN_ID, 'failed', Mockery::type( 'array' ), 'bundle_changed_mid_run' )
			->andReturnNull();

		$this->save_cursor();

		$first = $this->tick();

		$this->assertSame( 'running', $first['status'] );

		$this->install_pack( self::VERSION + 1 );

		$result = $this->tick();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertNull( Cursor::load() );
	}

	/**
	 * Ticks — each one its own process — until the run reports it is done.
	 *
	 * @param callable $finalize Side effect for the finalize double.
	 * @return array<int, array<string, mixed>> Every tick's result, in order.
	 */
	private function tick_until_finished( callable $finalize ): array {
		$results = [];

		for ( $i = 0; $i < self::MAX_TICKS; $i++ ) {
			$results[] = $this->tick( $finalize );

			if ( $results[ $i ]['finished'] || 'running' !== $results[ $i ]['status'] ) {
				break;
			}
		}

		return $results;
	}
}
