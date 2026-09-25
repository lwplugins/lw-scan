<?php
/**
 * Turns repository rows into WP-CLI table rows.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Admin\Settings\Format;
use LightweightPlugins\Scan\Admin\Settings\RunStatsView;
use LightweightPlugins\Scan\Findings\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * The whole CLI surface's presentation layer, and the only part of it with
 * no WP-CLI in it: every method is pure, so the printed cells are
 * unit-testable without WordPress or a terminal (spec §11.2).
 *
 * Timestamps are printed as UTC — `Admin\Settings\Format::datetime()`
 * renders in the site timezone via `wp_date()`, which no pure method may
 * call; a CLI table is read next to log files and cron output anyway, so
 * one unambiguous zone beats a localized one. `Format::duration()` and
 * `RunStatsView` are reused as-is: both are pure readers over the same
 * data the admin tables print.
 */
final class Formatter {

	/** Columns of the findings table (`findings_rows()`). */
	public const FINDINGS_COLUMNS = [ 'id', 'severity', 'type', 'where', 'detected_by', 'last_seen', 'state', 'reference' ];

	/** Columns of every metric/value table (`run_summary()`, `status()`). */
	public const SUMMARY_COLUMNS = [ 'metric', 'value' ];

	/** Signature ids printed before the "(+N)" suffix. */
	private const SIGNATURE_PREVIEW = 3;

	/** Printed wherever a value is absent (no timestamp, no phase). */
	private const NONE = '-';

	/**
	 * One row per finding, as `wp lw-scan findings` and the new-findings
	 * table at the end of `wp lw-scan run` print them.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings-table rows, `signature_ids` decoded or still JSON.
	 * @return array<int, array{id:int, severity:string, type:string, where:string, detected_by:string, last_seen:string, state:string}>
	 */
	public static function findings_rows( array $findings ): array {
		$rows = [];

		foreach ( $findings as $finding ) {
			$rows[] = [
				'id'          => (int) ( $finding['id'] ?? 0 ),
				'severity'    => (string) ( $finding['severity'] ?? '' ),
				'type'        => (string) ( $finding['type'] ?? '' ),
				'where'       => (string) ( $finding['locator'] ?? '' ),
				'detected_by' => self::detected_by( $finding ),
				'last_seen'   => self::timestamp( (int) ( $finding['last_seen'] ?? 0 ) ),
				'state'       => (string) ( $finding['state'] ?? '' ),
				'reference'   => Attribution::fields( $finding )['reference'],
			];
		}

		return $rows;
	}

	/**
	 * What one run did, printed after `wp lw-scan run` finishes.
	 *
	 * @param array<string, mixed> $run Run row with `stats` decoded (`Db\RunsRepository::get()`), or [] when it is gone.
	 * @return array<int, array{metric:string, value:string}>
	 */
	public static function run_summary( array $run ): array {
		$stats    = RunStatsView::of( $run );
		$started  = (int) ( $run['started_at'] ?? 0 );
		$finished = (int) ( $run['finished_at'] ?? 0 );
		$error    = (string) ( $run['error'] ?? '' );

		$rows = [
			'run'              => (string) (int) ( $run['id'] ?? 0 ),
			'status'           => (string) ( $run['status'] ?? '' ),
			'scope'            => self::scope( $run ),
			'trigger'          => (string) ( $run['trigger_kind'] ?? '' ),
			'started'          => self::timestamp( $started ),
			'finished'         => self::timestamp( $finished ),
			'duration'         => $started > 0 && $finished >= $started ? Format::duration( $finished - $started ) : self::NONE,
			'bundle'           => (string) (int) ( $run['bundle_version'] ?? 0 ),
			'files indexed'    => (string) $stats->files_indexed(),
			'files scanned'    => (string) $stats->files_scanned(),
			'db rows'          => (string) $stats->db_rows(),
			'packages checked' => (string) $stats->software_checked(),
			'new findings'     => (string) ( $stats->alerts_new() + $stats->review_new() ),
			'new alerts'       => (string) $stats->alerts_new(),
			'new to review'    => (string) $stats->review_new(),
		];

		if ( '' !== $error ) {
			$rows['error'] = $error;
		}

		return self::metric_rows( $rows );
	}

