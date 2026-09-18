<?php
/**
 * WP-Cron driver: the recurring scheduled scan and the tick relay that
 * keeps an in-progress run moving forward across requests.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use DateTimeImmutable;
use DateTimeZone;
use LightweightPlugins\Scan\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §10.4. Two WP-Cron hooks: a recurring `HOOK_SCHEDULED` that opens a
 * run, and a self-rescheduling single `HOOK_TICK` that relays
 * `Runner::tick()` across requests until the run finishes or stalls behind
 * another process's lock. `ensure_scheduled()`, hooked on `init`, heals
 * both if WP-Cron ever drops them (a missed cron, a migrated site, a
 * plugin that clears all scheduled hooks, ...) — the recurring event from
 * autoloaded options alone, the tick relay only where the run state is
 * worth a query.
 */
final class Scheduler {

	public const HOOK_SCHEDULED = 'lw_scan_scheduled';
	public const HOOK_TICK      = 'lw_scan_tick';

	/** How long a stalled ("busy") tick waits before the next relay attempt. */
	private const BUSY_DELAY = 30;

	/** @var array<string, string> `schedule` option value => WP-Cron recurrence key. */
	private const INTERVALS = [
		'hourly' => 'hourly',
		'daily'  => 'daily',
		'weekly' => 'weekly',
	];

	/** @var array<int, string> Option keys whose change actually moves the schedule. */
	private const SCHEDULE_KEYS = [ 'schedule', 'schedule_hour' ];

	/** @var callable|null Test-only override for the RunnerInterface instance the hook handlers use. */
	private static $runner_factory = null;

	/**
	 * @var bool True while `schedule()` is inside its own `Options::update()`
	 *           call — `reschedule()` checks this to avoid re-entering
	 *           `schedule()` from the `update_option_{option}` hook that
	 *           call itself fires.
	 */
	private static bool $rescheduling = false;

	/**
	 * Hooks both WP-Cron actions, the `weekly` schedule filter, the `init`
	 * self-heal and the settings-save reschedule.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', [ self::class, 'add_weekly_schedule' ] );
		add_action( self::HOOK_SCHEDULED, [ self::class, 'handle_scheduled' ] );
		add_action( self::HOOK_TICK, [ self::class, 'handle_tick' ] );
		add_action( 'init', [ self::class, 'ensure_scheduled' ] );
		add_action( 'update_option_' . Options::OPTION_NAME, [ self::class, 'reschedule' ], 10, 2 );
	}

	/**
	 * Registers WP's built-in `weekly` recurrence when the site doesn't
	 * already have one (WP >= 5.4 ships it; a handful of older forks and
	 * multisite setups strip cron_schedules down, so it isn't guaranteed).
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function add_weekly_schedule( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'lw-scan' ),
			];
		}

		return $schedules;
	}

	/**
	 * (Re)schedules the recurring scan from the current options and records
	 * when it will next fire. A no-op beyond clearing the hooks when the
	 * schedule is `off`.
	 *
	 * The trailing `Options::update()` writes `next_due`, which — through a
	 * real `update_option()` — fires the very `update_option_{option}` hook
	 * `reschedule()` listens on. `$rescheduling` tells that re-entrant call
	 * to stand down instead of recursing back into `schedule()`.
	 */
	public static function schedule(): void {
		self::unschedule();

		$options  = Options::all();
		$interval = self::INTERVALS[ (string) ( $options['schedule'] ?? 'daily' ) ] ?? '';

		if ( '' === $interval ) {
			return;
		}

		$next_due = self::next_due_from( $options );

		wp_schedule_event( $next_due, $interval, self::HOOK_SCHEDULED );

		self::$rescheduling = true;

		Options::update( [ 'next_due' => $next_due ] );

		self::$rescheduling = false;
	}

	/**
	 * Clears both hooks: the recurring scan and any pending tick relay.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK_SCHEDULED );
		wp_clear_scheduled_hook( self::HOOK_TICK );
	}

	/**
	 * Re-derives the schedule after a settings save
	 * (`update_option_lw_scan_options`, hooked with its native
	 * `($old_value, $value)` signature). Two guards keep this from firing
	 * `wp_schedule_event()` needlessly: it stands down while `schedule()`
	 * is already mid-flight (`$rescheduling`), and it only reschedules when
	 * a schedule-relevant key (`schedule`, `schedule_hour`) actually
	 * changed — a save that only touches, say, `notify_emails` (or
	 * `schedule()`'s own `next_due` write) is not a reason to reschedule.
	 *
	 * @param mixed $old_value The option's previous value.
	 * @param mixed $value     The option's new value.
	 */
	public static function reschedule( $old_value, $value ): void {
		if ( self::$rescheduling ) {
			return;
		}

		if ( ! self::schedule_keys_changed( (array) $old_value, (array) $value ) ) {
			return;
		}

		self::schedule();
	}

