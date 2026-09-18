<?php
/**
 * Tests for Vuln\VersionRange.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Vuln;

use LightweightPlugins\Scan\Vuln\VersionRange;
use PHPUnit\Framework\TestCase;

final class VersionRangeTest extends TestCase {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function sample_range(): array {
		return [
			'*-1.37' => [
				'to_version'     => '1.37',
				'from_version'   => '*',
				'to_inclusive'   => true,
				'from_inclusive' => true,
			],
		];
	}

	public function test_version_equal_to_inclusive_upper_bound_is_affected(): void {
		$this->assertTrue( VersionRange::affects( '1.37', $this->sample_range() ) );
	}

	public function test_version_above_upper_bound_is_not_affected(): void {
		$this->assertFalse( VersionRange::affects( '1.38', $this->sample_range() ) );
	}

	public function test_exclusive_upper_bound_excludes_the_boundary_version(): void {
		$range = [
			'r' => [
				'from_version' => '*',
				'to_version'   => '1.37',
				'to_inclusive' => false,
			],
		];

		$this->assertFalse( VersionRange::affects( '1.37', $range ) );
		$this->assertTrue( VersionRange::affects( '1.36.9', $range ) );
	}

	public function test_version_below_lower_bound_is_not_affected(): void {
		$range = [
			'r' => [
				'from_version' => '2.0',
				'to_version'   => '*',
			],
		];

		$this->assertFalse( VersionRange::affects( '1.9', $range ) );
		$this->assertTrue( VersionRange::affects( '2.0', $range ) );
	}

	public function test_normalize_lowercases_and_turns_dashes_into_dots(): void {
		$this->assertSame( '5.9.3.beta', VersionRange::normalize( '5.9.3-BETA' ) );
	}

	public function test_dash_suffixed_installed_version_compares_against_dot_suffixed_bound(): void {
		$range = [
			'r' => [
				'from_version' => '*',
				'to_version'   => '5.9.3.beta',
			],
		];

		$this->assertTrue( VersionRange::affects( '5.9.3-beta', $range ) );
		$this->assertFalse( VersionRange::affects( '5.9.3-rc2', $range ) );
	}

	public function test_matches_when_any_one_of_several_ranges_matches(): void {
		$ranges = [
			'a' => [
				'from_version' => '1.0',
				'to_version'   => '1.5',
			],
			'b' => [
				'from_version' => '2.0',
				'to_version'   => '2.5',
			],
		];

		$this->assertTrue( VersionRange::affects( '2.2', $ranges ) );
		$this->assertFalse( VersionRange::affects( '1.8', $ranges ) );
	}
}
