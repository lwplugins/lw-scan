<?php
/**
 * Tests for Run\Phases.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class PhasesTest extends MonkeyTestCase {

	public function test_all_lists_every_phase_in_pipeline_order(): void {
		$this->assertSame(
			[ 'bundle', 'index', 'hash', 'files', 'db', 'vuln', 'finalize' ],
			Phases::ALL
		);
	}

	public function test_full_scope_runs_every_phase(): void {
		$this->assertSame( Phases::ALL, Phases::for_scope( 'full' ) );
	}

	public function test_changed_scope_runs_every_phase(): void {
		$this->assertSame( Phases::ALL, Phases::for_scope( 'changed' ) );
	}

	public function test_db_scope_skips_file_phases(): void {
		$this->assertSame(
			[ 'bundle', 'db', 'vuln', 'finalize' ],
			Phases::for_scope( 'db' )
		);
	}

	public function test_path_scope_skips_db_and_vuln_phases(): void {
		$this->assertSame(
			[ 'bundle', 'index', 'hash', 'files', 'finalize' ],
			Phases::for_scope( 'path' )
		);
	}

	public function test_for_scope_returns_empty_for_unknown_scope(): void {
		$this->assertSame( [], Phases::for_scope( 'bogus' ) );
	}

	/**
	 * @dataProvider provide_valid_scopes
	 */
	public function test_valid_scope_accepts_known_scopes( string $scope ): void {
		$this->assertTrue( Phases::valid_scope( $scope ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function provide_valid_scopes(): array {
		return [
			'full'    => [ 'full' ],
			'changed' => [ 'changed' ],
			'db'      => [ 'db' ],
			'path'    => [ 'path' ],
		];
	}

	public function test_valid_scope_rejects_unknown_scope(): void {
		$this->assertFalse( Phases::valid_scope( 'bogus' ) );
	}
}
