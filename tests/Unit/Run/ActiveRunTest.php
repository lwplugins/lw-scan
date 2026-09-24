<?php
/**
 * Tests for Run\ActiveRun::run_active().
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use LightweightPlugins\Scan\Run\ActiveRun;
use LightweightPlugins\Scan\Run\Cursor;
use PHPUnit\Framework\TestCase;

/**
 * Pure predicate behind the REST and CLI busy guards: no WP
 * functions, no DB access, so this runs against plain PHPUnit.
 */
final class ActiveRunTest extends TestCase {

	public function test_no_cursor_is_not_active(): void {
		$this->assertFalse( ActiveRun::run_active( null ) );
	}

	public function test_a_fresh_running_cursor_is_active(): void {
		$cursor = Cursor::fresh( 7, 'changed', '', [ 'bundle', 'index', 'finalize' ] );

		$this->assertTrue( ActiveRun::run_active( $cursor ) );
	}

	public function test_a_stopped_but_resumable_cursor_is_still_active(): void {
		$cursor = Cursor::fresh( 7, 'changed', '', [ 'bundle', 'index', 'finalize' ] );
		$cursor->set( 'stop_requested', true );

		$this->assertTrue( ActiveRun::run_active( $cursor ) );
	}
}
