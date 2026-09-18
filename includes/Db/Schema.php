<?php
/**
 * Database schema for the plugin's own tables.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Db;

use LightweightPlugins\Scan\Health\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and version-tracks the three tables the scanner owns: the file
 * index, the findings list and the run history (spec §4.1-4.3).
 */
final class Schema {

	/**
	 * Bumped to 2 in 1.0.0: the files table's `signal` column became
	 * `path_signal` (SIGNAL is a reserved word, so the original CREATE
	 * TABLE was a syntax error) and the runs table's `error` column lost
	 * its TEXT DEFAULT. maybe_install() only re-runs the DDL when the
	 * stored version differs, so the rename needs a new number to reach
	 * installs that already recorded version 1.
	 */
	public const VERSION = 2;

	public const VERSION_OPTION = 'lw_scan_db_version';

	/**
	 * Back-off flag set when install() could not create every table.
	 *
	 * Without it, an install that keeps failing would re-run the whole DDL
	 * — `wp-admin/includes/upgrade.php` and everything it pulls in, three
	 * dbDelta() calls, three existence probes — on every single request,
	 * front end and REST included.
	 */
	public const RETRY_TRANSIENT = 'lw_scan_install_retry';

	/** Minutes a failed install waits before an ordinary request retries it. */
	private const RETRY_MINUTES = 5;

