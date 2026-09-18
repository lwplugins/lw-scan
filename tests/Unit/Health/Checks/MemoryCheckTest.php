<?php
/**
 * Tests for Health\Checks\MemoryCheck.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Health\Checks;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Health\Checks\MemoryCheck;
use LightweightPlugins\Scan\Run\Budget;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * Task 12: the check is informational only now — it reads `memory_limit`
 * and what the request has already spent, and never asks WordPress to
 * raise anything. Both readings are injected: simulating either for real
 * would mean lowering the test process's own limit or allocating hundreds
 * of megabytes, and neither is worth doing to a test runner.
 */
final class MemoryCheckTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		Functions\when( 'wp_convert_hr_to_bytes' )->alias(
			static function ( $value ): int {
				$value = strtolower( trim( (string) $value ) );
				$bytes = (int) $value;

				if ( false !== strpos( $value, 'g' ) ) {
					return $bytes * 1024 * 1024 * 1024;
				}

				if ( false !== strpos( $value, 'm' ) ) {
					return $bytes * 1024 * 1024;
				}

				if ( false !== strpos( $value, 'k' ) ) {
					return $bytes * 1024;
				}

				return $bytes;
			}
		);

		Functions\stubTranslationFunctions();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\expect( 'wp_raise_memory_limit' )->never();
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ): string {
				return round( (int) $bytes / 1048576 ) . ' MB';
			}
		);
	}

	public function test_id_and_label(): void {
		$check = new MemoryCheck();

		$this->assertSame( 'memory', $check->id() );
		$this->assertSame( 'Memory & time budget', $check->label() );
	}

	public function test_budget_seconds_is_computed_from_max_execution_time(): void {
		$original = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '25' );

		try {
			$check = new MemoryCheck();

			$this->assertSame( Budget::seconds(), $check->budget_seconds() );
			$this->assertSame( 15.0, $check->budget_seconds() );
		} finally {
			ini_set( 'max_execution_time', false === $original ? '30' : $original );
		}
	}

	public function test_status_ok_when_memory_limit_is_unlimited(): void {
		$result = $this->check_with( '-1', 400 * 1048576 )->run();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertStringContainsString( 'unlimited', $result['message'] );
		$this->assertFalse( $result['blocking'] );
	}

	public function test_status_critical_when_memory_limit_is_below_128mb(): void {
		$result = $this->check_with( '64M', 20 * 1048576 )->run();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( '64M', $result['message'] );
		$this->assertStringContainsString( '128 MB', $result['message'] );
		$this->assertFalse( $result['blocking'] );
	}

	public function test_status_warning_when_less_than_48mb_is_free(): void {
		// 256M limit, 230 MB already used: 26 MB free, under the 48 MB a
		// scan tick needs.
		$result = $this->check_with( '256M', 230 * 1048576 )->run();

		$this->assertSame( 'warning', $result['status'] );
		$this->assertStringContainsString( '230 MB', $result['message'] );
		$this->assertStringContainsString( '256 MB', $result['message'] );
		$this->assertStringContainsString( '26 MB', $result['message'] );
		$this->assertStringContainsString( 'WP-CLI', $result['message'] );
	}

	public function test_status_ok_when_at_least_48mb_is_free(): void {
		// 256M limit, 166 MB already used: 90 MB free, comfortably above
		// what a scan tick needs.
		$result = $this->check_with( '256M', 166 * 1048576 )->run();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertStringContainsString( '90 MB', $result['message'] );
		$this->assertStringContainsString( '256 MB', $result['message'] );
	}

	/**
	 * @param string $limit memory_limit ini value.
	 * @param int    $usage Bytes the request has already spent.
	 */
	private function check_with( string $limit, int $usage ): MemoryCheck {
		return new MemoryCheck(
			static function ( string $key ) use ( $limit ) {
				return 'memory_limit' === $key ? $limit : ini_get( $key );
			},
			static function () use ( $usage ): int {
				return $usage;
			}
		);
	}
}
