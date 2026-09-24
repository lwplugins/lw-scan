<?php
/**
 * Tests for Rest\SettingsInput.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Rest\SettingsInput;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * A partial save must leave every key the client did not send exactly as
 * stored — above all the two lists the sanitizer would otherwise read as
 * empty.
 */
final class SettingsInputTest extends MonkeyTestCase {

	/** @var array<string, mixed> The stored `lw_scan_options` row. */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();

		$this->stored = [
			'schedule'       => 'weekly',
			'excluded_paths' => [ 'wp-content/cache', 'wp-content/big-backups' ],
			'notify_emails'  => [ 'ops@example.com', 'dev@example.com' ],
			'notify_enabled' => true,
			'notify_level'   => 'review',
			'heuristics'     => false,
			'next_due'       => 1790000000,
			'plugin_version' => '1.3.0',
		];

		$stored = &$this->stored;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$stored ) {
				return Options::OPTION_NAME === $name ? $stored : $fallback;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'sanitize_key' )->alias( static fn ( $value ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ) );
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'is_email' )->alias( static fn ( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false );
	}

	public function test_unsent_lists_keep_their_stored_values(): void {
		$saved = SettingsInput::sanitize( [ 'schedule' => 'daily' ] );

		$this->assertSame( 'daily', $saved['schedule'] );
		$this->assertSame( [ 'wp-content/cache', 'wp-content/big-backups' ], $saved['excluded_paths'] );
		$this->assertSame( [ 'ops@example.com', 'dev@example.com' ], $saved['notify_emails'] );
	}

	public function test_unsent_switches_and_bookkeeping_keep_their_stored_values(): void {
		$saved = SettingsInput::sanitize( [ 'notify_limit' => 50 ] );

		$this->assertFalse( $saved['heuristics'] );
		$this->assertTrue( $saved['notify_enabled'] );
		$this->assertSame( 'review', $saved['notify_level'] );
		$this->assertSame( 1790000000, $saved['next_due'] );
		$this->assertSame( '1.3.0', $saved['plugin_version'] );
		$this->assertSame( 50, $saved['notify_limit'] );
	}

	public function test_sent_lists_replace_the_stored_ones(): void {
		$saved = SettingsInput::sanitize(
			[
				'excluded_paths' => [ '/wp-content/tmp/', '../etc' ],
				'notify_emails'  => [ 'new@example.com', 'not-an-address' ],
			]
		);

		$this->assertSame( [ 'wp-content/tmp' ], $saved['excluded_paths'] );
		$this->assertSame( [ 'new@example.com' ], $saved['notify_emails'] );
	}

	public function test_notify_send_off_switches_mail_off_and_remembers_the_level(): void {
		$saved = SettingsInput::sanitize( [ 'notify_send' => 'off' ] );

		$this->assertFalse( $saved['notify_enabled'] );
		$this->assertSame( 'review', $saved['notify_level'] );
		$this->assertArrayNotHasKey( 'notify_send', $saved );
	}

	public function test_notify_send_alert_switches_mail_on_at_that_level(): void {
		$this->stored['notify_enabled'] = false;

		$saved = SettingsInput::sanitize( [ 'notify_send' => 'alert' ] );

		$this->assertTrue( $saved['notify_enabled'] );
		$this->assertSame( 'alert', $saved['notify_level'] );
	}

	public function test_merge_ignores_non_editable_keys_and_nulls(): void {
		$merged = SettingsInput::merge(
			[
				'next_due'       => 5,
				'excluded_paths' => [ 'a' ],
			],
			[
				'next_due'       => 99,
				'plugin_version' => '9.9.9',
				'excluded_paths' => null,
				'bogus'          => 'x',
			]
		);

		$this->assertSame(
			[
				'next_due'       => 5,
				'excluded_paths' => [ 'a' ],
			],
			$merged
		);
	}
}
