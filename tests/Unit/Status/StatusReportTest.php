<?php
/**
 * Tests for Status\StatusReport.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Status;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Status\EndpointSettings;
use LightweightPlugins\Scan\Status\StatusReport;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FakeStore;
use RuntimeException;

require_once dirname( __DIR__ ) . '/Db/FakeWpdb.php';

final class StatusReportTest extends MonkeyTestCase {

	use FakeStore;

	/** @var int How many times the injected measurement ran. */
	private int $measured = 0;

	/** @var array{level:string, summary:string, details:array<string, mixed>} What the injected measurement answers. */
	private array $verdict = [
		'level'   => 'crit',
		'summary' => '2 new alerts.',
		'details' => [
			'alerts_new'     => 2,
			'last_run_at'    => 1750000000,
			'last_status'    => 'done',
			'bundle_version' => 20260916045,
		],
	];

	protected function setUp(): void {
		parent::setUp();
		$this->stub_store();
		$this->measured = 0;

		Functions\stubTranslationFunctions();
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function report(): StatusReport {
		return new StatusReport(
			new EndpointSettings(),
			function (): array {
				++$this->measured;

				return $this->verdict;
			}
		);
	}

	public function test_the_wire_format_has_the_documented_shape(): void {
		$wire = $this->report()->wire();

		$this->assertSame( [ 'overall', 'checked_at', 'cached', 'site', 'checks' ], array_keys( $wire ) );
		$this->assertSame(
			[
				'url'     => 'https://example.com',
				'wp'      => '6.8',
				'php'     => PHP_VERSION,
				'lw_scan' => LW_SCAN_VERSION,
			],
			$wire['site']
		);
		$this->assertSame( [ 'lw_scan' ], array_keys( $wire['checks'] ) );
		$this->assertSame(
			[ 'status', 'summary', 'details', 'checked_at', 'duration_ms' ],
			array_keys( $wire['checks']['lw_scan'] )
		);
		$this->assertFalse( $wire['cached'] );
		$this->assertIsInt( $wire['checks']['lw_scan']['duration_ms'] );
	}

	public function test_checked_at_is_iso_8601_utc(): void {
		$wire = $this->report()->wire();

		$pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/';

		$this->assertMatchesRegularExpression( $pattern, $wire['checked_at'] );
		$this->assertSame( $wire['checked_at'], $wire['checks']['lw_scan']['checked_at'] );
	}

	/**
	 * @dataProvider provide_levels
	 *
	 * @param string $level A level StatusCheck::measure() can return.
	 */
	public function test_overall_and_the_check_status_are_the_measured_level( string $level ): void {
		$this->verdict['level'] = $level;

		$wire = $this->report()->wire();

		$this->assertSame( $level, $wire['overall'] );
		$this->assertSame( $level, $wire['checks']['lw_scan']['status'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_levels(): array {
		return [
			'ok'      => [ 'ok' ],
			'warn'    => [ 'warn' ],
			'crit'    => [ 'crit' ],
			'unknown' => [ 'unknown' ],
		];
	}

	public function test_summary_and_details_are_exactly_what_the_status_check_measured(): void {
		$check = $this->report()->wire()['checks']['lw_scan'];

		$this->assertSame( '2 new alerts.', $check['summary'] );
		$this->assertSame( $this->verdict['details'], $check['details'] );
	}

	public function test_an_unrecognised_level_is_published_as_unknown(): void {
		$this->verdict['level'] = 'catastrophic';

		$wire = $this->report()->wire();

		$this->assertSame( 'unknown', $wire['overall'] );
		$this->assertSame( 'unknown', $wire['checks']['lw_scan']['status'] );
	}

	public function test_a_second_request_within_the_ttl_is_served_from_the_cache(): void {
		$first  = $this->report()->wire();
		$second = $this->report()->wire();

		$this->assertSame( 1, $this->measured );
		$this->assertFalse( $first['cached'] );
		$this->assertTrue( $second['cached'] );
		$this->assertSame( $first['checked_at'], $second['checked_at'] );
		$this->assertSame( $first['checks'], $second['checks'] );
	}

	public function test_the_result_is_stored_for_the_configured_ttl(): void {
		( new EndpointSettings() )->set_cache_ttl( 900 );

		$this->report()->wire();

		$this->assertSame( 900, $this->transients[ StatusReport::TRANSIENT ]['ttl'] );
	}

	public function test_fresh_recomputes_and_refreshes_the_cache(): void {
		$this->report()->wire();

		$this->verdict['level']   = 'ok';
		$this->verdict['summary'] = 'Last scan clean, 11 minutes ago.';

		$fresh = $this->report()->wire( true );
		$after = $this->report()->wire();

		$this->assertSame( 2, $this->measured );
		$this->assertFalse( $fresh['cached'] );
		$this->assertSame( 'ok', $fresh['overall'] );
		$this->assertTrue( $after['cached'] );
		$this->assertSame( 'ok', $after['overall'] );
	}

	public function test_a_stored_result_older_than_the_ttl_is_recomputed(): void {
		$this->transients[ StatusReport::TRANSIENT ] = [
			'value' => [
				'checked_at' => time() - 301,
				'result'     => [
					'status'      => 'ok',
					'summary'     => 'stale',
					'details'     => [],
					'duration_ms' => 1,
				],
			],
			'ttl'   => 3600,
		];

		$wire = $this->report()->wire();

		$this->assertSame( 1, $this->measured );
		$this->assertFalse( $wire['cached'] );
		$this->assertSame( '2 new alerts.', $wire['checks']['lw_scan']['summary'] );
	}

	/**
	 * @dataProvider provide_corrupt_cache_entries
	 *
	 * @param mixed $junk What the transient holds.
	 */
	public function test_a_corrupt_cache_entry_is_recomputed( $junk ): void {
		$this->transients[ StatusReport::TRANSIENT ] = [
			'value' => $junk,
			'ttl'   => 300,
		];

		$wire = $this->report()->wire();

		$this->assertSame( 1, $this->measured );
		$this->assertFalse( $wire['cached'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_corrupt_cache_entries(): array {
		return [
			'a string'           => [ 'garbage' ],
			'no result'          => [ [ 'checked_at' => time() ] ],
			'no timestamp'       => [ [ 'result' => [ 'status' => 'ok' ] ] ],
			'result not an array' => [
				[
					'checked_at' => time(),
					'result'     => 'ok',
				],
			],
		];
	}

	public function test_clear_cache_drops_the_stored_result(): void {
		$this->report()->wire();

		StatusReport::clear_cache();

		$this->assertArrayNotHasKey( StatusReport::TRANSIENT, $this->transients );
	}

	public function test_a_measurement_that_throws_is_published_as_unknown_without_its_message(): void {
		$report = new StatusReport(
			new EndpointSettings(),
			static function (): array {
				throw new RuntimeException( '/var/www/html/wp-content/secret.php exploded' );
			}
		);

		$wire  = $report->wire();
		$check = $wire['checks']['lw_scan'];

		$this->assertSame( 'unknown', $wire['overall'] );
		$this->assertSame( 'unknown', $check['status'] );
		$this->assertSame( [], $check['details'] );
		$this->assertStringContainsString( 'RuntimeException', $check['summary'] );
		$this->assertStringNotContainsString( '/', $check['summary'] );
	}

	public function test_the_real_status_check_publishes_its_own_details_and_no_path(): void {
		$now = time();

		$wpdb                = new \wpdb();
		$wpdb->results_queue = [
			[
				[
					'state'    => 'new',
					'severity' => 'alert',
					'total'    => 1,
				],
				[
					'state'    => 'new',
					'severity' => 'review',
					'total'    => 4,
				],
			],
			[
				[
					'type'  => 'file',
					'total' => 5,
				],
			],
			[
				[
					'id'          => 7,
					'status'      => 'done',
					'scope'       => 'path',
					'scope_path'  => 'wp-content/plugins/secret',
					'error'       => '/var/www/html/wp-content/plugins/secret/x.php',
					'started_at'  => $now - 700,
					'finished_at' => $now - 600,
					'stats'       => '{}',
				],
			],
		];
		$wpdb->var_queue     = [ 5 ];
		$GLOBALS['wpdb']     = $wpdb;

		$this->options['lw_scan_state'] = [
			'bundle_version'  => 20260916045,
			'last_success_at' => $now - 600,
		];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'human_time_diff' )->justReturn( '10 mins' );

		$check = ( new StatusReport( new EndpointSettings() ) )->wire()['checks']['lw_scan'];

		$this->assertSame( 'crit', $check['status'] );
		$this->assertSame( '1 new alert.', $check['summary'] );
		$this->assertSame(
			[
				'alerts_new'     => 1,
				'last_run_at'    => $now - 600,
				'last_status'    => 'done',
				'bundle_version' => 20260916045,
			],
			$check['details']
		);
		$this->assertArrayNotHasKey( 'review_new', $check['details'] );
		// Slashes unescaped, so a path anywhere in the check would show as one.
		$this->assertStringNotContainsString( '/', (string) json_encode( $check, JSON_UNESCAPED_SLASHES ) );
	}
}
