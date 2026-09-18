<?php
/**
 * Tests for Scanner\LiteralLayer.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Scanner\LiteralLayer;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;

final class LiteralLayerTest extends MonkeyTestCase {

	private Signatures $signatures;

	protected function setUp(): void {
		parent::setUp();

		$this->signatures = FixturePack::signatures( 'mini' );
	}

	/**
	 * The mini fixture with `test:lit:2` flagged new: the plain-literal rule
	 * at that position, located through the pack rather than assumed.
	 */
	private function with_lit_2_new(): Signatures {
		$meta     = $this->signatures->meta();
		$position = null;

		foreach ( $this->signatures->pack()->plain_literals() as $index => $row ) {
			if ( 'test:lit:2' === $meta->id( $row[1] ) ) {
				$position = $index;
			}
		}

		$this->assertNotNull( $position, 'fixture precondition: test:lit:2 must be a plain-literal rule in the pack' );

		return FixturePack::signatures(
			'mini',
			NewSignatures::from_array(
				[
					'version' => 2,
					'since'   => 1,
					'regex'   => [],
					'literal' => [ (int) $position ],
					'hash'    => false,
				]
			)
		);
	}

	public function test_no_match_when_the_literal_is_absent(): void {
		$lc = 'nothing to see here';

		$matches = LiteralLayer::match( $lc, $this->signatures, [ 'file_php', 'file_any' ], 0, $lc );

		$this->assertSame( [], $matches );
	}

	public function test_only_entries_whose_target_is_in_the_target_set_are_returned(): void {
		$raw = 'found MALWARE_TOKEN here';
		$lc  = strtolower( $raw );

		$php_only = LiteralLayer::match( $lc, $this->signatures, [ 'file_php' ], 0, $raw );
		$any_only = LiteralLayer::match( $lc, $this->signatures, [ 'file_any' ], 0, $raw );
		$both     = LiteralLayer::match( $lc, $this->signatures, [ 'file_php', 'file_any' ], 0, $raw );

		$this->assertCount( 1, $php_only );
		$this->assertSame( 'test:lit:1', $php_only[0]->sig_id );

		$this->assertCount( 1, $any_only );
		$this->assertSame( 'test:lit:2', $any_only[0]->sig_id );

		$this->assertCount( 2, $both );
	}

	public function test_line_offset_and_excerpt_are_computed_from_the_match_position(): void {
		$raw = "AAA\nBBB\nfound MALWARE_TOKEN here";
		$lc  = strtolower( $raw );

		$matches = LiteralLayer::match( $lc, $this->signatures, [ 'file_any' ], 0, $raw );

		$this->assertCount( 1, $matches );

		$match = $matches[0];
		$pos   = strpos( $lc, 'malware_token' );

		$this->assertSame( $pos, $match->offset );
		$this->assertSame( 3, $match->line );
		$this->assertSame( $raw, $match->excerpt );
	}

	public function test_line_base_is_added_to_the_computed_line(): void {
		$raw = "AAA\nBBB\nfound MALWARE_TOKEN here";
		$lc  = strtolower( $raw );

		$matches = LiteralLayer::match( $lc, $this->signatures, [ 'file_any' ], 5, $raw );

		$this->assertCount( 1, $matches );
		$this->assertSame( 8, $matches[0]->line );
	}

	public function test_only_new_restricts_matches_to_the_new_literal_indexes(): void {
		$signatures = $this->with_lit_2_new();

		$raw = 'found MALWARE_TOKEN here';
		$lc  = strtolower( $raw );

		$matches = LiteralLayer::match( $lc, $signatures, [ 'file_php', 'file_any' ], 0, $raw, true );

		$this->assertCount( 1, $matches );
		$this->assertSame( 'test:lit:2', $matches[0]->sig_id );
	}

	public function test_only_new_returns_nothing_when_only_an_old_signature_matches(): void {
		$signatures = $this->with_lit_2_new();

		$raw = 'found MALWARE_TOKEN here';
		$lc  = strtolower( $raw );

		$matches = LiteralLayer::match( $lc, $signatures, [ 'file_php' ], 0, $raw, true );

		$this->assertSame( [], $matches );
	}
}
