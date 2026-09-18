<?php
/**
 * Tests for Run\Cursor.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class CursorTest extends MonkeyTestCase {

	private const PHASES = [ 'bundle', 'index', 'hash', 'files', 'finalize' ];

	public function test_fresh_starts_on_the_first_phase_of_the_given_list(): void {
		$cursor = Cursor::fresh( 7, 'path', 'wp-content/plugins/x', self::PHASES );

		$this->assertSame( 7, $cursor->run_id() );
		$this->assertSame( 'path', $cursor->scope() );
		$this->assertSame( 'wp-content/plugins/x', $cursor->path() );
		$this->assertSame( 'bundle', $cursor->phase() );
	}

	public function test_fresh_defaults_stop_requested_to_false(): void {
		$cursor = Cursor::fresh( 1, 'full', '', self::PHASES );

		$this->assertFalse( $cursor->stop_requested() );
	}

	public function test_fresh_records_a_started_at_timestamp(): void {
		$before = time();
		$cursor = Cursor::fresh( 1, 'full', '', self::PHASES );
		$after  = time();

		$this->assertGreaterThanOrEqual( $before, $cursor->started_at() );
		$this->assertLessThanOrEqual( $after, $cursor->started_at() );
	}

	public function test_next_phase_walks_the_stored_phase_list_in_order(): void {
		$cursor = Cursor::fresh( 1, 'path', '', self::PHASES );

		$this->assertSame( 'index', $cursor->next_phase() );
		$this->assertSame( 'index', $cursor->phase() );

		$this->assertSame( 'hash', $cursor->next_phase() );
		$this->assertSame( 'files', $cursor->next_phase() );
		$this->assertSame( 'finalize', $cursor->next_phase() );
	}

	public function test_next_phase_returns_null_once_the_list_is_exhausted(): void {
		$cursor = Cursor::fresh( 1, 'path', '', self::PHASES );

		$cursor->next_phase();
		$cursor->next_phase();
		$cursor->next_phase();
		$cursor->next_phase();

		$this->assertSame( 'finalize', $cursor->phase() );
		$this->assertNull( $cursor->next_phase() );
		$this->assertSame( 'finalize', $cursor->phase(), 'phase must not change once the list is exhausted' );
	}

	public function test_set_phase_resets_the_phase_specific_subcursors(): void {
		$cursor = Cursor::fresh( 1, 'full', '', self::PHASES );

		$cursor->set( 'file_id', 42 );
		$cursor->set( 'chunk_index', 3 );
		$cursor->set( 'regex_index', 9 );
		$cursor->set( 'resume', [ 'chunk' => 3 ] );
		$cursor->set( 'db', [ 'scanner' => 'options', 'last_id' => 500 ] );
		$cursor->set( 'vuln_index', 2 );

		$cursor->set_phase( 'hash' );

		$this->assertSame( 'hash', $cursor->phase() );
		$this->assertNull( $cursor->get( 'file_id' ) );
		$this->assertSame( 0, $cursor->get( 'chunk_index' ) );
		$this->assertSame( 0, $cursor->get( 'regex_index' ) );
		$this->assertSame( [], $cursor->get( 'resume' ) );
		$this->assertSame( [ 'scanner' => null, 'last_id' => 0 ], $cursor->get( 'db' ) );
		$this->assertSame( 0, $cursor->get( 'vuln_index' ) );
	}

	public function test_set_phase_does_not_disturb_cross_phase_fields(): void {
		$cursor = Cursor::fresh( 1, 'full', '', self::PHASES );

		$cursor->set( 'hash_last_id', 100 );
		$cursor->set( 'index_done', true );
		$cursor->set( 'files_total', 50 );
		$cursor->set( 'files_done', 20 );

		$cursor->set_phase( 'files' );

		$this->assertSame( 100, $cursor->get( 'hash_last_id' ) );
		$this->assertTrue( $cursor->get( 'index_done' ) );
		$this->assertSame( 50, $cursor->get( 'files_total' ) );
		$this->assertSame( 20, $cursor->get( 'files_done' ) );
	}

	public function test_get_set_are_generic_accessors_with_a_default(): void {
		$cursor = Cursor::fresh( 1, 'full', '', self::PHASES );

		$this->assertSame( 'fallback', $cursor->get( 'unknown_key', 'fallback' ) );

		$cursor->set( 'files_done', 12 );

		$this->assertSame( 12, $cursor->get( 'files_done' ) );
	}

	public function test_save_persists_the_full_array_under_the_run_state_key(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				static function ( $option, $value, $autoload ) {
					return State::OPTION_NAME === $option
						&& false === $autoload
						&& isset( $value['run'] )
						&& 3 === $value['run']['run_id']
						&& 'bundle' === $value['run']['phase'];
				}
			)
			->andReturn( true );

		Cursor::fresh( 3, 'full', '', self::PHASES )->save();
	}

	public function test_load_returns_null_when_no_run_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertNull( Cursor::load() );
	}

	public function test_load_reconstructs_a_cursor_from_stored_state(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'run' => Cursor::fresh( 9, 'db', '', [ 'bundle', 'db', 'finalize' ] )->to_array(),
			]
		);

		$cursor = Cursor::load();

		$this->assertNotNull( $cursor );
		$this->assertSame( 9, $cursor->run_id() );
		$this->assertSame( 'db', $cursor->scope() );
	}

	public function test_clear_removes_the_stored_cursor(): void {
		Functions\when( 'get_option' )->justReturn( [ 'run' => [ 'run_id' => 1 ] ] );

		Functions\expect( 'update_option' )
			->once()
			->with( State::OPTION_NAME, [ 'run' => null ], false )
			->andReturn( true );

		Cursor::clear();
	}
}
