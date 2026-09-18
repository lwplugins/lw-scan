<?php
/**
 * Tests for Scanner\HashLayer.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Scanner\HashLayer;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;

final class HashLayerTest extends MonkeyTestCase {

	/** md5 rule `test:md5:1` in the mini fixture. */
	private const MD5 = 'aabbccddeeff00112233445566778899';

	/** sha256 rule `test:sha256:1` in the mini fixture. */
	private const SHA256 = 'abababababababababababababababababababababababababababababababab';

	private Signatures $signatures;

	protected function setUp(): void {
		parent::setUp();

		$this->signatures = FixturePack::signatures( 'mini' );
	}

	public function test_matches_a_known_md5(): void {
		$matches = HashLayer::match( self::MD5, 'irrelevant-sha256', $this->signatures );

		$this->assertCount( 1, $matches );
		$this->assertSame( 'test:md5:1', $matches[0]->sig_id );
		$this->assertSame( 'infected', $matches[0]->tier );
		$this->assertSame( 0, $matches[0]->line );
		$this->assertSame( '', $matches[0]->excerpt );
	}

	public function test_matches_a_known_sha256(): void {
		$matches = HashLayer::match( 'irrelevant-md5', self::SHA256, $this->signatures );

		$this->assertCount( 1, $matches );
		$this->assertSame( 'test:sha256:1', $matches[0]->sig_id );
	}

	public function test_matches_both_md5_and_sha256_when_both_are_known(): void {
		$matches = HashLayer::match( self::MD5, self::SHA256, $this->signatures );

		$this->assertCount( 2, $matches );
	}

	public function test_no_match_returns_an_empty_array(): void {
		$matches = HashLayer::match( 'unknown-md5', 'unknown-sha256', $this->signatures );

		$this->assertSame( [], $matches );
	}

	public function test_match_result_to_array_contains_all_fields(): void {
		$matches = HashLayer::match( self::MD5, 'irrelevant-sha256', $this->signatures );

		$array = $matches[0]->to_array();

		$this->assertSame( 'test:md5:1', $array['sig_id'] );
		$this->assertSame( 'infected', $array['tier'] );
		$this->assertSame( 'unknown', $array['category'] );
		$this->assertSame( 'md5 sample one', $array['name'] );
		$this->assertSame( 0, $array['line'] );
		$this->assertSame( '', $array['excerpt'] );
	}

	public function test_allowlisted_matches_a_bare_md5(): void {
		$this->assertTrue( HashLayer::allowlisted( 'd41d8cd98f00b204e9800998ecf8427e', 999, $this->signatures->pack() ) );
	}

	public function test_allowlisted_matches_an_md5_plus_size_entry(): void {
		$this->assertTrue( HashLayer::allowlisted( 'abc', 123, $this->signatures->pack() ) );
	}

	public function test_allowlisted_is_false_for_the_same_md5_with_a_different_size(): void {
		$this->assertFalse( HashLayer::allowlisted( 'abc', 456, $this->signatures->pack() ) );
	}

	public function test_allowlisted_is_false_for_an_unknown_md5(): void {
		$this->assertFalse( HashLayer::allowlisted( 'ffffffffffffffffffffffffffffffff', 1, $this->signatures->pack() ) );
	}
}