	/**
	 * @param array<string, mixed> $old_options Previous option values.
	 * @param array<string, mixed> $new_options New option values.
	 */
	private static function schedule_keys_changed( array $old_options, array $new_options ): bool {
		foreach ( self::SCHEDULE_KEYS as $key ) {
			if ( ( $old_options[ $key ] ?? null ) !== ( $new_options[ $key ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pure: the next UTC timestamp the given schedule settings are due,
	 * measured from `$now` (defaults to `time()`). `off` is never due;
	 * `hourly` ignores the site timezone (a full hour is a full hour
	 * everywhere); `daily`/`weekly` are anchored to `schedule_hour` in the
	 * WordPress timezone.
	 *
	 * @param array<string, mixed> $options Plugin options (`schedule`, `schedule_hour`).
	 * @param int|null             $now     Reference timestamp; defaults to `time()`.
	 */
	public static function next_due_from( array $options, ?int $now = null ): int {
		$now      = $now ?? time();
		$schedule = (string) ( $options['schedule'] ?? 'daily' );

		if ( 'off' === $schedule ) {
			return 0;
		}

		if ( 'hourly' === $schedule ) {
			return ( intdiv( $now, HOUR_IN_SECONDS ) + 1 ) * HOUR_IN_SECONDS;
		}

		$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$hour = max( 0, min( 23, (int) ( $options['schedule_hour'] ?? 3 ) ) );

		$target = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( $hour, 0, 0 );
		$step   = 'weekly' === $schedule ? '+7 days' : '+1 day';

		if ( $target->getTimestamp() <= $now ) {
			$target = $target->modify( $step );
		}

		return $target->getTimestamp();
	}

	/**
	 * The recurring hook handler: opens a run — `catchup` as its trigger
	 * when `CatchUp::maybe_run()` fired this particular occurrence instead
	 * of WP-Cron's own clock, else `cron` — and relays it forward.
	 */
	public static function handle_scheduled(): void {
		$trigger = CatchUp::trigger_for_scheduled();
		$result  = self::runner()->start( $trigger, Options::scope() );

		if ( ! ( $result instanceof WP_Error ) ) {
			self::kick( 0 );
		}
	}

	/**
	 * The tick-relay hook handler: advances the in-progress run one step
	 * and, unless it just finished (or failed, or nothing is running),
	 * schedules the next relay.
	 */
	public static function handle_tick(): void {
		$result = self::runner()->tick( Runner::budget() );
		$status = $result['status'];

		if ( 'running' === $status ) {
			self::kick( 0 );
		} elseif ( 'busy' === $status ) {
			self::kick( self::BUSY_DELAY );
		}
	}

	/**
	 * Schedules the next tick relay (unless one is already pending) and
	 * nudges WP-Cron to run it promptly instead of waiting for the next
	 * page load.
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function kick( int $delay = 0 ): void {
		if ( false === wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK_TICK );
		}

		self::nudge_cron();
	}

	/**
	 * Nudges WP-Cron to run promptly instead of waiting for the next page
	 * load — `spawn_cron()` itself does not consult `DISABLE_WP_CRON`, so
	 * every caller that wants that setting respected goes through here
	 * instead of calling `spawn_cron()` directly (`kick()` above,
	 * `CatchUp::maybe_run()`).
	 */
	public static function nudge_cron(): void {
		if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}

	/**
	 * Heals both hooks on `init`: restores the recurring scan when it's
	 * enabled but missing, and relays a run that's in progress but has no
	 * pending tick and isn't locked by another process.
	 *
	 * In that order, and the second one not on every request. Everything the
	 * recurring half reads — `lw_scan_options`, WP-Cron's own `cron` option —
	 * is autoloaded, so it costs nothing. The relay half starts with
	 * `Cursor::load()`, which reads the deliberately non-autoloaded
	 * `lw_scan_state`: a query of its own on every front-end page view, for a
	 * condition a visitor's request cannot fix any sooner than the next admin
	 * page load or cron pass will. `may_relay()` keeps it to those.
	 */
	public static function ensure_scheduled(): void {
		$options = Options::all();

		if ( 'off' !== (string) ( $options['schedule'] ?? 'daily' ) && false === wp_next_scheduled( self::HOOK_SCHEDULED ) ) {
			self::schedule();
		}

		if ( self::may_relay() ) {
			self::relay_stalled_run();
		}
	}

	/**
	 * Whether this request may pay for a read of the non-autoloaded run
	 * state to go looking for a stalled run.
	 *
	 * Only the three contexts that can do something about one: wp-admin,
	 * where someone is watching a progress bar; WP-Cron, which is what a
	 * relay kick schedules onto anyway; and WP-CLI. The same trade
	 * `Db\Schema::may_retry()` makes, and what lets the readme say an
	 * ordinary page load costs nothing but options WordPress has already
	 * loaded.
	 */
	private static function may_relay(): bool {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Schedules the next tick for a run that is in progress but has no relay
	 * pending and is not being ticked by another process right now.
	 */
	private static function relay_stalled_run(): void {
		if ( null !== Cursor::load() && false === wp_next_scheduled( self::HOOK_TICK ) && ! Lock::held_elsewhere() ) {
			self::kick( 0 );
		}
	}

	/**
	 * Test-only: overrides the RunnerInterface instance `handle_scheduled()`/
	 * `handle_tick()` use, so tests can inject a Mockery double instead of
	 * the real `Runner` (which wires real repositories in its constructor).
	 *
	 * @param callable|null $factory Returns a RunnerInterface; null restores the default (`new Runner()`).
	 */
	public static function set_runner_factory( ?callable $factory ): void {
		self::$runner_factory = $factory;
	}

	private static function runner(): RunnerInterface {
		return null !== self::$runner_factory ? ( self::$runner_factory )() : new Runner();
	}
}
