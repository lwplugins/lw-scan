<?php
/**
 * HelloPack Client status check: the scanner's health in one sentence.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\HelloPack;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.4. HelloPack Client polls `hellopack_status_checks` for
 * third-party checks; ours reports whether the last scan found anything and
 * whether scans still run on schedule.
 *
 * HelloPack is optional, so nothing here may reference its classes at
 * parse time: the check object is an anonymous class built inside
 * `instance()` — only ever called once the interface is known to exist —
 * which keeps this file loadable on a site without HelloPack.
 *
 * The check is read-only, as the contract demands (docs/status-checks.md):
 * no writes, no HTTP, no loopback request. It runs four table queries and
 * reads two options. `FindingsRepository::counts()` runs three of the
 * queries: the state/severity grouping, which can be read from the
 * `list (state,severity,last_seen)` index; a `GROUP BY type` that no index
 * covers, so it reads the whole findings table; and a total `COUNT(*)`.
 * `RunsRepository::last()` runs the fourth, one row by primary key. Of the
 * options, `lw_scan_options` is autoloaded and `lw_scan_state` is not — one
 * more query, then cached for the request. The findings table holds one
 * row per finding on record, so the unindexed grouping stays cheap on a
 * healthy site but grows with it.
 * `evaluate()` and `interval_seconds()` hold the whole decision
 * and touch nothing but their arguments, so the rule table is unit-testable
 * without WordPress; the reads live in `measure()`.
 *
 * `details` deliberately carries no `scope_path`, error message or any
 * other string that could contain a filesystem path — the report is
 * published behind nothing but a URL key.
 */
final class StatusCheck {

	/** Check id in the HelloPack report; `[a-z0-9_]{2,40}`. */
	public const CHECK_ID = 'lw_scan';

	/**
	 * Hooks the filter registration. `plugins_loaded` at priority 20 runs
	 * after HelloPack Client has loaded its own classes (the contract
	 * allows `plugins_loaded` or `init`; the registry is built after
	 * `init`), so `interface_exists()` gives a truthful answer without
	 * forcing an autoload of a class that may not exist.
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', [ self::class, 'maybe_register' ], 20 );
	}

	/**
	 * Adds the filter only on a site that actually runs HelloPack Client.
	 */
	public static function maybe_register(): void {
		if ( ! interface_exists( '\HelloPack\Client\Status\StatusCheck' ) ) {
			return;
		}

		add_filter( 'hellopack_status_checks', [ self::class, 'add_check' ] );
	}

	/**
	 * @param array<int, mixed> $checks Checks collected so far.
	 * @return array<int, mixed>
	 */
	public static function add_check( array $checks ): array {
		$checks[] = self::instance();

		return $checks;
	}

	/**
	 * The check object. Only call this when
	 * `\HelloPack\Client\Status\StatusCheck` exists.
	 *
	 * @return object A `\HelloPack\Client\Status\StatusCheck` implementation.
	 */
	public static function instance(): object {
		return new class() implements \HelloPack\Client\Status\StatusCheck {

			public function id(): string {
				return StatusCheck::CHECK_ID;
			}

			public function label(): string {
				return __( 'Malware scan', 'lw-scan' );
			}

			public function description(): string {
				return __( 'New alerts from the last scan, and whether scans still run on schedule.', 'lw-scan' );
			}

			public function applies(): bool {
				return true;
			}

			public function run(): \HelloPack\Client\Status\CheckResult {
				$verdict = StatusCheck::measure();
				$summary = (string) $verdict['summary'];
				$details = (array) $verdict['details'];

				switch ( (string) $verdict['level'] ) {
					case 'crit':
						return \HelloPack\Client\Status\CheckResult::crit( $summary, $details );
					case 'warn':
						return \HelloPack\Client\Status\CheckResult::warn( $summary, $details );
					case 'unknown':
						return \HelloPack\Client\Status\CheckResult::unknown( $summary, $details );
					default:
						return \HelloPack\Client\Status\CheckResult::ok( $summary, $details );
				}
			}
		};
	}

	/**
	 * Takes the measurement: the repository and option reads, then
	 * `evaluate()`. The bundle version is merged into the details here
	 * rather than passed through `evaluate()`, which stays a pure function
	 * of the run state.
	 *
	 * @return array{level:string, summary:string, details:array<string, mixed>}
	 */
	public static function measure(): array {
		$verdict = self::evaluate(
			FindingsRepository::counts(),
			RunsRepository::last(),
			self::last_success_at(),
			(string) Options::get( 'schedule', 'daily' ),
			time()
		);

		$verdict['details']['bundle_version'] = (int) State::get( 'bundle_version', 0 );

		return $verdict;
	}

