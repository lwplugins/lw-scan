<?php
/**
 * Tests for CLI\Formatter.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\CLI;

use LightweightPlugins\Scan\CLI\Formatter;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * The formatter is the one part of the CLI surface with no WP-CLI (and no
 * WordPress) in it: the commands hand it raw repository rows and it hands
 * back `WP_CLI\Utils\format_items()`-shaped rows. So these tests feed it
 * the exact shapes `FindingsRepository::list()`/`::new_since()`,
 * `RunsRepository::get()`, `Runner::progress()` and
 * `FindingsRepository::counts()` produce, and assert on the printed cells.
 */
final class FormatterTest extends MonkeyTestCase {

	/**
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function finding( array $overrides = [] ): array {
		return array_merge(
			[
				'id'            => 12,
				'type'          => 'file',
				'locator'       => 'wp-content/plugins/evil/x.php',
				'severity'      => 'alert',
				'tier'          => 'infected',
				'signature_ids' => [ 'LW0001', 'LW0002' ],
				'last_seen'     => 1700000000,
				'state'         => 'new',
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function run_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'             => 7,
				'trigger_kind'   => 'cli',
				'scope'          => 'changed',
				'scope_path'     => '',
				'started_at'     => 1700000000,
				'finished_at'    => 1700000100,
				'status'         => 'done',
				'bundle_version' => 20260916045,
				'error'          => '',
				'stats'          => [
					'files'    => [
						'indexed' => 3120,
						'scanned' => 120,
					],
					'db'       => [
						'options' => 10,
						'posts'   => 5,
					],
					'vuln'     => [ 'software' => 28 ],
					'findings' => [
						'new'        => 2,
						'alerts_new' => 1,
						'review_new' => 1,
					],
				],
			],
			$overrides
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function counts(): array {
		return [
			'state' => [
				'new'          => [
					'alert'  => 1,
					'review' => 4,
				],
				'acknowledged' => [ 'review' => 2 ],
				'ignored'      => [],
			],
			'type'  => [ 'file' => 7 ],
			'total' => 7,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function progress(): array {
		return [
			'status'       => 'running',
			'phase'        => 'files',
			'done'         => 120,
			'total'        => 3120,
			'findings_new' => 2,
			'elapsed'      => 100,
			'run_id'       => 7,
			'last_tick_at' => 1700000000,
		];
	}

	/**
	 * @param array<int, array<string, string>> $rows metric/value rows.
	 * @return array<string, string>
	 */
	private function by_metric( array $rows ): array {
		return array_column( $rows, 'value', 'metric' );
	}

	public function test_findings_rows_fill_every_declared_column(): void {
		$rows = Formatter::findings_rows( [ $this->finding() ] );

		$this->assertCount( 1, $rows );
		$this->assertSame( Formatter::FINDINGS_COLUMNS, array_keys( $rows[0] ) );
		$this->assertSame( 12, $rows[0]['id'] );
		$this->assertSame( 'alert', $rows[0]['severity'] );
		$this->assertSame( 'file', $rows[0]['type'] );
		$this->assertSame( 'wp-content/plugins/evil/x.php', $rows[0]['where'] );
		$this->assertSame( 'LW0001, LW0002', $rows[0]['detected_by'] );
		$this->assertSame( '2023-11-14 22:13:20', $rows[0]['last_seen'] );
		$this->assertSame( 'new', $rows[0]['state'] );
	}

	public function test_findings_rows_decode_a_raw_signature_ids_column(): void {
		$rows = Formatter::findings_rows( [ $this->finding( [ 'signature_ids' => '["LW0003"]' ] ) ] );

		$this->assertSame( 'LW0003', $rows[0]['detected_by'] );
	}

	public function test_findings_rows_cap_the_signature_list(): void {
		$rows = Formatter::findings_rows( [ $this->finding( [ 'signature_ids' => [ 'a', 'b', 'c', 'd', 'e' ] ] ) ] );

		$this->assertSame( 'a, b, c (+2)', $rows[0]['detected_by'] );
	}

	public function test_findings_rows_fall_back_to_the_tier_when_nothing_matched_by_id(): void {
		$rows = Formatter::findings_rows(
			[
				$this->finding(
					[
						'signature_ids' => [],
						'tier'          => 'integrity',
					]
				),
			]
		);

		$this->assertSame( 'integrity', $rows[0]['detected_by'] );
	}

	public function test_findings_rows_print_a_dash_for_a_missing_timestamp(): void {
		$rows = Formatter::findings_rows( [ $this->finding( [ 'last_seen' => 0 ] ) ] );

		$this->assertSame( '-', $rows[0]['last_seen'] );
	}

