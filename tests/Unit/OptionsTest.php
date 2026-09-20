<?php
/**
 * Tests for Options.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit;

use Brain\Monkey\Expectation\Expectation;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Options;

final class OptionsTest extends MonkeyTestCase {

	public function test_defaults_contain_every_key(): void {
		$defaults = Options::get_defaults();

		$this->assertSame(
			[
				'schedule',
				'schedule_hour',
				'scope',
				'bundle_auto_update',
				'heuristics',
				'max_file_size',
				'excluded_paths',
				'follow_symlinks',
				'notify_enabled',
				'notify_emails',
				'notify_level',
				'notify_limit',
				'admin_notice',
				'next_due',
				'last_auto_run',
				'plugin_version',
			],
			array_keys( $defaults )
		);

		$this->assertSame( 'daily', $defaults['schedule'] );
		$this->assertSame( 3, $defaults['schedule_hour'] );
		$this->assertSame( 'changed', $defaults['scope'] );
		$this->assertTrue( $defaults['bundle_auto_update'] );
		$this->assertTrue( $defaults['heuristics'] );
		$this->assertSame( 2097152, $defaults['max_file_size'] );
		$this->assertSame(
			[
				'wp-content/cache',
				'wp-content/backup*',
				'wp-content/upgrade',
				'wp-content/upgrade-temp-backup',
				'wp-content/uploads/lw-img-backups',
				'wp-content/ai1wm-backups',
				'wp-content/updraft',
			],
			$defaults['excluded_paths']
		);
		$this->assertFalse( $defaults['follow_symlinks'] );
		$this->assertTrue( $defaults['notify_enabled'] );
		$this->assertSame( [], $defaults['notify_emails'] );
		$this->assertSame( 'alert', $defaults['notify_level'] );
		$this->assertSame( 20, $defaults['notify_limit'] );
		$this->assertTrue( $defaults['admin_notice'] );
		$this->assertSame( 0, $defaults['next_due'] );
		$this->assertSame( 0, $defaults['last_auto_run'] );
	}

	public function test_get_merges_stored_over_defaults(): void {
		Functions\expect( 'get_option' )->andReturn( [ 'scope' => 'full' ] );

		$this->assertSame( 'full', Options::get( 'scope' ) );
		$this->assertSame( 'daily', Options::get( 'schedule' ) );
	}

	public function test_excluded_paths_normalizes_and_adds_storage(): void {
		$this->stub_storage_dir( ABSPATH . 'wp-content/lw-scan' );
		Functions\expect( 'get_option' )->andReturn(
			[
				'excluded_paths' => [
					' /wp-content/cache/ ',
					"wp-content\\backup*",
					'wp-content/cache',
				],
			]
		);

		$this->assertSame(
			[
				'wp-content/cache',
				'wp-content/backup*',
				'wp-content/lw-scan',
			],
			Options::excluded_paths()
		);
	}

	public function test_excluded_paths_follows_a_filtered_storage_directory(): void {
		// Not a hardcoded `wp-content/lw-scan`: a site that moves the storage
		// directory would otherwise have the signature bundle scanned as
		// content, and exclude a directory that is not there.
		$this->stub_storage_dir( ABSPATH . 'wp-content/uploads/private/scan-store' );
		Functions\expect( 'get_option' )->andReturn( [ 'excluded_paths' => [] ] );

		$this->assertSame( [ 'wp-content/uploads/private/scan-store' ], Options::excluded_paths() );
	}

	/**
	 * @param string $absolute What the `lw_scan_storage_dir` filter answers.
	 */
	private function stub_storage_dir( string $absolute ): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		Filters\expectApplied( 'lw_scan_storage_dir' )->zeroOrMoreTimes()->andReturn( $absolute );
	}

	public function test_update_merges_and_saves(): void {
		Functions\expect( 'get_option' )->andReturn( [ 'scope' => 'full' ] );

		$expected = array_merge( Options::get_defaults(), [ 'scope' => 'full', 'schedule_hour' => 5 ] );

		Functions\expect( 'update_option' )
			->once()
			->with( Options::OPTION_NAME, $expected, true )
			->andReturn( true );

		Options::update( [ 'schedule_hour' => 5 ] );
	}

	public function test_all_runs_the_options_through_the_lw_scan_options_filter(): void {
		Functions\expect( 'get_option' )->andReturn( [] );
		$this->expect_heuristics_filter()->once();

		$this->assertFalse( Options::all()['heuristics'] );
	}

	public function test_update_saves_the_stored_values_not_the_filtered_ones(): void {
		Functions\expect( 'get_option' )->andReturn( [] );
		$this->expect_heuristics_filter()->zeroOrMoreTimes();

		$saved = [];
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing(
				static function ( string $name, array $value ) use ( &$saved ): bool {
					$saved = $value;

					return true;
				}
			);

		Options::update( [ 'next_due' => 123 ] );

		$this->assertTrue( $saved['heuristics'] );
		$this->assertSame( 123, $saved['next_due'] );
	}

	/**
	 * A runtime `lw_scan_options` filter that forces heuristics off — the
	 * one `wp lw-scan run --no-heuristics` installs.
	 *
	 * @return Expectation
	 */
	private function expect_heuristics_filter(): Expectation {
		return Filters\expectApplied( 'lw_scan_options' )->andReturnUsing(
			static function ( array $options ): array {
				$options['heuristics'] = false;

				return $options;
			}
		);
	}
}
