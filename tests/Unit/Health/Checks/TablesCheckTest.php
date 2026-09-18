<?php
/**
 * Tests for Health\Checks\TablesCheck.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Health\Checks;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Health\Checks\TablesCheck;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

require_once dirname( __DIR__, 2 ) . '/Db/FakeWpdb.php';

final class TablesCheckTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();

		// Schema::exists() memoizes its result for the life of the process
		// (TablesCheck itself asks Schema::missing_tables(), which never
		// caches); forget it so no earlier test leaks into this one.
		Schema::reset_exists_cache();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Schema::reset_exists_cache();
		parent::tearDown();
	}

	public function test_status_critical_and_blocking_when_tables_are_missing(): void {
		$wpdb            = new \wpdb();
		$wpdb->var_queue = [ null, null, null ]; // SHOW TABLES LIKE ... found nothing, three times.
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new TablesCheck() )->run();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertTrue( $result['blocking'] );
		$this->assertArrayNotHasKey( 'details', $result );
		$this->assertStringContainsString( 'wp_lw_scan_files, wp_lw_scan_findings, wp_lw_scan_runs', $result['message'] );
	}

	public function test_status_critical_and_names_only_the_missing_table(): void {
		$wpdb = new \wpdb();
		// Probed in files/findings/runs order: only the files table is gone —
		// exactly the croco2 case, where the other two were created fine.
		$wpdb->var_queue = [ null, 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new TablesCheck() )->run();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertTrue( $result['blocking'] );
		$this->assertStringContainsString( '(wp_lw_scan_files)', $result['message'] );
		$this->assertStringNotContainsString( 'wp_lw_scan_runs', $result['message'] );
	}

	public function test_status_ok_and_reports_row_counts_when_every_table_is_innodb(): void {
		$wpdb            = new \wpdb();
		// One SHOW TABLES per table: Schema::missing_tables() probes all three.
		$wpdb->var_queue = [ 'wp_lw_scan_files', 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];
		$wpdb->row_queue = [
			[
				'Engine' => 'InnoDB',
				'Rows'   => '4455',
			],
			[
				'Engine' => 'InnoDB',
				'Rows'   => '6',
			],
			[
				'Engine' => 'InnoDB',
				'Rows'   => '41',
			],
		];
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new TablesCheck() )->run();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertFalse( $result['blocking'] );
		$this->assertSame(
			'wp_lw_scan_files (4,455 rows) · wp_lw_scan_findings (6 rows) · wp_lw_scan_runs (41 rows) · all InnoDB',
			$result['message']
		);
		$this->assertSame(
			[
				'tables' => [
					'files'    => [
						'rows'   => 4455,
						'engine' => 'InnoDB',
					],
					'findings' => [
						'rows'   => 6,
						'engine' => 'InnoDB',
					],
					'runs'     => [
						'rows'   => 41,
						'engine' => 'InnoDB',
					],
				],
			],
			$result['details']
		);
	}

	public function test_row_counts_carry_no_html_entities_into_plain_text_output(): void {
		// Under a Hungarian locale WordPress' own number_format_i18n()
		// separates thousands with an HTML-encoded non-breaking space, and
		// `wp lw-scan status` printed the entity verbatim: "44&nbsp;185
		// rows". Health messages are plain text, read by the CLI as much as
		// by the admin, so they are formatted plainly.
		Functions\when( 'number_format_i18n' )->justReturn( '44&nbsp;185' );

		$wpdb            = new \wpdb();
		$wpdb->var_queue = [ 'wp_lw_scan_files', 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];
		$wpdb->row_queue = [
			[
				'Engine' => 'InnoDB',
				'Rows'   => '44185',
			],
			[
				'Engine' => 'InnoDB',
				'Rows'   => '10',
			],
			[
				'Engine' => 'InnoDB',
				'Rows'   => '18',
			],
		];
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new TablesCheck() )->run();

		$this->assertStringNotContainsString( '&', $result['message'] );
		$this->assertStringContainsString( '44,185 rows', $result['message'] );
	}

	public function test_status_warning_when_a_table_is_not_innodb(): void {
		$wpdb            = new \wpdb();
		$wpdb->var_queue = [ 'wp_lw_scan_files', 'wp_lw_scan_findings', 'wp_lw_scan_runs' ];
		$wpdb->row_queue = [
			[
				'Engine' => 'InnoDB',
				'Rows'   => '10',
			],
			[
				'Engine' => 'MyISAM',
				'Rows'   => '2',
			],
			[
				'Engine' => 'InnoDB',
				'Rows'   => '1',
			],
		];
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new TablesCheck() )->run();

		$this->assertSame( 'warning', $result['status'] );
		$this->assertFalse( $result['blocking'] );
		$this->assertStringContainsString( 'not InnoDB: wp_lw_scan_findings (MyISAM)', $result['message'] );
		$this->assertSame( 'MyISAM', $result['details']['tables']['findings']['engine'] );
	}
}
