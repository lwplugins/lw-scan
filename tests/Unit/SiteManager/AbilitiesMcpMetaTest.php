<?php
/**
 * Tests that the abilities opt in to LW Site Manager's MCP discovery.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\SiteManager;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\SiteManager\Abilities;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * Regression: the abilities carried show_in_rest but no `mcp` meta, so LW
 * Site Manager's MCP server (which only auto-exposes site-manager/*) never
 * listed them; they were reachable through REST only.
 *
 * @covers \LightweightPlugins\Scan\SiteManager\Abilities
 */
final class AbilitiesMcpMetaTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Abilities::reset();
	}

	protected function tearDown(): void {
		Abilities::reset();
		parent::tearDown();
	}

	/**
	 * Registers the abilities and returns their registration args.
	 *
	 * @return array<string, array<string, mixed>> Registration args by ability name.
	 */
	private function registered(): array {
		$registered = [];

		Functions\when( 'wp_register_ability' )->alias(
			static function ( string $name, array $args ) use ( &$registered ): void {
				$registered[ $name ] = $args;
			}
		);

		Abilities::register_abilities();

		return $registered;
	}

	public function test_every_ability_is_a_public_mcp_tool(): void {
		$registered = $this->registered();

		$this->assertSame(
			[ 'lw-scan/run', 'lw-scan/status', 'lw-scan/findings', 'lw-scan/acknowledge' ],
			array_keys( $registered )
		);

		foreach ( $registered as $name => $args ) {
			$this->assertSame(
				[
					'public' => true,
					'type'   => 'tool',
				],
				$args['meta']['mcp'] ?? null,
				$name
			);
			$this->assertTrue( $args['meta']['show_in_rest'], $name );
		}
	}

	public function test_mcp_exposure_does_not_bypass_the_permission_callbacks(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		foreach ( $this->registered() as $name => $args ) {
			$this->assertFalse( (bool) call_user_func( $args['permission_callback'] ), $name );
		}
	}
}
