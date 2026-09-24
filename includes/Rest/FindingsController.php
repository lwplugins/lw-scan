<?php
/**
 * `/findings*` — the Findings tab: list, state changes, clear.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Findings\StateChange;
use LightweightPlugins\Scan\Health\Environment;
use LightweightPlugins\Scan\Rest\Presenter\FindingCounts;
use LightweightPlugins\Scan\Rest\Presenter\FindingPresenter;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Filters are whitelisted before they reach the repository, which
 * prepares them as placeholders. State changes share their id and state
 * rules with the `lw-scan/acknowledge` ability
 * (`Findings\StateChange::normalize_ids()`, `FindingsRepository::STATES`).
 *
 * Clearing deletes every finding and the stored file hashes, so the next
 * scan re-hashes and re-checks every file and reports again anything still
 * on the site. It is refused while a run's cursor exists: that run would
 * keep writing findings against the hashes this just reset.
 */
final class FindingsController implements ControllerInterface {

	private const PER_PAGE_DEFAULT = 50;

	private const PER_PAGE_MAX = 200;

	public function routes(): array {
		return [
			'/findings'       => [
				[
					'methods'  => 'GET',
					'callback' => [ $this, 'index' ],
				],
			],
			'/findings/state' => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'set_state' ],
				],
			],
			'/findings/clear' => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'clear' ],
				],
			],
		];
	}

	/**
	 * GET /findings.
	 *
	 * @param WP_REST_Request $request Request: severity, type, state, search, page, per_page.
	 * @return array<string, mixed>
	 */
	public function index( WP_REST_Request $request ): array {
		$filters  = self::filters( $request );
		$page     = Params::int( $request, 'page', 1, 1, PHP_INT_MAX );
		$per_page = Params::int( $request, 'per_page', self::PER_PAGE_DEFAULT, 1, self::PER_PAGE_MAX );
		$list     = FindingsRepository::list( array_filter( $filters ), $page, $per_page );

		$presenter = new FindingPresenter( admin_url( 'update-core.php' ) );
		$total     = (int) $list['total'];

		return [
			'items'       => array_map( [ $presenter, 'present' ], $list['items'] ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'counts'      => FindingCounts::complete( FindingsRepository::counts() ),
		];
	}

	/**
	 * POST /findings/state.
	 *
	 * @param WP_REST_Request $request Request: ids, state.
	 * @return array{updated:int}|WP_Error
	 */
	public function set_state( WP_REST_Request $request ) {
		$state = Params::key( $request, 'state' );

		if ( ! in_array( $state, FindingsRepository::STATES, true ) ) {
			return Errors::bad_request( 'lw_scan_bad_state', __( 'Unknown finding state.', 'lw-scan' ) );
		}

		$ids = StateChange::normalize_ids( Params::scalars( $request, 'ids' ) );

		return [ 'updated' => [] === $ids ? 0 : FindingsRepository::set_state( $ids, $state ) ];
	}

	/**
	 * POST /findings/clear.
	 *
	 * @return array{cleared:int}|WP_Error
	 */
	public function clear() {
		$busy = Errors::busy_if_run_active();

		if ( null !== $busy ) {
			return $busy;
		}

		$cleared = FindingsRepository::clear();
		( new FilesRepository() )->reset_hashes();
		Environment::invalidate();

		return [ 'cleared' => $cleared ];
	}

	/**
	 * The list filters, whitelisted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{severity:string, type:string, state:string, search:string}
	 */
	private static function filters( WP_REST_Request $request ): array {
		return [
			'severity' => self::one_of( Params::key( $request, 'severity' ), FindingCounts::SEVERITIES ),
			'type'     => self::one_of( Params::key( $request, 'type' ), FindingCounts::TYPES ),
			'state'    => self::one_of( Params::key( $request, 'state' ), FindingsRepository::STATES ),
			'search'   => Params::text( $request, 'search' ),
		];
	}

	/**
	 * @param string   $value   Sanitized value.
	 * @param string[] $allowed Accepted values.
	 */
	private static function one_of( string $value, array $allowed ): string {
		return in_array( $value, $allowed, true ) ? $value : '';
	}
}
