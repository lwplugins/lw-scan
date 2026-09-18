<?php
/**
 * Contract for one phase of the scan pipeline.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Run\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Every phase is resumable: it reads its own position from `$ctx->cursor`,
 * works until it is either finished or `$deadline()` reports the tick's
 * time budget spent, and writes its position back. The Runner persists the
 * cursor and advances to the next phase only on a `true` return.
 */
interface PhaseInterface {

	/**
	 * @param Context  $ctx      Run-scoped services and state for this tick.
	 * @param callable $deadline Returns true once the tick's time budget is spent.
	 * @return bool True when the phase is complete, false when it must resume next tick.
	 */
	public function run( Context $ctx, callable $deadline ): bool;
}
