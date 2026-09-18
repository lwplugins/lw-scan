<?php
/**
 * Tests for Bundle\NewSignatures.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Bundle;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class NewSignaturesTest extends MonkeyTestCase {

	private const DATA = [
		'version' => 2,
		'since'   => 1,
		'regex'   => [ 4, 7 ],
		'literal' => [ 1 ],
		'hash'    => true,
	];

	public function test_none_is_empty(): void {
		$none = NewSignatures::none();

		$this->assertTrue( $none->is_empty() );
		$this->assertFalse( $none->has_regex( 0 ) );
		$this->assertFalse( $none->has_literal( 0 ) );
		$this->assertFalse( $none->hash() );
		$this->assertSame( [], $none->regex_indexes() );
		$this->assertSame( 0, $none->since() );
	}

	public function test_since_is_the_version_the_rules_are_new_relative_to(): void {
		$this->assertSame( 1, NewSignatures::from_array( self::DATA )->since() );
		$this->assertSame( 0, NewSignatures::from_array( array_merge( self::DATA, [ 'since' => 0 ] ) )->since() );
	}

	public function test_a_valid_array_answers_membership(): void {
		$new = NewSignatures::from_array( self::DATA );

		$this->assertFalse( $new->is_empty() );
		$this->assertTrue( $new->has_regex( 4 ) );
		$this->assertTrue( $new->has_regex( 7 ) );
		$this->assertFalse( $new->has_regex( 5 ) );
		$this->assertTrue( $new->has_literal( 1 ) );
		$this->assertFalse( $new->has_literal( 0 ) );
		$this->assertTrue( $new->hash() );
		$this->assertSame( [ 4, 7 ], $new->regex_indexes() );
	}

	public function test_only_a_new_hash_rule_is_not_empty(): void {
		$new = NewSignatures::from_array( array_merge( self::DATA, [ 'regex' => [], 'literal' => [] ] ) );

		$this->assertFalse( $new->is_empty() );
		$this->assertTrue( NewSignatures::from_array( array_merge( self::DATA, [ 'regex' => [], 'literal' => [], 'hash' => false ] ) )->is_empty() );
	}

	public function test_regex_indexes_are_ascending_and_unique(): void {
		$new = NewSignatures::from_array( array_merge( self::DATA, [ 'regex' => [ 9, 2, 9, 5 ] ] ) );

		$this->assertSame( [ 2, 5, 9 ], $new->regex_indexes() );
	}

	/**
	 * @dataProvider provide_malformed
	 *
	 * @param array<string, mixed> $data Decoded new-<v>.json content.
	 */
	public function test_malformed_input_reads_as_none( array $data ): void {
		$this->assertTrue( NewSignatures::from_array( $data )->is_empty() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function provide_malformed(): array {
		$missing_since = self::DATA;
		unset( $missing_since['since'] );

		return [
			'empty array'          => [ [] ],
			'unrelated keys'       => [ [ 'nope' => 1 ] ],
			'regex not a list'     => [ array_merge( self::DATA, [ 'regex' => 'x' ] ) ],
			'non-int regex index'  => [ array_merge( self::DATA, [ 'regex' => [ 4, 'x' ] ] ) ],
			'negative literal'     => [ array_merge( self::DATA, [ 'literal' => [ -1 ] ] ) ],
			'hash not a bool'      => [ array_merge( self::DATA, [ 'hash' => 'yes' ] ) ],
			'version not an int'   => [ array_merge( self::DATA, [ 'version' => '2' ] ) ],
			'since missing'        => [ $missing_since ],
			'since not an int'     => [ array_merge( self::DATA, [ 'since' => '1' ] ) ],
			'negative since'       => [ array_merge( self::DATA, [ 'since' => -1 ] ) ],
			'since not older'      => [ array_merge( self::DATA, [ 'since' => 2 ] ) ],
			'since newer'          => [ array_merge( self::DATA, [ 'since' => 3 ] ) ],
		];
	}

	public function test_to_array_round_trips(): void {
		$new = NewSignatures::from_array( self::DATA );

		$this->assertSame( self::DATA, $new->to_array( 2, 1 ) );
		$this->assertSame( self::DATA, NewSignatures::from_array( $new->to_array( 2, 1 ) )->to_array( 2, 1 ) );
		$this->assertSame(
			[
				'version' => 3,
				'since'   => 2,
				'regex'   => [],
				'literal' => [],
				'hash'    => false,
			],
			NewSignatures::none()->to_array( 3, 2 )
		);
	}
}
