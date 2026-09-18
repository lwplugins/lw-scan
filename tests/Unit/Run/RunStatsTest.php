<?php
/**
 * Tests for Run\RunStats.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use LightweightPlugins\Scan\Run\RunStats;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class RunStatsTest extends MonkeyTestCase {

	public function test_to_array_is_zero_filled_when_nothing_was_recorded(): void {
		$stats = new RunStats();

		$this->assertSame(
			[
				'phases'         => [],
				'files'          => [
					'indexed'       => 0,
					'hashed'        => 0,
					'known_good'    => 0,
					'scanned'       => 0,
					'heuristics'    => 0,
					'large_skipped' => 0,
					'deleted'       => 0,
					'preg_errors'   => 0,
				],
				'db'             => [
					'options'  => 0,
					'posts'    => 0,
					'meta'     => 0,
					'users'    => 0,
					'triggers' => 0,
					'skipped'  => [],
				],
				'vuln'           => [
					'software' => 0,
					'lookups'  => 0,
					'skipped'  => 0,
				],
				'findings'       => [
					'new'        => 0,
					'updated'    => 0,
					'alerts_new' => 0,
					'review_new' => 0,
				],
				'remote'         => [
					'calls'     => 0,
					'errors'    => 0,
					'not_found' => 0,
				],
				'bundle_version' => 0,
				'budget_s'       => 0,
				'ticks'          => 0,
				'peak_memory'    => 0,
			],
			$stats->to_array()
		);
	}

	public function test_inc_increments_a_dotted_path_by_one_by_default(): void {
		$stats = new RunStats();

		$stats->inc( 'files.hashed' );

		$this->assertSame( 1, $stats->to_array()['files']['hashed'] );
	}

	public function test_inc_increments_a_dotted_path_by_the_given_amount(): void {
		$stats = new RunStats();

		$stats->inc( 'files.hashed', 5 );
		$stats->inc( 'files.hashed', 3 );

		$this->assertSame( 8, $stats->to_array()['files']['hashed'] );
	}

	public function test_inc_leaves_sibling_counters_untouched(): void {
		$stats = new RunStats();

		$stats->inc( 'files.hashed', 5 );

		$this->assertSame( 0, $stats->to_array()['files']['indexed'] );
	}

	public function test_set_writes_a_scalar_dotted_path(): void {
		$stats = new RunStats();

		$stats->set( 'bundle_version', 20260101001 );

		$this->assertSame( 20260101001, $stats->to_array()['bundle_version'] );
	}

	public function test_set_writes_a_nested_dotted_path(): void {
		$stats = new RunStats();

		$stats->set( 'db.skipped', [ 'wp_options.big_row' ] );

		$this->assertSame( [ 'wp_options.big_row' ], $stats->to_array()['db']['skipped'] );
	}

	public function test_phase_end_records_items_for_a_fresh_phase(): void {
		$stats = new RunStats();

		$stats->phase_start( 'hash' );
		$stats->phase_end( 'hash', 10 );

		$phase = $stats->to_array()['phases']['hash'];

		$this->assertSame( 10, $phase['items'] );
		$this->assertGreaterThanOrEqual( 0, $phase['ms'] );
	}

	public function test_phase_start_and_end_accumulate_across_ticks(): void {
		$tick1 = new RunStats();
		$tick1->phase_start( 'hash' );
		$tick1->phase_end( 'hash', 10 );
		$after_tick1 = $tick1->to_array();

		$tick2 = RunStats::from_array( $after_tick1 );
		$tick2->phase_start( 'hash' );
		$tick2->phase_end( 'hash', 5 );
		$after_tick2 = $tick2->to_array();

		$this->assertSame( 15, $after_tick2['phases']['hash']['items'] );
		$this->assertGreaterThanOrEqual( $after_tick1['phases']['hash']['ms'], $after_tick2['phases']['hash']['ms'] );
	}

	public function test_phase_end_for_a_different_phase_does_not_disturb_the_first(): void {
		$stats = new RunStats();

		$stats->phase_start( 'hash' );
		$stats->phase_end( 'hash', 10 );

		$stats->phase_start( 'files' );
		$stats->phase_end( 'files', 3 );

		$phases = $stats->to_array()['phases'];

		$this->assertSame( 10, $phases['hash']['items'] );
		$this->assertSame( 3, $phases['files']['items'] );
	}

	public function test_from_array_round_trips_arbitrary_stored_shape(): void {
		$stored = ( new RunStats() )->to_array();
		$stored['bundle_version'] = 42;

		$stats = RunStats::from_array( $stored );

		$this->assertSame( 42, $stats->to_array()['bundle_version'] );
	}

	public function test_init_constructor_argument_seeds_the_same_as_from_array(): void {
		$stored = [ 'ticks' => 3 ];

		$stats = new RunStats( $stored );

		$this->assertSame( 3, $stats->to_array()['ticks'] );
		$this->assertSame( 0, $stats->to_array()['files']['hashed'] );
	}

	public function test_max_raises_a_value_but_never_lowers_it(): void {
		$stats = new RunStats();

		$stats->max( 'peak_memory', 120 );
		$this->assertSame( 120, $stats->to_array()['peak_memory'] );

		$stats->max( 'peak_memory', 80 );
		$this->assertSame( 120, $stats->to_array()['peak_memory'], 'a smaller later reading must not win' );

		$stats->max( 'peak_memory', 300 );
		$this->assertSame( 300, $stats->to_array()['peak_memory'] );
	}
}
