<?php
/**
 * Reads and writes the plugin's run-history table.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Db;

defined( 'ABSPATH' ) || exit;

/**
 * One row per scan run, driven by Run\Runner. `stats` is a JSON blob
 * (RunStats, spec §10.6); every read decodes it back to an array.
 * Implements the narrower RunsRepositoryInterface so the Runner and its
 * phases can be unit-tested against a mock of this final class.
 */
final class RunsRepository implements RunsRepositoryInterface {

	/**
	 * Columns update() is allowed to write.
	 *
	 * @var array<int, string>
	 */
	private const UPDATE_FIELDS = [ 'finished_at', 'status', 'bundle_version', 'stats', 'error', 'trigger_kind', 'scope', 'scope_path' ];

	/**
	 * Starts a new run row with status=running.
	 *
	 * @param string $trigger        manual|cron|catchup|cli.
	 * @param string $scope          full|changed|db|path.
	 * @param string $path           Scope path (scope=path only), else ''.
	 * @param int    $bundle_version Signature bundle version at start.
	 * @return int New run id.
	 */
	public static function create( string $trigger, string $scope, string $path, int $bundle_version ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() on our own table.
		$wpdb->insert(
			Schema::runs_table(),
			[
				'trigger_kind'   => $trigger,
				'scope'          => $scope,
				'scope_path'     => $path,
				'started_at'     => time(),
				'status'         => 'running',
				'bundle_version' => $bundle_version,
				'stats'          => wp_json_encode( [] ),
				// `error` is a TEXT column, and MySQL 8 forbids a DEFAULT on
				// TEXT, so every INSERT has to supply it explicitly.
				'error'          => '',
			]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Merges whitelisted fields into a run row; `stats` arrays are
	 * JSON-encoded automatically.
	 *
	 * @param int                  $id     Run id.
	 * @param array<string, mixed> $fields Column => value; anything outside UPDATE_FIELDS is dropped.
	 * @return void
	 */
	public static function update( int $id, array $fields ): void {
		$data = array_intersect_key( $fields, array_flip( self::UPDATE_FIELDS ) );

		if ( [] === $data ) {
			return;
		}

		if ( array_key_exists( 'stats', $data ) && is_array( $data['stats'] ) ) {
			$data['stats'] = wp_json_encode( $data['stats'] );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() on our own table; column set is whitelisted above.
		$wpdb->update( Schema::runs_table(), $data, [ 'id' => $id ] );
	}

	/**
	 * Marks a run finished (done|stopped|failed) with final stats.
	 *
	 * @param int                  $id     Run id.
	 * @param string               $status done|stopped|failed.
	 * @param array<string, mixed> $stats  Final RunStats.
	 * @param string               $error  Error message (status=failed only).
	 * @return void
	 */
	public static function finish( int $id, string $status, array $stats, string $error = '' ): void {
		self::update(
			$id,
			[
				'status'      => $status,
				'finished_at' => time(),
				'stats'       => $stats,
				'error'       => $error,
			]
		);
	}

	/**
	 * A single run by id, stats decoded.
	 *
	 * @param int $id Run id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; id is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['stats'] = self::decode_stats( (string) ( $row['stats'] ?? '{}' ) );

		return $row;
	}

	/**
	 * The most recent runs, newest first, stats decoded.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function latest( int $limit = 10 ): array {
		global $wpdb;
		$table = Schema::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; limit is a placeholder. Admin run-history list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		$items = [];
		foreach ( (array) $rows as $row ) {
			$row['stats'] = self::decode_stats( (string) ( $row['stats'] ?? '{}' ) );
			$items[]      = $row;
		}

		return $items;
	}

	/**
	 * The most recent run of any status, or null if none exist.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last(): ?array {
		$rows = self::latest( 1 );

		return $rows[0] ?? null;
	}

	/**
	 * The most recent run that finished with status=done.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_successful(): ?array {
		global $wpdb;
		$table = Schema::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; status is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT 1", 'done' ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['stats'] = self::decode_stats( (string) ( $row['stats'] ?? '{}' ) );

		return $row;
	}

	/**
	 * Deletes every run older than the most recent `$keep` (spec §4.3: kept
	 * at 50, run by finalize).
	 *
	 * @param int $keep Number of most-recent runs to retain.
	 * @return void
	 */
	public static function prune( int $keep = 50 ): void {
		global $wpdb;
		$table = Schema::runs_table();

		if ( $keep <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values.
			$wpdb->query( "DELETE FROM {$table}" );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; keep count is a placeholder.
		$keep_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d", $keep ) );
		$keep_ids = array_map( 'intval', (array) $keep_ids );

		if ( [] === $keep_ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table name; {$placeholders} expands to a %d list sized to $keep_ids (the sniff can't evaluate that interpolation, so it can't see the placeholders it produces).
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id NOT IN ({$placeholders})", $keep_ids ) );
	}

	/**
	 * Count of trailing failed runs among the last 10 (by id desc), stopping
	 * at the first non-failed run. Used to trigger the failure-streak notice.
	 *
	 * @return int
	 */
	public static function consecutive_failures(): int {
		$count = 0;

		foreach ( self::latest( 10 ) as $row ) {
			if ( 'failed' !== $row['status'] ) {
				break;
			}
			++$count;
		}

		return $count;
	}

	/**
	 * Decodes the `stats` JSON column, tolerating malformed/empty storage.
	 *
	 * @param string $json JSON-encoded RunStats.
	 * @return array<string, mixed>
	 */
	private static function decode_stats( string $json ): array {
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : [];
	}
}
