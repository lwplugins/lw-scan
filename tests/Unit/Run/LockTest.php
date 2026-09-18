<?php
/**
 * Tests for Run\Lock.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Run\Lock;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class LockTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Lock::reset();
		parent::tearDown();
	}

	public function test_acquire_returns_true_when_get_lock_succeeds(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ '1' ] );

		$this->assertTrue( Lock::acquire() );

		$this->assertSame( [ 'SELECT GET_LOCK(%s, 0)' ], $GLOBALS['wpdb']->prepared_queries );
		$this->assertSame( [ 'lw_scan_run_1' ], $GLOBALS['wpdb']->prepared_args[0] );
	}

	public function test_acquire_returns_false_when_get_lock_is_held_elsewhere(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ '0' ] );

		$this->assertFalse( Lock::acquire() );
	}

	public function test_acquire_falls_back_to_transient_when_get_lock_is_unsupported(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ null ] );

		Functions\expect( 'get_transient' )->once()->with( 'lw_scan_lock' )->andReturn( false );
		Functions\expect( 'set_transient' )->once()->with( 'lw_scan_lock', \Mockery::type( 'string' ), 90 )->andReturn( true );

		$this->assertTrue( Lock::acquire() );
	}

	public function test_acquire_transient_fallback_fails_when_transient_already_set(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ null ] );

		Functions\expect( 'get_transient' )->once()->with( 'lw_scan_lock' )->andReturn( 1 );
		Functions\expect( 'set_transient' )->never();

		$this->assertFalse( Lock::acquire() );
	}

	public function test_release_issues_release_lock_after_a_successful_mysql_acquire(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ '1', '1' ] );

		Functions\expect( 'delete_transient' )->never();

		$this->assertTrue( Lock::acquire() );

		Lock::release();

		$this->assertSame(
			[ 'SELECT GET_LOCK(%s, 0)', 'SELECT RELEASE_LOCK(%s)' ],
			$GLOBALS['wpdb']->prepared_queries
		);
	}

	public function test_release_deletes_the_transient_after_a_successful_transient_acquire(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ null ] );

		$stored_token = null;

		Functions\expect( 'get_transient' )
			->with( 'lw_scan_lock' )
			->andReturnUsing(
				static function () use ( &$stored_token ) {
					return null === $stored_token ? false : $stored_token;
				}
			);

		Functions\expect( 'set_transient' )
			->once()
			->withArgs(
				static function ( $name, $value, $ttl ) use ( &$stored_token ) {
					$stored_token = $value;

					return 'lw_scan_lock' === $name && 90 === $ttl;
				}
			)
			->andReturn( true );

		Functions\expect( 'delete_transient' )->once()->with( 'lw_scan_lock' );

		$this->assertTrue( Lock::acquire() );

		Lock::release();
	}

	public function test_release_after_a_failed_transient_acquire_does_not_delete_another_holders_lock(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ null ] );

		Functions\expect( 'get_transient' )->with( 'lw_scan_lock' )->andReturn( 'someone-elses-token' );
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_transient' )->never();

		$this->assertFalse( Lock::acquire() );

		Lock::release();
	}

	public function test_release_without_a_prior_acquire_issues_no_queries_or_transient_calls(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [] );

		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'delete_transient' )->never();

		Lock::release();

		$this->assertSame( [], $GLOBALS['wpdb']->prepared_queries );
	}

	public function test_held_elsewhere_is_true_for_a_different_connection(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ '5', '9' ] );

		$this->assertTrue( Lock::held_elsewhere() );
	}

	public function test_held_elsewhere_is_false_for_our_own_connection(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ '9', '9' ] );

		Functions\expect( 'get_transient' )->once()->with( 'lw_scan_lock' )->andReturn( false );

		$this->assertFalse( Lock::held_elsewhere() );
	}

	public function test_held_elsewhere_falls_back_to_the_transient_when_unsupported(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ null ] );

		Functions\expect( 'get_transient' )->once()->with( 'lw_scan_lock' )->andReturn( 1 );

		$this->assertTrue( Lock::held_elsewhere() );
	}

	public function test_held_elsewhere_is_false_when_free_and_no_transient(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( [ null ] );

		Functions\expect( 'get_transient' )->once()->with( 'lw_scan_lock' )->andReturn( false );

		$this->assertFalse( Lock::held_elsewhere() );
	}
}
