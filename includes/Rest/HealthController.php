<?php
/**
 * `/health` and `/maintenance/*` — the Health tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use LightweightPlugins\Scan\Admin\Settings\BundleInfo;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Health\Environment;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\PackFetcher;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `?fresh=1` bypasses the 10-minute report cache and re-runs every check,
 * including the cron loopback probe — the React tab asks for it when it
 * opens and on "Run checks again", as the classic tab did.
 *
 * Both maintenance writes are refused while a run's cursor exists:
 * swapping the signature pack would invalidate a regex index a scan phase
 * already captured, and emptying the file index would delete rows a live
 * `FilesPhase` cursor points into. `op` selects the sub-command.
 *
 * - bundle `check` forces a fresh backend check (bypassing the "checked
 *   within the last hour" skip); `full` also ignores the ETag and the
 *   "already at this version" short-circuit and re-downloads the pack.
 * - index `rebuild` empties the file index and the findings derived purely
 *   from it (`file`, `integrity`); `db`/`vulnerability` findings stay.
 */
final class HealthController implements ControllerInterface {

	public function routes(): array {
		return [
			'/health'             => [
				[
					'methods'  => 'GET',
					'callback' => [ $this, 'health' ],
				],
			],
			'/maintenance/bundle' => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'bundle' ],
				],
			],
			'/maintenance/index'  => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'index' ],
				],
			],
		];
	}

	/**
	 * GET /health.
	 *
	 * @param WP_REST_Request $request Request: fresh.
	 * @return array<string, mixed>
	 */
	public function health( WP_REST_Request $request ): array {
		return array_merge(
			Environment::report( Params::bool( $request, 'fresh' ) ),
			[
				'cron_lines' => Environment::cron_command_lines(),
				'bundle'     => BundleInfo::summary(),
			]
		);
	}

	/**
	 * POST /maintenance/bundle.
	 *
	 * @param WP_REST_Request $request Request: op (check|full).
	 * @return array<string, mixed>|WP_Error
	 */
	public function bundle( WP_REST_Request $request ) {
		$busy = Errors::busy_if_run_active();

		if ( null !== $busy ) {
			return $busy;
		}

		$op = Params::key( $request, 'op' );

		if ( ! in_array( $op, [ 'check', 'full' ], true ) ) {
			return Errors::bad_request( 'lw_scan_bad_op', __( 'Unknown bundle operation.', 'lw-scan' ) );
		}

		$fetcher = new PackFetcher( new Client(), new Store() );

		return 'full' === $op ? $fetcher->force_full() : $fetcher->check( true );
	}

	/**
	 * POST /maintenance/index.
	 *
	 * @param WP_REST_Request $request Request: op (rebuild).
	 * @return array{ok:bool}|WP_Error
	 */
	public function index( WP_REST_Request $request ) {
		$busy = Errors::busy_if_run_active();

		if ( null !== $busy ) {
			return $busy;
		}

		if ( 'rebuild' !== Params::key( $request, 'op' ) ) {
			return Errors::bad_request( 'lw_scan_bad_op', __( 'Unknown index operation.', 'lw-scan' ) );
		}

		( new FilesRepository() )->truncate();
		FindingsRepository::truncate_type( 'file' );
		FindingsRepository::truncate_type( 'integrity' );
		Environment::invalidate();

		return [ 'ok' => true ];
	}
}
