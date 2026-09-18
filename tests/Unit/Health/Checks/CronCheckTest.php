<?php
/**
 * Tests for Health\Checks\CronCheck.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Health\Checks;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Health\Checks\CronCheck;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class CronCheckTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_id_and_label(): void {
		$check = new CronCheck();

		$this->assertSame( 'cron', $check->id() );
		$this->assertSame( 'WP-Cron', $check->label() );
	}

	public function test_probe_false_never_calls_wp_remote_post(): void {
		Functions\expect( 'wp_remote_post' )->never();

		$result = ( new CronCheck( false ) )->run();

		$this->assertSame( 'info', $result['status'] );
		$this->assertFalse( $result['blocking'] );
	}

	public function test_probe_false_reports_built_in_cron_when_disable_wp_cron_is_not_set(): void {
		Functions\expect( 'wp_remote_post' )->never();

		$result = ( new CronCheck( false ) )->run();

		$this->assertSame( 'Built-in WP-Cron (triggered by site visits).', $result['message'] );
	}

	#[RunInSeparateProcess]
	public function test_probe_false_reports_disable_wp_cron_when_set_and_still_skips_the_probe(): void {
		define( 'DISABLE_WP_CRON', true );

		Functions\expect( 'wp_remote_post' )->never();

		$result = ( new CronCheck( false ) )->run();

		$this->assertSame( 'info', $result['status'] );
		$this->assertStringContainsString( 'DISABLE_WP_CRON is set', $result['message'] );
	}

	public function test_probe_true_calls_wp_remote_post_once_and_reports_ok_on_success(): void {
		Functions\when( 'site_url' )->justReturn( 'https://example.test/wp-cron.php' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$result = ( new CronCheck( true ) )->run();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertFalse( $result['blocking'] );
	}

	public function test_probe_true_reports_warning_when_the_server_cannot_reach_itself(): void {
		Functions\when( 'site_url' )->justReturn( 'https://example.test/wp-cron.php' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );

		$result = ( new CronCheck( true ) )->run();

		$this->assertSame( 'warning', $result['status'] );
		$this->assertFalse( $result['blocking'] );
	}
}
