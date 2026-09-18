<?php
/**
 * Tests for Run\Scheduler.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use DateTimeZone;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Lock;
use LightweightPlugins\Scan\Run\RunnerInterface;
use LightweightPlugins\Scan\Run\Scheduler;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_Error;

final class SchedulerTest extends MonkeyTestCase {

	/** @var array<string, mixed> In-memory stand-in for the options/state tables. */
	private array $option_store = [];

	/** @var array<string, mixed> In-memory stand-in for the transients table. */
	private array $transient_store = [];

	/** @var array<int, string> Option names get_option() was asked for, in call order. */
	private array $read_options = [];

	protected function setUp(): void {
		parent::setUp();

		// `ensure_scheduled()` only looks for a stalled run where the answer
		// is worth a query; wp-admin is one of those places, and most cases
		// here are about the relay.
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		Scheduler::set_runner_factory( null );
		Lock::reset();

		$this->option_store    = [];
		$this->transient_store = [];
		$this->read_options    = [];
		$reads                 = &$this->read_options;
		$options                = &$this->option_store;
		$transients              = &$this->transient_store;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$options, &$reads ) {
				$reads[] = (string) $name;

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

		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Scheduler::set_runner_factory( null );
		Lock::reset();
		parent::tearDown();
	}

	// ---- next_due_from() ---------------------------------------------

	#[RunInSeparateProcess]
	public function test_next_due_from_falls_back_to_utc_when_wp_timezone_is_missing(): void {
		$this->assertFalse( function_exists( 'wp_timezone' ) );

		// 2024-01-10 02:00:00 UTC, schedule_hour 3 UTC (no wp_timezone stub) -> today 03:00 UTC.
		$now = gmmktime( 2, 0, 0, 1, 10, 2024 );

		$expected = gmmktime( 3, 0, 0, 1, 10, 2024 );

		$this->assertSame(
			$expected,
			Scheduler::next_due_from( [ 'schedule' => 'daily', 'schedule_hour' => 3 ], $now )
		);
	}

	public function test_next_due_from_returns_zero_when_schedule_is_off(): void {
		$this->assertSame( 0, Scheduler::next_due_from( [ 'schedule' => 'off' ], 1700000000 ) );
	}

	public function test_next_due_from_hourly_returns_the_next_full_hour(): void {
		$now      = gmmktime( 14, 22, 37, 1, 10, 2024 );
		$expected = gmmktime( 15, 0, 0, 1, 10, 2024 );

		$this->assertSame( $expected, Scheduler::next_due_from( [ 'schedule' => 'hourly' ], $now ) );
	}

	public function test_next_due_from_hourly_at_an_exact_hour_still_advances(): void {
		$now      = gmmktime( 15, 0, 0, 1, 10, 2024 );
		$expected = gmmktime( 16, 0, 0, 1, 10, 2024 );

		$this->assertSame( $expected, Scheduler::next_due_from( [ 'schedule' => 'hourly' ], $now ) );
	}

	public function test_next_due_from_daily_before_the_hour_uses_today(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'Europe/Budapest' ) );

		// 01:00 UTC == 02:00 Budapest (winter, UTC+1) -> before 03:00 local.
		$now      = gmmktime( 1, 0, 0, 1, 10, 2024 );
		$expected = gmmktime( 2, 0, 0, 1, 10, 2024 ); // 03:00 Budapest same day.

		$this->assertSame(
			$expected,
			Scheduler::next_due_from( [ 'schedule' => 'daily', 'schedule_hour' => 3 ], $now )
		);
	}

	public function test_next_due_from_daily_after_the_hour_rolls_to_tomorrow(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'Europe/Budapest' ) );

		// 03:00 UTC == 04:00 Budapest -> after 03:00 local.
		$now      = gmmktime( 3, 0, 0, 1, 10, 2024 );
		$expected = gmmktime( 2, 0, 0, 1, 11, 2024 ); // 03:00 Budapest next day.

		$this->assertSame(
			$expected,
			Scheduler::next_due_from( [ 'schedule' => 'daily', 'schedule_hour' => 3 ], $now )
		);
	}

	public function test_next_due_from_daily_at_the_exact_hour_is_strictly_after(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'Europe/Budapest' ) );

		// 02:00 UTC == 03:00 Budapest exactly.
		$now      = gmmktime( 2, 0, 0, 1, 10, 2024 );
		$expected = gmmktime( 2, 0, 0, 1, 11, 2024 );

		$this->assertSame(
			$expected,
			Scheduler::next_due_from( [ 'schedule' => 'daily', 'schedule_hour' => 3 ], $now )
		);
	}

	public function test_next_due_from_weekly_before_the_hour_uses_the_same_weekday(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'Europe/Budapest' ) );

		$now      = gmmktime( 1, 0, 0, 1, 10, 2024 );
		$expected = gmmktime( 2, 0, 0, 1, 10, 2024 );

		$this->assertSame(
			$expected,
			Scheduler::next_due_from( [ 'schedule' => 'weekly', 'schedule_hour' => 3 ], $now )
		);
	}

	public function test_next_due_from_weekly_after_the_hour_rolls_forward_seven_days(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'Europe/Budapest' ) );

		$now      = gmmktime( 3, 0, 0, 1, 10, 2024 );
		$expected = gmmktime( 2, 0, 0, 1, 17, 2024 );

		$this->assertSame(
			$expected,
			Scheduler::next_due_from( [ 'schedule' => 'weekly', 'schedule_hour' => 3 ], $now )
		);
	}

	/**
	 * 2024-03-31 is the last Sunday of March: Europe/Budapest springs
	 * forward from 02:00 CET straight to 03:00 CEST, so the wall-clock
	 * hour 02:00 never occurs that day. `$now` sits after the local
	 * schedule hour on the 30th, so the next occurrence rolls onto the
	 * transition day itself; both `schedule_hour` values should land on
	 * the first valid local instant at/after 03:00 CEST — 2024-03-31
	 * 01:00 UTC — not silently skip a day or land an hour off.
	 *
	 * @dataProvider provide_dst_spring_forward_hours
	 */
	public function test_next_due_from_daily_lands_correctly_across_the_spring_dst_transition( int $schedule_hour, int $now ): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'Europe/Budapest' ) );

		$expected = gmmktime( 1, 0, 0, 3, 31, 2024 );

		$this->assertSame(
			$expected,
			Scheduler::next_due_from( [ 'schedule' => 'daily', 'schedule_hour' => $schedule_hour ], $now )
		);
	}

	/**
	 * @return array<string, array{0:int, 1:int}>
	 */
	public static function provide_dst_spring_forward_hours(): array {
		return [
			// schedule_hour=3, now = 2024-03-30 03:00 UTC (04:00 CET, after 03:00 local).
			'schedule_hour after the gap'  => [ 3, gmmktime( 3, 0, 0, 3, 30, 2024 ) ],
			// schedule_hour=2 (falls inside the skipped 02:00-03:00 local
			// hour on the transition day itself), now = 2024-03-30 02:00
			// UTC (03:00 CET, after 02:00 local).
			'schedule_hour inside the gap' => [ 2, gmmktime( 2, 0, 0, 3, 30, 2024 ) ],
		];
	}

	// ---- add_weekly_schedule() -----------------------------------------

	public function test_add_weekly_schedule_adds_it_when_missing(): void {
		$schedules = Scheduler::add_weekly_schedule( [ 'hourly' => [ 'interval' => 3600, 'display' => 'x' ] ] );

		$this->assertArrayHasKey( 'weekly', $schedules );
		$this->assertSame( 7 * DAY_IN_SECONDS, $schedules['weekly']['interval'] );
	}

	public function test_add_weekly_schedule_leaves_an_existing_one_untouched(): void {
		$existing  = [
			'interval' => 123,
			'display'  => 'custom',
		];
		$schedules = Scheduler::add_weekly_schedule( [ 'weekly' => $existing ] );

		$this->assertSame( $existing, $schedules['weekly'] );
	}

	// ---- schedule() / unschedule() / reschedule() -----------------------

	public function test_schedule_clears_both_hooks_then_schedules_the_recurring_event(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		$this->option_store['lw_scan_options'] = [
			'schedule'      => 'daily',
			'schedule_hour' => 3,
		];

		$cleared = [];
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;

				return true;
			}
		);

		Functions\expect( 'wp_schedule_event' )
			->once()
			->withArgs(
				static function ( $timestamp, $recurrence, $hook ) {
					return is_int( $timestamp ) && 'daily' === $recurrence && Scheduler::HOOK_SCHEDULED === $hook;
				}
			)
			->andReturn( true );

		Scheduler::schedule();

		$this->assertSame( [ Scheduler::HOOK_SCHEDULED, Scheduler::HOOK_TICK ], $cleared );
		$this->assertIsInt( $this->option_store['lw_scan_options']['next_due'] );
		$this->assertGreaterThan( 0, $this->option_store['lw_scan_options']['next_due'] );
	}

	public function test_schedule_clears_hooks_but_schedules_nothing_when_off(): void {
		$this->option_store['lw_scan_options'] = [ 'schedule' => 'off' ];

		Functions\expect( 'wp_clear_scheduled_hook' )->twice()->andReturn( true );
		Functions\expect( 'wp_schedule_event' )->never();

		Scheduler::schedule();

		$this->assertArrayNotHasKey( 'next_due', $this->option_store['lw_scan_options'] );
	}

	public function test_unschedule_clears_both_hooks(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->twice()
			->withArgs(
				static function ( $hook ) {
					return in_array( $hook, [ Scheduler::HOOK_SCHEDULED, Scheduler::HOOK_TICK ], true );
				}
			)
			->andReturn( true );

		Scheduler::unschedule();
	}

	public function test_reschedule_reschedules_when_a_schedule_relevant_key_changed(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		$this->option_store['lw_scan_options'] = [
			'schedule'      => 'weekly',
			'schedule_hour' => 3,
		];

		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->withArgs(
				static function ( $timestamp, $recurrence ) {
					return 'weekly' === $recurrence;
				}
			)
			->andReturn( true );

		Scheduler::reschedule(
			[
				'schedule'      => 'daily',
				'schedule_hour' => 3,
			],
			[
				'schedule'      => 'weekly',
				'schedule_hour' => 3,
			]
		);
	}

	public function test_reschedule_does_nothing_when_only_an_unrelated_key_changed(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		Scheduler::reschedule(
			[
				'schedule'      => 'daily',
				'schedule_hour' => 3,
				'notify_emails' => [],
			],
			[
				'schedule'      => 'daily',
				'schedule_hour' => 3,
				'notify_emails' => [ 'a@example.test' ],
			]
		);
	}

	/**
	 * Reproduces the re-entrancy bug: a real `update_option()` persists the
	 * new value and THEN fires `update_option_{option}` synchronously — so
	 * `schedule()`'s own trailing `Options::update( [ 'next_due' => ... ] )`
	 * call re-invokes `reschedule()` from inside `schedule()` before it has
	 * returned. Without the `$rescheduling` guard this recurses back into
	 * `schedule()` (and from there into `Options::update()` again); with
	 * it, `wp_schedule_event()` runs exactly once for the one relevant
	 * settings change that started the chain.
	 */
	public function test_schedule_does_not_double_fire_when_its_own_option_write_re_enters_the_hook(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
		Functions\expect( 'wp_schedule_event' )->once()->andReturn( true );

		$this->option_store['lw_scan_options'] = [
			'schedule'      => 'daily',
			'schedule_hour' => 3,
		];

		$options = &$this->option_store;

		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$old              = $options[ $name ] ?? false;
				$options[ $name ] = $value;

				if ( Options::OPTION_NAME === $name ) {
					Scheduler::reschedule( $old, $value );
				}

				return true;
			}
		);

		// The settings save that starts the chain: 'schedule' actually
		// changed, so this call is expected to run schedule() once.
		Scheduler::reschedule(
			[ 'schedule' => 'off' ],
			[
				'schedule'      => 'daily',
				'schedule_hour' => 3,
			]
		);
	}

	// ---- handle_scheduled() ---------------------------------------------

	public function test_handle_scheduled_kicks_the_tick_relay_when_the_run_starts(): void {
		$runner = Mockery::mock( RunnerInterface::class );
		$runner->shouldReceive( 'start' )->once()->with( 'cron', 'changed' )->andReturn( 42 );
		Scheduler::set_runner_factory( static fn() => $runner );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		Functions\when( 'spawn_cron' )->justReturn( true );

		Scheduler::handle_scheduled();
	}

	public function test_handle_scheduled_passes_catchup_as_the_trigger_when_flagged(): void {
		$this->transient_store['lw_scan_catchup'] = true;

		$runner = Mockery::mock( RunnerInterface::class );
		$runner->shouldReceive( 'start' )->once()->with( 'catchup', 'changed' )->andReturn( 7 );
		Scheduler::set_runner_factory( static fn() => $runner );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'spawn_cron' )->justReturn( true );

		Scheduler::handle_scheduled();

		$this->assertFalse( $this->transient_store['lw_scan_catchup'] ?? false );
	}

	public function test_handle_scheduled_does_not_kick_when_the_run_is_busy(): void {
		$runner = Mockery::mock( RunnerInterface::class );
		$runner->shouldReceive( 'start' )->once()->andReturn( new WP_Error( 'lw_scan_busy', 'busy' ) );
		Scheduler::set_runner_factory( static fn() => $runner );

		Functions\expect( 'wp_next_scheduled' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();

		Scheduler::handle_scheduled();
	}

	// ---- handle_tick() ----------------------------------------------------

	public function test_handle_tick_kicks_immediately_while_running(): void {
		$runner = Mockery::mock( RunnerInterface::class );
		$runner->shouldReceive( 'tick' )->once()->andReturn( [ 'status' => 'running' ] );
		Scheduler::set_runner_factory( static fn() => $runner );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->withArgs(
				static function ( $timestamp, $hook ) {
					return is_int( $timestamp ) && Scheduler::HOOK_TICK === $hook;
				}
			)
			->andReturn( true );
		Functions\when( 'spawn_cron' )->justReturn( true );

		Scheduler::handle_tick();
	}

	public function test_handle_tick_delays_thirty_seconds_while_busy(): void {
		$runner = Mockery::mock( RunnerInterface::class );
		$runner->shouldReceive( 'tick' )->once()->andReturn( [ 'status' => 'busy' ] );
		Scheduler::set_runner_factory( static fn() => $runner );

		$scheduled_at = null;
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'spawn_cron' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $timestamp, $hook ) use ( &$scheduled_at ) {
				$scheduled_at = $timestamp;

				return true;
			}
		);

		$before = time();
		Scheduler::handle_tick();

		$this->assertGreaterThanOrEqual( $before + 30, $scheduled_at );
	}

	public function test_handle_tick_does_not_kick_once_finished(): void {
		$runner = Mockery::mock( RunnerInterface::class );
		$runner->shouldReceive( 'tick' )->once()->andReturn( [ 'status' => 'finished' ] );
		Scheduler::set_runner_factory( static fn() => $runner );

		Functions\expect( 'wp_next_scheduled' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();

		Scheduler::handle_tick();
	}

	// ---- kick() -------------------------------------------------------

	public function test_kick_does_not_reschedule_when_a_tick_is_already_pending(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 5 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'spawn_cron' )->once()->andReturn( true );

		Scheduler::kick( 0 );
	}

	public function test_kick_schedules_a_tick_and_spawns_cron_when_none_is_pending(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		Functions\expect( 'spawn_cron' )->once()->andReturn( true );

		Scheduler::kick( 0 );
	}

	#[RunInSeparateProcess]
	public function test_kick_does_not_spawn_cron_when_disable_wp_cron_is_true(): void {
		define( 'DISABLE_WP_CRON', true );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\expect( 'spawn_cron' )->never();

		Scheduler::kick( 0 );
	}

	// ---- ensure_scheduled() ------------------------------------------

	public function test_ensure_scheduled_kicks_when_a_cursor_exists_and_no_tick_is_pending(): void {
		$this->option_store['lw_scan_state']   = [
			'run' => [
				'run_id' => 1,
				'phase'  => 'bundle',
				'phases' => [ 'bundle' ],
			],
		];
		$this->option_store['lw_scan_options'] = [ 'schedule' => 'off' ];

		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( $hook ) {
				return Scheduler::HOOK_TICK !== $hook ? time() + 60 : false;
			}
		);
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		Functions\when( 'spawn_cron' )->justReturn( true );

		Scheduler::ensure_scheduled();
	}

	public function test_ensure_scheduled_does_not_kick_when_a_tick_is_already_pending(): void {
		$this->option_store['lw_scan_state']   = [
			'run' => [
				'run_id' => 1,
				'phase'  => 'bundle',
				'phases' => [ 'bundle' ],
			],
		];
		$this->option_store['lw_scan_options'] = [ 'schedule' => 'off' ];

		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 60 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		Scheduler::ensure_scheduled();
	}

	public function test_ensure_scheduled_does_not_kick_when_the_lock_is_held_elsewhere(): void {
		$this->option_store['lw_scan_state']   = [
			'run' => [
				'run_id' => 1,
				'phase'  => 'bundle',
				'phases' => [ 'bundle' ],
			],
		];
		$this->option_store['lw_scan_options'] = [ 'schedule' => 'off' ];
		$this->transient_store['lw_scan_lock'] = 'someone-elses-token';

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->never();

		Scheduler::ensure_scheduled();
	}

	public function test_ensure_scheduled_schedules_the_recurring_event_when_missing(): void {
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );

		$this->option_store['lw_scan_options'] = [
			'schedule'      => 'daily',
			'schedule_hour' => 3,
		];

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
		Functions\expect( 'wp_schedule_event' )->once()->andReturn( true );

		Scheduler::ensure_scheduled();
	}

	public function test_ensure_scheduled_does_nothing_when_schedule_is_off_and_nothing_is_running(): void {
		$this->option_store['lw_scan_options'] = [ 'schedule' => 'off' ];

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();

		Scheduler::ensure_scheduled();
	}

	public function test_ensure_scheduled_does_not_read_the_run_state_on_a_front_end_page_load(): void {
		// The readme promises an ordinary page load costs nothing but options
		// WordPress has already loaded. `lw_scan_state` is deliberately not
		// autoloaded, so reading it here is a query on every single hit.
		Functions\when( 'is_admin' )->justReturn( false );

		$this->option_store['lw_scan_options'] = [
			'schedule'      => 'daily',
			'schedule_hour' => 3,
		];
		$this->option_store['lw_scan_state']   = [
			'run' => [
				'run_id' => 1,
				'phase'  => 'bundle',
				'phases' => [ 'bundle' ],
			],
		];

		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( $hook ) {
				return Scheduler::HOOK_TICK !== $hook ? time() + 60 : false;
			}
		);
		Functions\expect( 'wp_schedule_single_event' )->never();

		Scheduler::ensure_scheduled();

		$this->assertNotContains( 'lw_scan_state', $this->read_options );
	}

	public function test_ensure_scheduled_still_relays_a_stalled_run_during_cron(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );

		$this->option_store['lw_scan_options'] = [ 'schedule' => 'off' ];
		$this->option_store['lw_scan_state']   = [
			'run' => [
				'run_id' => 1,
				'phase'  => 'bundle',
				'phases' => [ 'bundle' ],
			],
		];

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->andReturn( true );
		Functions\when( 'spawn_cron' )->justReturn( true );

		Scheduler::ensure_scheduled();
	}

	public function test_ensure_scheduled_does_not_reschedule_when_already_scheduled(): void {
		$this->option_store['lw_scan_options'] = [
			'schedule'      => 'daily',
			'schedule_hour' => 3,
		];

		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 60 );
		Functions\expect( 'wp_schedule_event' )->never();

		Scheduler::ensure_scheduled();
	}
}
