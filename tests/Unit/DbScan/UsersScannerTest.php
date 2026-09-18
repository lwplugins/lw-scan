<?php
/**
 * Tests for DbScan\UsersScanner.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\DbScan;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\DbScan\UsersScanner;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use Mockery;

require_once __DIR__ . '/../Db/FakeWpdb.php';

final class UsersScannerTest extends MonkeyTestCase {

	public function test_first_run_only_seeds_the_baseline_without_raising_findings(): void {
		$wpdb                  = new \wpdb();
		$wpdb->results_queue[] = [ [ 'user_id' => '5' ] ];

		Functions\when( 'get_option' )->justReturn( [] );
		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				static function ( $option, $value, $autoload ) {
					return State::OPTION_NAME === $option
						&& [ 'admin_user_ids' => [ 5 ] ] === $value
						&& false === $autoload;
				}
			)
			->andReturn( true );

		$findings = Mockery::mock( FindingsRepositoryInterface::class );
		$findings->shouldNotReceive( 'upsert' );

		$scanner = new UsersScanner( $wpdb, $findings );

		$this->assertSame(
			[
				'users'      => 1,
				'new_admins' => 0,
			],
			$scanner->scan()
		);
	}

	public function test_a_new_admin_id_not_in_the_stored_baseline_raises_a_finding(): void {
		$wpdb                  = new \wpdb();
		$wpdb->results_queue[] = [ [ 'user_id' => '5' ], [ 'user_id' => '9' ] ];
		$wpdb->row_queue[]     = [
			'user_login'      => 'evil',
			'user_registered' => '2026-01-01 00:00:00',
		];

		Functions\when( 'get_option' )->justReturn( [ 'admin_user_ids' => [ 5 ] ] );
		Functions\when( 'update_option' )->justReturn( true );

		$findings = Mockery::mock( FindingsRepositoryInterface::class );
		$findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					static function ( Finding $finding ): bool {
						return 'db' === $finding->type
							&& 'users:capabilities:9' === $finding->locator
							&& 'suspicious' === $finding->tier
							&& 'credential' === $finding->category
							&& 'review' === $finding->severity
							&& 'New administrator account: evil (registered 2026-01-01 00:00:00)' === $finding->reason;
					}
				)
			)
			->andReturn(
				[
					'id'      => 2,
					'created' => true,
					'changed' => true,
				]
			);

		$scanner = new UsersScanner( $wpdb, $findings );

		$this->assertSame(
			[
				'users'      => 2,
				'new_admins' => 1,
			],
			$scanner->scan()
		);
	}

	public function test_an_admin_id_that_disappears_raises_no_finding(): void {
		$wpdb                  = new \wpdb();
		$wpdb->results_queue[] = [ [ 'user_id' => '5' ] ];

		Functions\when( 'get_option' )->justReturn( [ 'admin_user_ids' => [ 5, 9 ] ] );
		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				static function ( $option, $value, $autoload ) {
					return [ 'admin_user_ids' => [ 5 ] ] === $value;
				}
			)
			->andReturn( true );

		$findings = Mockery::mock( FindingsRepositoryInterface::class );
		$findings->shouldNotReceive( 'upsert' );

		$scanner = new UsersScanner( $wpdb, $findings );

		$this->assertSame(
			[
				'users'      => 1,
				'new_admins' => 0,
			],
			$scanner->scan(),
			'an admin row that was examined counts even when nothing came of it'
		);
	}
}
