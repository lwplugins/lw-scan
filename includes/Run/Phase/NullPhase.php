<?php
/**
 * No-op phase for a name the pipeline doesn't know.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Run\Context;

defined( 'ABSPATH' ) || exit;

/**
 * A stored cursor can name a phase this build has no handler for — after a
 * downgrade, or when a run outlives a pipeline change. Reporting that phase
 * complete lets the cursor drain to the end instead of spinning on a name
 * nothing can advance past.
 */
final class NullPhase implements PhaseInterface {

	public function run( Context $ctx, callable $deadline ): bool {
		unset( $ctx, $deadline );

		return true;
	}
}
