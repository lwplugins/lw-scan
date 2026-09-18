<?php
/**
 * Cross-request run lock: one tick may run at a time per site.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * Prefers a MySQL named lock (`GET_LOCK()`), which is session-scoped and
 * self-releasing on disconnect — so a fatal error mid-tick doesn't leave a
 * stale lock behind. When the DB layer doesn't support named locks (`NULL`
 * back from `GET_LOCK()`), falls back to a transient with a fixed TTL long
 * enough to outlive one tick's budget. One lock per site, multisite-aware
 * via `get_current_blog_id()`.
 *
 * `acquire()`/`release()` are meant to bracket a single tick within one PHP
 * process, so ownership is tracked in static state: `release()` only ever
 * undoes what THIS process's `acquire()` actually won. A `release()` with
 * no prior successful `acquire()` — e.g. a `finally` block running after a
 * failed acquire — is a no-op on both the MySQL and transient paths, and
 * on the transient path `release()` additionally checks the transient
 * still holds the token this process itself stored, so it never deletes
 * another process's active lock.
 */
final class Lock {

	private const TRANSIENT = 'lw_scan_lock';
	private const TTL       = 90;

	/** @var bool Whether this process's acquire() call won the lock. */
	private static bool $held = false;

	/** @var string 'mysql'|'transient'|'' — which mechanism `$held` was won through. */
	private static string $mechanism = '';

	/** @var string|null This process's own transient ownership token, set only on a transient acquire. */
	private static ?string $token = null;

	public static function acquire(): bool {
		$result = self::query( 'SELECT GET_LOCK(%s, 0)' );

		if ( null === $result ) {
			self::$held      = self::acquire_transient();
			self::$mechanism = self::$held ? 'transient' : '';

			return self::$held;
		}

		self::$held      = '1' === $result;
		self::$mechanism = self::$held ? 'mysql' : '';

		return self::$held;
	}

	public static function release(): void {
		if ( ! self::$held ) {
			return;
		}

		if ( 'mysql' === self::$mechanism ) {
			self::query( 'SELECT RELEASE_LOCK(%s)' );
		} elseif ( 'transient' === self::$mechanism && get_transient( self::TRANSIENT ) === self::$token ) {
			delete_transient( self::TRANSIENT );
		}

		self::$held      = false;
		self::$mechanism = '';
		self::$token     = null;
	}

	/**
	 * Resets the ownership state `acquire()`/`release()` track. Test-only:
	 * a real PHP process already starts with none of it set, so production
	 * code never needs this.
	 */
	public static function reset(): void {
		self::$held      = false;
		self::$mechanism = '';
		self::$token     = null;
	}

	/**
	 * True when the lock is currently held by another connection (via
	 * `IS_USED_LOCK()`) or when the transient fallback is set — checked
	 * unconditionally, since the transient can be held by another process
	 * even when this call's own `IS_USED_LOCK()` succeeds. Independent of
	 * `$held`: this answers "is anyone else holding it right now", not
	 * "did this process win it".
	 */
	public static function held_elsewhere(): bool {
		$holder      = self::query( 'SELECT IS_USED_LOCK(%s)' );
		$held_by_row = null !== $holder && self::connection_id() !== $holder;

		return $held_by_row || false !== get_transient( self::TRANSIENT );
	}

	private static function acquire_transient(): bool {
		// Check-then-act race: two processes can both see get_transient()
		// as false here and both proceed to set_transient() before either
		// write lands. Only reachable when MySQL named locks are
		// unsupported, so this is an accepted best-effort degrade, not the
		// primary locking mechanism.
		if ( false !== get_transient( self::TRANSIENT ) ) {
			return false;
		}

		self::$token = uniqid( '', true );

		set_transient( self::TRANSIENT, self::$token, self::TTL );

		return true;
	}

	private static function connection_id(): ?string {
		global $wpdb;

		if ( ! self::wpdb_usable( $wpdb ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- fixed literal, no dynamic values; only used to compare against IS_USED_LOCK()'s holder.
		$value = $wpdb->get_var( 'SELECT CONNECTION_ID()' );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Runs `SELECT <fn>(%s)` against this site's named lock. Returns null
	 * when `$wpdb` (or named-lock support) is unavailable, which callers
	 * treat as "fall back to the transient lock".
	 *
	 * @param string $sql_template A `SELECT <fn>(%s)` query template; the lock name is its only placeholder.
	 */
	private static function query( string $sql_template ): ?string {
		global $wpdb;

		if ( ! self::wpdb_usable( $wpdb ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql_template is a fixed literal from this class; the lock name is the query's only placeholder.
		$value = $wpdb->get_var( $wpdb->prepare( $sql_template, self::name() ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * @param mixed $wpdb The global `$wpdb`, or whatever a test has put in its place.
	 * @phpstan-assert-if-true object $wpdb
	 */
	private static function wpdb_usable( $wpdb ): bool {
		return is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' );
	}

	private static function name(): string {
		return 'lw_scan_run_' . get_current_blog_id();
	}
}
