<?php
/**
 * Tests for Scanner\Heuristic\Gate.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner\Heuristic;

use LightweightPlugins\Scan\Scanner\Heuristic\Gate;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class GateTest extends MonkeyTestCase {

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'         => 7,
				'kind'       => 'php',
				'known_good' => 0,
				'size'       => 4096,
				'origin'     => 'plugin:x',
			],
			$overrides
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function config( bool $heuristics = true, int $memory_limit = PHP_INT_MAX ): array {
		return [
			'heuristics'   => $heuristics,
			'memory_limit' => $memory_limit,
		];
	}

	public function test_a_normal_unscanned_php_file_is_allowed(): void {
		$this->assertTrue( Gate::allows( $this->row(), false, $this->config() ) );
	}

	public function test_non_php_files_are_rejected(): void {
		$this->assertFalse( Gate::allows( $this->row( [ 'kind' => 'js' ] ), false, $this->config() ) );
		$this->assertFalse( Gate::allows( $this->row( [ 'kind' => 'binary' ] ), false, $this->config() ) );
	}

	public function test_known_good_files_are_rejected(): void {
		$this->assertFalse( Gate::allows( $this->row( [ 'known_good' => 1 ] ), false, $this->config() ) );
	}

	public function test_files_of_half_a_megabyte_or_more_are_rejected(): void {
		$this->assertTrue( Gate::allows( $this->row( [ 'size' => 524287 ] ), false, $this->config() ) );
		$this->assertFalse( Gate::allows( $this->row( [ 'size' => 524288 ] ), false, $this->config() ) );
	}

	public function test_a_file_that_already_has_an_infected_match_is_rejected(): void {
		$this->assertFalse( Gate::allows( $this->row(), true, $this->config() ) );
	}

	public function test_disabled_heuristics_reject_everything(): void {
		$this->assertFalse( Gate::allows( $this->row(), false, $this->config( false ) ) );
	}

	public function test_a_memory_limit_that_leaves_no_headroom_rejects_the_file(): void {
		$this->assertFalse( Gate::allows( $this->row(), false, $this->config( true, 1024 ) ) );
	}

	public function test_an_unlimited_memory_limit_always_has_headroom(): void {
		$this->assertTrue( Gate::allows( $this->row(), false, $this->config( true, -1 ) ) );
	}

	public function test_memory_limit_bytes_parses_the_ini_value(): void {
		$original = ini_get( 'memory_limit' );

		try {
			ini_set( 'memory_limit', '-1' );
			$this->assertSame( PHP_INT_MAX, Gate::memory_limit_bytes() );

			ini_set( 'memory_limit', '1G' );
			$this->assertSame( 1073741824, Gate::memory_limit_bytes() );

			ini_set( 'memory_limit', '512M' );
			$this->assertSame( 536870912, Gate::memory_limit_bytes() );
		} finally {
			ini_set( 'memory_limit', false === $original ? '-1' : $original );
		}
	}
}
