<?php
/**
 * How much time one tick may take, and how it frees memory afterwards.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §10.2: on the web a tick gets 5–20 seconds, derived from
 * `max_execution_time` with enough headroom left for the rest of the
 * request; under WP-CLI there is no limit, because the CLI runs the whole
 * pipeline in a single tick.
 *
 * Task 12 removed the memory side of this class — the floor it used to ask
 * WordPress to raise `memory_limit` to, and the pre-flight headroom check
 * that refused a run rather than risk an OOM. Both existed because loading
 * the old compiled signature bundle could cost tens of megabytes on top of
 * the WordPress bootstrap; the signature pack that replaced it holds the
 * same rule set in a few megabytes, so there is no floor worth asking a
 * host for any more. A tick that genuinely runs out of memory now fails
 * plainly instead: `Runner`'s `FatalGuard` catches the fatal and closes the
 * run with `RunError::OUT_OF_MEMORY`.
 */
final class Budget {

	/** Caps the per-tick budget on the web. */
	private const MAX = 20.0;

	/** Floor for the per-tick budget on the web. */
	private const MIN = 5.0;

	/** Headroom left for the rest of the request. */
	private const HEADROOM = 10;

	public static function seconds(): float {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return (float) PHP_INT_MAX;
		}

		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			return self::MAX;
		}

		return min( self::MAX, max( self::MIN, (float) ( $limit - self::HEADROOM ) ) );
	}

	/**
	 * Drops what the in-process object cache accumulated over a long run.
	 * A flush is only safe against the in-memory array cache — with an
	 * external object cache it would wipe the whole site's cache.
	 */
	public static function free_memory(): void {
		if ( function_exists( 'wp_using_ext_object_cache' ) && ! wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}
}
