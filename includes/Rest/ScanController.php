<?php
/**
 * `/scan*` — the Scan tab: overview, polling, start, assist tick, stop.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Rest\Presenter\ScanOverview;
use LightweightPlugins\Scan\Rest\Presenter\ScanStatus;
use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Run\Runner;
use LightweightPlugins\Scan\Run\Scheduler;
use LightweightPlugins\Scan\Run\StopFlag;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Starting validates the scope before anything else happens, so a doomed
 * request never persists the heuristics toggle or reaches
 * `Runner::start()` (which validates again on its own path). The toggle
 * is stored in the options — not passed as a one-off — so the pipeline's
 * own `Options::all()` read picks it up for this and every later run.
 *
 * The assist tick stays short: capped at 8 seconds even when
 * `Runner::budget()` allows more, so a stalled poll loop never turns into
 * a slow admin request.
 */
final class ScanController implements ControllerInterface {

	/** Hard cap on an assist tick's budget, seconds. */
	private const MAX_TICK_BUDGET = 8.0;

	public function routes(): array {
		return [
			'/scan'        => [
				[
					'methods'  => 'GET',
					'callback' => [ $this, 'overview' ],
				],
			],
			'/scan/status' => [
				[
					'methods'  => 'GET',
					'callback' => [ $this, 'status' ],
				],
			],
			'/scan/start'  => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'start' ],
				],
			],
			'/scan/tick'   => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'tick' ],
				],
			],
			'/scan/stop'   => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'stop' ],
				],
			],
		];
	}

	/**
	 * GET /scan.
	 *
	 * @return array<string, mixed>
	 */
	public function overview(): array {
		if ( ! Schema::exists() ) {
			return [ 'schema_exists' => false ];
		}

		$payload  = ScanStatus::payload();
		$progress = array_diff_key( $payload, array_flip( [ 'feed', 'counts', 'last_run' ] ) );

		return array_merge(
			[
				'schema_exists' => true,
				'progress'      => $progress,
				'feed'          => $payload['feed'],
			],
			ScanOverview::build( $payload['counts'] )
		);
	}

	/**
	 * GET /scan/status.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		return ScanStatus::payload();
	}

	/**
	 * POST /scan/start.
	 *
	 * @param WP_REST_Request $request Request: scope, path, heuristics, resume.
	 * @return array{run_id:int}|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		$scope = Params::key( $request, 'scope' );

		if ( ! Phases::valid_scope( $scope ) ) {
			return Errors::bad_request( 'lw_scan_bad_scope', __( 'Unknown scan scope.', 'lw-scan' ) );
		}

		Options::update( [ 'heuristics' => Params::bool( $request, 'heuristics' ) ] );

		$result = ( new Runner() )->start(
			'manual',
			$scope,
			Params::text( $request, 'path' ),
			Params::bool( $request, 'resume' )
		);

		if ( $result instanceof WP_Error ) {
			return Errors::from_runner( $result );
		}

		Scheduler::kick( 0 );

		return [ 'run_id' => (int) $result ];
	}

	/**
	 * POST /scan/tick — one assist tick.
	 *
	 * @return array<string, mixed>
	 */
	public function tick(): array {
		return ( new Runner() )->tick( min( Runner::budget(), self::MAX_TICK_BUDGET ) );
	}

	/**
	 * POST /scan/stop — cooperative: `Runner::tick()` polls the flag.
	 *
	 * @return array{ok:bool}
	 */
	public function stop(): array {
		StopFlag::request();

		return [ 'ok' => true ];
	}
}
