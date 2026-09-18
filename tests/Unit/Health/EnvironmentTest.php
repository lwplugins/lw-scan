<?php
/**
 * Tests for Health\Environment.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Health;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Health\Checks\CheckInterface;
use LightweightPlugins\Scan\Health\Environment;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

require_once dirname( __DIR__ ) . '/Db/FakeWpdb.php';

final class EnvironmentTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	public function test_invalidate_drops_the_cached_report(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'lw_scan_health' )->andReturn( true );

		Environment::invalidate();
	}

	public function test_verdict_is_ok_when_every_check_is_ok(): void {
		$report = $this->subclass_with_checks( [ $this->fake_check( 'ok' ), $this->fake_check( 'ok' ) ] )::report( true );

		$this->assertSame( 'ok', $report['verdict'] );
	}

	public function test_verdict_is_warning_when_a_check_warns(): void {
		$report = $this->subclass_with_checks( [ $this->fake_check( 'ok' ), $this->fake_check( 'warning' ) ] )::report( true );

		$this->assertSame( 'warning', $report['verdict'] );
	}

	public function test_verdict_is_critical_when_a_check_is_critical(): void {
		$report = $this->subclass_with_checks( [ $this->fake_check( 'warning' ), $this->fake_check( 'critical' ) ] )::report( true );

		$this->assertSame( 'critical', $report['verdict'] );
	}

	public function test_verdict_ignores_info_status(): void {
		$report = $this->subclass_with_checks( [ $this->fake_check( 'ok' ), $this->fake_check( 'info' ) ] )::report( true );

		$this->assertSame( 'ok', $report['verdict'] );
	}

	public function test_report_rows_carry_id_label_message_and_blocking(): void {
		$report = $this->subclass_with_checks( [ $this->fake_check( 'critical', true, 'boom' ) ] )::report( true );

		$this->assertSame(
			[
				'id'       => 'fake',
				'label'    => 'Fake check',
				'status'   => 'critical',
				'message'  => 'boom',
				'blocking' => true,
			],
			$report['rows'][0]
		);
	}

	public function test_report_uses_the_transient_cache_unless_fresh(): void {
		$cached = [
			'verdict' => 'warning',
			'rows'    => [],
			'tiles'   => [],
		];

		Functions\when( 'get_transient' )->justReturn( $cached );

		$report = $this->subclass_with_checks( [ $this->fake_check( 'critical' ) ] )::report();

		$this->assertSame( $cached, $report );
	}

	public function test_a_cached_report_still_gets_a_freshly_measured_memory_row(): void {
		// The memory row describes the request that asks, not the site: the
		// same install answers `memory_limit` with 256M on an admin page and
		// with -1 under WP-CLI, so a ten-minute-old memory verdict is
		// whichever request happened to fill the cache.
		Functions\when( 'get_transient' )->justReturn(
			[
				'verdict' => 'ok',
				'rows'    => [
					[
						'id'       => 'memory',
						'label'    => 'Memory & time budget',
						'status'   => 'ok',
						'message'  => 'measured by some other request',
						'blocking' => false,
					],
				],
				'tiles'   => [],
			]
		);

		$report = $this->subclass_with_checks( [ $this->fake_check( 'critical', false, 'measured now', null, 'memory' ) ] )::report();

		$this->assertSame( 'measured now', $report['rows'][0]['message'] );
		$this->assertSame( 'critical', $report['rows'][0]['status'] );
		$this->assertSame( 'critical', $report['verdict'], 'the verdict follows the row that was re-measured' );
	}

	public function test_a_cached_report_keeps_every_other_row_as_it_was(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'verdict' => 'warning',
				'rows'    => [
					[
						'id'       => 'tables',
						'label'    => 'Database tables',
						'status'   => 'warning',
						'message'  => 'cached',
						'blocking' => false,
					],
				],
				'tiles'   => [],
			]
		);

		$report = $this->subclass_with_checks( [ $this->fake_check( 'ok', false, 'not this one', null, 'memory' ) ] )::report();

		$this->assertSame( 'cached', $report['rows'][0]['message'] );
		$this->assertSame( 'warning', $report['verdict'] );
	}

	public function test_report_bypasses_the_transient_cache_when_fresh(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'verdict' => 'ok',
				'rows'    => [],
				'tiles'   => [],
			]
		);

		$report = $this->subclass_with_checks( [ $this->fake_check( 'critical' ) ] )::report( true );

		$this->assertSame( 'critical', $report['verdict'] );
	}

	public function test_report_rows_carry_details_when_a_check_provides_them(): void {
		$details = [ 'tables' => [ 'files' => [ 'rows' => 4455 ] ] ];
		$report  = $this->subclass_with_checks( [ $this->fake_check( 'ok', false, 'msg', $details ) ] )::report( true );

		$this->assertSame( $details, $report['rows'][0]['details'] );
	}

	public function test_report_rows_omit_details_when_a_check_does_not_provide_them(): void {
		$report = $this->subclass_with_checks( [ $this->fake_check( 'ok' ) ] )::report( true );

		$this->assertArrayNotHasKey( 'details', $report['rows'][0] );
	}

	public function test_probe_allowed_mirrors_the_fresh_flag(): void {
		$this->assertTrue( Environment::probe_allowed( true ) );
		$this->assertFalse( Environment::probe_allowed( false ) );
	}

	public function test_report_does_not_allow_the_cron_probe_on_a_cold_cache_when_not_fresh(): void {
		// Cold cache: get_transient() returns false (stubbed in setUp()).
		$class = $this->subclass_with_checks( [ $this->fake_check( 'ok' ) ] );

		$class::report( false );

		$this->assertFalse( $class::$probe_seen );
	}

	public function test_report_allows_the_cron_probe_when_fresh(): void {
		$class = $this->subclass_with_checks( [ $this->fake_check( 'ok' ) ] );

		$class::report( true );

		$this->assertTrue( $class::$probe_seen );
	}

	public function test_the_bundle_tile_counts_signatures_from_stored_state_without_reading_the_bundle(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$dir = sys_get_temp_dir() . '/lw-scan-envtiles-' . uniqid();
		mkdir( $dir, 0755, true );

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $dir ) {
				return 'lw_scan_storage_dir' === $tag ? $dir : $value;
			}
		);
		Functions\when( 'get_option' )->justReturn(
			[
				'bundle_version'    => 20260916045,
				'bundle_count'      => 13298,
				'bundle_checked_at' => 1700000000,
			]
		);

		try {
			// No bundle-<v>.json.gz on disk at all: the tile must not be
			// gunzipping and json_decoding a 5 MB file to count signatures.
			$tiles = $this->subclass_with_real_tiles()::report( true )['tiles'];

			$this->assertSame( 20260916045, $tiles['bundle']['version'] );
			$this->assertSame( 13298, $tiles['bundle']['count'] );
			$this->assertSame( 1700000000, $tiles['bundle']['checked_at'] );
		} finally {
			$this->remove_dir( $dir );
		}
	}

	public function test_a_run_is_refused_when_the_plugins_tables_are_missing(): void {
		// Nothing downstream of the start gate survives a missing table: the
		// run row cannot be written, so the run has nowhere to record what it
		// found. Better to say so than to scan into nothing.
		$this->with_environment(
			[ null, null, null ],
			function (): void {
				$issue = Environment::blocking_issue();

				$this->assertIsString( $issue );
				$this->assertStringContainsString( 'database tables are missing', (string) $issue );
			}
		);
	}

	public function test_a_run_is_allowed_when_storage_bundle_and_tables_are_all_in_place(): void {
		$this->with_environment(
			[ 'wp_lw_scan_files', 'wp_lw_scan_findings', 'wp_lw_scan_runs' ],
			function (): void {
				$this->assertNull( Environment::blocking_issue() );
			}
		);
	}

	/**
	 * Runs `$assertions` against the three real checks `blocking_issue()`
	 * consults: a writable storage directory, `BundleCheck` (never
	 * blocking, so it needs no pack file on disk to satisfy this), and a
	 * wpdb double whose `SHOW TABLES` probes answer `$table_probes`.
	 *
	 * @param array<int, string|null> $table_probes What the three existence probes return, in files/findings/runs order.
	 * @param callable                $assertions   Assertions to run.
	 */
	private function with_environment( array $table_probes, callable $assertions ): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$dir = sys_get_temp_dir() . '/lw-scan-envblocking-' . uniqid();
		mkdir( $dir, 0755, true );

		Functions\stubTranslationFunctions();
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $dir ) {
				return 'lw_scan_storage_dir' === $tag ? $dir : $value;
			}
		);
		Functions\when( 'get_option' )->justReturn( [ 'bundle_version' => 5 ] );
		Functions\when( 'size_format' )->justReturn( '1 KB' );

		$wpdb            = new \wpdb();
		$wpdb->var_queue = $table_probes;
		$GLOBALS['wpdb'] = $wpdb;

		Schema::reset_exists_cache();

		try {
			$assertions();
		} finally {
			unset( $GLOBALS['wpdb'] );
			Schema::reset_exists_cache();
			$this->remove_dir( $dir );
		}
	}

	public function test_cron_command_lines_use_abspath_and_match_the_mockup(): void {
		$lines = Environment::cron_command_lines();

		$this->assertSame(
			[
				sprintf( '0 3 * * * cd %s && wp lw-scan run --scope=changed --quiet', rtrim( ABSPATH, '/\\' ) ),
				sprintf( '*/15 * * * * cd %s && wp cron event run --due-now --quiet', rtrim( ABSPATH, '/\\' ) ),
			],
			$lines
		);
	}

	/**
	 * @param string $dir Directory to delete, with everything under it.
	 */
	private function remove_dir( string $dir ): void {
		foreach ( (array) glob( $dir . '/*' ) as $entry ) {
			is_dir( $entry ) ? $this->remove_dir( $entry ) : unlink( $entry );
		}

		foreach ( (array) glob( $dir . '/.htaccess' ) as $entry ) {
			unlink( $entry );
		}

		rmdir( $dir );
	}

	/**
	 * A subclass with no checks but the real `tiles()`, so the tile figures
	 * can be exercised without constructing every real check.
	 *
	 * @return class-string<Environment>
	 */
	private function subclass_with_real_tiles(): string {
		$environment = new class() extends Environment {

			protected function checks( bool $probe_cron ): array {
				unset( $probe_cron );

				return [];
			}
		};

		return get_class( $environment );
	}

	/**
	 * `report()` builds its working instance with `new static()` (no
	 * constructor args), so the fake checks can't be handed in through a
	 * constructor; a static property set right after creation is the
	 * simplest way to inject them while still exercising the real,
	 * inherited `report()`.
	 *
	 * @param CheckInterface[] $checks Checks the subclass's report() should run.
	 * @return class-string<Environment>
	 */
	private function subclass_with_checks( array $checks ): string {
		$environment = new class() extends Environment {

			/**
			 * @var CheckInterface[]
			 */
			public static array $injected = [];

			/**
			 * The `$probe_cron` argument `report()` last passed to `checks()`.
			 *
			 * @var bool
			 */
			public static bool $probe_seen = false;

			protected function checks( bool $probe_cron ): array {
				self::$probe_seen = $probe_cron;

				return self::$injected;
			}

			protected function tiles(): array {
				return [];
			}
		};

		$class_name             = get_class( $environment );
		$class_name::$injected  = $checks;
		$class_name::$probe_seen = false;

		return $class_name;
	}

	/**
	 * @param array<string,mixed>|null $details Optional `details` element to include in run()'s result.
	 * @param string                   $id      Row id, for the rows report() treats specially.
	 */
	private function fake_check( string $status, bool $blocking = false, string $message = 'msg', ?array $details = null, string $id = 'fake' ): CheckInterface {
		return new class( $status, $blocking, $message, $details, $id ) implements CheckInterface {

			private string $status;

			private bool $blocking;

			private string $message;

			/**
			 * @var array<string,mixed>|null
			 */
			private ?array $details;

			private string $row_id;

			/**
			 * @param array<string,mixed>|null $details Optional `details` element to include in run()'s result.
			 * @param string                   $id      Row id.
			 */
			public function __construct( string $status, bool $blocking, string $message, ?array $details, string $id ) {
				$this->status   = $status;
				$this->blocking = $blocking;
				$this->message  = $message;
				$this->details  = $details;
				$this->row_id   = $id;
			}

			public function id(): string {
				return $this->row_id;
			}

			public function label(): string {
				return 'Fake check';
			}

			public function run(): array {
				$result = [
					'status'   => $this->status,
					'message'  => $this->message,
					'blocking' => $this->blocking,
				];

				if ( null !== $this->details ) {
					$result['details'] = $this->details;
				}

				return $result;
			}
		};
	}
}
