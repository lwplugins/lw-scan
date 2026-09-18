<?php
/**
 * Scans wp_postmeta for db_post signature matches on a short list of keys.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\DbScan;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §7: only a handful of meta keys are worth checking (page builders
 * and menu items are common injection targets), so this queries by
 * `meta_key IN (…)` with no LIKE prefilter needed on top.
 */
final class MetaScanner {

	private const LIMIT = 500;

	/**
	 * Meta keys scanned for db_post signature matches.
	 *
	 * @var string[]
	 */
	private const META_KEYS = [ '_wp_page_template', '_elementor_data', '_menu_item_url' ];

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
	 * @param int $after_id Cursor: only rows with a greater meta_id.
	 * @param int $limit    Max rows to pull this call.
	 * @return array{last_id:int, done:bool, rows:int, findings:int}
	 */
	public function scan( int $after_id, int $limit = self::LIMIT ): array {
		$rules = $this->signatures->pack()->db_rules( 'db_post' );

		if ( [] === $rules ) {
			return [
				'last_id'  => $after_id,
				'done'     => true,
				'rows'     => 0,
				'findings' => 0,
			];
		}

		$rows = $this->fetch_rows( $after_id, $limit );

		$last_id  = $after_id;
		$findings = 0;

		foreach ( $rows as $row ) {
			$last_id = (int) $row['meta_id'];

			$matches = RowMatcher::match( (string) $row['meta_value'], $rules, $this->signatures );

			if ( [] === $matches ) {
				continue;
			}

			$label = sprintf( '%s (post %d)', (string) $row['meta_key'], (int) $row['post_id'] );

			$this->findings->upsert( DbFinding::build( 'postmeta', 'meta_value', $last_id, $matches, $label ) );
			++$findings;
		}

		return [
			'last_id'  => $last_id,
			'done'     => count( $rows ) < $limit,
			'rows'     => count( $rows ),
			'findings' => $findings,
		];
	}

	/**
	 * @param int $after_id Cursor.
	 * @param int $limit    Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_rows( int $after_id, int $limit ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress core table, no LW Scan equivalent; property, not user input.
		$table = $wpdb->postmeta;

		$placeholders = implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) );

		$sql = "SELECT meta_id, post_id, meta_key, meta_value FROM {$table} WHERE meta_key IN ({$placeholders}) AND meta_id > %d ORDER BY meta_id LIMIT %d";

		$args   = self::META_KEYS;
		$args[] = $after_id;
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is $wpdb->postmeta, not user input; $placeholders is a fixed-length %s list matching self::META_KEYS.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}
}
