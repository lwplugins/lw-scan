<?php
/**
 * Reads and writes the plugin's file-index table.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Db;

defined( 'ABSPATH' ) || exit;

/**
 * One row per indexed file: stat data from the walker, then md5/sha256/kind
 * from the hash pass, then a scan cursor (`scanned_bundle`) the scanner
 * pass advances. `$wpdb` is constructor-injected so tests (and the CLI's
 * potential future multi-site loop) can pass a specific connection; a null
 * argument falls back to the global one.
 *
 * Every method starts by aliasing the injected connection into a local
 * `$wpdb` variable: WPCS's PreparedSQL sniffs only recognize the literal
 * `$wpdb` variable name, not property access, so `$this->wpdb->prepare()`
 * reads as an unprepared query to static analysis even though it isn't.
 */
final class FilesRepository implements FilesRepositoryInterface {

	private const BATCH_SIZE = 200;

	private const DELETE_CHUNK = 500;

	/**
	 * Columns update_hashed() is allowed to write.
	 *
	 * Public so SchemaTest can assert the list against the columns the
	 * CREATE TABLE statement actually declares.
	 *
	 * @var array<int, string>
	 */
	public const HASH_FIELDS = [ 'md5', 'sha256', 'kind', 'origin', 'known_good', 'path_signal', 'indexed_at' ];

	/**
	 * Columns the walker's stat batch inserts, in placeholder order.
	 *
	 * Public for the same reason as HASH_FIELDS. Its order and length are
	 * tied to the `(%s, %s, %d, %d, %d, %s, %d)` row template below.
	 *
	 * @var array<int, string>
	 */
	public const STAT_COLUMNS = [ 'path', 'path_hash', 'size', 'mtime', 'path_signal', 'origin', 'seen_run' ];

	/**
	 * Columns in HASH_FIELDS that are integers rather than strings.
	 *
	 * @var array<int, string>
	 */
	private const HASH_FIELD_INTS = [ 'known_good', 'path_signal', 'indexed_at' ];

	/** @var \wpdb Injected (or global) database connection. */
	private \wpdb $wpdb;

	/**
	 * @param \wpdb|null $wpdb Connection to use; defaults to the global one.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Upserts a batch of walker stat rows, 200 at a time.
	 *
	 * Existing rows have md5/sha256/kind/known_good/scanned_bundle reset
	 * whenever the stat actually changed (size OR mtime); `size` and
	 * `mtime` themselves are assigned last in the SET list, as plain
	 * `VALUES()` with no `IF()`, so every reset branch above still reads
	 * their pre-update values — MySQL evaluates a multi-column SET list
	 * left to right, and an earlier reassignment of `size`/`mtime` would
	 * make later `<> VALUES(...)` comparisons trivially false.
	 *
	 * @param array<int, array{path:string,size:int,mtime:int,path_signal:int,origin:string}> $rows  Stat rows from the walker.
	 * @param int                                                                             $run_id Current run id, stored as `seen_run`.
	 * @return void
	 */
	public function upsert_stat_batch( array $rows, int $run_id ): void {
		if ( [] === $rows ) {
			return;
		}

		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		foreach ( array_chunk( $rows, self::BATCH_SIZE ) as $chunk ) {
			$placeholders = [];
			$values       = [];

			foreach ( $chunk as $row ) {
				$path = (string) $row['path'];

				$placeholders[] = '(%s, %s, %d, %d, %d, %s, %d)';
				$values[]       = $path;
				$values[]       = hash( 'sha256', $path );
				$values[]       = (int) $row['size'];
				$values[]       = (int) $row['mtime'];
				$values[]       = (int) $row['path_signal'];
				$values[]       = (string) $row['origin'];
				$values[]       = $run_id;
			}

			$condition = 'size <> VALUES(size) OR mtime <> VALUES(mtime)';

			$sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', self::STAT_COLUMNS ) . ') VALUES '
				. implode( ', ', $placeholders )
				. ' ON DUPLICATE KEY UPDATE '
				. 'seen_run = VALUES(seen_run), '
				. 'origin = VALUES(origin), '
				. "md5 = IF({$condition}, '', md5), "
				. "sha256 = IF({$condition}, '', sha256), "
				. "kind = IF({$condition}, '', kind), "
				. "known_good = IF({$condition}, 0, known_good), "
				. "scanned_bundle = IF({$condition}, 0, scanned_bundle), "
				. 'size = VALUES(size), '
				. 'mtime = VALUES(mtime)';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name from Schema::files_table(); every value is a placeholder. $sql is assembled above from literals only (the sniff can't trace a non-literal first argument to prepare()). Batched stat upsert from the walker.
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
		}
	}

