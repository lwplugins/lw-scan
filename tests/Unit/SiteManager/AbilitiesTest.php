<?php
/**
 * Tests for SiteManager\Abilities.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\SiteManager;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use LightweightPlugins\Scan\SiteManager\AbilityCallbacks;
use LightweightPlugins\Scan\SiteManager\Abilities;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class AbilitiesTest extends MonkeyTestCase {

	/** @var array<string, array<string, mixed>> Ability name => registration args. */
	private array $registered = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Abilities::reset();
		$this->registered = [];
	}

	protected function tearDown(): void {
		Abilities::reset();
		parent::tearDown();
	}

	/**
	 * Captures every `wp_register_ability()` call into `$this->registered`.
	 *
	 * @param int $times Expected number of calls.
	 */
	private function capture_registrations( int $times = 4 ): void {
		$captured = &$this->registered;

		Functions\expect( 'wp_register_ability' )
			->times( $times )
			->andReturnUsing(
				static function ( $name, $args ) use ( &$captured ) {
					$captured[ (string) $name ] = (array) $args;

					return null;
				}
			);
	}

	public function test_register_hooks_the_two_abilities_api_init_actions(): void {
		Actions\expectAdded( 'wp_abilities_api_categories_init' )->once();
		Actions\expectAdded( 'wp_abilities_api_init' )->once();

		Abilities::register();
	}

	public function test_register_category_registers_the_lw_scan_category(): void {
		Functions\expect( 'wp_register_ability_category' )
			->once()
			->with( 'lw-scan', \Mockery::type( 'array' ) )
			->andReturn( null );

		Abilities::register_category();
	}

	public function test_register_category_runs_only_once(): void {
		Functions\expect( 'wp_register_ability_category' )->once()->andReturn( null );

		Abilities::register_category();
		Abilities::register_category();
	}

	public function test_register_abilities_registers_the_four_documented_abilities(): void {
		$this->capture_registrations();

		Abilities::register_abilities();

		$this->assertSame(
			[ 'lw-scan/run', 'lw-scan/status', 'lw-scan/findings', 'lw-scan/acknowledge' ],
			array_keys( $this->registered )
		);
	}

	public function test_register_abilities_runs_only_once(): void {
		$this->capture_registrations();

		Abilities::register_abilities();
		Abilities::register_abilities();
	}

	public function test_every_ability_declares_the_required_arguments(): void {
		$this->capture_registrations();

		Abilities::register_abilities();

		foreach ( $this->registered as $name => $args ) {
			foreach ( [ 'label', 'description', 'category', 'execute_callback', 'permission_callback' ] as $key ) {
				$this->assertArrayHasKey( $key, $args, $name . ' is missing ' . $key );
			}

			$this->assertSame( 'lw-scan', $args['category'] );
			$this->assertTrue( $args['meta']['show_in_rest'] );
		}
	}

	public function test_every_ability_executes_through_the_ability_callbacks(): void {
		$this->capture_registrations();

		Abilities::register_abilities();

		foreach ( $this->registered as $name => $args ) {
			$this->assertSame( AbilityCallbacks::class, $args['execute_callback'][0], $name );
			$this->assertTrue( is_callable( $args['execute_callback'] ), $name );
		}
	}

	public function test_read_only_abilities_are_annotated_as_read_only(): void {
		$this->capture_registrations();

		Abilities::register_abilities();

		$this->assertTrue( $this->registered['lw-scan/status']['meta']['annotations']['readonly'] );
		$this->assertTrue( $this->registered['lw-scan/findings']['meta']['annotations']['readonly'] );
		$this->assertFalse( $this->registered['lw-scan/run']['meta']['annotations']['readonly'] );
		$this->assertFalse( $this->registered['lw-scan/acknowledge']['meta']['annotations']['readonly'] );
	}

	public function test_acknowledge_is_annotated_as_a_destructive_but_idempotent_write(): void {
		$this->capture_registrations();

		Abilities::register_abilities();

		$annotations = $this->registered['lw-scan/acknowledge']['meta']['annotations'];

		$this->assertTrue( $annotations['destructive'], 'acknowledge overwrites the state of existing findings' );
		$this->assertTrue( $annotations['idempotent'], 'setting the same state twice changes nothing more' );
	}

	public function test_starting_a_scan_is_annotated_as_neither_destructive_nor_idempotent(): void {
		$this->capture_registrations();

		Abilities::register_abilities();

		$annotations = $this->registered['lw-scan/run']['meta']['annotations'];

		$this->assertFalse( $annotations['destructive'], 'a scan only adds rows, it overwrites nothing' );
		$this->assertFalse( $annotations['idempotent'], 'calling run again opens another scan or is refused as busy' );
	}

	public function test_the_run_input_schema_accepts_the_documented_scopes(): void {
		$this->capture_registrations();

		Abilities::register_abilities();

		$schema = $this->registered['lw-scan/run']['input_schema'];

		$this->assertSame( [ 'scope' ], $schema['required'] );
		$this->assertSame( [ 'full', 'changed', 'db', 'path' ], $schema['properties']['scope']['enum'] );
	}

	public function test_permission_callbacks_refuse_a_user_without_manage_options(): void {
		Functions\expect( 'current_user_can' )->times( 4 )->with( 'manage_options' )->andReturn( false );
		$this->capture_registrations();

		Abilities::register_abilities();

		foreach ( $this->registered as $name => $args ) {
			$this->assertFalse( ( $args['permission_callback'] )(), $name );
		}
	}

	public function test_permission_callbacks_allow_an_administrator(): void {
		Functions\expect( 'current_user_can' )->times( 4 )->with( 'manage_options' )->andReturn( true );
		$this->capture_registrations();

		Abilities::register_abilities();

		foreach ( $this->registered as $name => $args ) {
			$this->assertTrue( ( $args['permission_callback'] )(), $name );
		}
	}
}
