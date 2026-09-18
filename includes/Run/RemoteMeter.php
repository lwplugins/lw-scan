<?php
/**
 * Books one tick's share of the backend traffic.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use LightweightPlugins\Scan\Remote\Client;

defined( 'ABSPATH' ) || exit;

/**
 * `Remote\Client` counts calls and errors per PHP request, but a run's
 * stats accumulate across requests — so what belongs to the stretch being
 * metered is the delta between construction and `record()`, never the
 * absolute counter. `Run\Runner` meters one phase at a time: a delta taken
 * around the whole tick would only be known after the last phase
 * (FinalizePhase) had already written the stats onto the run row.
 */
final class RemoteMeter {

	/** @var array{calls:int, errors:int, not_found:int} Counters as of construction. */
	private array $before;

	public function __construct() {
		$this->before = Client::counters();
	}

	/**
	 * @param RunStats $stats Run stats to add the delta since construction to.
	 */
	public function record( RunStats $stats ): void {
		$after = Client::counters();

		$stats->inc( 'remote.calls', max( 0, $after['calls'] - $this->before['calls'] ) );
		$stats->inc( 'remote.errors', max( 0, $after['errors'] - $this->before['errors'] ) );
		$stats->inc( 'remote.not_found', max( 0, $after['not_found'] - $this->before['not_found'] ) );
	}
}
