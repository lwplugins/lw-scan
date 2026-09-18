<?php
/**
 * Tests for Schema table name helpers.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Db;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class SchemaTest extends MonkeyTestCase {

	/**
	 * Words that may not be used as a bare (unquoted) identifier. dbDelta()
	 * strips backticks from field names, so a column named after one of
	 * these makes its CREATE TABLE a syntax error on MySQL/MariaDB — and
	 * dbDelta() swallows that error, leaving the table silently missing.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED_WORDS = [
		'signal', 'key', 'keys', 'order', 'group', 'index', 'primary', 'references', 'condition',
		'status', 'trigger', 'usage', 'select', 'table', 'column', 'default', 'values', 'rank',
		'system', 'role', 'window', 'of', 'over', 'cube', 'function', 'lead', 'lag', 'rows',
		'groups', 'json_table', 'empty', 'first_value', 'nth_value', 'ntile', 'percent_rank',
		'row_number', 'dense_rank', 'cume_dist', 'generated', 'stored', 'virtual',
		'optimizer_costs', 'parser', 'partition', 'ignore', 'no_write_to_binlog', 'read', 'write',
		'load', 'lines', 'both', 'leading', 'trailing', 'kill', 'force', 'iterate', 'leave',
		'loop', 'repeat', 'undo', 'until', 'while', 'elseif', 'get', 'diagnostics', 'resignal',
		'sqlexception', 'sqlstate', 'sqlwarning', 'master_bind', 'master_ssl_verify_server_cert',
		'slow', 'io_after_gtids', 'io_before_gtids', 'dual', 'purge', 'replace', 'rlike', 'regexp',
		'spatial', 'ssl', 'starting', 'straight_join', 'terminated', 'unlock', 'unsigned',
		'utc_date', 'utc_time', 'utc_timestamp', 'varcharacter', 'varying', 'year_month',
		'zerofill', 'accessible', 'add', 'all', 'alter', 'analyze', 'and', 'as', 'asc',
		'asensitive', 'before', 'between', 'bigint', 'binary', 'blob', 'by', 'call', 'cascade',
		'case', 'change', 'char', 'character', 'check', 'collate', 'constraint', 'continue',
		'convert', 'create', 'cross', 'current_date', 'current_time', 'current_timestamp',
		'current_user', 'cursor', 'database', 'databases', 'day_hour', 'day_microsecond',
		'day_minute', 'day_second', 'dec', 'decimal', 'declare', 'delayed', 'delete', 'desc',
		'describe', 'deterministic', 'distinct', 'distinctrow', 'div', 'double', 'drop', 'each',
		'else', 'enclosed', 'escaped', 'exists', 'exit', 'explain', 'false', 'fetch', 'float',
		'float4', 'float8', 'for', 'from', 'fulltext', 'grant', 'having', 'high_priority',
		'hour_microsecond', 'hour_minute', 'hour_second', 'if', 'in', 'infile', 'inner', 'inout',
		'insensitive', 'insert', 'int', 'int1', 'int2', 'int3', 'int4', 'int8', 'integer',
		'interval', 'into', 'is', 'join', 'left', 'like', 'limit', 'linear', 'localtime',
		'localtimestamp', 'lock', 'long', 'longblob', 'longtext', 'low_priority', 'match',
		'maxvalue', 'mediumblob', 'mediumint', 'mediumtext', 'middleint', 'minute_microsecond',
		'minute_second', 'mod', 'modifies', 'natural', 'not', 'null', 'numeric', 'on', 'optimize',
		'option', 'optionally', 'or', 'out', 'outer', 'outfile', 'precision', 'procedure', 'range',
		'reads', 'real', 'release', 'rename', 'require', 'restrict', 'return', 'revoke', 'right',
		'schema', 'schemas', 'second_microsecond', 'sensitive', 'separator', 'set', 'show',
		'smallint', 'specific', 'sql', 'sql_big_result', 'sql_calc_found_rows', 'sql_small_result',
		'then', 'tinyblob', 'tinyint', 'tinytext', 'to', 'true', 'union', 'unique', 'update',
		'use', 'using', 'varbinary', 'varchar', 'when', 'where', 'with', 'xor',
	];

	/**
	 * Words carried in RESERVED_WORDS that the MySQL 8.0 manual's keyword
	 * list (dev.mysql.com/doc/refman/8.0/en/keywords.html, checked
	 * 2026-09-17) classifies as NON-reserved, so they are legal bare
	 * identifiers. `status` is the one that matters here: the runs table
	 * has shipped with a `status` column and MariaDB 10.11 creates it
	 * without complaint (croco2 deployment evidence, task 27) — renaming it
	 * would be churn with no portability gain.
	 *
	 * @var array<int, string>
	 */
	private const NON_RESERVED = [ 'status', 'role', 'parser', 'slow', 'diagnostics' ];

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_files_table_uses_wpdb_prefix(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
		};

		$this->assertSame( 'wp_lw_scan_files', Schema::files_table() );
	}

	public function test_findings_table_uses_wpdb_prefix(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
		};

		$this->assertSame( 'wp_lw_scan_findings', Schema::findings_table() );
	}

	public function test_runs_table_uses_wpdb_prefix(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
		};

		$this->assertSame( 'wp_lw_scan_runs', Schema::runs_table() );
	}

	public function test_no_create_statement_uses_a_reserved_word_as_a_column_name(): void {
		$this->stub_wpdb();

		$reserved = array_diff( self::RESERVED_WORDS, self::NON_RESERVED );

		foreach ( Schema::create_statements( '' ) as $key => $sql ) {
			$columns = self::columns_of( $sql );

			$this->assertNotEmpty( $columns, "No columns parsed out of the {$key} table statement." );

			foreach ( $columns as $column ) {
				$this->assertNotContains(
					$column,
					$reserved,
					"Column `{$column}` in the {$key} table is a reserved word: dbDelta() drops backticks, so its CREATE TABLE is a syntax error."
				);
			}
		}
	}

	public function test_no_text_or_blob_column_declares_a_default(): void {
		$this->stub_wpdb();

		foreach ( Schema::create_statements( '' ) as $key => $sql ) {
			foreach ( self::column_lines( $sql ) as $column => $line ) {
				if ( 1 !== preg_match( '/^[a-z0-9_]+\s+(tiny|medium|long)?(text|blob)\b/i', $line ) ) {
					continue;
				}

				$this->assertStringNotContainsStringIgnoringCase(
					'DEFAULT',
					$line,
					"Column `{$column}` in the {$key} table is a TEXT/BLOB column with a DEFAULT, which MySQL 8 rejects."
				);
			}
		}
	}

	public function test_create_statements_cover_the_three_tables_and_their_columns(): void {
		$this->stub_wpdb();

		$statements = Schema::create_statements( 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertSame( [ 'files', 'findings', 'runs' ], array_keys( $statements ) );
		$this->assertStringContainsString( 'CREATE TABLE wp_lw_scan_files (', $statements['files'] );
		$this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $statements['files'] );

		// Guards the parser the two assertions above rely on: if columns_of()
		// ever stopped seeing real columns, those tests would pass vacuously.
		$this->assertSame(
			[ 'id', 'path', 'path_hash', 'size', 'mtime', 'md5', 'sha256', 'kind', 'origin', 'known_good', 'path_signal', 'scanned_bundle', 'seen_run', 'indexed_at' ],
			self::columns_of( $statements['files'] )
		);
	}

	public function test_install_records_the_version_once_all_three_tables_exist(): void {
		$wpdb = $this->stub_wpdb();
		// SHOW TABLES LIKE, one per table, in files/findings/runs order.
		$wpdb->var_queue = [ 'wp_lw_scan_files', 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];

		$written = $this->stub_install_functions();

		$this->assertTrue( Schema::install() );
		$this->assertSame( [ 'lw_scan_db_version' => Schema::VERSION ], $written->values );
		// The health report caches "tables missing" for 10 minutes; without
		// this the user is told the schema is broken long after it is fixed.
		$this->assertSame( [ Schema::RETRY_TRANSIENT, 'lw_scan_health' ], $written->deleted );
		$this->assertSame( [], $written->set );
	}

	public function test_install_leaves_the_version_unwritten_when_a_table_is_missing(): void {
		$wpdb = $this->stub_wpdb();
		// dbDelta swallowed a failed CREATE TABLE: the files table is absent.
		$wpdb->var_queue = [ null, 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];

		$written = $this->stub_install_functions();

		$this->assertFalse( Schema::install() );
		$this->assertSame( [], $written->values, 'A broken install must stay retryable: maybe_install() short-circuits on the version option.' );
		$this->assertSame( [ Schema::RETRY_TRANSIENT ], $written->set, 'A failed install must leave a back-off flag, or every request re-runs the whole DDL.' );
	}

	/**
	 * Installs the FakeWpdb stub as $GLOBALS['wpdb'] and returns it.
	 */
	private function stub_wpdb(): \wpdb {
		require_once __DIR__ . '/FakeWpdb.php';

		$wpdb            = new \wpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Schema::reset_exists_cache();

		return $wpdb;
	}

	/**
	 * Stubs dbDelta()/update_option() and returns the recorder holding every
	 * option install() wrote.
	 */
	private function stub_install_functions(): object {
		$written = new class() {
			/** @var array<string, mixed> Options written, name => value. */
			public array $values = [];

			/** @var array<int, string> CREATE TABLE statements handed to dbDelta(). */
			public array $ddl = [];

			/** @var array<int, string> Transients set. */
			public array $set = [];

			/** @var array<int, string> Transients deleted. */
			public array $deleted = [];
		};

		Functions\when( 'dbDelta' )->alias(
			static function ( $sql ) use ( $written ) {
				$written->ddl[] = (string) $sql;

				return [];
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( $written ) {
				$written->values[ (string) $name ] = $value;

				return true;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $name ) use ( $written ) {
				$written->set[] = (string) $name;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ) use ( $written ) {
				$written->deleted[] = (string) $name;

				return true;
			}
		);

		return $written;
	}

	/**
	 * Column names declared by a CREATE TABLE statement, in order.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return array<int, string>
	 */
	private static function columns_of( string $sql ): array {
		return array_keys( self::column_lines( $sql ) );
	}

	/**
	 * Column name => the trimmed line declaring it.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return array<string, string>
	 */
	private static function column_lines( string $sql ): array {
		$lines = [];

		foreach ( explode( "\n", $sql ) as $line ) {
			$line = trim( rtrim( trim( $line ), ',' ) );

			if ( 1 === preg_match( '/^(CREATE\s+TABLE|\)|PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|FULLTEXT\s+KEY)\b/i', $line ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^([a-z0-9_]+)\s+\S/i', $line, $matches ) ) {
				$lines[ strtolower( $matches[1] ) ] = $line;
			}
		}

		return $lines;
	}

	public function test_files_repository_only_writes_columns_the_files_table_declares(): void {
		$this->stub_wpdb();

		$columns = self::columns_of( Schema::create_statements( '' )['files'] );

		$this->assertSame(
			[],
			array_values( array_diff( FilesRepository::HASH_FIELDS, $columns ) ),
			'update_hashed() would write a column the files table does not declare.'
		);
		$this->assertSame(
			[],
			array_values( array_diff( FilesRepository::STAT_COLUMNS, $columns ) ),
			'The stat-batch INSERT names a column the files table does not declare.'
		);
	}

	public function test_maybe_install_skips_a_failed_install_on_an_ordinary_request(): void {
		$wpdb    = $this->stub_wpdb();
		$written = $this->stub_install_functions();

		$this->stub_request_context( false );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( 1 );

		Schema::maybe_install();

		// Nothing at all: no upgrade.php, no dbDelta, not even an existence probe.
		$this->assertSame( [], $written->ddl );
		$this->assertSame( [], $wpdb->queries );
		$this->assertSame( [], $written->values );
	}

	public function test_maybe_install_retries_in_the_admin_while_the_backoff_is_set(): void {
		$wpdb            = $this->stub_wpdb();
		$wpdb->var_queue = [ 'wp_lw_scan_files', 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];
		$written         = $this->stub_install_functions();

		$this->stub_request_context( true );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( 1 );

		Schema::maybe_install();

		$this->assertCount( 3, $written->ddl );
		$this->assertSame( [ 'lw_scan_db_version' => Schema::VERSION ], $written->values );
	}

	/**
	 * Stubs the three "can this request retry" probes.
	 *
	 * @param bool $is_admin Whether the request is an admin one.
	 */
	private function stub_request_context( bool $is_admin ): void {
		Functions\when( 'is_admin' )->justReturn( $is_admin );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
	}
}
