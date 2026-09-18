<?php
/**
 * Execute callbacks behind the four lw-scan abilities.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\SiteManager;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Findings\StateChange;
use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Run\Runner;
use LightweightPlugins\Scan\Run\RunnerInterface;
use LightweightPlugins\Scan\Run\Scheduler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.3. `Abilities` owns the registration and the schemas; the work
 * itself lives here, one method per ability, so neither file has to change
 * when the other does.
 *
 * Every method is an `execute_callback`: the Abilities API has already run
 * the ability's `permission_callback` (`manage_options`) and validated the
 * input against its `input_schema` by the time we are called. The input is
 * still re-sanitized and re-validated here — an ability is also callable
 * straight from PHP, where no schema stands between the caller and us.
 *
 * `lw-scan/run` never scans inline: like the admin Start button it opens
 * the run and hands it to the WP-Cron tick relay, so an agent's HTTP call
 * returns in milliseconds instead of holding a request open for minutes.
 */
final class AbilityCallbacks {

	/** Findings per page when the caller does not say. */
	private const DEFAULT_PER_PAGE = 50;

	/** Hard ceiling on one findings page, whatever the caller asks for. */
	private const MAX_PER_PAGE = 200;

	/** Filters `FindingsRepository::list()` understands and we expose. */
	private const FILTER_KEYS = [ 'severity', 'type', 'state' ];

	/** @var callable|null Test-only override for the RunnerInterface instance `run()` uses. */
	private static $runner_factory = null;

	/**
	 * `lw-scan/run`: opens a scan and kicks the tick relay.
	 *
	 * @param array<string, mixed> $input {scope, path?, resume?}.
	 * @return array{run_id:int, started:bool}|WP_Error
	 */
	public static function run( array $input ) {
		$scope  = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : '';
		$path   = isset( $input['path'] ) ? sanitize_text_field( (string) $input['path'] ) : '';
		$resume = ! empty( $input['resume'] );

		if ( ! Phases::valid_scope( $scope ) ) {
			return new WP_Error( 'lw_scan_bad_scope', __( 'Unknown scan scope.', 'lw-scan' ) );
		}

		$result = self::runner()->start( 'ability', $scope, $path, $resume );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		Scheduler::kick( 0 );

		return [
			'run_id'  => (int) $result,
			'started' => true,
		];
	}

	/**
	 * `lw-scan/status`: the running (or last) run plus the finding counts.
	 *
	 * @return array{progress:array<string, mixed>, last_run:array<string, mixed>|null, counts:array<string, mixed>}
	 */
	public static function status(): array {
		return [
			'progress' => ( new Runner() )->progress(),
			'last_run' => RunsRepository::last(),
			'counts'   => FindingsRepository::counts(),
		];
	}

	/**
	 * `lw-scan/findings`: one filtered, paginated page of findings.
	 *
	 * @param array<string, mixed> $input {severity?, type?, state?, page?, per_page?}.
	 * @return array{items:array<int, array<string, mixed>>, total:int}
	 */
	public static function findings( array $input ): array {
		$query = self::findings_query( $input );

		return FindingsRepository::list( $query['filters'], $query['page'], $query['per_page'] );
	}

	/**
	 * `lw-scan/acknowledge`: bulk state change on a set of findings.
	 *
	 * @param array<string, mixed> $input {ids, state}.
	 * @return array{updated:int}|WP_Error
	 */
	public static function acknowledge( array $input ) {
		$state = isset( $input['state'] ) ? sanitize_key( (string) $input['state'] ) : '';

		if ( ! in_array( $state, FindingsRepository::STATES, true ) ) {
			return new WP_Error( 'lw_scan_bad_state', __( 'Unknown finding state.', 'lw-scan' ) );
		}

		$ids = StateChange::normalize_ids( (array) ( $input['ids'] ?? [] ) );

		if ( [] === $ids ) {
			return [ 'updated' => 0 ];
		}

		return [ 'updated' => FindingsRepository::set_state( $ids, $state ) ];
	}

	/**
	 * Pure: turns raw ability input into the three `FindingsRepository::list()`
	 * arguments. Unknown filter keys are dropped rather than passed on, and
	 * `per_page` is clamped so one call can never ask for the whole table.
	 *
	 * @param array<string, mixed> $input Raw ability input.
	 * @return array{filters:array<string, string>, page:int, per_page:int}
	 */
	public static function findings_query( array $input ): array {
		$filters = [];

		foreach ( self::FILTER_KEYS as $key ) {
			$value = isset( $input[ $key ] ) ? sanitize_key( (string) $input[ $key ] ) : '';

			if ( '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : self::DEFAULT_PER_PAGE;

		return [
			'filters'  => $filters,
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'per_page' => max( 1, min( self::MAX_PER_PAGE, $per_page ) ),
		];
	}

	/**
	 * Test-only: overrides the RunnerInterface instance `run()` uses, so
	 * tests can inject a Mockery double instead of the real `Runner`
	 * (which wires real repositories in its constructor). Mirrors
	 * `Run\Scheduler::set_runner_factory()`.
	 *
	 * @param callable|null $factory Returns a RunnerInterface; null restores the default (`new Runner()`).
	 */
	public static function set_runner_factory( ?callable $factory ): void {
		self::$runner_factory = $factory;
	}

	private static function runner(): RunnerInterface {
		return null !== self::$runner_factory ? ( self::$runner_factory )() : new Runner();
	}
}
