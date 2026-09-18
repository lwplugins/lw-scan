<?php
/**
 * Database table presence, engine and row-count check for the Health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

use LightweightPlugins\Scan\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §12: all three tables must exist (a missing one means
 * `Schema::install()` never ran or failed — nothing to scan into), and
 * every table should be InnoDB (a MyISAM table silently drops the atomic
 * upserts the pipeline relies on). Row counts (`SHOW TABLE STATUS`'
 * `Rows` column, fetched alongside `Engine` in the same query — no
 * separate `SELECT COUNT(*)` per table) are informational, for the Health
 * tab and `wp lw-scan status` to show table growth at a glance.
 *
 * A missing table marks this row `blocking`, and it means it:
 * `Environment::blocking_issue()` — the scan-start gate — runs this check
 * alongside `StorageCheck`/`BundleCheck`, because a run whose row cannot be
 * written has nowhere to record what it finds.
 */
final class TablesCheck implements CheckInterface {

	public function id(): string {
		return 'tables';
	}

	public function label(): string {
		return __( 'Database tables', 'lw-scan' );
	}

	public function run(): array {
		$missing = Schema::missing_tables();

		if ( [] !== $missing ) {
			return [
				'status'   => 'critical',
				'message'  => sprintf(
					/* translators: %s: comma-separated list of missing table names. */
					__( "The plugin's database tables are missing (%s) — deactivate and reactivate the plugin.", 'lw-scan' ),
					implode( ', ', $missing )
				),
				'blocking' => true,
			];
		}

		$tables = [
			'files'    => Schema::files_table(),
			'findings' => Schema::findings_table(),
			'runs'     => Schema::runs_table(),
		];

		$details    = [];
		$parts      = [];
		$non_innodb = [];

		foreach ( $tables as $key => $table ) {
			$status = $this->status_of( $table );
			$engine = null !== $status ? $status['engine'] : '';
			$rows   = null !== $status ? $status['rows'] : 0;

			$details[ $key ] = [
				'rows'   => $rows,
				'engine' => $engine,
			];

			// number_format(), not number_format_i18n(): a Health message is
			// plain text and `wp lw-scan status` prints it as it stands. The
			// localized formatter separates thousands with an HTML-encoded
			// non-breaking space under some locales, which reached the
			// terminal as a literal "44&nbsp;185 rows".
			/* translators: 1: table name. 2: row count. */
			$parts[] = sprintf( __( '%1$s (%2$s rows)', 'lw-scan' ), $table, number_format( $rows ) );

			if ( '' !== $engine && 'InnoDB' !== $engine ) {
				$non_innodb[] = $table . ' (' . $engine . ')';
			}
		}

		$summary = [] === $non_innodb
			? __( 'all InnoDB', 'lw-scan' )
			/* translators: %s: comma-separated list of "table (engine)". */
			: sprintf( __( 'not InnoDB: %s', 'lw-scan' ), implode( ', ', $non_innodb ) );

		return [
			'status'   => [] === $non_innodb ? 'ok' : 'warning',
			'message'  => implode( ' · ', $parts ) . ' · ' . $summary,
			'blocking' => false,
			'details'  => [ 'tables' => $details ],
		];
	}

	/**
	 * @param string $table Fully-qualified table name.
	 * @return array{engine:string, rows:int}|null Null when the table isn't found.
	 */
	private function status_of( string $table ): ?array {
		global $wpdb;

		// esc_like(): `_` is a LIKE wildcard, so an unescaped table name can
		// match another install's similarly-named table first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is the placeholder for SHOW TABLE STATUS LIKE, resolved once for the Health tab.
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );

		if ( ! is_array( $row ) || ! isset( $row['Engine'] ) ) {
			return null;
		}

		return [
			'engine' => (string) $row['Engine'],
			'rows'   => isset( $row['Rows'] ) ? (int) $row['Rows'] : 0,
		];
	}
}
