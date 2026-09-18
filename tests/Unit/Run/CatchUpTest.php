<?php
/**
 * Tests for Run\CatchUp.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use DateTimeZone;
use LightweightPlugins\Scan\Run\CatchUp;
use LightweightPlugins\Scan\Run\Scheduler;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class CatchUpTest extends MonkeyTestCase {

	/** @var array<string, mixed> In-memory stand-in for the options/state tables. */
	private array $option_store = [];

	/** @var array<string, mixed> In-memory stand-in for the transients table. */
	private array $transient_store = [];

	protected function setUp(): void {
		parent::setUp();

		$this->option_store    = [];
		$this->transient_store = [];
		$options                = &$this->option_store;
		$transients              = &$this->transient_store;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( $name ) use ( &$transients ) {
				return array_key_exists( $name, $transients ) ? $transients[ $name ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $name, $value ) use ( &$transients ) {
				$transients[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ) use ( &$transients ) {
				unset( $transients[ $name ] );

				return true;
			}
		);

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
	}

	public function test_maybe_run_does_nothing_while_doing_ajax(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$this->option_store['lw_scan_options'] = [ 'next_due' => time() - 100 ];

		Functions\expect( 'wp_schedule_single_event' )->never();

		CatchUp::maybe_run();
	}

	public function test_maybe_run_does_nothing_while_doing_cron(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->option_store['lw_scan_options'] = [ 'next_due' => time() - 100 ];

		Functions\expect( 'wp_schedule_single_event' )->never();

		CatchUp::maybe_run();
	}

	#[RunInSeparateProcess]
	public function test_maybe_run_does_nothing_during_a_rest_request(): void {
		define( 'REST_REQUEST', true );

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$this->option_store['lw_scan_options'] = [ 'next_due' => time() - 100 ];

		Functions\expect( 'wp_schedule_single_event' )->never();

		CatchUp::maybe_run();
	}

	#[RunInSeparateProcess]
	public function test_maybe_run_does_nothing_under_wp_cli(): void {
		define( 'WP_CLI', true );

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$this->option_store['lw_scan_options'] = [ 'next_due' => time() - 100 ];

		Functions\expect( 'wp_schedule_single_event' )->never();

		CatchUp::maybe_run();
	}

	public function test_maybe_run_does_nothing_when_not_yet_due(): void {
		$this->option_store['lw_scan_options'] = [ 'next_due' => time() + 3600 ];

		Functions\expect( 'wp_schedule_single_event' )->never();

		CatchUp::maybe_run();
	}

	public function test_maybe_run_does_nothing_when_next_due_is_unset(): void {
		$this->option_store['lw_scan_options'] = [ 'next_due' => 0 ];

		Functions\expect( 'wp_schedule_single_event' )->never();

		CatchUp::maybe_run();
	}

	public function test_maybe_run_does_nothing_when_a_run_is_already_in_progress(): void {
		$this->option_store['lw_scan_options'] = [ 'next_due' => time() - 100 ];
		$this->option_store['lw_scan_state']   = [
			'run' => [
				'run_id' => 1,
				'phase'  => 'bundle',
				'phases' => [ 'bundle' ],
			],
		];

		Functions\expect( 'wp_schedule_single_event' )->never();

		CatchUp::maybe_run();
	}

	public function test_maybe_run_schedules_a_catchup_run_when_overdue_and_idle(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		$overdue_since                          = time() - 100;
		$this->option_store['lw_scan_options'] = [
			'next_due'      => $overdue_since,
			'schedule'      => 'daily',
			'schedule_hour' => 3,
		];

		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->withArgs(
				static function ( $timestamp, $hook ) {
					return is_int( $timestamp ) && Scheduler::HOOK_SCHEDULED === $hook;
				}
			)
			->andReturn( true );

		Functions\expect( 'spawn_cron' )->once()->andReturn( true );

		CatchUp::maybe_run();

		$this->assertTrue( (bool) $this->transient_store['lw_scan_catchup'] );
		$this->assertGreaterThan( $overdue_since, $this->option_store['lw_scan_options']['next_due'] );
	}

	/**
	 * `spawn_cron()` does not itself consult `DISABLE_WP_CRON` (WordPress
	 * only checks it in `wp_cron()`/`spawn_cron()`'s own callers), so
	 * `maybe_run()` must go through `Scheduler::nudge_cron()` rather than
	 * calling `spawn_cron()` directly, the same as `Scheduler::kick()`
	 * does.
	 */
	#[RunInSeparateProcess]
	public function test_maybe_run_does_not_spawn_cron_when_disable_wp_cron_is_true(): void {
		define( 'DISABLE_WP_CRON', true );

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		$this->option_store['lw_scan_options'] = [
			'next_due'      => time() - 100,
			'schedule'      => 'daily',
			'schedule_hour' => 3,
		];

		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\expect( 'spawn_cron' )->never();

		CatchUp::maybe_run();
	}

	public function test_trigger_for_scheduled_returns_cron_when_no_flag_was_set(): void {
		$this->assertSame( 'cron', CatchUp::trigger_for_scheduled() );
	}

	public function test_trigger_for_scheduled_consumes_the_flag_once(): void {
		$this->transient_store['lw_scan_catchup'] = true;

		$this->assertSame( 'catchup', CatchUp::trigger_for_scheduled() );
		$this->assertSame( 'cron', CatchUp::trigger_for_scheduled() );
	}
}
