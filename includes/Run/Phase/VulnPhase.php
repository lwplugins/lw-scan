<?php
/**
 * Pipeline phase: match installed software against the vulnerability feed.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Vuln\Matcher;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the installed core/plugin/theme list through `Vuln\Matcher`,
 * resuming from `vuln_index` (spec §8.3). The matcher always gets the
 * complete, tick-stable software list from the Context — it prunes stale
 * vulnerability findings against that whole list, so handing it only the
 * not-yet-visited tail would delete findings for everything already done.
 */
final class VulnPhase implements PhaseInterface {

	public function run( Context $ctx, callable $deadline ): bool {
		$cursor   = $ctx->cursor;
		$software = $ctx->software();

		$result = ( new Matcher( $ctx->vulns(), $ctx->findings ) )->run(
			$software,
			(int) $cursor->get( 'vuln_index', 0 ),
			$deadline
		);

		$cursor->set( 'vuln_index', (int) $result['index'] );

		$ctx->items += (int) $result['lookups'];
		$ctx->stats->set( 'vuln.software', count( $software ) );
		$ctx->stats->inc( 'vuln.lookups', (int) $result['lookups'] );
		$ctx->stats->inc( 'vuln.findings', (int) $result['findings'] );
		$ctx->stats->inc( 'vuln.skipped_items', (int) $result['skipped_items'] );

		if ( null !== $result['skipped'] ) {
			$ctx->stats->set( 'vuln.skipped', (string) $result['skipped'] );
		}

		return (bool) $result['done'];
	}
}
