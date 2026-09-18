<?php
/**
 * Scans wp_options for db_option signature matches.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\DbScan;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §7: rows are pulled by relevance (autoloaded, a short list of
 * security-sensitive named options, or anything with "widget" in its
 * name — themes/plugins stash serialized widget config there), never
 * lw-scan's own state and never transients. `RowMatcher` + `DbFinding`
 * (shared across every DbScan\*Scanner) do the actual signature matching
 * and finding bookkeeping; this class only owns the query and the cursor.
 */
final class OptionsScanner {

	private const LIMIT = 500;

	/**
	 * Named options worth checking even when not autoloaded.
	 *
	 * @var string[]
	 */
	private const NAMED_OPTIONS = [
		'active_plugins',
		'template',
		'stylesheet',
		'siteurl',
		'home',
		'admin_email',
		'default_role',
		'users_can_register',
	];

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
	 * @param int $after_id Cursor: only rows with a greater option_id.
	 * @param int $limit    Max rows to pull this call.
	 * @return array{last_id:int, done:bool, rows:int, findings:int}
	 */
	public function scan( int $after_id, int $limit = self::LIMIT ): array {
		$rules = $this->signatures->pack()->db_rules( 'db_option' );

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
			$last_id = (int) $row['option_id'];

			$matches = RowMatcher::match( (string) $row['option_value'], $rules, $this->signatures );

			if ( [] === $matches ) {
				continue;
			}

			$this->findings->upsert( DbFinding::build( 'options', 'option_value', $last_id, $matches, (string) $row['option_name'] ) );
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
	 * @param array<int, array{sig:int, re:string, like:?string}> $rules    Db_option rules from the pack.
	 * @param int                                                 $after_id Cursor.
	 * @param int                                                 $limit    Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_rows( array $rules, int $after_id, int $limit ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress core table, no LW Scan equivalent; property, not user input.
		$table = $wpdb->options;

		$named_placeholders = implode( ',', array_fill( 0, count( self::NAMED_OPTIONS ), '%s' ) );

		$sql = "SELECT option_id, option_name, option_value FROM {$table}"
			. " WHERE (autoload IN ('yes','on','auto','auto-on') OR option_name IN ({$named_placeholders}) OR option_name LIKE '%widget%')"
			. " AND option_name NOT LIKE 'lw\\_scan\\_%'"
			. " AND option_name NOT LIKE '\\_transient%'"
			. " AND option_name NOT LIKE '\\_site\\_transient%'"
			. ' AND option_id > %d';

		$args = array_merge( self::NAMED_OPTIONS, [ $after_id ] );

		[ $like_sql, $like_args ] = self::like_clause( LikePrefilter::groups( $rules, 'option_value' ) );

		if ( '' !== $like_sql ) {
			$sql .= " AND {$like_sql}";
			$args = array_merge( $args, $like_args );
		}

		$sql   .= ' ORDER BY option_id LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is $wpdb->options, not user input; $sql is assembled above from literals plus the LIKE fragments below, which are themselves built from %s placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Every LikePrefilter group is OR'd into a single WHERE clause: a
	 * matching option is one that satisfies ANY db_option rule's
	 * LIKE shortcut, so there is no benefit to querying per group (and it
	 * would break the simple id-cursor pagination below).
	 *
	 * @param array<int, array{sql:string, args:array<int,string>}> $groups LikePrefilter::groups() result.
	 * @return array{0:string, 1:array<int,string>}
	 */
	private static function like_clause( array $groups ): array {
		if ( [] === $groups || '' === $groups[0]['sql'] ) {
			return [ '', [] ];
		}

		$fragments = [];
		$args      = [];

		foreach ( $groups as $group ) {
			$fragments[] = $group['sql'];
			$args        = array_merge( $args, $group['args'] );
		}

		return [ '(' . implode( ' OR ', $fragments ) . ')', $args ];
	}
}
