<?php
/**
 * Tests for DbScan\DbFinding.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\DbScan;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\DbScan\DbFinding;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Scanner\MatchResult;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;

final class DbFindingTest extends MonkeyTestCase {

	private function signatures(): Signatures {
		$builder = new PackBuilder();
		$builder->sig( 0, 'suspicious', 'regex', 'backdoor' );
		$builder->sig( 1, 'infected', 'regex', 'dropper' );

		$meta = $builder->meta();

		return new Signatures(
			$builder->pack(),
			static function () use ( $meta ): PackMeta {
				return $meta;
			},
			NewSignatures::none()
		);
	}

	public function test_build_shapes_a_single_match_finding(): void {
		$signatures = $this->signatures();
		$matches    = [ MatchResult::from_signatures( $signatures, 0, 0, '...<script src=//x>...', 5 ) ];

		$finding = DbFinding::build( 'options', 'option_value', 42, $matches, 'widget_foo' );

		$this->assertSame( 'db', $finding->type );
		$this->assertSame( 'options:option_value:42', $finding->locator );
		$this->assertSame( 'suspicious', $finding->tier );
		$this->assertSame( Severity::of( 'suspicious', 0, 'db' ), $finding->severity );
		$this->assertSame( 'backdoor', $finding->category );
		$this->assertSame( [ 'test:0' ], $finding->signature_ids );
		$this->assertSame( '...<script src=//x>...', $finding->excerpt );
		$this->assertSame( 'widget_foo: rule 0', $finding->reason );
		$this->assertSame(
			[
				'table'  => 'options',
				'column' => 'option_value',
				'row_id' => 42,
				'label'  => 'widget_foo',
			],
			$finding->meta
		);
	}

	public function test_build_uses_the_highest_tier_among_several_matches(): void {
		$signatures = $this->signatures();
		$matches    = [
			MatchResult::from_signatures( $signatures, 0, 0, 'excerpt-a', 1 ),
			MatchResult::from_signatures( $signatures, 1, 0, 'excerpt-b', 9 ),
		];

		$finding = DbFinding::build( 'options', 'option_value', 42, $matches, 'widget_foo' );

		$this->assertSame( 'infected', $finding->tier );
		$this->assertSame( [ 'test:0', 'test:1' ], $finding->signature_ids );
	}

	public function test_build_takes_category_and_excerpt_from_the_first_match_regardless_of_tier(): void {
		$signatures = $this->signatures();
		$matches    = [
			MatchResult::from_signatures( $signatures, 0, 0, 'first-excerpt', 1 ),
			MatchResult::from_signatures( $signatures, 1, 0, 'second-excerpt', 9 ),
		];

		$finding = DbFinding::build( 'options', 'option_value', 42, $matches, 'widget_foo' );

		$this->assertSame( 'backdoor', $finding->category );
		$this->assertSame( 'first-excerpt', $finding->excerpt );
		$this->assertSame( 'widget_foo: rule 0', $finding->reason );
	}
}
