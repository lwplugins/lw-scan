<?php
/**
 * Tests for Scanner\RegexWalks.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Scanner\FileScanner;
use LightweightPlugins\Scan\Scanner\RegexLayer;
use LightweightPlugins\Scan\Scanner\RegexWalks;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;

final class RegexWalksTest extends MonkeyTestCase {

	public function test_each_target_set_and_restriction_gets_the_walk_regex_layer_selects(): void {
		$signatures = FixturePack::signatures(
			'mini',
			NewSignatures::from_array(
				[
					'version' => 20260101001,
					'since'   => 1,
					'regex'   => [ 4 ],
					'literal' => [],
					'hash'    => false,
				]
			)
		);
		$walks      = new RegexWalks( $signatures );
		$sets       = [
			FileScanner::targets_for( 'php', 'a.php' ),
			FileScanner::targets_for( 'js', 'a.js' ),
			FileScanner::targets_for( 'other', '.htaccess' ),
			[ 'file_php', 'file_any' ],
		];

		foreach ( [ false, true, false ] as $only_new ) {
			foreach ( $sets as $targets ) {
				$this->assertSame(
					RegexLayer::indexes_for( $signatures, $targets, $only_new ),
					$walks->indexes( $targets, $only_new ),
					implode( ',', $targets ) . ( $only_new ? ' (new only)' : '' )
				);
			}
		}

		$this->assertSame( [ 4 ], $walks->indexes( FileScanner::targets_for( 'php', 'a.php' ), true ), 'the restricted walk is kept apart from the full one' );
	}
}