	public function test_run_summary_reports_the_run_facts_and_its_stats(): void {
		$values = $this->by_metric( Formatter::run_summary( $this->run_row() ) );

		$this->assertSame( '7', $values['run'] );
		$this->assertSame( 'done', $values['status'] );
		$this->assertSame( 'changed', $values['scope'] );
		$this->assertSame( 'cli', $values['trigger'] );
		$this->assertSame( '2023-11-14 22:13:20', $values['started'] );
		$this->assertSame( '2023-11-14 22:15:00', $values['finished'] );
		$this->assertSame( '1 m 40 s', $values['duration'] );
		$this->assertSame( '20260916045', $values['bundle'] );
		$this->assertSame( '3120', $values['files indexed'] );
		$this->assertSame( '120', $values['files scanned'] );
		$this->assertSame( '15', $values['db rows'] );
		$this->assertSame( '28', $values['packages checked'] );
		$this->assertSame( '2', $values['new findings'] );
		$this->assertSame( '1', $values['new alerts'] );
		$this->assertSame( '1', $values['new to review'] );
	}

	public function test_run_summary_names_the_scanned_directory_for_a_path_run(): void {
		$values = $this->by_metric(
			Formatter::run_summary(
				$this->run_row(
					[
						'scope'      => 'path',
						'scope_path' => 'wp-content/uploads',
					]
				)
			)
		);

		$this->assertSame( 'path (wp-content/uploads)', $values['scope'] );
	}

	public function test_run_summary_hides_the_error_row_unless_the_run_failed(): void {
		$clean = $this->by_metric( Formatter::run_summary( $this->run_row() ) );

		$this->assertArrayNotHasKey( 'error', $clean );

		$failed = $this->by_metric(
			Formatter::run_summary(
				$this->run_row(
					[
						'status' => 'failed',
						'error'  => 'PCRE backtrack limit',
					]
				)
			)
		);

		$this->assertSame( 'PCRE backtrack limit', $failed['error'] );
	}

	public function test_run_summary_survives_a_run_row_that_never_got_stats(): void {
		$values = $this->by_metric( Formatter::run_summary( [] ) );

		$this->assertSame( '0', $values['run'] );
		$this->assertSame( '-', $values['started'] );
		$this->assertSame( '0', $values['files scanned'] );
	}

	public function test_status_reports_the_live_run_then_the_findings_counts(): void {
		$values = $this->by_metric( Formatter::status( $this->progress(), $this->run_row(), $this->counts() ) );

		$this->assertSame( 'running', $values['status'] );
		$this->assertSame( 'files', $values['phase'] );
		$this->assertSame( '120/3120', $values['files'] );
		$this->assertSame( '2', $values['new this run'] );
		$this->assertSame( '1 m 40 s', $values['elapsed'] );
		$this->assertSame( '#7 done (2023-11-14 22:15:00)', $values['last run'] );
		$this->assertSame( '1', $values['new alerts'] );
		$this->assertSame( '4', $values['new to review'] );
		$this->assertSame( '2', $values['acknowledged'] );
		$this->assertSame( '0', $values['ignored'] );
		$this->assertSame( '7', $values['findings total'] );
	}

	public function test_status_says_never_when_no_run_has_ever_finished(): void {
		$idle = [
			'status'       => 'idle',
			'phase'        => '',
			'done'         => 0,
			'total'        => 0,
			'findings_new' => 0,
			'elapsed'      => 0,
			'run_id'       => 0,
			'last_tick_at' => 0,
		];

		$values = $this->by_metric( Formatter::status( $idle, [], $this->counts() ) );

		$this->assertSame( 'idle', $values['status'] );
		$this->assertSame( 'never', $values['last run'] );
		$this->assertSame( '-', $values['phase'] );
	}

	public function test_summary_rows_are_shaped_for_format_items(): void {
		foreach ( Formatter::status( $this->progress(), $this->run_row(), $this->counts() ) as $row ) {
			$this->assertSame( Formatter::SUMMARY_COLUMNS, array_keys( $row ) );
			$this->assertIsString( $row['value'] );
		}
	}

	/**
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function endpoint_status( array $overrides = [] ): array {
		return array_merge(
			[
				'enabled'     => true,
				'ttl_minutes' => 5,
				'has_key'     => true,
				'key_set_at'  => 1700000000,
			],
			$overrides
		);
	}

	public function test_endpoint_summary_reports_on_with_a_key(): void {
		$line = Formatter::endpoint_summary( $this->endpoint_status() );

		$this->assertStringContainsString( 'on', $line );
		$this->assertStringContainsString( '5 min', $line );
		$this->assertStringContainsString( '2023-11-14 22:13:20', $line );
	}

	public function test_endpoint_summary_reports_off_and_no_key(): void {
		$line = Formatter::endpoint_summary(
			$this->endpoint_status(
				[
					'enabled'    => false,
					'has_key'    => false,
					'key_set_at' => 0,
				]
			)
		);

		$this->assertStringContainsString( 'off', $line );
		$this->assertStringContainsString( 'no key yet', $line );
	}

	public function test_endpoint_status_rows_are_shaped_for_format_items(): void {
		foreach ( Formatter::endpoint_status_rows( $this->endpoint_status() ) as $row ) {
			$this->assertSame( Formatter::SUMMARY_COLUMNS, array_keys( $row ) );
			$this->assertIsString( $row['value'] );
		}
	}

	public function test_endpoint_status_rows_show_the_key_state(): void {
		$values = $this->by_metric( Formatter::endpoint_status_rows( $this->endpoint_status( [ 'has_key' => false ] ) ) );

		$this->assertSame( 'none', $values['key'] );
	}
}