	/**
	 * Where the scanner stands right now, printed by `wp lw-scan status`.
	 *
	 * @param array<string, mixed> $progress `Run\Runner::progress()` output.
	 * @param array<string, mixed> $last_run Run row the progress belongs to, or [] when nothing has ever run.
	 * @param array<string, mixed> $counts   `Db\FindingsRepository::counts()` output.
	 * @return array<int, array{metric:string, value:string}>
	 */
	public static function status( array $progress, array $last_run, array $counts ): array {
		$state = is_array( $counts['state'] ?? null ) ? $counts['state'] : [];
		$new   = is_array( $state['new'] ?? null ) ? $state['new'] : [];

		return self::metric_rows(
			[
				'status'         => (string) ( $progress['status'] ?? '' ),
				'phase'          => '' === (string) ( $progress['phase'] ?? '' ) ? self::NONE : (string) $progress['phase'],
				'files'          => sprintf( '%d/%d', (int) ( $progress['done'] ?? 0 ), (int) ( $progress['total'] ?? 0 ) ),
				'new this run'   => (string) (int) ( $progress['findings_new'] ?? 0 ),
				'elapsed'        => Format::duration( (int) ( $progress['elapsed'] ?? 0 ) ),
				'last run'       => self::last_run( $last_run ),
				'new alerts'     => (string) (int) ( $new['alert'] ?? 0 ),
				'new to review'  => (string) (int) ( $new['review'] ?? 0 ),
				'acknowledged'   => (string) self::total( $state['acknowledged'] ?? [] ),
				'ignored'        => (string) self::total( $state['ignored'] ?? [] ),
				'findings total' => (string) (int) ( $counts['total'] ?? 0 ),
			]
		);
	}

	/**
	 * The one-line summary `wp lw-scan endpoint status` prints by default.
	 *
	 * @param array{enabled:bool, ttl_minutes:int, has_key:bool, key_set_at:int} $status `CLI\EndpointCli::status()` output.
	 */
	public static function endpoint_summary( array $status ): string {
		return sprintf(
			'Status endpoint: %s, reuse %d min, %s',
			$status['enabled'] ? 'on' : 'off',
			$status['ttl_minutes'],
			$status['has_key'] ? sprintf( 'key created %s', self::timestamp( $status['key_set_at'] ) ) : 'no key yet'
		);
	}

	/**
	 * The same facts as `endpoint_summary()`, as metric/value rows for
	 * `wp lw-scan endpoint status --format=<table|json|yaml>`.
	 *
	 * @param array{enabled:bool, ttl_minutes:int, has_key:bool, key_set_at:int} $status `CLI\EndpointCli::status()` output.
	 * @return array<int, array{metric:string, value:string}>
	 */
	public static function endpoint_status_rows( array $status ): array {
		return self::metric_rows(
			[
				'enabled'     => $status['enabled'] ? 'on' : 'off',
				'reuse'       => sprintf( '%d min', $status['ttl_minutes'] ),
				'key'         => $status['has_key'] ? 'present' : 'none',
				'key created' => self::timestamp( $status['key_set_at'] ),
			]
		);
	}

	/**
	 * The one-line summary `wp lw-scan notify status` prints by default.
	 *
	 * @param array{enabled:bool, level:string, recipients:string[], uses_admin_email:bool, limit:int, baseline:bool} $status `CLI\NotifyCli::status()` output.
	 */
	public static function notify_summary( array $status ): string {
		return sprintf(
			'Notifications: %s, %s, to %s, %s per e-mail, baseline %s',
			$status['enabled'] ? 'on' : 'off',
			self::notify_level_label( $status['level'] ),
			self::notify_recipients( $status ),
			self::notify_limit( $status['limit'] ),
			$status['baseline'] ? 'taken' : 'not taken yet'
		);
	}

	/**
	 * The same facts as `notify_summary()`, as metric/value rows for
	 * `wp lw-scan notify status --format=<table|json|yaml>`.
	 *
	 * @param array{enabled:bool, level:string, recipients:string[], uses_admin_email:bool, limit:int, baseline:bool} $status `CLI\NotifyCli::status()` output.
	 * @return array<int, array{metric:string, value:string}>
	 */
	public static function notify_status_rows( array $status ): array {
		return self::metric_rows(
			[
				'e-mail'     => $status['enabled'] ? 'on' : 'off',
				'level'      => self::notify_level_label( $status['level'] ),
				'recipients' => self::notify_recipients( $status ),
				'per e-mail' => self::notify_limit( $status['limit'] ),
				'baseline'   => $status['baseline'] ? 'taken' : 'not taken yet',
			]
		);
	}

