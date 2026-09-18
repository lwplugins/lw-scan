<?php
/**
 * Cooperative stop signal for a running scan.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * The `stop_requested` flag lives inside the persisted Cursor
 * (`State::get('run')`), not in its own option, so a stopped run and an
 * absent run are both "nothing to check" for callers that just call
 * `Cursor::load()` once. `Run\Runner::tick()` polls `requested()` between
 * phase steps; the admin "Stop" action calls `request()`. Requesting or
 * clearing the flag with no run in progress is a no-op — there is nothing
 * to flag.
 */
final class StopFlag {

	public static function request(): void {
		self::write( true );
	}

	public static function requested(): bool {
		$cursor = Cursor::load();

		return null !== $cursor && $cursor->stop_requested();
	}

	/**
	 * The same check, but guaranteed to see another request's write.
	 * `get_option()` answers from the object cache, and a ticking process
	 * refreshes that cache with its own copy of the state option on every
	 * cursor save — so a Stop sent from a concurrent AJAX request would
	 * stay invisible for the rest of the tick (and then be overwritten).
	 * Dropping the cached entry first forces the next read to come from
	 * the database.
	 */
	public static function requested_fresh(): bool {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( State::OPTION_NAME, 'options' );
		}

		return self::requested();
	}

	public static function clear(): void {
		self::write( false );
	}

	private static function write( bool $stop_requested ): void {
		$cursor = Cursor::load();

		if ( null === $cursor ) {
			return;
		}

		$cursor->set( 'stop_requested', $stop_requested );
		$cursor->save();
	}
}
