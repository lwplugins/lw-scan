<?php
/**
 * Tests for Notify\FailureStreak.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Notify;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Notify\FailureStreak;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class FailureStreakTest extends MonkeyTestCase {

	/** @var array<string, mixed> In-memory stand-in for the options/state tables. */
	private array $option_store = [];

	protected function setUp(): void {
		parent::setUp();

		// The failure-streak subject goes through __() now that it is
		// translatable, and Run\RunError's sentences always did.
		Functions\stubTranslationFunctions();

		$this->option_store = [];
		$store               = &$this->option_store;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$store ) {
				unset( $store[ $name ] );

				return true;
			}
		);

		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Site' );
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
	}

	public function test_record_failure_does_not_notify_before_the_third_failure(): void {
		Functions\expect( 'wp_mail' )->never();

		FailureStreak::record_failure( 'timeout' );
		$this->assertSame( 1, State::get( 'failure_streak' ) );

		FailureStreak::record_failure( 'timeout' );
		$this->assertSame( 2, State::get( 'failure_streak' ) );

		$this->assertFalse( (bool) State::get( 'failure_notified', false ) );
	}

	public function test_record_failure_notifies_on_the_third_consecutive_failure(): void {
		Functions\expect( 'wp_mail' )->once()->andReturn( true );

		$this->option_store['lw_scan_options'] = [ 'notify_emails' => [ 'a@example.test' ] ];

		FailureStreak::record_failure( 'e1' );
		FailureStreak::record_failure( 'e2' );
		FailureStreak::record_failure( 'e3' );

		$this->assertSame( 3, State::get( 'failure_streak' ) );
		$this->assertTrue( (bool) State::get( 'failure_notified' ) );
	}

	public function test_record_failure_does_not_notify_again_after_the_flag_is_set(): void {
		Functions\expect( 'wp_mail' )->once()->andReturn( true );

		$this->option_store['lw_scan_options'] = [ 'notify_emails' => [ 'a@example.test' ] ];

		FailureStreak::record_failure( 'e1' );
		FailureStreak::record_failure( 'e2' );
		FailureStreak::record_failure( 'e3' );
		FailureStreak::record_failure( 'e4' );

		$this->assertSame( 4, State::get( 'failure_streak' ) );
	}

	public function test_record_success_resets_streak_and_notified_flag(): void {
		Functions\when( 'wp_mail' )->justReturn( true );

		$this->option_store['lw_scan_options'] = [ 'notify_emails' => [ 'a@example.test' ] ];

		FailureStreak::record_failure( 'e1' );
		FailureStreak::record_failure( 'e2' );
		FailureStreak::record_failure( 'e3' );

		FailureStreak::record_success();

		$this->assertSame( 0, State::get( 'failure_streak' ) );
		$this->assertFalse( (bool) State::get( 'failure_notified' ) );
	}
}
