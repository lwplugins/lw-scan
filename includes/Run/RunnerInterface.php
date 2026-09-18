<?php
/**
 * Contract Run\Scheduler drives instead of the concrete Runner.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * `Runner`'s constructor wires real repositories, which makes it awkward to
 * double in a test that only cares about `Scheduler` relaying `start()`/
 * `tick()` calls. This interface is that seam: `Scheduler::set_runner_factory()`
 * swaps in a Mockery mock of it instead of a `Runner` subclass.
 */
interface RunnerInterface {

	/**
	 * Opens a run: validates, creates the run row and saves a fresh cursor.
	 *
	 * @param string $trigger manual|cron|catchup|cli.
	 * @param string $scope   full|changed|db|path.
	 * @param string $path    Directory to scan, scope=path only.
	 * @param bool   $resume  Continue a stopped run instead of refusing as busy.
	 * @return int|WP_Error The run id, or why it could not start.
	 */
	public function start( string $trigger, string $scope, string $path = '', bool $resume = false );

	/**
	 * Works the pipeline for up to `$budget` seconds.
	 *
	 * @param float $budget Seconds this tick may use; 0 or less uses `Runner::budget()`.
	 * @return array{status:string, phase:string, done:int, total:int, findings_new:int, finished:bool, run_id:int}
	 */
	public function tick( float $budget = 0.0 ): array;
}
