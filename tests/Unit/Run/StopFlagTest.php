<?php
/**
 * Tests for Run\StopFlag.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\StopFlag;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class StopFlagTest extends MonkeyTestCase {

	private const PHASES = [ 'bundle', 'db', 'finalize' ];

	public function test_requested_is_false_when_no_run_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertFalse( StopFlag::requested() );
	}

	public function test_requested_reflects_the_stored_cursor_flag(): void {
		$cursor = Cursor::fresh( 1, 'db', '', self::PHASES );

		Functions\when( 'get_option' )->justReturn( [ 'run' => $cursor->to_array() ] );

		$this->assertFalse( StopFlag::requested() );
	}

	public function test_request_sets_stop_requested_true_on_the_saved_cursor(): void {
		$cursor = Cursor::fresh( 1, 'db', '', self::PHASES );

		Functions\when( 'get_option' )->justReturn( [ 'run' => $cursor->to_array() ] );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				static function ( $option, $value, $autoload ) {
					return State::OPTION_NAME === $option
						&& false === $autoload
						&& true === $value['run']['stop_requested'];
				}
			)
			->andReturn( true );

		StopFlag::request();
	}

	public function test_request_is_a_no_op_when_no_run_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_option' )->never();

		StopFlag::request();
	}

	public function test_clear_sets_stop_requested_false_on_the_saved_cursor(): void {
		$cursor = Cursor::fresh( 1, 'db', '', self::PHASES );
		$cursor->set( 'stop_requested', true );

		Functions\when( 'get_option' )->justReturn( [ 'run' => $cursor->to_array() ] );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				static function ( $option, $value, $autoload ) {
					return State::OPTION_NAME === $option
						&& false === $autoload
						&& false === $value['run']['stop_requested'];
				}
			)
			->andReturn( true );

		StopFlag::clear();
	}

	public function test_clear_is_a_no_op_when_no_run_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_option' )->never();

		StopFlag::clear();
	}
}