	/**
	 * What a notify level means, spelled out as the Notifications tab
	 * spells it. The one place either the command's messages or its tables
	 * get the wording from.
	 *
	 * @param string $level `alerts`|`review`, as `CLI\NotifyCli` reports it.
	 */
	public static function notify_level_label( string $level ): string {
		return 'review' === $level ? 'new alerts + review items' : 'new alerts only';
	}

	/**
	 * Who mail reaches, saying out loud when that is the site's admin
	 * address because nothing was configured.
	 *
	 * @param array{recipients:string[], uses_admin_email:bool} $status `CLI\NotifyCli::status()` output.
	 */
	private static function notify_recipients( array $status ): string {
		if ( [] === $status['recipients'] ) {
			return 'nobody';
		}

		$list = implode( ', ', $status['recipients'] );

		return $status['uses_admin_email'] ? sprintf( 'the site admin (%s)', $list ) : $list;
	}

	/**
	 * @param int $limit Findings one e-mail may list, 0 for all of them.
	 */
	private static function notify_limit( int $limit ): string {
		return 0 === $limit ? 'all items' : sprintf( 'up to %d items', $limit );
	}

	/**
	 * A timestamp as UTC, or a dash when there is none.
	 *
	 * @param int $ts Unix timestamp; 0 means "never".
	 */
	public static function timestamp( int $ts ): string {
		return $ts > 0 ? gmdate( 'Y-m-d H:i:s', $ts ) : self::NONE;
	}

	/**
	 * "#7 done (2026-09-16 03:12:40)", or "never".
	 *
	 * @param array<string, mixed> $run Run row, or [] when nothing has ever run.
	 */
	private static function last_run( array $run ): string {
		if ( [] === $run ) {
			return 'never';
		}

		$finished = (int) ( $run['finished_at'] ?? 0 );

		return sprintf(
			'#%d %s (%s)',
			(int) ( $run['id'] ?? 0 ),
			(string) ( $run['status'] ?? '' ),
			self::timestamp( $finished > 0 ? $finished : (int) ( $run['started_at'] ?? 0 ) )
		);
	}

	/**
	 * The scan scope, naming the directory when the run was scoped to one.
	 *
	 * @param array<string, mixed> $run Run row.
	 */
	private static function scope( array $run ): string {
		$scope = (string) ( $run['scope'] ?? '' );
		$path  = (string) ( $run['scope_path'] ?? '' );

		return '' === $path ? $scope : sprintf( '%s (%s)', $scope, $path );
	}

	/**
	 * The signature ids that matched, capped so one noisy file cannot
	 * stretch the table; a finding with no ids of its own (integrity,
	 * path-signal only) shows its tier instead.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function detected_by( array $finding ): string {
		$stored = $finding['signature_ids'] ?? [];
		$ids    = is_array( $stored ) ? array_map( 'strval', $stored ) : Finding::decode_list( (string) $stored );

		if ( [] === $ids ) {
			return (string) ( $finding['tier'] ?? '' );
		}

		$shown = array_slice( $ids, 0, self::SIGNATURE_PREVIEW );
		$rest  = count( $ids ) - count( $shown );

		return implode( ', ', $shown ) . ( $rest > 0 ? sprintf( ' (+%d)', $rest ) : '' );
	}

	/**
	 * @param mixed $severities Severity => count map from `counts()['state']`.
	 * @return int Findings in that state, whatever their severity.
	 */
	private static function total( $severities ): int {
		return is_array( $severities ) ? (int) array_sum( array_map( 'intval', $severities ) ) : 0;
	}

	/**
	 * Turns an ordered metric => value map into the two-column rows
	 * `SUMMARY_COLUMNS` prints. Public because every metric/value table in
	 * the CLI is this shape — `run_summary()` and `status()` here,
	 * `bundle status` and `index stats` in their own commands — and three
	 * hand-built copies of the same two keys is three chances to disagree
	 * with the column list they are printed against.
	 *
	 * @param array<string, string> $values Metric => value, in print order.
	 * @return array<int, array{metric:string, value:string}>
	 */
	public static function metric_rows( array $values ): array {
		$rows = [];

		foreach ( $values as $metric => $value ) {
			$rows[] = [
				'metric' => $metric,
				'value'  => $value,
			];
		}

		return $rows;
	}
}
