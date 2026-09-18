<?php
/**
 * Tests for DbScan\RowMatcher.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\DbScan;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\DbScan\RowMatcher;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;

final class RowMatcherTest extends MonkeyTestCase {

	/**
	 * @param PackBuilder $builder Rules to wrap.
	 */
	private static function built( PackBuilder $builder ): Signatures {
		$meta = $builder->meta();

		return new Signatures(
			$builder->pack(),
			static function () use ( $meta ): PackMeta {
				return $meta;
			},
			NewSignatures::none()
		);
	}

	public function test_matches_an_injected_script_tag(): void {
		$builder = new PackBuilder();
		$builder->sig( 0, 'suspicious', 'regex', 'backdoor' );
		$signatures = self::built( $builder );

		$rules = [
			[
				'sig'  => 0,
				're'   => '/<script[^>]*src=\/\/[a-z0-9.-]+/i',
				'like' => '%script%',
			],
		];

		$matches = RowMatcher::match( 'prefix <script src=//linkangood.x></script> suffix', $rules, $signatures );

		$this->assertCount( 1, $matches );
		$this->assertSame( 0, $matches[0]->sig_index );
		$this->assertSame( 'test:0', $matches[0]->sig_id );
		$this->assertSame( 'suspicious', $matches[0]->tier );
		$this->assertSame( 0, $matches[0]->line );
		$this->assertStringContainsString( 'linkangood', $matches[0]->excerpt );
	}

	public function test_returns_empty_array_when_no_rule_matches(): void {
		$builder = new PackBuilder();
		$builder->sig( 0, 'suspicious', 'regex', 'backdoor' );
		$signatures = self::built( $builder );

		$rules = [
			[
				'sig'  => 0,
				're'   => '/<script[^>]*src=\/\/[a-z0-9.-]+/i',
				'like' => '%script%',
			],
		];

		$matches = RowMatcher::match( 'a perfectly ordinary option value', $rules, $signatures );

		$this->assertSame( [], $matches );
	}

	public function test_every_matching_rule_produces_its_own_result(): void {
		$builder = new PackBuilder();
		$builder->sig( 0, 'suspicious', 'regex', 'backdoor' );
		$builder->sig( 1, 'infected', 'regex', 'dropper' );
		$signatures = self::built( $builder );

		$rules = [
			[
				'sig'  => 0,
				're'   => '/<script[^>]*src=\/\/[a-z0-9.-]+/i',
				'like' => '%script%',
			],
			[
				'sig'  => 1,
				're'   => '/linkangood\.x/',
				'like' => '%linkangood%',
			],
		];

		$matches = RowMatcher::match( '<script src=//linkangood.x></script>', $rules, $signatures );

		$this->assertCount( 2, $matches );
		$this->assertSame( 'suspicious', $matches[0]->tier );
		$this->assertSame( 'infected', $matches[1]->tier );
	}
}
