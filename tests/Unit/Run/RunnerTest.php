<?php
/**
 * Tests for Run\Runner.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Lock;
use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Run\Runner;
use LightweightPlugins\Scan\Run\StopFlag;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use Mockery;
use RuntimeException;

require_once dirname( __DIR__ ) . '/WpErrorStub.php';

/**
 * Every repository is mocked through its interface and the phase pipeline
 * is replaced with FakePhase doubles (InjectedRunner), so these tests cover
 * the orchestration only: start validation, the tick loop's phase
 * sequencing, stop/failure handling and the non-locking progress read.
 */
final class RunnerTest extends MonkeyTestCase {

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var FilesRepositoryInterface&\Mockery\MockInterface */
	private $files;

	/** @var FindingsRepositoryInterface&\Mockery\MockInterface */
	private $findings;

	/** @var RunsRepositoryInterface&\Mockery\MockInterface */
	private $runs;

	/** Temp storage dir standing in for wp-content/lw-scan/, so Health\Environment::blocking_issue() (which Starter now consults for real) sees a writable dir with a loadable bundle. */
	private string $health_dir;

	/** @var string The process's own memory_limit, restored after each test. */
	private string $memory_limit;

	protected function setUp(): void {
		parent::setUp();

		// `Gate::memory_limit_bytes()` reads the process's real memory_limit
		// (it feeds the `memory_limit` stat and the heuristic gate), and
		// every test here but `with_scarce_memory()`'s is about something
		// else entirely. Unlimited takes the suite's own footprint — and
		// whatever the CI runner's ini says — out of the equation.
		$this->memory_limit = (string) ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '-1' );

		$this->option_store = [];
		$this->stub_options();
		$this->stub_environment_as_healthy();

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\stubTranslationFunctions();

		$GLOBALS['wpdb'] = new FakeLockWpdb( [ '1', '1', '1', '1', '1', '1' ] );

		$this->files    = Mockery::mock( FilesRepositoryInterface::class );
		$this->findings = Mockery::mock( FindingsRepositoryInterface::class );
		$this->runs     = Mockery::mock( RunsRepositoryInterface::class );

