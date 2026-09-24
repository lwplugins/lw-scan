<?php
/**
 * Tests for Rest\Presenter\ScanOverview's pure shaping steps.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest\Presenter;

use LightweightPlugins\Scan\Admin\Settings\RunStatsView;
use LightweightPlugins\Scan\Rest\Presenter\ScanOverview;
use PHPUnit\Framework\TestCase;

final class ScanOverviewTest extends TestCase {

	public function test_tiles_carry_the_counts_and_the_last_run_breakdown(): void {
		$run = RunStatsView::of(
			[
				'stats' => [
					'files' => [ 'scanned' => 900 ],
					'db'    => [
						'options' => 10000,
						'posts'   => 2000,
					],
					'vuln'  => [ 'software' => 30 ],
				],
			]
		);

		$tiles = ScanOverview::tiles(
			[
				'total'      => '5000',
				'known_good' => '4100',
			],
			12,
			$run,
			[
				'schedule' => 'daily',
				'scope'    => 'changed',
				'next_due' => 1790000000,
			]
		);

		$this->assertSame(
			[
				'files_total'    => 5000,
				'queued'         => 12,
				'known_good'     => 4100,
				'known_good_pct' => 82.0,
				'deep_scanned'   => 900,
				'deep_breakdown' => [
					'files'    => 900,
					'db_rows'  => 12000,
					'packages' => 30,
				],
				'next_due'       => 1790000000,
				'schedule'       => 'daily',
				'schedule_scope' => 'changed',
			],
			$tiles
		);
	}

	public function test_an_empty_index_is_zero_percent_known_good(): void {
		$tiles = ScanOverview::tiles( [], 0, RunStatsView::of( null ), [] );

		$this->assertSame( 0.0, $tiles['known_good_pct'] );
	}

	public function test_next_due_is_zero_while_scheduled_scans_are_off(): void {
		$this->assertSame(
			0,
			ScanOverview::next_due(
				[
					'schedule' => 'off',
					'next_due' => 1790000000,
				]
			)
		);
	}

	public function test_the_estimate_is_thirty_milliseconds_per_queued_file(): void {
		$starter = ScanOverview::starter(
			[
				'scope'      => 'full',
				'heuristics' => true,
			],
			true,
			12,
			20260916
		);

		$this->assertSame(
			[
				'scope'          => 'full',
				'heuristics'     => true,
				'can_resume'     => true,
				'queued'         => 12,
				'estimate_ms'    => 360,
				'bundle_version' => 20260916,
			],
			$starter
		);
	}
}
