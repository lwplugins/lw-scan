<?php
/**
 * Tests for Rest\Presenter\SettingsView::fields().
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest\Presenter;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Rest\Presenter\SettingsView;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class SettingsViewTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_email' )->alias( static fn ( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false );
	}

	/**
	 * @dataProvider provide_send_modes
	 */
	public function test_notify_send_combines_the_switch_and_the_level( bool $enabled, string $level, string $expected ): void {
		$stored = array_merge(
			Options::get_defaults(),
			[
				'notify_enabled' => $enabled,
				'notify_level'   => $level,
			]
		);

		$this->assertSame( $expected, SettingsView::fields( $stored )['notify_send'] );
	}

	/**
	 * @return array<string, array{0: bool, 1: string, 2: string}>
	 */
	public static function provide_send_modes(): array {
		return [
			'off keeps no level' => [ false, 'review', 'off' ],
			'alerts only'        => [ true, 'alert', 'alert' ],
			'everything new'     => [ true, 'review', 'review' ],
		];
	}

	public function test_fields_cast_the_stored_values(): void {
		$stored = array_merge(
			Options::get_defaults(),
			[
				'schedule_hour'  => '4',
				'max_file_size'  => '1048576',
				'notify_emails'  => [ 'ops@example.com', 'broken' ],
				'excluded_paths' => [ 'wp-content/cache' ],
				'schedule'       => 'off',
				'next_due'       => 1790000000,
			]
		);

		$fields = SettingsView::fields( $stored );

		$this->assertSame( 4, $fields['schedule_hour'] );
		$this->assertSame( 1048576, $fields['max_file_size'] );
		$this->assertSame( [ 'ops@example.com' ], $fields['notify_emails'] );
		$this->assertSame( [ 'wp-content/cache' ], $fields['excluded_paths'] );
		$this->assertSame( 0, $fields['next_due'] );
		$this->assertArrayNotHasKey( 'meta', $fields );
	}
}
