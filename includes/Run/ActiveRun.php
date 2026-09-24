<?php
/**
 * Whether a scan run is still in progress.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * No WP functions, no DB access — just "is a run still in progress" from
 * an already-loaded (or absent) cursor. The REST busy guard
 * (`Rest\Errors::busy_if_run_active()`) and the CLI guards
 * (`CLI\RunGuard`, `CLI\StopCommand`) share it, so every entry point
 * agrees on what "a run is in progress" means.
 */
final class ActiveRun {

	/**
	 * A cursor exists for any run that has not been cleared yet — a
	 * running scan, or one that was stopped but left resumable
	 * (`Run\Starter` only clears the cursor once a run is truly over).
	 * Both count as active for a caller that must not mutate shared
	 * state such a run depends on.
	 *
	 * @param Cursor|null $cursor `Run\Cursor::load()` result.
	 */
	public static function run_active( ?Cursor $cursor ): bool {
		return null !== $cursor;
	}
}
