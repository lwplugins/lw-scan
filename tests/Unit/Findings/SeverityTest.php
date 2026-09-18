<?php
/**
 * Tests for Severity::of() and Severity::tier_rank().
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Findings;

use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class SeverityTest extends MonkeyTestCase {

	/**
	 * @dataProvider provide_tier_signal_cases
	 */
	public function test_of_maps_tier_and_signal_to_severity( string $tier, int $signal, string $type, string $expected ): void {
		$this->assertSame( $expected, Severity::of( $tier, $signal, $type ) );
	}

	public static function provide_tier_signal_cases(): array {
		return [
			'integrity, low signal'          => [ 'integrity', 0, 'file', Severity::ALERT ],
			'integrity, high signal'         => [ 'integrity', 100, 'db', Severity::ALERT ],
			'infected, low signal'           => [ 'infected', 0, 'file', Severity::ALERT ],
			'suspicious, signal just under'  => [ 'suspicious', 39, 'file', Severity::REVIEW ],
			'suspicious, signal at boundary' => [ 'suspicious', 40, 'file', Severity::ALERT ],
			'suspicious, signal well over'   => [ 'suspicious', 100, 'file', Severity::ALERT ],
			'info, any signal'               => [ 'info', 0, 'file', Severity::REVIEW ],
			'suspicious low signal, db type' => [ 'suspicious', 0, 'db', Severity::REVIEW ],
		];
	}

	/**
	 * @dataProvider provide_tier_rank_cases
	 */
	public function test_tier_rank_orders_tiers( string $tier, int $expected ): void {
		$this->assertSame( $expected, Severity::tier_rank( $tier ) );
	}

	public static function provide_tier_rank_cases(): array {
		return [
			'integrity'  => [ 'integrity', 4 ],
			'infected'   => [ 'infected', 3 ],
			'suspicious' => [ 'suspicious', 2 ],
			'info'       => [ 'info', 1 ],
			'unknown'    => [ 'bogus', 0 ],
		];
	}
}
