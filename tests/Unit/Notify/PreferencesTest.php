<?php
/**
 * Tests for Notify\Preferences.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Notify;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Notify\Preferences;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class PreferencesTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'get_option' )->justReturn( 'admin@example.test' );
	}

	public function test_an_options_array_without_the_notify_keys_reads_the_defaults(): void {
		$prefs = new Preferences( [ 'notify_level' => 'review' ] );

		$this->assertFalse( $prefs->enabled(), 'A missing notify_enabled reads as the default, which is off.' );
		$this->assertSame( 'review', $prefs->level() );
		$this->assertSame( 20, $prefs->limit() );
	}

	public function test_enabled_is_false_when_the_switch_is_off(): void {
		$this->assertFalse( ( new Preferences( [ 'notify_enabled' => false ] ) )->enabled() );
		$this->assertFalse( ( new Preferences( [ 'notify_enabled' => '' ] ) )->enabled() );
		$this->assertFalse( ( new Preferences( [ 'notify_enabled' => '0' ] ) )->enabled() );
	}

	public function test_level_falls_back_to_alert_for_anything_unknown(): void {
		$this->assertSame( 'alert', ( new Preferences( [] ) )->level() );
		$this->assertSame( 'alert', ( new Preferences( [ 'notify_level' => 'everything' ] ) )->level() );
		$this->assertSame( 'alert', ( new Preferences( [ 'notify_level' => 'off' ] ) )->level() );
	}

	public function test_limit_accepts_only_the_offered_caps(): void {
		$this->assertSame( 10, ( new Preferences( [ 'notify_limit' => 10 ] ) )->limit() );
		$this->assertSame( 50, ( new Preferences( [ 'notify_limit' => '50' ] ) )->limit() );
		$this->assertSame( 0, ( new Preferences( [ 'notify_limit' => 0 ] ) )->limit(), '0 is the "All" choice.' );
		$this->assertSame( 20, ( new Preferences( [ 'notify_limit' => 33 ] ) )->limit() );
		$this->assertSame( 20, ( new Preferences( [ 'notify_limit' => -5 ] ) )->limit() );
	}

	public function test_recipients_use_the_configured_list(): void {
		$prefs = new Preferences( [ 'notify_emails' => [ 'ops@example.test', 'dev@example.test' ] ] );

		$this->assertSame( [ 'ops@example.test', 'dev@example.test' ], $prefs->recipients() );
		$this->assertFalse( $prefs->uses_admin_email() );
	}

	public function test_recipients_fall_back_to_the_site_admin_address(): void {
		$prefs = new Preferences( [ 'notify_emails' => [] ] );

		$this->assertSame( [ 'admin@example.test' ], $prefs->recipients() );
		$this->assertTrue( $prefs->uses_admin_email() );
		$this->assertSame( 'admin@example.test', $prefs->admin_email() );
	}

	public function test_recipients_drop_invalid_addresses_and_duplicates(): void {
		$prefs = new Preferences(
			[
				'notify_emails' => [ 'ops@example.test', 'not-an-email', 'ops@example.test' ],
			]
		);

		$this->assertSame( [ 'ops@example.test' ], $prefs->recipients() );
	}

	public function test_recipients_are_empty_when_the_list_holds_nothing_usable_and_there_is_no_admin_email(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( [], ( new Preferences( [ 'notify_emails' => [ 'nonsense' ] ] ) )->recipients() );
		$this->assertSame( [], ( new Preferences( [] ) )->recipients() );
	}

	public function test_a_non_array_recipient_option_is_treated_as_empty(): void {
		$prefs = new Preferences( [ 'notify_emails' => 'ops@example.test' ] );

		$this->assertTrue( $prefs->uses_admin_email() );
		$this->assertSame( [ 'admin@example.test' ], $prefs->recipients() );
	}
}
