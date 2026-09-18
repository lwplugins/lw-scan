<?php
/**
 * Detects newly created administrator accounts.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\DbScan;

use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §7: a backdoor plugin/theme often grants itself an admin account
 * rather than modifying files. This has no signature rules of its own — it
 * diffs the current `capabilities` admin set against `State.admin_user_ids`
 * from the previous run. The very first run only seeds that baseline (an
 * empty stored list means "never compared before", not "no admins existed
 * before"), so it never fires on initial install.
 */
final class UsersScanner {

	/** @var \wpdb */
	private \wpdb $wpdb;

	/** @var FindingsRepositoryInterface */
	private FindingsRepositoryInterface $findings;

	/**
	 * @param \wpdb                       $wpdb     Database connection.
	 * @param FindingsRepositoryInterface $findings Findings repository.
	 */
	public function __construct( \wpdb $wpdb, FindingsRepositoryInterface $findings ) {
		$this->wpdb     = $wpdb;
		$this->findings = $findings;
	}

	/**
	 * @return array{users:int, new_admins:int} `users` is administrator rows examined, so the db phase can report what it looked at (not only what it found).
	 */
	public function scan(): array {
		$current = $this->current_admin_ids();

		$stored     = State::get( 'admin_user_ids', [] );
		$stored_ids = is_array( $stored ) ? array_map( 'intval', $stored ) : [];

		$new_admins = 0;

		if ( [] !== $stored_ids ) {
			foreach ( array_diff( $current, $stored_ids ) as $user_id ) {
				$this->flag_new_admin( $user_id );
				++$new_admins;
			}
		}

		State::set( 'admin_user_ids', $current );

		return [
			'users'      => count( $current ),
			'new_admins' => $new_admins,
		];
	}

	/**
	 * @return int[]
	 */
	private function current_admin_ids(): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress core table, no LW Scan equivalent; property, not user input.
		$table = $wpdb->usermeta;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->usermeta, not user input; meta_key/meta_value are placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s", $wpdb->prefix . 'capabilities', '%administrator%' ), ARRAY_A );

		$rows = is_array( $rows ) ? $rows : [];

		$ids = [];

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['user_id'];
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param int $user_id Newly seen administrator user id.
	 * @return void
	 */
	private function flag_new_admin( int $user_id ): void {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress core table, no LW Scan equivalent; property, not user input.
		$table = $wpdb->users;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->users, not user input; user id is a placeholder.
		$user = $wpdb->get_row( $wpdb->prepare( "SELECT user_login, user_registered FROM {$table} WHERE ID = %d", $user_id ), ARRAY_A );

		$login      = is_array( $user ) ? (string) ( $user['user_login'] ?? '' ) : '';
		$registered = is_array( $user ) ? (string) ( $user['user_registered'] ?? '' ) : '';

		$finding = new Finding( 'db', "users:capabilities:{$user_id}", 'suspicious', Severity::REVIEW );

		$finding->category = 'credential';
		$finding->reason   = sprintf( 'New administrator account: %s (registered %s)', $login, $registered );
		$finding->meta     = [
			'user_id'         => $user_id,
			'user_login'      => $login,
			'user_registered' => $registered,
		];

		$this->findings->upsert( $finding );
	}
}
