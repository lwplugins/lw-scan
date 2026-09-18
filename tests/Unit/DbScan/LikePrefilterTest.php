<?php
/**
 * Tests for DbScan\LikePrefilter.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\DbScan;

use LightweightPlugins\Scan\DbScan\LikePrefilter;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class LikePrefilterTest extends MonkeyTestCase {

	public function test_zero_rules_returns_no_groups(): void {
		$this->assertSame( [], LikePrefilter::groups( [], 'option_name' ) );
	}

	public function test_one_rule_returns_a_single_group(): void {
		$rules = [
			[
				'sig'  => 0,
				're'   => '/x/',
				'like' => '%foo%',
			],
		];

		$groups = LikePrefilter::groups( $rules, 'option_name' );

		$this->assertSame(
			[
				[
					'sql'  => '(option_name LIKE %s)',
					'args' => [ '%foo%' ],
				],
			],
			$groups
		);
	}

	public function test_sixty_rules_are_chunked_into_groups_of_fifty(): void {
		$rules = [];

		for ( $i = 0; $i < 60; $i++ ) {
			$rules[] = [
				'sig'  => $i,
				're'   => '/x/',
				'like' => "%v{$i}%",
			];
		}

		$groups = LikePrefilter::groups( $rules, 'option_name' );

		$this->assertCount( 2, $groups );
		$this->assertCount( 50, $groups[0]['args'] );
		$this->assertCount( 10, $groups[1]['args'] );
		$this->assertSame(
			'(' . implode( ' OR ', array_fill( 0, 50, 'option_name LIKE %s' ) ) . ')',
			$groups[0]['sql']
		);
		$this->assertSame(
			'(' . implode( ' OR ', array_fill( 0, 10, 'option_name LIKE %s' ) ) . ')',
			$groups[1]['sql']
		);
	}

	public function test_two_columns_are_or_ed_within_one_group(): void {
		$rules = [
			[
				'sig'  => 0,
				're'   => '/x/',
				'like' => '%foo%',
			],
			[
				'sig'  => 1,
				're'   => '/y/',
				'like' => '%bar%',
			],
		];

		$groups = LikePrefilter::groups( $rules, 'post_content', 'post_excerpt' );

		$this->assertSame(
			[
				[
					'sql'  => '(post_content LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s OR post_excerpt LIKE %s)',
					'args' => [ '%foo%', '%bar%', '%foo%', '%bar%' ],
				],
			],
			$groups
		);
	}

	public function test_two_columns_halve_the_values_per_chunk_so_placeholders_stay_bounded(): void {
		$rules = [];

		for ( $i = 0; $i < 60; $i++ ) {
			$rules[] = [
				'sig'  => $i,
				're'   => '/x/',
				'like' => "%v{$i}%",
			];
		}

		$groups = LikePrefilter::groups( $rules, 'post_content', 'post_excerpt' );

		$this->assertCount( 3, $groups );
		$this->assertCount( 50, $groups[0]['args'] );
		$this->assertCount( 50, $groups[1]['args'] );
		$this->assertCount( 20, $groups[2]['args'] );
	}

	public function test_no_column_returns_no_groups(): void {
		$rules = [
			[
				'sig'  => 0,
				're'   => '/x/',
				'like' => '%foo%',
			],
		];

		$this->assertSame( [], LikePrefilter::groups( $rules ) );
	}

	public function test_a_null_like_rule_bypasses_filtering_with_one_empty_group(): void {
		$rules = [
			[
				'sig'  => 0,
				're'   => '/x/',
				'like' => '%foo%',
			],
			[
				'sig'  => 1,
				're'   => '/y/',
				'like' => null,
			],
		];

		$groups = LikePrefilter::groups( $rules, 'option_name' );

		$this->assertSame(
			[
				[
					'sql'  => '',
					'args' => [],
				],
			],
			$groups
		);
	}
}
