<?php
/**
 * Reads and writes the plugin's findings table.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Db;

use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Fingerprint;
use LightweightPlugins\Scan\Findings\Merger;

defined( 'ABSPATH' ) || exit;

/**
 * One row per finding, keyed by the sha256 of type+locator (spec §4.2/§9).
 * Static, like the plugin's other single-table repositories — every method
 * reaches for the global `$wpdb` on the request it runs in.
 */
final class FindingsRepository implements FindingsRepositoryInterface {

	/**
	 * The states a finding may be in (spec §9), and the only values
	 * `set_state()` accepts. Public because every caller that takes a state
	 * from outside — the Findings tab's AJAX handler, the
	 * `lw-scan/acknowledge` ability — validates against this one list.
	 *
	 * @var string[]
	 */
	public const STATES = [ 'new', 'acknowledged', 'ignored' ];

	/**
	 * Inserts a new finding, or merges into the existing one with the same
	 * `locator_hash` (spec §9): signature IDs union, tier rises to the
	 * higher of the two, excerpt/line/reason/meta refresh to the incoming
	 * values, and an `ignored` finding whose signature set grew reopens to
	 * `new`.
	 *
	 * @param Finding $finding Freshly built finding from a scanner/matcher.
	 * @return array{id:int, created:bool, changed:bool}
	 */
	public static function upsert( Finding $finding ): array {
		global $wpdb;

		$table        = Schema::findings_table();
		$now          = time();
		$locator_hash = Fingerprint::of( $finding->type, $finding->locator );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; locator_hash is a placeholder. Unique-key lookup before insert/update.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE locator_hash = %s", $locator_hash ), ARRAY_A );

		if ( ! is_array( $existing ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert() on our own table; row built entirely by Finding::to_row().
			$wpdb->insert( $table, $finding->to_row( $now ) );

			return [
				'id'      => (int) $wpdb->insert_id,
				'created' => true,
				'changed' => true,
			];
		}

		$merged = self::merge_rows( $existing, $finding, $now );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() on our own table; id came from the unique-key lookup above.
		$wpdb->update( $table, $merged['row'], [ 'id' => (int) $existing['id'] ] );

		return [
			'id'      => (int) $existing['id'],
			'created' => false,
			'changed' => $merged['changed'],
		];
	}

	/**
	 * Pure merge logic behind upsert()'s update branch — no DB access, so
	 * it can be unit-tested directly. Delegates to Findings\Merger; kept as
	 * a thin static wrapper here so the merge rules live next to the rest
	 * of the finding model in includes/Findings/.
	 *
	 * @param array<string, mixed> $existing Current DB row for this locator_hash.
	 * @param Finding              $incoming Freshly built finding to merge in.
	 * @param int                  $now      Current unix timestamp.
	 * @return array{row: array<string, mixed>, changed: bool, reopened: bool}
	 */
	public static function merge_rows( array $existing, Finding $incoming, int $now ): array {
		return Merger::merge_rows( $existing, $incoming, $now );
	}

	/**
	 * Sets the state for a batch of findings. Callers validate `$state`
	 * against `self::STATES` first; nothing else is a valid state.
	 *
	 * @param int[]  $ids   Finding ids; see `Findings\StateChange::normalize_ids()`.
	 * @param string $state One of `self::STATES` (new|acknowledged|ignored).
	 * @return int Rows updated.
	 */
	public static function set_state( array $ids, string $state ): int {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( [] === $ids ) {
			return 0;
		}

		global $wpdb;
		$table        = Schema::findings_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( [ $state, time() ], $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table name; {$placeholders} expands to a %d list sized to $ids, so $args has more values than the two literal %s/%d the sniff can see (the sniff can't evaluate that interpolation). Bulk state change from the admin UI.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = %s, state_changed_at = %d WHERE id IN ({$placeholders})", $args ) );
	}

	/**
	 * A filtered, paginated findings list.
	 *
	 * @param array{severity?:string, type?:string, state?:string, search?:string} $filters  Optional filters.
	 * @param int                                                                  $page     1-based page number.
	 * @param int                                                                  $per_page Rows per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function list( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table = Schema::findings_table();

		[$where, $args] = self::build_filters( $filters );
		$where_sql      = [] === $where ? '' : ' WHERE ' . implode( ' AND ', $where );

		if ( [] === $args ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no filters applied so no dynamic values.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where_sql}" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table name; $where_sql's %s placeholders come from build_filters() (the sniff can't see through that interpolation), matched 1:1 with $args.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where_sql}", $args ) );
		}

		$offset    = max( 0, ( $page - 1 ) * $per_page );
		$list_args = array_merge( $args, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- own table name; $where_sql's %s placeholders from build_filters() aren't visible to the sniff, so $list_args (filters + LIMIT + OFFSET) looks longer than the two literal %d it can see.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where_sql} ORDER BY last_seen DESC LIMIT %d OFFSET %d", $list_args ), ARRAY_A );

		$items = [];
		foreach ( (array) $rows as $row ) {
			$row['signature_ids'] = Finding::decode_list( (string) ( $row['signature_ids'] ?? '[]' ) );
			$row['meta']          = Finding::decode_map( (string) ( $row['meta'] ?? '{}' ) );
			$items[]              = $row;
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Builds the WHERE fragments and prepare() args for list()/its count.
	 *
	 * @param array{severity?:string, type?:string, state?:string, search?:string} $filters Optional filters.
	 * @return array{0: string[], 1: array<int, string>}
	 */
	private static function build_filters( array $filters ): array {
		$where = [];
		$args  = [];

		foreach ( [ 'severity', 'type', 'state' ] as $column ) {
			if ( ! empty( $filters[ $column ] ) ) {
				$where[] = "{$column} = %s";
				$args[]  = (string) $filters[ $column ];
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			global $wpdb;
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = '(locator LIKE %s OR signature_ids LIKE %s OR reason LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		return [ $where, $args ];
	}

	/**
	 * Counts grouped by state x severity, by type, and overall.
	 *
	 * @return array{state: array<string, array<string, int>>, type: array<string, int>, total: int}
	 */
	public static function counts(): array {
		global $wpdb;
		$table = Schema::findings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values. Admin dashboard counts.
		$state_rows = $wpdb->get_results( "SELECT state, severity, COUNT(*) AS total FROM {$table} GROUP BY state, severity", ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values. Admin dashboard counts.
		$type_rows = $wpdb->get_results( "SELECT type, COUNT(*) AS total FROM {$table} GROUP BY type", ARRAY_A );

		$states = [
			'new'          => [],
			'acknowledged' => [],
			'ignored'      => [],
		];
		foreach ( (array) $state_rows as $row ) {
			$state = (string) $row['state'];
			if ( ! isset( $states[ $state ] ) ) {
				$states[ $state ] = [];
			}
			$states[ $state ][ (string) $row['severity'] ] = (int) $row['total'];
		}

		$types = [];
		foreach ( (array) $type_rows as $row ) {
			$types[ (string) $row['type'] ] = (int) $row['total'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		return [
			'state' => $states,
			'type'  => $types,
			'total' => $total,
		];
	}

	/**
	 * New findings since a timestamp, by first_seen or reopen time.
	 *
	 * @param int $ts Unix timestamp (typically the run's started_at).
	 * @return array<int, array<string, mixed>>
	 */
	public static function new_since( int $ts ): array {
		global $wpdb;
		$table = Schema::findings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; timestamp is a placeholder. Notification query after a run.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE state = 'new' AND (first_seen >= %d OR state_changed_at >= %d) ORDER BY last_seen DESC", $ts, $ts ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Deletes findings tied to files that no longer exist.
	 *
	 * @param int[] $file_ids File ids.
	 * @return int Rows deleted.
	 */
	public static function delete_for_files( array $file_ids ): int {
		$ids = array_values( array_unique( array_map( 'intval', $file_ids ) ) );

		if ( [] === $ids ) {
			return 0;
		}

		global $wpdb;
		$table        = Schema::findings_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table name; {$placeholders} expands to a %d list sized to $ids (the sniff can't evaluate that interpolation). Cleanup after delete_unseen().
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE file_id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Which of `$locators` already have a finding of `$type` — one query for
	 * the whole batch, through the unique `locator_hash` key, so a caller
	 * deciding per queue batch never asks per file.
	 *
	 * @param string   $type     Finding type.
	 * @param string[] $locators Locators to look up.
	 * @return string[] The subset of `$locators` with a finding, in input order.
	 */
	public static function existing_locators( string $type, array $locators ): array {
		$by_hash = [];

		foreach ( $locators as $locator ) {
			$by_hash[ Fingerprint::of( $type, (string) $locator ) ] = (string) $locator;
		}

		if ( [] === $by_hash ) {
			return [];
		}

		global $wpdb;
		$table        = Schema::findings_table();
		$placeholders = implode( ',', array_fill( 0, count( $by_hash ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table name; {$placeholders} expands to a %s list sized to the hashes (the sniff can't evaluate that interpolation).
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT locator_hash FROM {$table} WHERE locator_hash IN ({$placeholders})", array_keys( $by_hash ) ), ARRAY_A );
		$found = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$found[ (string) $row['locator_hash'] ] = true;
		}

		return array_values( array_intersect_key( $by_hash, $found ) );
	}

	/**
	 * Deletes the finding for one type+locator, if any.
	 *
	 * @param string $type    Finding type.
	 * @param string $locator Locator string.
	 * @return int Rows deleted (0 or 1).
	 */
	public static function delete_by_locator( string $type, string $locator ): int {
		global $wpdb;
		$table = Schema::findings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; locator_hash is a placeholder.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE locator_hash = %s", Fingerprint::of( $type, $locator ) ) );
	}

	/**
	 * Deletes vulnerability findings whose locator is no longer reported by
	 * the matcher. An empty list deletes every vulnerability finding.
	 *
	 * @param string[] $locators Locators still current.
	 * @return int Rows deleted.
	 */
	public static function delete_vuln_not_in( array $locators ): int {
		global $wpdb;
		$table = Schema::findings_table();

		if ( [] === $locators ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, fixed type literal, no dynamic values.
			return (int) $wpdb->query( "DELETE FROM {$table} WHERE type = 'vulnerability'" );
		}

		$placeholders = implode( ',', array_fill( 0, count( $locators ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table name; {$placeholders} expands to a %s list sized to $locators (the sniff can't evaluate that interpolation).
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE type = 'vulnerability' AND locator NOT IN ({$placeholders})", array_values( $locators ) ) );
	}

	/**
	 * A single finding by id, JSON columns decoded.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::findings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; id is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['signature_ids'] = Finding::decode_list( (string) ( $row['signature_ids'] ?? '[]' ) );
		$row['meta']          = Finding::decode_map( (string) ( $row['meta'] ?? '{}' ) );

		return $row;
	}

	/**
	 * Deletes every finding of one type (used before a full vuln/db re-scan).
	 *
	 * @param string $type Finding type.
	 * @return void
	 */
	public static function truncate_type( string $type ): void {
		global $wpdb;
		$table = Schema::findings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; type is a placeholder.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE type = %s", $type ) );
	}

	/**
	 * Deletes every finding (the Findings tab's "Clear all findings").
	 *
	 * Callers pair this with `FilesRepository::reset_hashes()` so the next
	 * scan re-checks every file and reports again whatever is still there.
	 *
	 * @return int Rows deleted.
	 */
	public static function clear(): int {
		global $wpdb;
		$table = Schema::findings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name from Schema::findings_table(), no dynamic values; explicit user-requested clear.
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}
}
