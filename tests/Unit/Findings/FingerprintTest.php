<?php
/**
 * Tests for Fingerprint::of().
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Findings;

use LightweightPlugins\Scan\Findings\Fingerprint;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class FingerprintTest extends MonkeyTestCase {

	public function test_is_deterministic_for_the_same_input(): void {
		$this->assertSame(
			Fingerprint::of( 'file', 'wp-content/plugins/x/x.php' ),
			Fingerprint::of( 'file', 'wp-content/plugins/x/x.php' )
		);
	}

	public function test_differs_by_type_for_the_same_locator(): void {
		$this->assertNotSame(
			Fingerprint::of( 'file', 'core:wordpress' ),
			Fingerprint::of( 'vulnerability', 'core:wordpress' )
		);
	}

	public function test_matches_the_sha256_of_type_pipe_locator(): void {
		$this->assertSame(
			hash( 'sha256', 'file|a.php' ),
			Fingerprint::of( 'file', 'a.php' )
		);
	}
}
