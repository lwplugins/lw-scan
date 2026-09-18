<?php
/**
 * Scans database triggers for db_trigger signature matches.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\DbScan;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §7: a small number of sites use triggers to reinject a backdoor
 * whenever a row is touched. `SHOW TRIGGERS` needs no `$wpdb->prepare()`
 * (no dynamic values), and some hosts revoke the `TRIGGER` privilege
 * outright — that surfaces as a non-empty `$wpdb->last_error`, reported as
 * `skipped` rather than as zero triggers found.
 */
final class TriggersScanner {

	/** @var \wpdb */
	private \wpdb $wpdb;

	/** @var Signatures */
	private Signatures $signatures;

	/** @var FindingsRepositoryInterface */
	private FindingsRepositoryInterface $findings;

	/**
	 * @param \wpdb                       $wpdb       Database connection.
	 * @param Signatures                  $signatures Loaded signature set to scan with.
	 * @param FindingsRepositoryInterface $findings   Findings repository.
	 */
	public function __construct( \wpdb $wpdb, Signatures $signatures, FindingsRepositoryInterface $findings ) {
		$this->wpdb       = $wpdb;
		$this->signatures = $signatures;
		$this->findings   = $findings;
	}

	/**
	 * @return array{triggers:int, findings:int, skipped:bool}
	 */
	public function scan(): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- fixed literal, no dynamic values.
		$rows = $wpdb->get_results( 'SHOW TRIGGERS', ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			return [
				'triggers' => 0,
				'findings' => 0,
				'skipped'  => true,
			];
		}

		$rows  = is_array( $rows ) ? $rows : [];
		$rules = $this->signatures->pack()->db_rules( 'db_trigger' );

		$findings = 0;

		if ( [] !== $rules ) {
			foreach ( $rows as $row ) {
				$findings += $this->match_row( $row, $rules );
			}
		}

		return [
			'triggers' => count( $rows ),
			'findings' => $findings,
			'skipped'  => false,
		];
	}

	/**
	 * @param array<string, mixed>                                $row   One SHOW TRIGGERS row.
	 * @param array<int, array{sig:int, re:string, like:?string}> $rules Db_trigger rules from the pack.
	 * @return int 1 if a finding was upserted, 0 otherwise.
	 */
	private function match_row( array $row, array $rules ): int {
		$name      = (string) ( $row['Trigger'] ?? '' );
		$statement = (string) ( $row['Statement'] ?? '' );

		if ( '' === $statement ) {
			return 0;
		}

		$matches = RowMatcher::match( $statement, $rules, $this->signatures );

		if ( [] === $matches ) {
			return 0;
		}

		// row_id has no meaning for a trigger (it's keyed by name, not a
		// numeric id) — build with 0, then overwrite the locator/meta with
		// the trigger name so it still fits DbFinding's shared shape.
		$finding = DbFinding::build( 'triggers', 'statement', 0, $matches, $name );

		$finding->locator        = "triggers:statement:{$name}";
		$finding->meta['row_id'] = $name;

		$this->findings->upsert( $finding );

		return 1;
	}
}
