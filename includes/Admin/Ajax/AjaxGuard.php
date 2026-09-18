<?php
/**
 * Pure predicate behind AjaxGuardTrait::refuse_if_run_active().
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Run\Cursor;

defined( 'ABSPATH' ) || exit;

/**
 * No WP functions, no DB access — just "is a run still in progress" from
 * an already-loaded (or absent) cursor. Kept separate from
 * `AjaxGuardTrait` so it is unit-testable without Brain Monkey.
 */
final class AjaxGuard {

	/**
	 * A cursor exists for any run that has not been cleared yet — a
	 * running scan, or one that was stopped but left resumable
	 * (`Run\Starter` only clears the cursor once a run is truly over).
	 * Both count as active for a handler that must not mutate shared
	 * state such a run depends on.
	 *
	 * @param Cursor|null $cursor `Run\Cursor::load()` result.
	 */
	public static function run_active( ?Cursor $cursor ): bool {
		return null !== $cursor;
	}
}
