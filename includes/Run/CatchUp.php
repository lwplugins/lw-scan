<?php
/**
 * Catches up a scan that WP-Cron's own clock missed.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §10.5. Low-traffic sites can go for hours without a request landing
 * after WP-Cron's `next_due` passes, so nothing ever fires the recurring
 * hook. `maybe_run()`, hooked late on `init` (priority 20, after most
 * plugins' own init work), notices a missed schedule on an ordinary page
 * load and kicks a one-off run itself — but never from inside a request
 * that is already an AJAX, WP-Cron, REST or CLI run, to avoid recursing
 * into its own trigger or double-scheduling against a real cron tick.
 */
final class CatchUp {

	/** Transient flagging that the next HOOK_SCHEDULED firing is a catch-up, not WP-Cron's own clock. */
	private const FLAG = 'lw_scan_catchup';

	/** Long enough to outlive the spawn_cron() round trip, short enough not to mislabel a later, unrelated firing. */
	private const FLAG_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Hooks `maybe_run()` onto `init` at priority 20.
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'maybe_run' ], 20 );
	}

	/**
	 * Starts a catch-up run when the schedule is overdue and nothing is
	 * currently running.
	 */
	public static function maybe_run(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return;
		}

		$options  = Options::all();
		$next_due = (int) ( $options['next_due'] ?? 0 );

		if ( $next_due <= 0 || $next_due >= time() || null !== Cursor::load() ) {
			return;
		}

		// Push next_due forward first so a second request racing in behind
		// this one no longer sees the schedule as overdue.
		Options::update( [ 'next_due' => Scheduler::next_due_from( $options ) ] );

		set_transient( self::FLAG, true, self::FLAG_TTL );

		wp_schedule_single_event( time(), Scheduler::HOOK_SCHEDULED );

		Scheduler::nudge_cron();
	}

	/**
	 * Consumes the catch-up flag `maybe_run()` left behind, if any — read
	 * once and deleted, so a later, normally-clocked firing of
	 * `HOOK_SCHEDULED` isn't mislabeled too.
	 *
	 * @return string `catchup` when this firing came from `maybe_run()`, else `cron`.
	 */
	public static function trigger_for_scheduled(): string {
		if ( ! get_transient( self::FLAG ) ) {
			return 'cron';
		}

		delete_transient( self::FLAG );

		return 'catchup';
	}
}
