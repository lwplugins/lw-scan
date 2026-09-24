<?php
/**
 * Tests for Rest\Presenter\ScanStatus.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest\Presenter;

use LightweightPlugins\Scan\Rest\Presenter\ScanStatus;
use PHPUnit\Framework\TestCase;

/**
 * `build_payload()` and `run_context()` are pure — no WP functions, no `$wpdb` access — so these
 * run against plain PHPUnit, feeding it the exact raw shapes
 * `Runner::progress()`, `FindingsRepository::new_since()`,
 * `FindingsRepository::counts()` and `RunsRepository::last()` produce.
 */
final class ScanStatusTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function sample_progress(): array {
		return [
			'status'       => 'running',
			'phase'        => 'files',
			'done'         => 10,
			'total'        => 100,
			'findings_new' => 2,
			'elapsed'      => 30,
			'run_id'       => 7,
			'last_tick_at' => 1700000000,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sample_counts(): array {
		return [
			'state' => [ 'new' => [ 'alert' => 1 ] ],
			'type'  => [ 'file' => 1 ],
			'total' => 1,
		];
	}

	public function test_merges_progress_fields_into_the_top_level(): void {
		$payload = ScanStatus::build_payload( $this->sample_progress(), [], $this->sample_counts(), null );

		$this->assertSame( 'running', $payload['status'] );
		$this->assertSame( 'files', $payload['phase'] );
		$this->assertSame( 10, $payload['done'] );
		$this->assertSame( 100, $payload['total'] );
		$this->assertSame( 1700000000, $payload['last_tick_at'] );
	}

	public function test_shapes_each_feed_row_from_the_raw_findings_columns(): void {
		$row = [
			'severity'      => 'alert',
			'type'          => 'file',
			'locator'       => 'wp-content/uploads/shell.php',
			'tier'          => 'infected',
			'signature_ids' => '["sig-1","sig-2"]',
			'reason'        => 'Matched known webshell signature.',
		];

		$payload = ScanStatus::build_payload( $this->sample_progress(), [ $row ], $this->sample_counts(), null );

		$this->assertSame(
			[
				'severity'  => 'alert',
				'type'      => 'file',
				'locator'   => 'wp-content/uploads/shell.php',
				'tier'      => 'infected',
				'signature' => 'sig-1',
				'reason'    => 'Matched known webshell signature.',
			],
			$payload['feed'][0]
		);
	}

	public function test_feed_row_signature_is_empty_string_when_no_signature_ids(): void {
		$row = [
			'severity'      => 'review',
			'type'          => 'integrity',
			'locator'       => 'wp-includes/version.php',
			'tier'          => 'suspicious',
			'signature_ids' => '[]',
			'reason'        => 'Checksum mismatch.',
		];

		$payload = ScanStatus::build_payload( $this->sample_progress(), [ $row ], $this->sample_counts(), null );

		$this->assertSame( '', $payload['feed'][0]['signature'] );
	}

	public function test_feed_is_capped_at_twenty_rows(): void {
		$row = [
			'severity'      => 'review',
			'type'          => 'file',
			'locator'       => 'x',
			'tier'          => 'info',
			'signature_ids' => '[]',
			'reason'        => '',
		];

		$payload = ScanStatus::build_payload( $this->sample_progress(), array_fill( 0, 25, $row ), $this->sample_counts(), null );

		$this->assertCount( 20, $payload['feed'] );
	}

	public function test_counts_carry_every_state_severity_and_type_key(): void {
		$payload = ScanStatus::build_payload( $this->sample_progress(), [], $this->sample_counts(), null );

		$this->assertSame(
			[
				'state' => [
					'new'          => [
						'alert'  => 1,
						'review' => 0,
					],
					'acknowledged' => [
						'alert'  => 0,
						'review' => 0,
					],
					'ignored'      => [
						'alert'  => 0,
						'review' => 0,
					],
				],
				'type'  => [
					'file'          => 1,
					'integrity'     => 0,
					'db'            => 0,
					'vulnerability' => 0,
				],
				'total' => 1,
			],
			$payload['counts']
		);
	}

	public function test_run_context_prefers_the_live_cursor_scope(): void {
		$context = ScanStatus::run_context( 'db', [ 'scope' => 'full' ], 'changed', 1700000000 );

		$this->assertSame(
			[
				'scope'      => 'db',
				'phases'     => [ 'bundle', 'db', 'vuln', 'finalize' ],
				'started_at' => 1700000000,
			],
			$context
		);
	}

	public function test_run_context_falls_back_to_the_last_run_scope(): void {
		$context = ScanStatus::run_context( '', [ 'scope' => 'path' ], 'changed', 5 );

		$this->assertSame( 'path', $context['scope'] );
		$this->assertSame( [ 'bundle', 'index', 'hash', 'files', 'finalize' ], $context['phases'] );
	}

	public function test_run_context_falls_back_to_the_configured_scope_without_any_run(): void {
		$context = ScanStatus::run_context( '', null, 'changed', 0 );

		$this->assertSame( 'changed', $context['scope'] );
		$this->assertSame( 0, $context['started_at'] );
	}

	public function test_run_context_lists_every_phase_for_an_unknown_scope(): void {
		$context = ScanStatus::run_context( 'bogus', null, 'changed', 0 );

		$this->assertSame( [ 'bundle', 'index', 'hash', 'files', 'db', 'vuln', 'finalize' ], $context['phases'] );
	}

	public function test_last_run_is_null_when_no_run_exists(): void {
		$payload = ScanStatus::build_payload( $this->sample_progress(), [], $this->sample_counts(), null );

		$this->assertNull( $payload['last_run'] );
	}

	public function test_last_run_is_summarized_to_id_status_and_timestamps(): void {
		$run = [
			'id'          => 7,
			'status'      => 'done',
			'started_at'  => 1700000000,
			'finished_at' => 1700000600,
			'stats'       => [ 'ticks' => 5 ],
			'scope'       => 'changed',
		];

		$payload = ScanStatus::build_payload( $this->sample_progress(), [], $this->sample_counts(), $run );

		$this->assertSame(
			[
				'id'          => 7,
				'status'      => 'done',
				'started_at'  => 1700000000,
				'finished_at' => 1700000600,
			],
			$payload['last_run']
		);
	}
}