	/**
	 * Fully-qualified name of the file-index table.
	 *
	 * @return string
	 */
	public static function files_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'lw_scan_files';
	}

	/**
	 * Fully-qualified name of the findings table.
	 *
	 * @return string
	 */
	public static function findings_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'lw_scan_findings';
	}

	/**
	 * Fully-qualified name of the run-history table.
	 *
	 * @return string
	 */
	public static function runs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'lw_scan_runs';
	}

	/**
	 * Create or upgrade the tables when the stored version is behind.
	 *
	 * Reads a single autoloaded option, so this is cheap to call on every
	 * request (it is, from Plugin::init_components()). When that option is
	 * behind — the only case that costs anything — a failed attempt is rate
	 * limited by may_retry() rather than repeated on every hit.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::VERSION ) {
			return;
		}

		if ( ! self::may_retry() ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether this request may re-attempt an install that already failed.
	 *
	 * A failed install leaves a short back-off flag behind, which ordinary
	 * requests honour. The three contexts that can actually do something
	 * about a broken install — an admin screen, a cron run, WP-CLI — ignore
	 * the flag, so `wp plugin activate` and the next admin page load always
	 * get a real attempt.
	 *
	 * @return bool
	 */
	private static function may_retry(): bool {
		if ( ! get_transient( self::RETRY_TRANSIENT ) ) {
			return true;
		}

		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Run dbDelta for all three tables and record the version.
	 *
	 * A failed CREATE TABLE is reported through `$wpdb`'s error handler and
	 * dbDelta() returns normally, so broken DDL looks like a success to its
	 * caller. Recording the version regardless would make that breakage
	 * permanent — maybe_install() short-circuits on the option, and the
	 * health row's "deactivate and reactivate" advice re-runs the same DDL.
	 * The version is therefore only written once all three tables are
	 * actually present; until then the install is retried, bounded by the
	 * back-off flag may_retry() reads.
	 *
	 * @return bool True when all three tables exist afterwards.
	 */
	public static function install(): bool {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( self::create_statements( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}

		self::reset_exists_cache();

		if ( [] !== self::missing_tables() ) {
			set_transient( self::RETRY_TRANSIENT, 1, self::RETRY_MINUTES * MINUTE_IN_SECONDS );

			return false;
		}

		// Autoloaded on purpose: maybe_install() reads it on every request, so
		// it must not cost a query of its own.
		update_option( self::VERSION_OPTION, self::VERSION, true );

		delete_transient( self::RETRY_TRANSIENT );
		Environment::invalidate();

		return true;
	}

	/**
	 * Which of the three tables are absent right now (never memoized).
	 *
	 * The single source of truth for "is the schema actually there": both
	 * install()'s verification and the Health tables row read it, so they
	 * can never disagree about what counts as installed.
	 *
	 * @return array<int, string> Fully-qualified names of the missing tables; empty when all three exist.
	 */
	public static function missing_tables(): array {
		$missing = [];

		foreach ( [ self::files_table(), self::findings_table(), self::runs_table() ] as $table ) {
			if ( ! self::table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	/**
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// esc_like() matters: `_` is a LIKE wildcard, so an unescaped
		// `wp_lw_scan_files` also matches another install's `wpxlw_scan_files`
		// — and SHOW TABLES would hand back that row instead.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe for our own table.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * The CREATE TABLE statements for all three tables, in install order.
	 *
	 * Built without touching the database so the DDL itself is assertable:
	 * a reserved column name or a DEFAULT on a TEXT column is a syntax or
	 * strict-mode error that only surfaces inside dbDelta(), on a real
	 * server, where it is silently swallowed.
	 *
	 * @param string $collate Charset/collation suffix from `$wpdb->get_charset_collate()`.
	 * @return array<string, string> Table key => CREATE TABLE statement.
	 */
	public static function create_statements( string $collate ): array {
		return [
			'files'    => self::files_sql( $collate ),
			'findings' => self::findings_sql( $collate ),
			'runs'     => self::runs_sql( $collate ),
		];
	}

	/**
	 * Formatting matters: dbDelta() is whitespace-sensitive — two spaces
	 * after the key type, one field per line, no backticks on the table name.
	 *
	 * @param string $collate Charset/collation suffix.
	 * @return string
	 */
	private static function files_sql( string $collate ): string {
		$table = self::files_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(1000) NOT NULL,
			path_hash char(64) NOT NULL,
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			mtime int(10) unsigned NOT NULL DEFAULT 0,
			md5 char(32) NOT NULL DEFAULT '',
			sha256 char(64) NOT NULL DEFAULT '',
			kind varchar(10) NOT NULL DEFAULT '',
			origin varchar(120) NOT NULL DEFAULT '',
			known_good tinyint(1) NOT NULL DEFAULT 0,
			path_signal tinyint(3) unsigned NOT NULL DEFAULT 0,
			scanned_bundle bigint(20) unsigned NOT NULL DEFAULT 0,
			seen_run bigint(20) unsigned NOT NULL DEFAULT 0,
			indexed_at int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY scan_queue (known_good,scanned_bundle,path_signal),
			KEY seen (seen_run)
		) {$collate};";
	}

	/**
	 * @param string $collate Charset/collation suffix.
	 * @return string
	 */
	private static function findings_sql( string $collate ): string {
		$table = self::findings_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(16) NOT NULL,
			locator varchar(1000) NOT NULL,
			locator_hash char(64) NOT NULL,
			file_id bigint(20) unsigned NOT NULL DEFAULT 0,
			signature_ids text NOT NULL,
			tier varchar(12) NOT NULL,
			category varchar(24) NOT NULL DEFAULT '',
			severity varchar(8) NOT NULL,
			excerpt text NOT NULL,
			line int(10) unsigned NOT NULL DEFAULT 0,
			reason text NOT NULL,
			meta longtext NOT NULL,
			first_seen int(10) unsigned NOT NULL,
			last_seen int(10) unsigned NOT NULL,
			state varchar(12) NOT NULL DEFAULT 'new',
			state_changed_at int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY locator_hash (locator_hash),
			KEY list (state,severity,last_seen),
			KEY file (file_id)
		) {$collate};";
	}

	/**
	 * @param string $collate Charset/collation suffix.
	 * @return string
	 */
	private static function runs_sql( string $collate ): string {
		$table = self::runs_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trigger_kind varchar(10) NOT NULL,
			scope varchar(10) NOT NULL,
			scope_path varchar(1000) NOT NULL DEFAULT '',
			started_at int(10) unsigned NOT NULL,
			finished_at int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(10) NOT NULL,
			bundle_version bigint(20) unsigned NOT NULL DEFAULT 0,
			stats longtext NOT NULL,
			error text NOT NULL,
			PRIMARY KEY  (id),
			KEY started (started_at)
		) {$collate};";
	}

	/**
	 * Memoized result of `exists()`, null until first probed.
	 *
	 * @var bool|null
	 */
	private static ?bool $exists_cache = null;

	/**
	 * Whether the file-index table exists (probed once per request).
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		if ( null === self::$exists_cache ) {
			self::$exists_cache = self::table_exists( self::files_table() );
		}

		return self::$exists_cache;
	}

	/**
	 * Forgets the memoized `exists()` result so the next call re-probes.
	 * `install()`/`drop()` call this themselves; unit tests that need
	 * `exists()` to reflect a fixture change within the same process call
	 * it directly (mirrors `Bundle\PackLoader::reset()`).
	 *
	 * @return void
	 */
	public static function reset_exists_cache(): void {
		self::$exists_cache = null;
	}

	/**
	 * Drop all three tables and forget the stored version (uninstall).
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( [ self::files_table(), self::findings_table(), self::runs_table() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from our own Schema::*_table(), no user input; deliberate DROP TABLE on uninstall only.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::VERSION_OPTION );

		self::reset_exists_cache();
	}
}
