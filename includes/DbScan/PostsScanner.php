<?php
/**
 * Scans wp_posts for db_post signature matches.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\DbScan;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §7: `post_content` and `post_excerpt` are checked separately (a
 * match in one doesn't imply a match in the other), each producing its own
 * `DbFinding` with its own locator. The LIKE prefilter therefore covers
 * both columns: narrowing on `post_content` alone discarded rows whose
 * only hit is in the excerpt before the regex ever ran.
 */
final class PostsScanner {

	private const LIMIT = 500;

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
	 * @param int $after_id Cursor: only rows with a greater ID.
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

		$rows = $this->fetch_rows( $rules, $after_id, $limit );

		$last_id  = $after_id;
		$findings = 0;

		foreach ( $rows as $row ) {
			$last_id = (int) $row['ID'];
			$label   = self::label( $row );

			$findings += $this->match_column( 'post_content', (string) $row['post_content'], $last_id, $rules, $label );
			$findings += $this->match_column( 'post_excerpt', (string) $row['post_excerpt'], $last_id, $rules, $label );
		}

		return [
			'last_id'  => $last_id,
			'done'     => count( $rows ) < $limit,
			'rows'     => count( $rows ),
			'findings' => $findings,
		];
	}

	/**
	 * @param string                                              $column Column name.
	 * @param string                                              $value  Column value.
	 * @param int                                                 $row_id Post ID.
	 * @param array<int, array{sig:int, re:string, like:?string}> $rules  Db_post rules from the pack.
	 * @param string                                              $label  Row label.
	 * @return int 1 if a finding was upserted, 0 otherwise.
	 */
	private function match_column( string $column, string $value, int $row_id, array $rules, string $label ): int {
		if ( '' === $value ) {
			return 0;
		}

		$matches = RowMatcher::match( $value, $rules, $this->signatures );

		if ( [] === $matches ) {
			return 0;
		}

		$this->findings->upsert( DbFinding::build( 'posts', $column, $row_id, $matches, $label ) );

		return 1;
	}

	/**
	 * @param array<string, mixed> $row One fetch_rows() row.
	 */
	private static function label( array $row ): string {
		$title = substr( (string) ( $row['post_title'] ?? '' ), 0, 40 );

		return sprintf( '%s #%d %s', (string) $row['post_type'], (int) $row['ID'], $title );
	}

	/**
	 * @param array<int, array{sig:int, re:string, like:?string}> $rules    Db_post rules from the pack.
	 * @param int                                                 $after_id Cursor.
	 * @param int                                                 $limit    Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_rows( array $rules, int $after_id, int $limit ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress core table, no LW Scan equivalent; property, not user input.
		$table = $wpdb->posts;

		$sql  = "SELECT ID, post_type, post_title, post_content, post_excerpt FROM {$table}"
			. " WHERE post_status <> 'trash' AND post_type <> 'revision' AND ID > %d";
		$args = [ $after_id ];

		$groups = LikePrefilter::groups( $rules, 'post_content', 'post_excerpt' );

		if ( [] !== $groups && '' !== $groups[0]['sql'] ) {
			$fragments = [];

			foreach ( $groups as $group ) {
				$fragments[] = $group['sql'];
				$args        = array_merge( $args, $group['args'] );
			}

			$sql .= ' AND (' . implode( ' OR ', $fragments ) . ')';
		}

		$sql   .= ' ORDER BY ID LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is $wpdb->posts, not user input; $sql is assembled above from literals plus %d/%s placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}
}
