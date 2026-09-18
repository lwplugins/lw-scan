<?php
/**
 * Tests for State.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\State;

final class StateTest extends MonkeyTestCase {

	public function test_get_default_when_missing(): void {
		Functions\expect( 'get_option' )->andReturn( false );

		$this->assertSame( 5, State::get( 'x', 5 ) );
	}

	public function test_set_writes_non_autoloaded(): void {
		Functions\expect( 'get_option' )->andReturn( [] );

		Functions\expect( 'update_option' )
			->once()
			->with( State::OPTION_NAME, [ 'a' => 1 ], false )
			->andReturn( true );

		State::set( 'a', 1 );
	}

	public function test_first_write_adds_option_with_autoload_no(): void {
		Functions\expect( 'get_option' )->andReturn( false );

		Functions\expect( 'add_option' )
			->once()
			->with( State::OPTION_NAME, [ 'a' => 1 ], '', false )
			->andReturn( true );

		State::set( 'a', 1 );
	}
}
