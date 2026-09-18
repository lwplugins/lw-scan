<?php
/**
 * Tests for HelloPack\StatusCheck.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\HelloPack;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\HelloPack\StatusCheck;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class StatusCheckTest extends MonkeyTestCase {

	/** A fixed "now" so every staleness assertion is deterministic. */
	private const NOW = 1750000000;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'human_time_diff' )->justReturn( '11 minutes' );
	}

	/**
	 * @param int $alerts New alert-severity findings.
	 * @param int $review New review-severity findings.
	 * @return array<string, mixed> A `FindingsRepository::counts()`-shaped array.
	 */
	private function counts( int $alerts = 0, int $review = 0 ): array {
		return [
			'state' => [
				'new'          => [
					'alert'  => $alerts,
					'review' => $review,
				],
				'acknowledged' => [ 'alert' => 7 ],
				'ignored'      => [ 'review' => 3 ],
			],
			'type'  => [ 'file' => $alerts + $review ],
			'total' => $alerts + $review + 10,
		];
	}

	/**
	 * @param string $status     Run status.
	 * @param int    $finished_at Finish timestamp.
	 * @return array<string, mixed> A `RunsRepository::last()`-shaped row.
	 */
	private function run_row( string $status = 'done', int $finished_at = self::NOW - 600 ): array {
		return [
			'id'           => 42,
			'trigger_kind' => 'cron',
			'scope'        => 'changed',
			'status'       => $status,
			'started_at'   => $finished_at - 60,
			'finished_at'  => $finished_at,
		];
	}

	public function test_interval_seconds_maps_every_schedule(): void {
		$this->assertSame( 3600, StatusCheck::interval_seconds( 'hourly' ) );
		$this->assertSame( 86400, StatusCheck::interval_seconds( 'daily' ) );
		$this->assertSame( 604800, StatusCheck::interval_seconds( 'weekly' ) );
	}

	public function test_interval_seconds_returns_php_int_max_when_the_schedule_is_off(): void {
		$this->assertSame( PHP_INT_MAX, StatusCheck::interval_seconds( 'off' ) );
	}

	public function test_interval_seconds_falls_back_to_daily_for_an_unknown_schedule(): void {
		$this->assertSame( 86400, StatusCheck::interval_seconds( 'fortnightly' ) );
	}

	public function test_evaluate_reports_unknown_when_no_scan_has_ever_run(): void {
		$result = StatusCheck::evaluate( $this->counts(), null, null, 'daily', self::NOW );

		$this->assertSame( 'unknown', $result['level'] );
		$this->assertSame( 'No scan has run yet.', $result['summary'] );
	}

	public function test_evaluate_reports_crit_for_new_alerts_even_without_a_run_on_record(): void {
		$result = StatusCheck::evaluate( $this->counts( 2 ), null, null, 'daily', self::NOW );

		$this->assertSame( 'crit', $result['level'] );
		$this->assertSame( '2 new alerts.', $result['summary'] );
	}

	public function test_evaluate_ignores_review_findings_when_no_run_is_on_record(): void {
		$result = StatusCheck::evaluate( $this->counts( 0, 1 ), null, null, 'daily', self::NOW );

		$this->assertSame( 'unknown', $result['level'] );
		$this->assertSame( 'No scan has run yet.', $result['summary'] );
	}

	public function test_evaluate_reports_crit_for_new_alerts(): void {
		$result = StatusCheck::evaluate( $this->counts( 3 ), $this->run_row(), self::NOW - 600, 'daily', self::NOW );

		$this->assertSame( 'crit', $result['level'] );
		$this->assertSame( '3 new alerts.', $result['summary'] );
	}

	public function test_evaluate_uses_the_singular_summary_for_one_new_alert(): void {
		$result = StatusCheck::evaluate( $this->counts( 1 ), $this->run_row(), self::NOW - 600, 'daily', self::NOW );

		$this->assertSame( 'crit', $result['level'] );
		$this->assertSame( '1 new alert.', $result['summary'] );
	}

	public function test_evaluate_reports_ok_for_review_only_findings_when_the_last_run_succeeded_recently(): void {
		$result = StatusCheck::evaluate( $this->counts( 0, 2 ), $this->run_row(), self::NOW - 600, 'daily', self::NOW );

		$this->assertSame( 'ok', $result['level'] );
		$this->assertSame( 'Last scan clean, 11 minutes ago.', $result['summary'] );
	}

	public function test_evaluate_prefers_crit_over_warn_when_both_are_present(): void {
		$result = StatusCheck::evaluate( $this->counts( 1, 5 ), $this->run_row(), self::NOW - 600, 'daily', self::NOW );

		$this->assertSame( 'crit', $result['level'] );
	}

	public function test_evaluate_only_counts_new_findings_not_acknowledged_ones(): void {
		$result = StatusCheck::evaluate( $this->counts(), $this->run_row(), self::NOW - 600, 'daily', self::NOW );

		$this->assertSame( 'ok', $result['level'] );
		$this->assertSame( 0, $result['details']['alerts_new'] );
	}

	public function test_evaluate_reports_warn_when_the_last_run_failed(): void {
		$result = StatusCheck::evaluate( $this->counts(), $this->run_row( 'failed' ), self::NOW - 600, 'daily', self::NOW );

		$this->assertSame( 'warn', $result['level'] );
		$this->assertSame( 'The last scan failed.', $result['summary'] );
	}

	public function test_evaluate_reports_warn_when_the_last_success_is_older_than_two_intervals(): void {
		$stale = self::NOW - ( 2 * 86400 ) - 60;

		$result = StatusCheck::evaluate( $this->counts(), $this->run_row( 'done', $stale ), $stale, 'daily', self::NOW );

		$this->assertSame( 'warn', $result['level'] );
		$this->assertSame( 'No successful scan in the last two scheduled windows.', $result['summary'] );
	}

	public function test_evaluate_stays_ok_just_inside_two_intervals(): void {
		$fresh = self::NOW - ( 2 * 86400 ) + 60;

		$result = StatusCheck::evaluate( $this->counts(), $this->run_row( 'done', $fresh ), $fresh, 'daily', self::NOW );

		$this->assertSame( 'ok', $result['level'] );
	}

	public function test_evaluate_never_reports_stale_when_the_schedule_is_off(): void {
		$ancient = self::NOW - ( 400 * 86400 );

		$result = StatusCheck::evaluate( $this->counts(), $this->run_row( 'done', $ancient ), $ancient, 'off', self::NOW );

		$this->assertSame( 'ok', $result['level'] );
	}

	public function test_evaluate_reports_warn_when_a_run_exists_but_none_has_ever_succeeded(): void {
		$result = StatusCheck::evaluate( $this->counts(), $this->run_row( 'stopped' ), null, 'daily', self::NOW );

		$this->assertSame( 'warn', $result['level'] );
		$this->assertSame( 'No scan has completed successfully yet.', $result['summary'] );
	}

	public function test_evaluate_reports_ok_for_a_clean_recent_scan(): void {
		$result = StatusCheck::evaluate( $this->counts(), $this->run_row(), self::NOW - 600, 'daily', self::NOW );

		$this->assertSame( 'ok', $result['level'] );
		$this->assertSame( 'Last scan clean, 11 minutes ago.', $result['summary'] );
	}

	public function test_evaluate_details_carry_the_documented_keys(): void {
		$result = StatusCheck::evaluate( $this->counts( 2, 4 ), $this->run_row(), self::NOW - 600, 'daily', self::NOW );

		$this->assertSame(
			[ 'alerts_new', 'last_run_at', 'last_status' ],
			array_keys( $result['details'] )
		);
		$this->assertSame( 2, $result['details']['alerts_new'] );
		$this->assertSame( self::NOW - 600, $result['details']['last_run_at'] );
		$this->assertSame( 'done', $result['details']['last_status'] );
	}

	public function test_evaluate_details_no_longer_carry_review_new(): void {
		$result = StatusCheck::evaluate( $this->counts( 0, 4 ), $this->run_row(), self::NOW - 600, 'daily', self::NOW );

		$this->assertArrayNotHasKey( 'review_new', $result['details'] );
	}

	public function test_evaluate_details_never_leak_a_path(): void {
		$run               = $this->run_row();
		$run['scope_path'] = 'wp-content/plugins/secret';

		$result = StatusCheck::evaluate( $this->counts( 1 ), $run, self::NOW - 600, 'daily', self::NOW );

		foreach ( $result['details'] as $value ) {
			$this->assertStringNotContainsString( '/', (string) $value );
		}
	}

	public function test_evaluate_falls_back_to_started_at_when_the_run_has_not_finished(): void {
		$run                = $this->run_row( 'running' );
		$run['finished_at'] = 0;

		$result = StatusCheck::evaluate( $this->counts(), $run, self::NOW - 600, 'daily', self::NOW );

		$this->assertSame( $run['started_at'], $result['details']['last_run_at'] );
		$this->assertSame( 'running', $result['details']['last_status'] );
	}
}
