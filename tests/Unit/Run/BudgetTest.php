<?php
/**
 * Tests for Run\Budget.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Run\Budget;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * The memory floor, the pre-flight headroom check and everything measuring
 * the compiled/pack bundle are gone (Task 12): a run that runs out of
 * memory now fails plainly instead of being asked around. What is left of
 * `Run\Budget` is the per-tick time budget and the in-process cache flush.
 *
 * Note for whoever adds a case here: `seconds()` reads `WP_CLI`, a constant
 * `Tests\Unit\Run\CatchUpTest` defines `true` and which — once defined —
 * cannot be undefined for the rest of the process. This class sorts before
 * `CatchUpTest` alphabetically, so it must stay that way: a `seconds()` test
 * added after that constant exists would only ever see the CLI branch.
 */
final class BudgetTest extends MonkeyTestCase {

	/** @var string The process's own max_execution_time, restored after each test. */
	private string $max_execution_time;

	protected function setUp(): void {
		parent::setUp();

		$original                 = ini_get( 'max_execution_time' );
		$this->max_execution_time = false === $original ? '30' : $original;
	}

	protected function tearDown(): void {
		ini_set( 'max_execution_time', $this->max_execution_time );
		parent::tearDown();
	}

	public function test_seconds_is_derived_from_max_execution_time(): void {
		ini_set( 'max_execution_time', '25' );

		$this->assertSame( 15.0, Budget::seconds() );
	}

	public function test_seconds_is_clamped_to_the_floor(): void {
		ini_set( 'max_execution_time', '12' );

		$this->assertSame( 5.0, Budget::seconds() );
	}

	public function test_seconds_is_clamped_to_the_ceiling(): void {
		ini_set( 'max_execution_time', '120' );

		$this->assertSame( 20.0, Budget::seconds() );
	}

	public function test_seconds_defaults_to_the_ceiling_when_the_ini_value_is_unlimited(): void {
		ini_set( 'max_execution_time', '0' );

		$this->assertSame( 20.0, Budget::seconds() );
	}

	public function test_free_memory_flushes_the_internal_cache_without_an_external_object_cache(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\expect( 'wp_cache_flush' )->once();

		Budget::free_memory();
	}

	public function test_free_memory_leaves_an_external_object_cache_alone(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\expect( 'wp_cache_flush' )->never();

		Budget::free_memory();
	}
}