	/**
	 * The rule table (spec §11.4), in precedence order: new alerts, then —
	 * only when nothing new is waiting — never ran, a failed or
	 * never-successful last run, a success older than two scheduled
	 * windows, otherwise clean. Review-severity findings never colour the
	 * verdict; they are mostly benign library/test-fixture matches and are
	 * left for the admin UI, not this report.
	 *
	 * Findings outrank the missing run row on purpose: the runs table is
	 * pruned (and can be restored from a backup) independently of the
	 * findings table, and an alert nobody has acknowledged still deserves a
	 * `crit` even when no run is on record any more.
	 *
	 * @param array<string, mixed>      $counts          `FindingsRepository::counts()` output.
	 * @param array<string, mixed>|null $last_run        `RunsRepository::last()` output.
	 * @param int|null                  $last_success_at When the last run finished successfully.
	 * @param string                    $schedule        The `schedule` option.
	 * @param int                       $now             Reference timestamp.
	 * @return array{level:string, summary:string, details:array<string, mixed>}
	 */
	public static function evaluate( array $counts, ?array $last_run, ?int $last_success_at, string $schedule, int $now ): array {
		$alerts = self::new_count( $counts, 'alert' );
		$status = null === $last_run ? '' : (string) ( $last_run['status'] ?? '' );

		$verdict = self::findings_verdict( $alerts );

		if ( null === $verdict && null === $last_run ) {
			$verdict = self::verdict( 'unknown', __( 'No scan has run yet.', 'lw-scan' ) );
		}

		if ( null === $verdict ) {
			$verdict = self::freshness_verdict( $status, $last_success_at, $schedule, $now )
				?? self::clean_verdict( (int) $last_success_at, $now );
		}

		$verdict['details'] = [
			'alerts_new'  => $alerts,
			'last_run_at' => self::run_time( $last_run ),
			'last_status' => $status,
		];

		return $verdict;
	}

	/**
	 * Seconds between two scheduled scans. `off` yields `PHP_INT_MAX` so a
	 * site that scans on demand is never reported as stale; an unknown
	 * value falls back to the `daily` default.
	 *
	 * @param string $schedule The `schedule` option (hourly|daily|weekly|off).
	 */
	public static function interval_seconds( string $schedule ): int {
		switch ( $schedule ) {
			case 'hourly':
				return HOUR_IN_SECONDS;
			case 'weekly':
				return 7 * DAY_IN_SECONDS;
			case 'off':
				return PHP_INT_MAX;
			default:
				return DAY_IN_SECONDS;
		}
	}

	/**
	 * @param int $alerts New alert-severity findings.
	 * @return array{level:string, summary:string, details:array<string, mixed>}|null Null when there are no new alerts.
	 */
	private static function findings_verdict( int $alerts ): ?array {
		if ( $alerts > 0 ) {
			/* translators: %d: number of new alert-severity findings. */
			return self::verdict( 'crit', sprintf( _n( '%d new alert.', '%d new alerts.', $alerts, 'lw-scan' ), $alerts ) );
		}

		return null;
	}

	/**
	 * @param string   $status          The last run's status.
	 * @param int|null $last_success_at When the last run finished successfully.
	 * @param string   $schedule        The `schedule` option.
	 * @param int      $now             Reference timestamp.
	 * @return array{level:string, summary:string, details:array<string, mixed>}|null Null when scans are running on time.
	 */
	private static function freshness_verdict( string $status, ?int $last_success_at, string $schedule, int $now ): ?array {
		if ( 'failed' === $status ) {
			return self::verdict( 'warn', __( 'The last scan failed.', 'lw-scan' ) );
		}

		if ( null === $last_success_at || $last_success_at <= 0 ) {
			return self::verdict( 'warn', __( 'No scan has completed successfully yet.', 'lw-scan' ) );
		}

		$interval = self::interval_seconds( $schedule );

		if ( PHP_INT_MAX !== $interval && ( $now - $last_success_at ) > ( 2 * $interval ) ) {
			return self::verdict( 'warn', __( 'No successful scan in the last two scheduled windows.', 'lw-scan' ) );
		}

		return null;
	}

	/**
	 * @param int $last_success_at When the last run finished successfully.
	 * @param int $now             Reference timestamp.
	 * @return array{level:string, summary:string, details:array<string, mixed>}
	 */
	private static function clean_verdict( int $last_success_at, int $now ): array {
		return self::verdict(
			'ok',
			/* translators: %s: human-readable time span, e.g. "11 minutes". */
			sprintf( __( 'Last scan clean, %s ago.', 'lw-scan' ), human_time_diff( $last_success_at, $now ) )
		);
	}

	/**
	 * @param string $level   ok|warn|crit|unknown.
	 * @param string $summary One human-readable sentence.
	 * @return array{level:string, summary:string, details:array<string, mixed>}
	 */
	private static function verdict( string $level, string $summary ): array {
		return [
			'level'   => $level,
			'summary' => $summary,
			'details' => [],
		];
	}

	/**
	 * @param array<string, mixed> $counts   `FindingsRepository::counts()` output.
	 * @param string               $severity alert|review.
	 */
	private static function new_count( array $counts, string $severity ): int {
		$new = $counts['state']['new'] ?? [];

		return is_array( $new ) ? (int) ( $new[ $severity ] ?? 0 ) : 0;
	}

	/**
	 * When the last run last moved: its finish time, or its start time
	 * while it is still going.
	 *
	 * @param array<string, mixed>|null $run `RunsRepository::last()` output.
	 */
	private static function run_time( ?array $run ): int {
		if ( null === $run ) {
			return 0;
		}

		$finished = (int) ( $run['finished_at'] ?? 0 );

		return $finished > 0 ? $finished : (int) ( $run['started_at'] ?? 0 );
	}

	/**
	 * `Run\Phase\FinalizePhase` writes this on every successful finish.
	 */
	private static function last_success_at(): ?int {
		$stored = (int) State::get( 'last_success_at', 0 );

		return $stored > 0 ? $stored : null;
	}
}