	/**
	 * The next unhashed rows (`md5 = ''`), ordered by id for a stable cursor.
	 *
	 * @param int $after_id Cursor: only rows with a greater id.
	 * @param int $limit    Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function next_unhashed( int $after_id, int $limit ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; id/limit are placeholders. Hash-pass cursor query.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE md5 = '' AND id > %d ORDER BY id LIMIT %d", $after_id, $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Writes the hash-pass result for one row (whitelisted columns only).
	 *
	 * @param int                  $id     Row id.
	 * @param array<string, mixed> $fields Column => value; anything outside HASH_FIELDS is dropped.
	 * @return void
	 */
	public function update_hashed( int $id, array $fields ): void {
		$data = array_intersect_key( $fields, array_flip( self::HASH_FIELDS ) );

		if ( [] === $data ) {
			return;
		}

		$formats = [];
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = in_array( $column, self::HASH_FIELD_INTS, true ) ? '%d' : '%s';
		}

		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() on our own table; column set is whitelisted above.
		$wpdb->update( Schema::files_table(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	/**
	 * The next batch of files due a content scan for this bundle version.
	 *
	 * `<>` rather than `<`: a downgraded pack (backend withdraws a bad
	 * version and re-serves an older one) leaves every row stamped above
	 * the new current version, and `<` would queue nothing.
	 *
	 * @param int    $bundle_version Current signature bundle version.
	 * @param int    $after_id       Cursor: only rows with a greater id.
	 * @param int    $limit          Max rows.
	 * @param string $path_prefix    Optional path prefix filter (scope=path).
	 * @return array<int, array<string, mixed>>
	 */
	public function queue_for_scan( int $bundle_version, int $after_id, int $limit, string $path_prefix = '' ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();
		$sql   = "SELECT * FROM {$table} WHERE known_good = 0 AND scanned_bundle <> %d AND md5 <> '' AND kind <> 'unreadable' AND id > %d";
		$args  = [ $bundle_version, $after_id ];

		if ( '' !== $path_prefix ) {
			$sql   .= ' AND path LIKE %s';
			$args[] = $wpdb->esc_like( $path_prefix ) . '%';
		}

		$sql   .= ' ORDER BY id LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name; every dynamic value above is a placeholder ($sql is assembled from literals only; the sniff can't trace a non-literal first argument to prepare()). Content-scan queue cursor.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Count of files still queued for a content scan at this bundle version.
	 * Same `<>` reasoning as `queue_for_scan()`.
	 *
	 * @param int    $bundle_version Current signature bundle version.
	 * @param string $path_prefix    Optional path prefix filter (scope=path).
	 * @return int
	 */
	public function count_queue( int $bundle_version, string $path_prefix = '' ): int {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE known_good = 0 AND scanned_bundle <> %d AND md5 <> '' AND kind <> 'unreadable'";
		$args  = [ $bundle_version ];

		if ( '' !== $path_prefix ) {
			$sql   .= ' AND path LIKE %s';
			$args[] = $wpdb->esc_like( $path_prefix ) . '%';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name; every dynamic value above is a placeholder ($sql is assembled from literals only; the sniff can't trace a non-literal first argument to prepare()). Progress counter, run on demand.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Marks a file as scanned at the given bundle version.
	 *
	 * @param int $id             Row id.
	 * @param int $bundle_version Bundle version just scanned against.
	 * @return void
	 */
	public function mark_scanned( int $id, int $bundle_version ): void {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() on our own table by primary key.
		$wpdb->update( Schema::files_table(), [ 'scanned_bundle' => $bundle_version ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
	}

	/**
	 * Resets the scan cursor so matching files are content-scanned again
	 * (used at scope=full).
	 *
	 * @param string $path_prefix Optional path prefix filter.
	 * @return void
	 */
	public function reset_scanned( string $path_prefix = '' ): void {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		if ( '' === $path_prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values; full-scope rescan reset.
			$wpdb->query( "UPDATE {$table} SET scanned_bundle = 0" );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; path is a placeholder. Path-scope rescan reset.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET scanned_bundle = 0 WHERE path LIKE %s", $wpdb->esc_like( $path_prefix ) . '%' ) );
	}

	/**
	 * Drops every stored hash along with the known-good verdict and the
	 * scan cursor that were derived from it, so the next run re-hashes each
	 * indexed file and re-decides both from scratch.
	 *
	 * `Upgrader` calls this after a version change: `known_good` and the
	 * findings behind it are only ever recomputed for files the hash pass
	 * re-reads, so without this an upgrade that fixes a checksum or
	 * classification bug would leave the wrong verdicts in place on every
	 * file that happened not to change on disk. The stat columns
	 * (`path`, `path_hash`, `size`, `mtime`) are deliberately left alone —
	 * the walk stays incremental, only the hash pass redoes its work.
	 *
	 * @return void
	 */
	public function reset_hashes(): void {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name from Schema::files_table(), no dynamic values; one-shot post-upgrade re-hash reset.
		$wpdb->query( "UPDATE {$table} SET md5 = '', sha256 = '', known_good = 0, scanned_bundle = 0" );
	}

	/**
	 * Deletes rows not touched by the given run (files removed from disk
	 * since), in chunks of 500.
	 *
	 * @param int $run_id Current run id.
	 * @return int[] Deleted row ids.
	 */
	public function delete_unseen( int $run_id ): array {
		$wpdb    = $this->wpdb;
		$table   = Schema::files_table();
		$deleted = [];

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; run_id/limit are placeholders. Chunked pre-delete lookup.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE seen_run < %d ORDER BY id LIMIT %d", $run_id, self::DELETE_CHUNK ) );
			$ids = array_map( 'intval', (array) $ids );

			if ( [] === $ids ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table name; {$placeholders} expands to a %d list sized to $ids (the sniff can't evaluate that interpolation, so it can't see the placeholders it produces). Chunked cleanup delete.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );

			$deleted    = array_merge( $deleted, $ids );
			$chunk_size = count( $ids );
		} while ( self::DELETE_CHUNK === $chunk_size );

		return $deleted;
	}

	/**
	 * Total number of indexed files.
	 *
	 * @return int
	 */
	public function count(): int {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Summary counts for the health/stats views.
	 *
	 * @return array{total:int, known_good:int, kinds:array<string,int>}
	 */
	public function stats(): array {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values. Shown on demand only.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values. Shown on demand only.
		$known_good = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE known_good = 1" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values. Shown on demand only.
		$rows = $wpdb->get_results( "SELECT kind, COUNT(*) AS total FROM {$table} GROUP BY kind", ARRAY_A );

		$kinds = [];
		foreach ( (array) $rows as $row ) {
			$kinds[ (string) $row['kind'] ] = (int) $row['total'];
		}

		return [
			'total'      => $total,
			'known_good' => $known_good,
			'kinds'      => $kinds,
		];
	}

	/**
	 * A single row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; id is a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Empties the file index (uninstall / full reset).
	 *
	 * @return void
	 */
	public function truncate(): void {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own table name, no dynamic values.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Count of files whose path-signal is at least `$min` (health/stats).
	 *
	 * @param int $min Minimum signal, inclusive.
	 * @return int
	 */
	public function count_where_signal_at_least( int $min ): int {
		$wpdb  = $this->wpdb;
		$table = Schema::files_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table name; min is a placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE path_signal >= %d", $min ) );
	}
}