		$this->findings->shouldReceive( 'new_since' )->andReturn( [] );
		$this->runs->shouldReceive( 'update' )->andReturnNull();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Lock::reset();
		PackLoader::use_store( null );
		$this->remove_dir( $this->health_dir );
		ini_set( 'memory_limit', $this->memory_limit );
		parent::tearDown();
	}

	/**
	 * `Starter::open()` consults `Health\Environment::blocking_issue()` for
	 * real now (the class exists as of Task 20); it runs `StorageCheck` and
	 * `BundleCheck` fresh, so every `start()` test needs a writable storage
	 * dir and a loadable bundle to not be wrongly blocked. This is
	 * orthogonal to what RunnerTest actually covers (phase orchestration),
	 * so it's stubbed to always report healthy rather than exercised.
	 */
	private function stub_environment_as_healthy(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$this->health_dir = sys_get_temp_dir() . '/lw-scan-runnertest-' . uniqid();
		mkdir( $this->health_dir, 0755, true );
		PackLoader::use_store( new Store( $this->health_dir ) );
		$this->write_signature_files( 1 );

		$health_dir = $this->health_dir;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $health_dir ) {
				return 'lw_scan_storage_dir' === $tag ? $health_dir : $value;
			}
		);
		Functions\when( 'size_format' )->justReturn( '0 B' );

		$this->option_store['lw_scan_state'] = [ 'bundle_version' => 1 ];
	}

	/**
	 * The signature files a version needs on disk: the pack and meta a tick
	 * scans with (`Bundle\PackLoader`), which is also all `Health\Checks\BundleCheck`
	 * looks at now.
	 *
	 * @param int $version Version to write.
	 */
	private function write_signature_files( int $version ): void {
		FixturePack::install( new Store( $this->health_dir ), 'lw', $version );
	}

	/**
	 * Takes the storage directory back to what a fresh install has: an empty
	 * state, and no signature file of any kind on disk.
	 */
	private function forget_the_bundle(): void {
		foreach ( [ 'bundle-*', 'pack-*', 'meta-*' ] as $pattern ) {
			foreach ( (array) glob( $this->health_dir . '/' . $pattern ) as $file ) {
				unlink( $file );
			}
		}

		$this->option_store['lw_scan_state'] = [];
		PackLoader::reset();
	}

	/**
	 * Writes another version's signature files into the storage dir and
	 * points the stored state at it, as a signature update would.
	 *
	 * @param int $version Version to install.
	 */
	private function install_bundle( int $version ): void {
		$this->write_signature_files( $version );

		\LightweightPlugins\Scan\State::set( 'bundle_version', $version );
		PackLoader::reset();
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			is_dir( $file ) ? $this->remove_dir( $file ) : unlink( $file );
		}

		foreach ( (array) glob( $dir . '/.htaccess' ) as $file ) {
			unlink( $file );
		}

		rmdir( $dir );
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
	}

	/**
	 * @param array<string, \LightweightPlugins\Scan\Run\Phase\PhaseInterface> $phases Phase name => double.
	 */
	private function runner( array $phases = [] ): InjectedRunner {
		$runner = new InjectedRunner( $this->files, $this->findings, $this->runs );
		$runner->set_phases( $phases );

		return $runner;
	}

	/**
	 * Saves a cursor and stubs the run row it points at.
	 *
	 * @param string $scope   Scan scope.
	 * @param string $status  Status of the matching run row.
	 * @param int    $run_id  Run id.
	 */
	private function save_cursor( string $scope = 'db', string $status = 'running', int $run_id = 42 ): Cursor {
		$cursor = Cursor::fresh( $run_id, $scope, '', Phases::for_scope( $scope ) );
		$cursor->save();

		$this->stub_run_row( $status, $run_id );

		return $cursor;
	}

	/**
	 * @param string               $status Status of the run row.
	 * @param int                  $run_id Run id.
	 * @param array<string, mixed> $stats  Stats already stored on the row.
	 */
	private function stub_run_row( string $status = 'running', int $run_id = 42, array $stats = [] ): void {
		$this->runs->shouldReceive( 'get' )->with( $run_id )->andReturn(
			[
				'id'         => $run_id,
				'status'     => $status,
				'started_at' => time(),
				'stats'      => $stats,
			]
		);
	}

	public function test_start_creates_a_run_row_and_saves_a_fresh_cursor(): void {
		$this->runs->shouldReceive( 'create' )->once()->with( 'manual', 'changed', '', 1 )->andReturn( 7 );

		$result = $this->runner()->start( 'manual', 'changed' );

		$this->assertSame( 7, $result );

		$cursor = Cursor::load();
		$this->assertNotNull( $cursor );
		$this->assertSame( 7, $cursor->run_id() );
		$this->assertSame( 'bundle', $cursor->phase() );
		$this->assertSame( 'changed', $cursor->scope() );
	}

	public function test_a_manual_start_drops_the_cached_vulnerability_feed(): void {
		$cache = $this->seed_cache_files();

		$this->runs->shouldReceive( 'create' )->once()->andReturn( 7 );

		$this->assertSame( 7, $this->runner()->start( 'manual', 'db' ) );

		$this->assertFileDoesNotExist( $cache . '/vuln-plugin-elementor.json' );
		$this->assertFileExists( $cache . '/checksum-core-6.8.json' );
	}

	public function test_a_scheduled_start_keeps_the_cached_vulnerability_feed(): void {
		$cache = $this->seed_cache_files();

		$this->runs->shouldReceive( 'create' )->once()->andReturn( 7 );

		$this->assertSame( 7, $this->runner()->start( 'cron', 'changed' ) );

		$this->assertFileExists( $cache . '/vuln-plugin-elementor.json' );
	}

	public function test_a_path_scan_keeps_the_cached_vulnerability_feed(): void {
		// scope=path has no vuln phase, so there is nothing to refresh for.
		$cache = $this->seed_cache_files();

		$this->runs->shouldReceive( 'create' )->once()->andReturn( 7 );

		$this->assertSame( 7, $this->runner()->start( 'manual', 'path', 'Fixtures/corpus' ) );

		$this->assertFileExists( $cache . '/vuln-plugin-elementor.json' );
	}

	/**
	 * One cached vulnerability response and one checksum list in the test
	 * storage dir's cache.
	 *
	 * @return string The cache directory.
	 */
	private function seed_cache_files(): string {
		$cache = ( new Store( $this->health_dir ) )->cache_dir();

		file_put_contents( $cache . '/vuln-plugin-elementor.json', '{"vulnerabilities":[]}' );
		file_put_contents( $cache . '/checksum-core-6.8.json', '{}' );

		return $cache;
	}

	public function test_start_opens_the_first_run_on_an_install_with_no_bundle_yet(): void {
		// A fresh install: nothing downloaded, nothing compiled, state at 0.
		// The `bundle` phase of this very run is what fetches the signatures,
		// so the start gate must not be the thing that stops it.
		$this->forget_the_bundle();

		$this->runs->shouldReceive( 'create' )->once()->with( 'manual', 'changed', '', 0 )->andReturn( 3 );

		$this->assertSame( 3, $this->runner()->start( 'manual', 'changed' ) );

		$cursor = Cursor::load();
		$this->assertNotNull( $cursor );
		$this->assertSame( 'bundle', $cursor->phase() );
	}

	public function test_start_refuses_when_the_run_row_could_not_be_written(): void {
		// $wpdb->insert() failing leaves insert_id at 0. Taking that as a run
		// id meant a cursor pointing at run 0, a tick writing findings against
		// a row that does not exist, and a Scan tab with nothing to show.
		$this->runs->shouldReceive( 'create' )->once()->andReturn( 0 );

		$result = $this->runner()->start( 'manual', 'changed' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lw_scan_no_run', $result->get_error_code() );
		$this->assertNull( Cursor::load(), 'no cursor may be left pointing at a run that was never created' );
	}

	public function test_start_refuses_when_the_plugins_tables_are_missing(): void {
		$GLOBALS['wpdb']->tables_installed = false;

		$this->runs->shouldReceive( 'create' )->never();

		$result = $this->runner()->start( 'manual', 'changed' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lw_scan_blocked', $result->get_error_code() );
	}

	public function test_start_returns_busy_when_a_cursor_already_exists(): void {
		$this->save_cursor();
		$this->runs->shouldReceive( 'create' )->never();

		$result = $this->runner()->start( 'manual', 'changed' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lw_scan_busy', $result->get_error_code() );
	}

	public function test_start_with_resume_reopens_the_stopped_run_and_keeps_its_cursor(): void {
		$cursor = $this->save_cursor( 'db', 'stopped' );
		$cursor->set_phase( 'vuln' );
		$cursor->set( 'stop_requested', true );
		$cursor->save();

		$this->runs->shouldReceive( 'create' )->never();

		$result = $this->runner()->start( 'manual', 'changed', '', true );

		$this->assertSame( 42, $result );

		$reloaded = Cursor::load();
		$this->assertNotNull( $reloaded );
		$this->assertSame( 'vuln', $reloaded->phase() );
		$this->assertFalse( $reloaded->stop_requested() );
	}

	public function test_start_clears_a_stopped_cursor_and_starts_fresh_without_resume(): void {
		$this->save_cursor( 'db', 'stopped' );

		$this->runs->shouldReceive( 'create' )->once()->with( 'manual', 'changed', '', 1 )->andReturn( 51 );

		$this->assertSame( 51, $this->runner()->start( 'manual', 'changed' ) );

		$cursor = Cursor::load();
		$this->assertNotNull( $cursor );
		$this->assertSame( 51, $cursor->run_id() );
		$this->assertSame( 'bundle', $cursor->phase() );
		$this->assertFalse( $cursor->stop_requested() );
	}

	public function test_start_with_resume_keeps_going_while_the_bundle_is_the_one_the_run_pinned(): void {
		$cursor = $this->save_cursor( 'db', 'stopped' );
		$cursor->set( 'bundle_version', 1 );
		$cursor->set_phase( 'vuln' );
		$cursor->save();

		$this->runs->shouldReceive( 'create' )->never();

		$this->assertSame( 42, $this->runner()->start( 'manual', 'changed', '', true ) );

		$reloaded = Cursor::load();
		$this->assertNotNull( $reloaded );
		$this->assertSame( 'vuln', $reloaded->phase() );
	}

	public function test_start_with_resume_starts_over_when_the_signatures_were_updated(): void {
		$cursor = $this->save_cursor( 'db', 'stopped' );
		$cursor->set( 'trigger', 'cron' );
		$cursor->set( 'bundle_version', 1 );
		$cursor->set_phase( 'vuln' );
		$cursor->save();

		// "Update signatures" landed while the run was stopped: a new bundle
		// on disk and the state pointing at it.
		$this->install_bundle( 2 );

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with( 42, 'failed', Mockery::type( 'array' ), 'bundle_changed' )
			->andReturnNull();
		$this->runs->shouldReceive( 'create' )->once()->with( 'cron', 'db', '', 2 )->andReturn( 77 );

		$this->assertSame( 77, $this->runner()->start( 'manual', 'changed', '', true ) );

		$reloaded = Cursor::load();
		$this->assertNotNull( $reloaded );
		$this->assertSame( 77, $reloaded->run_id() );
		$this->assertSame( 'bundle', $reloaded->phase(), 'the replacement run starts at the beginning' );
		$this->assertSame( 'db', $reloaded->scope(), 'and scans what the stopped run was scanning' );
	}

	public function test_start_with_resume_replaces_the_run_even_when_memory_is_scarce(): void {
		// There is no memory gate left to refuse a replacement run on
		// (Task 12): a host with almost nothing free still gets to try, and
		// an actual out-of-memory failure is reported plainly if the tick
		// then dies of one.
		$cursor = $this->save_cursor( 'db', 'stopped' );
		$cursor->set( 'bundle_version', 1 );
		$cursor->save();

		$this->install_bundle( 2 );

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with( 42, 'failed', Mockery::type( 'array' ), 'bundle_changed' )
			->andReturnNull();
		$this->runs->shouldReceive( 'create' )->once()->with( 'manual', 'db', '', 2 )->andReturn( 77 );

		$result = $this->with_scarce_memory(
			function () {
				return $this->runner()->start( 'manual', 'changed', '', true );
			}
		);

		$this->assertSame( 77, $result );
	}

	public function test_start_with_resume_refuses_a_run_that_is_still_running(): void {
		$this->save_cursor( 'db', 'running' );
		$this->runs->shouldReceive( 'create' )->never();

		$result = $this->runner()->start( 'manual', 'changed', '', true );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lw_scan_busy', $result->get_error_code() );
	}

	public function test_start_with_resume_reports_nothing_to_resume_for_a_finished_run(): void {
		$this->save_cursor( 'db', 'done' );
		$this->runs->shouldReceive( 'create' )->never();

		$result = $this->runner()->start( 'manual', 'changed', '', true );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lw_scan_nothing_to_resume', $result->get_error_code() );
	}

	public function test_start_rejects_an_unknown_scope(): void {
		$this->runs->shouldReceive( 'create' )->never();

		$result = $this->runner()->start( 'manual', 'everything' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lw_scan_bad_scope', $result->get_error_code() );
	}

	public function test_start_rejects_a_path_scope_outside_abspath(): void {
		$this->runs->shouldReceive( 'create' )->never();

		$result = $this->runner()->start( 'manual', 'path', '../../etc' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lw_scan_bad_path', $result->get_error_code() );
	}

	public function test_start_accepts_a_path_scope_inside_abspath(): void {
		$this->runs->shouldReceive( 'create' )->once()->with( 'cli', 'path', 'Fixtures/corpus', 1 )->andReturn( 9 );

		$this->assertSame( 9, $this->runner()->start( 'cli', 'path', 'Fixtures/corpus' ) );
	}

	public function test_start_succeeds_even_when_memory_is_scarce(): void {
		$this->runs->shouldReceive( 'create' )->once()->with( 'manual', 'changed', '', 1 )->andReturn( 12 );

		$result = $this->with_scarce_memory(
			function () {
				return $this->runner()->start( 'manual', 'changed' );
			}
		);

		$this->assertSame( 12, $result );
	}

	public function test_tick_does_not_fail_a_run_another_request_is_ticking(): void {
		$this->save_cursor();
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ '0' ] );

		$this->runs->shouldReceive( 'finish' )->never();

		$result = $this->runner( [ 'bundle' => new FakePhase( true ) ] )->tick( 5.0 );

		$this->assertSame( 'busy', $result['status'] );
		$this->assertNotNull( Cursor::load() );
	}

	public function test_tick_stops_rather_than_failing_when_a_stop_is_pending(): void {
		$cursor = $this->save_cursor();
		$cursor->set( 'stop_requested', true );
		$cursor->save();

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with( 42, 'stopped', Mockery::type( 'array' ) )
			->andReturnNull();

		$result = $this->runner( [ 'bundle' => new FakePhase( true ) ] )->tick( 5.0 );

		$this->assertSame( 'stopped', $result['status'] );
		$this->assertNotNull( Cursor::load(), 'a stopped run keeps its cursor and can be resumed' );
	}

	public function test_a_fatal_out_of_memory_message_closes_the_run_with_the_limit_it_measured(): void {
		$this->save_cursor();

		$captured = [];

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with(
				42,
				'failed',
				Mockery::on(
					static function ( $stats ) use ( &$captured ): bool {
						$captured = $stats;

						return is_array( $stats );
					}
				),
				'out_of_memory'
			)
			->andReturnNull();

		// A phase that never completes leaves the tick "mid-flight", the
		// same state a real fatal would interrupt. A bounded memory_limit
		// (rather than setUp()'s default unlimited one) is what makes the
		// tick stamp a `memory_limit` stat in the first place.
		$this->with_scarce_memory(
			function (): void {
				$runner = $this->runner( [ 'bundle' => new FakePhase( false ) ] );
				$runner->tick( 0.0001 );

				$this->invoke_fatal_guard( $runner, 'Allowed memory size of 268435456 bytes exhausted (tried to allocate 20480 bytes)' );
			}
		);

		$this->assertArrayHasKey( 'memory_limit', $captured );
	}

	public function test_a_fatal_message_without_the_allowed_memory_size_prefix_is_recorded_verbatim(): void {
		$this->save_cursor();

		$captured_error = null;

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with(
				42,
				'failed',
				Mockery::type( 'array' ),
				Mockery::on(
					static function ( $error ) use ( &$captured_error ): bool {
						$captured_error = $error;

						return true;
					}
				)
			)
			->andReturnNull();

		$runner = $this->runner( [ 'bundle' => new FakePhase( false ) ] );
		$runner->tick( 0.0001 );

		$this->invoke_fatal_guard( $runner, 'Call to undefined function foo()' );

		$this->assertSame( 'Call to undefined function foo()', $captured_error );
	}

	public function test_tick_without_a_cursor_reports_idle_even_when_memory_is_scarce(): void {
		$this->runs->shouldReceive( 'finish' )->never();

		$result = $this->with_scarce_memory(
			function (): array {
				return $this->runner()->tick( 5.0 );
			}
		);

		$this->assertSame( 'idle', $result['status'] );
	}

	/**
	 * Runs $callback with 1 MB of memory left. There is no memory gate left
	 * to trip on this (Task 12), so every caller of this helper asserts the
	 * opposite of what it used to: the call goes ahead anyway.
	 *
	 * @param callable $callback What to run.
	 * @return mixed Whatever $callback returned.
	 */
	private function with_scarce_memory( callable $callback ) {
		$previous = (string) ini_get( 'memory_limit' );

		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + ( 1 * 1024 * 1024 ) ) );

		try {
			return $callback();
		} finally {
			ini_set( 'memory_limit', $previous );
		}
	}

	/**
	 * Extracts the `FatalGuard` callback a `Runner` wired itself up with in
	 * its constructor and invokes it directly, standing in for the fatal
	 * error `FatalGuard`'s own shutdown handler would otherwise have to
	 * catch — not reproducible in a test process without ending it.
	 *
	 * @param Runner $runner  The runner whose guard callback is invoked.
	 * @param string $message The fatal error message to simulate.
	 */
	private function invoke_fatal_guard( Runner $runner, string $message ): void {
		// setAccessible() is a no-op since PHP 8.1 and deprecated in 8.5.
		$guard = ( new \ReflectionProperty( Runner::class, 'guard' ) )->getValue( $runner );

		$callback = ( new \ReflectionProperty( \LightweightPlugins\Scan\Run\FatalGuard::class, 'on_fatal' ) )->getValue( $guard );

		$callback( $message );
	}

	public function test_tick_without_a_cursor_reports_idle(): void {
		$result = $this->runner()->tick( 5.0 );

		$this->assertSame( 'idle', $result['status'] );
		$this->assertFalse( $result['finished'] );
	}

	public function test_tick_reports_busy_when_the_lock_is_held_elsewhere(): void {
		$this->save_cursor();
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ '0' ] );

		$phase = new FakePhase( true );

		$result = $this->runner( [ 'bundle' => $phase ] )->tick( 5.0 );

		$this->assertSame( 'busy', $result['status'] );
		$this->assertSame( 0, $phase->calls );
	}

	public function test_tick_advances_to_the_next_phase_when_a_phase_completes(): void {
		$this->save_cursor();

		$bundle = new FakePhase( true );
		$db     = new FakePhase( false );

		$result = $this->runner(
			[
				'bundle' => $bundle,
				'db'     => $db,
			]
		)->tick( 0.0001 );

		$this->assertSame( 'running', $result['status'] );
		$this->assertFalse( $result['finished'] );
		$this->assertSame( 1, $bundle->calls );

		$cursor = Cursor::load();
		$this->assertNotNull( $cursor );
		$this->assertSame( 'db', $cursor->phase() );
	}

	public function test_tick_keeps_the_same_phase_when_the_phase_runs_out_of_budget(): void {
		$this->save_cursor();

		$bundle = new FakePhase( false );

		$result = $this->runner( [ 'bundle' => $bundle ] )->tick( 0.0001 );

		$this->assertSame( 'running', $result['status'] );
		$this->assertFalse( $result['finished'] );

		$cursor = Cursor::load();
		$this->assertNotNull( $cursor );
		$this->assertSame( 'bundle', $cursor->phase() );
	}

	public function test_tick_fires_the_phase_changed_action_when_the_phase_advances(): void {
		$this->save_cursor();

		Actions\expectDone( 'lw_scan_phase_changed' )->once()->with( 'db', Mockery::type( 'array' ) );

		$this->runner(
			[
				'bundle' => new FakePhase( true ),
				'db'     => new FakePhase( false ),
			]
		)->tick( 0.0001 );
	}

	public function test_tick_finishes_the_run_when_the_last_phase_completes(): void {
		$this->save_cursor();

		$finalize = new FakePhase(
			true,
			null,
			static function (): void {
				Cursor::clear();
			}
		);

		$result = $this->runner(
			[
				'bundle'   => new FakePhase( true ),
				'db'       => new FakePhase( true ),
				'vuln'     => new FakePhase( true ),
				'finalize' => $finalize,
			]
		)->tick( 5.0 );

		$this->assertTrue( $result['finished'] );
		$this->assertSame( 'finished', $result['status'] );
		$this->assertSame( 1, $finalize->calls );
		$this->assertNull( Cursor::load() );
	}

	public function test_start_resets_the_remote_counters(): void {
		Client::$calls     = 17;
		Client::$errors    = 3;
		Client::$not_found = 5;

		$this->runs->shouldReceive( 'create' )->once()->andReturn( 7 );

		$this->runner()->start( 'manual', 'changed' );

		$this->assertSame(
			[
				'calls'     => 0,
				'errors'    => 0,
				'not_found' => 0,
			],
			Client::counters()
		);
	}

	public function test_a_phases_backend_traffic_is_in_the_stats_the_next_phase_sees(): void {
		$this->save_cursor();
		Client::reset_counters();

		$captured = [];

		$bundle = new FakePhase(
			true,
			null,
			static function (): void {
				Client::$calls  += 2;
				Client::$errors += 1;
			}
		);

		$finalize = new FakePhase(
			true,
			null,
			static function ( $ctx ) use ( &$captured ): void {
				// Snapshot the stats exactly where FinalizePhase writes them
				// onto the run row: the tick-level meter recorded its delta
				// only after this, so the run was always stored with zeros.
				$captured = $ctx->stats->to_array();
				Cursor::clear();
			}
		);

		$this->runner(
			[
				'bundle'   => $bundle,
				'db'       => new FakePhase( true ),
				'vuln'     => new FakePhase( true ),
				'finalize' => $finalize,
			]
		)->tick( 5.0 );

		$this->assertSame( 2, $captured['remote']['calls'] );
		$this->assertSame( 1, $captured['remote']['errors'] );
	}

	public function test_a_throwing_phases_backend_traffic_is_still_recorded(): void {
		$this->save_cursor();
		Client::reset_counters();

		$captured = [];

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with(
				42,
				'failed',
				Mockery::on(
					static function ( $stats ) use ( &$captured ): bool {
						$captured = $stats;

						return is_array( $stats );
					}
				),
				'boom'
			)
			->andReturnNull();

		$phase = new FakePhase(
			true,
			null,
			static function (): void {
				Client::$calls += 3;

				throw new RuntimeException( 'boom' );
			}
		);

		$this->runner( [ 'bundle' => $phase ] )->tick( 5.0 );

		$this->assertSame( 3, $captured['remote']['calls'] );
	}

	public function test_tick_records_this_ticks_peak_memory(): void {
		$this->save_cursor();

		$this->assertGreaterThan( 0, $this->stats_after_the_first_phase()['peak_memory'] );
	}

	public function test_tick_keeps_the_highest_peak_memory_any_tick_reported(): void {
		$cursor = Cursor::fresh( 42, 'db', '', Phases::for_scope( 'db' ) );
		$cursor->save();

		// A previous tick's high-water mark, far above anything this test
		// process will reach: a quieter tick must not write it down.
		$this->stub_run_row( 'running', 42, [ 'peak_memory' => 4294967296 ] );

		$this->assertSame( 4294967296, $this->stats_after_the_first_phase()['peak_memory'] );
	}

	public function test_tick_does_not_stamp_memory_limit_when_it_is_unlimited(): void {
		$this->save_cursor();

		// setUp() leaves the process's memory_limit at '-1': Gate::memory_limit_bytes()
		// reads that as PHP_INT_MAX, and stamping that sentinel would let
		// RunError::memory_label() print something like "8 EB".
		$this->assertArrayNotHasKey( 'memory_limit', $this->stats_after_the_first_phase() );
	}

	public function test_tick_stamps_memory_limit_when_it_is_bounded(): void {
		$this->save_cursor();

		$stats = $this->with_scarce_memory(
			function (): array {
				return $this->stats_after_the_first_phase();
			}
		);

		$this->assertArrayHasKey( 'memory_limit', $stats );
	}

	/**
	 * Runs one tick over two phases and returns the stats the second one
	 * saw -- which is the first point at which a phase step, and so a
	 * peak-memory reading, has been booked.
	 *
	 * @return array<string, mixed>
	 */
	private function stats_after_the_first_phase(): array {
		$captured = [];

		$this->runner(
			[
				'bundle' => new FakePhase( true ),
				'db'     => new FakePhase(
					false,
					null,
					static function ( $ctx ) use ( &$captured ): void {
						$captured = $ctx->stats->to_array();
					}
				),
			]
		)->tick( 5.0 );

		return $captured;
	}

	public function test_tick_marks_the_run_failed_and_clears_the_cursor_when_a_phase_throws(): void {
		$this->save_cursor();

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with( 42, 'failed', Mockery::type( 'array' ), 'boom' )
			->andReturnNull();

		$result = $this->runner( [ 'bundle' => new FakePhase( true, new RuntimeException( 'boom' ) ) ] )->tick( 5.0 );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertNull( Cursor::load() );
	}

	public function test_tick_stops_and_keeps_the_cursor_when_a_stop_was_requested(): void {
		$cursor = $this->save_cursor();
		$cursor->set( 'stop_requested', true );
		$cursor->save();

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with( 42, 'stopped', Mockery::type( 'array' ) )
			->andReturnNull();

		$phase = new FakePhase( true );

		$result = $this->runner( [ 'bundle' => $phase ] )->tick( 5.0 );

		$this->assertSame( 'stopped', $result['status'] );
		$this->assertSame( 0, $phase->calls );
		$this->assertNotNull( Cursor::load() );
	}

	public function test_tick_stops_when_a_stop_arrives_while_the_tick_is_running(): void {
		$this->save_cursor();

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with( 42, 'stopped', Mockery::type( 'array' ) )
			->andReturnNull();

		$db = new FakePhase( true );

		$result = $this->runner(
			[
				'bundle' => new FakePhase(
					true,
					null,
					static function (): void {
						StopFlag::request();
					}
				),
				'db'     => $db,
			]
		)->tick( 5.0 );

		$this->assertSame( 'stopped', $result['status'] );
		$this->assertSame( 0, $db->calls );

		$cursor = Cursor::load();
		$this->assertNotNull( $cursor );
		$this->assertTrue( $cursor->stop_requested() );
	}

	public function test_tick_on_an_already_stopped_run_does_not_finish_it_again(): void {
		$cursor = $this->save_cursor( 'db', 'stopped' );
		$cursor->set( 'stop_requested', true );
		$cursor->save();

		$this->runs->shouldReceive( 'finish' )->never();

		$phase = new FakePhase( true );

		$result = $this->runner( [ 'bundle' => $phase ] )->tick( 5.0 );

		$this->assertSame( 'stopped', $result['status'] );
		$this->assertSame( 0, $phase->calls );
		$this->assertNotNull( Cursor::load() );
	}

	public function test_tick_sees_a_stop_that_only_a_fresh_option_read_reveals(): void {
		$cursor = $this->save_cursor();

		$stopped = $cursor->to_array();
		$stopped['stop_requested'] = true;

		$stale   = $this->option_store;
		$flushed = false;

		// Stands in for WordPress' request-level options cache: until the
		// ticking process drops the cached entry it keeps seeing its own
		// copy, so the concurrent Stop is invisible.
		Functions\when( 'wp_cache_delete' )->alias(
			static function () use ( &$flushed ): bool {
				$flushed = true;

				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$stale, &$flushed, $stopped ) {
				if ( \LightweightPlugins\Scan\State::OPTION_NAME === $name && $flushed ) {
					return [ 'run' => $stopped ];
				}

				return array_key_exists( $name, $stale ) ? $stale[ $name ] : $default_value;
			}
		);

		$this->runs->shouldReceive( 'finish' )
			->once()
			->with( 42, 'stopped', Mockery::type( 'array' ) )
			->andReturnNull();

		$db = new FakePhase( true );

		$result = $this->runner(
			[
				'bundle' => new FakePhase( true ),
				'db'     => $db,
			]
		)->tick( 5.0 );

		$this->assertTrue( $flushed, 'the runner must drop the cached option before reading the stop flag' );
		$this->assertSame( 'stopped', $result['status'] );
		$this->assertSame( 0, $db->calls );
	}

	public function test_budget_falls_back_to_twenty_seconds_without_an_execution_limit(): void {
		$this->with_max_execution_time(
			'0',
			function (): void {
				$this->assertSame( 20.0, Runner::budget() );
			}
		);
	}

	public function test_budget_is_capped_at_twenty_seconds(): void {
		$this->with_max_execution_time(
			'60',
			function (): void {
				$this->assertSame( 20.0, Runner::budget() );
			}
		);
	}

	public function test_budget_has_a_five_second_floor(): void {
		$this->with_max_execution_time(
			'15',
			function (): void {
				$this->assertSame( 5.0, Runner::budget() );
			}
		);
	}

	public function test_progress_is_idle_without_a_cursor(): void {
		$this->runs->shouldReceive( 'last' )->andReturn( null );

		$progress = $this->runner()->progress();

		$this->assertSame( 'idle', $progress['status'] );
		$this->assertSame( 0, $progress['run_id'] );
	}

	public function test_progress_reports_the_running_phase_from_the_cursor(): void {
		$cursor = $this->save_cursor();
		$cursor->set( 'files_total', 120 );
		$cursor->set( 'files_done', 30 );
		$cursor->save();

		$this->runs->shouldReceive( 'last' )->andReturn( null );

		$progress = $this->runner()->progress();

		$this->assertSame( 'running', $progress['status'] );
		$this->assertSame( 'bundle', $progress['phase'] );
		$this->assertSame( 30, $progress['done'] );
		$this->assertSame( 120, $progress['total'] );
		$this->assertSame( 42, $progress['run_id'] );
	}

	/**
	 * @param string   $value    max_execution_time value to run $callback under.
	 * @param callable $callback Assertions to run.
	 */
	private function with_max_execution_time( string $value, callable $callback ): void {
		$previous = (string) ini_get( 'max_execution_time' );

		ini_set( 'max_execution_time', $value );

		try {
			$callback();
		} finally {
			ini_set( 'max_execution_time', $previous );
		}
	}
}
