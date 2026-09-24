<?php
/**
 * Tests for Rest\Presenter\RunPresenter.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest\Presenter;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Rest\Presenter\RunPresenter;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class RunPresenterTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'size_format' )->alias( static fn ( $bytes ) => ( (int) $bytes / 1048576 ) . ' MB' );
	}

	/**
	 * A run row as `RunsRepository` returns it: `$wpdb` strings, `stats` decoded.
	 *
	 * @param array<string, mixed> $overrides Columns to replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function run_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'           => '7',
				'trigger_kind' => 'manual',
				'scope'        => 'changed',
				'scope_path'   => '',
				'started_at'   => '1790000000',
				'finished_at'  => '1790000038',
				'status'       => 'done',
				'error'        => '',
				'stats'        => [
					'files'    => [
						'indexed' => 5000,
						'scanned' => 900,
					],
					'findings' => [
						'alerts_new' => 0,
						'review_new' => 1,
					],
				],
			],
			$overrides
		);
	}

	public function test_summary_is_null_without_a_run(): void {
		$this->assertNull( RunPresenter::summary( null ) );
	}

	public function test_summary_casts_the_row_and_measures_the_duration(): void {
		$this->assertSame(
			[
				'id'          => 7,
				'trigger'     => 'manual',
				'scope'       => 'changed',
				'started_at'  => 1790000000,
				'finished_at' => 1790000038,
				'duration'    => 38,
				'status'      => 'done',
			],
			RunPresenter::summary( self::run_row() )
		);
	}

	public function test_an_unfinished_run_has_no_duration(): void {
		$this->assertNull( RunPresenter::duration( self::run_row( [ 'finished_at' => '0' ] ) ) );
	}

	public function test_a_run_finished_in_its_first_second_lasted_zero_seconds(): void {
		$this->assertSame( 0, RunPresenter::duration( self::run_row( [ 'finished_at' => '1790000000' ] ) ) );
	}

	public function test_row_maps_the_stats_blob(): void {
		$this->assertSame(
			[
				'id'          => 7,
				'trigger'     => 'manual',
				'scope'       => 'changed',
				'scope_path'  => '',
				'started_at'  => 1790000000,
				'finished_at' => 1790000038,
				'status'      => 'done',
				'error_label' => '',
				'indexed'     => 5000,
				'scanned'     => 900,
				'alerts_new'  => 0,
				'review_new'  => 1,
			],
			RunPresenter::row( self::run_row() )
		);
	}

	public function test_row_explains_an_out_of_memory_failure(): void {
		$row = RunPresenter::row(
			self::run_row(
				[
					'status' => 'failed',
					'error'  => 'out_of_memory',
					'stats'  => [ 'memory_limit' => 268435456 ],
				]
			)
		);

		$this->assertSame( 'PHP ran out of memory during the scan (memory_limit 256 MB). Raise memory_limit or run the scan with WP-CLI.', $row['error_label'] );
		$this->assertSame( 0, $row['indexed'] );
	}

	public function test_row_passes_an_unknown_error_code_through(): void {
		$this->assertSame( 'backend_down', RunPresenter::row( self::run_row( [ 'error' => 'backend_down' ] ) )['error_label'] );
	}
}
