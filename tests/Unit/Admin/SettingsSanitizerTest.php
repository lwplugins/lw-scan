<?php
/**
 * Tests for Admin\SettingsSanitizer.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Admin\SettingsSanitizer;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class SettingsSanitizerTest extends MonkeyTestCase {

	/**
	 * Stored option the sanitizer falls back to / preserves from.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();

		$this->stored = [
			'schedule'      => 'weekly',
			'schedule_hour' => 5,
			'scope'         => 'full',
			'notify_level'  => 'review',
			'max_file_size' => 5242880,
			'next_due'      => 1789000000,
			'last_auto_run' => 1788900000,
		'plugin_version' => '0.9.0',
		];

		Functions\when( 'get_option' )->alias(
			fn ( string $name, $default_value = false ) => Options::OPTION_NAME === $name ? $this->stored : $default_value
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ): string => trim( strip_tags( (string) $value ) )
		);
		Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $value ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);
		Functions\when( 'is_email' )->alias(
			static fn ( $value ) => false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? (string) $value : false
		);
	}

	public function test_returns_exactly_the_default_keys(): void {
		$out = SettingsSanitizer::sanitize( [] );

		$this->assertSame( array_keys( Options::get_defaults() ), array_keys( $out ) );
	}

	public function test_valid_input_is_kept(): void {
		$out = SettingsSanitizer::sanitize(
			[
				'schedule'           => 'hourly',
				'schedule_hour'      => '11',
				'scope'              => 'db',
				'bundle_auto_update' => '1',
				'heuristics'         => '1',
				'max_file_size'      => '1048576',
				'excluded_paths'     => "wp-content/cache\nwp-content/upgrade",
				'follow_symlinks'    => '1',
				'notify_emails'      => 'a@example.com, b@example.com',
				'notify_level'       => 'review',
				'admin_notice'       => '1',
			]
		);

		$this->assertSame( 'hourly', $out['schedule'] );
		$this->assertSame( 11, $out['schedule_hour'] );
		$this->assertSame( 'db', $out['scope'] );
		$this->assertTrue( $out['bundle_auto_update'] );
		$this->assertTrue( $out['heuristics'] );
		$this->assertSame( 1048576, $out['max_file_size'] );
		$this->assertSame( [ 'wp-content/cache', 'wp-content/upgrade' ], $out['excluded_paths'] );
		$this->assertTrue( $out['follow_symlinks'] );
		$this->assertSame( [ 'a@example.com', 'b@example.com' ], $out['notify_emails'] );
		$this->assertSame( 'review', $out['notify_level'] );
		$this->assertTrue( $out['admin_notice'] );
	}

	public function test_unknown_enum_values_fall_back_to_the_stored_value(): void {
		$out = SettingsSanitizer::sanitize(
			[
				'schedule'     => 'every-minute',
				'scope'        => 'path',
				'notify_level' => 'everything',
			]
		);

		$this->assertSame( 'weekly', $out['schedule'] );
		$this->assertSame( 'full', $out['scope'] );
		$this->assertSame( 'review', $out['notify_level'] );
	}

	public function test_schedule_hour_is_clamped_to_the_day(): void {
		$this->assertSame( 23, SettingsSanitizer::sanitize( [ 'schedule_hour' => '99' ] )['schedule_hour'] );
		$this->assertSame( 0, SettingsSanitizer::sanitize( [ 'schedule_hour' => '-4' ] )['schedule_hour'] );
		$this->assertSame( 5, SettingsSanitizer::sanitize( [] )['schedule_hour'] );
	}

	public function test_max_file_size_outside_the_allowed_list_falls_back(): void {
		$this->assertSame( 5242880, SettingsSanitizer::sanitize( [ 'max_file_size' => '777' ] )['max_file_size'] );
		$this->assertSame( 524288, SettingsSanitizer::sanitize( [ 'max_file_size' => '524288' ] )['max_file_size'] );
	}

	/**
	 * Every checkbox renders a hidden `0` companion, so an unchecked box
	 * still posts its key — which is what lets an absent key mean "another
	 * tab's form posted this, leave it alone".
	 */
	public function test_booleans_are_off_when_the_checkbox_posts_zero(): void {
		$out = SettingsSanitizer::sanitize(
			[
				'bundle_auto_update' => '0',
				'heuristics'         => '0',
				'follow_symlinks'    => '0',
				'admin_notice'       => '0',
			]
		);

		$this->assertFalse( $out['bundle_auto_update'] );
		$this->assertFalse( $out['heuristics'] );
		$this->assertFalse( $out['follow_symlinks'] );
		$this->assertFalse( $out['admin_notice'] );
	}

	/**
	 * Settings and Notifications are two forms over one option row. Saving
	 * one must not switch off the other's checkboxes.
	 */
	public function test_booleans_absent_from_the_form_keep_the_stored_value(): void {
		$this->stored['heuristics']   = true;
		$this->stored['admin_notice'] = true;

		$out = SettingsSanitizer::sanitize( [ 'notify_send' => 'alert' ] );

		$this->assertTrue( $out['heuristics'], 'Saving the Notifications tab must not disable the heuristic layer.' );
		$this->assertTrue( $out['admin_notice'] );
	}

	public function test_the_send_control_maps_off_to_the_switch_without_losing_the_level(): void {
		$out = SettingsSanitizer::sanitize( [ 'notify_send' => 'off' ] );

		$this->assertFalse( $out['notify_enabled'] );
		$this->assertSame( 'review', $out['notify_level'], 'Switching off must remember the level for when it is switched back on.' );
	}

	public function test_the_send_control_maps_a_level_to_the_switch_and_the_level(): void {
		$out = SettingsSanitizer::sanitize( [ 'notify_send' => 'alert' ] );

		$this->assertTrue( $out['notify_enabled'] );
		$this->assertSame( 'alert', $out['notify_level'] );
	}

	public function test_an_unknown_send_control_value_changes_nothing(): void {
		$this->stored['notify_enabled'] = true;

		$out = SettingsSanitizer::sanitize( [ 'notify_send' => 'sometimes' ] );

		$this->assertTrue( $out['notify_enabled'] );
		$this->assertSame( 'review', $out['notify_level'] );
	}

	public function test_the_send_control_is_not_stored_as_an_option(): void {
		$this->assertArrayNotHasKey( 'notify_send', SettingsSanitizer::sanitize( [ 'notify_send' => 'off' ] ) );
	}

	public function test_notify_limit_accepts_only_the_offered_caps(): void {
		$this->stored['notify_limit'] = 50;

		$this->assertSame( 10, SettingsSanitizer::sanitize( [ 'notify_limit' => '10' ] )['notify_limit'] );
		$this->assertSame( 0, SettingsSanitizer::sanitize( [ 'notify_limit' => '0' ] )['notify_limit'] );
		$this->assertSame( 50, SettingsSanitizer::sanitize( [ 'notify_limit' => '33' ] )['notify_limit'] );
		$this->assertSame( 50, SettingsSanitizer::sanitize( [] )['notify_limit'], 'An absent cap belongs to the other tab\'s form.' );
	}

	public function test_excluded_paths_are_normalized_and_deduplicated(): void {
		$out = SettingsSanitizer::sanitize(
			[
				'excluded_paths' => "  /wp-content/cache/  \r\nwp-content\\backup*\n\nwp-content/cache\n",
			]
		);

		$this->assertSame( [ 'wp-content/cache', 'wp-content/backup*' ], $out['excluded_paths'] );
	}

	public function test_excluded_paths_containing_dot_dot_are_rejected(): void {
		$out = SettingsSanitizer::sanitize(
			[
				'excluded_paths' => "wp-content/cache\n../../etc\nwp-content/../uploads",
			]
		);

		$this->assertSame( [ 'wp-content/cache' ], $out['excluded_paths'] );
	}

	public function test_excluded_paths_accept_an_array_too(): void {
		$out = SettingsSanitizer::sanitize( [ 'excluded_paths' => [ 'wp-content/cache', '..' ] ] );

		$this->assertSame( [ 'wp-content/cache' ], $out['excluded_paths'] );
	}

	public function test_notify_emails_split_on_commas_and_newlines_and_drop_invalid(): void {
		$out = SettingsSanitizer::sanitize(
			[
				'notify_emails' => "ops@example.com,  not-an-email\nadmin@example.com, ops@example.com",
			]
		);

		$this->assertSame( [ 'ops@example.com', 'admin@example.com' ], $out['notify_emails'] );
	}

	public function test_next_due_and_last_auto_run_are_preserved_when_the_form_omits_them(): void {
		$out = SettingsSanitizer::sanitize( [ 'schedule' => 'daily' ] );

		$this->assertSame( 1789000000, $out['next_due'] );
		$this->assertSame( 1788900000, $out['last_auto_run'] );
	}

	/**
	 * The sanitize callback also runs for Scheduler/FinalizePhase writes,
	 * which go through Options::update() and therefore submit every key —
	 * pinning these two to the stored value would freeze the schedule.
	 */
	public function test_next_due_and_last_auto_run_accept_a_submitted_value(): void {
		$out = SettingsSanitizer::sanitize(
			[
				'next_due'      => '1789600000',
				'last_auto_run' => 1789500000,
			]
		);

		$this->assertSame( 1789600000, $out['next_due'] );
		$this->assertSame( 1789500000, $out['last_auto_run'] );
	}

	public function test_a_scheduler_style_update_keeps_the_rest_of_the_options(): void {
		$this->stored = array_merge( Options::get_defaults(), $this->stored );

		$out = SettingsSanitizer::sanitize( array_merge( $this->stored, [ 'next_due' => 1789600000 ] ) );

		$this->assertSame( 1789600000, $out['next_due'] );
		$this->assertSame( 'weekly', $out['schedule'] );
		$this->assertSame( 'full', $out['scope'] );
		$this->assertSame( 5242880, $out['max_file_size'] );
		$this->assertTrue( $out['heuristics'] );
	}

	public function test_non_array_input_yields_the_stored_values(): void {
		$out = SettingsSanitizer::sanitize( 'nonsense' );

		$this->assertSame( 'weekly', $out['schedule'] );
		$this->assertSame( [], $out['notify_emails'] );
	}

	public function test_a_runtime_options_filter_is_not_saved_to_the_database(): void {
		Filters\expectApplied( 'lw_scan_options' )
			->zeroOrMoreTimes()
			->andReturnUsing(
				static function ( array $options ): array {
					$options['schedule'] = 'off';

					return $options;
				}
			);

		$out = SettingsSanitizer::sanitize( [] );

		$this->assertSame( 'weekly', $out['schedule'] );
	}

	/**
	 * `plugin_version` is `Upgrader`'s marker, not a setting: the form has
	 * no field for it, and a save must not send the site back through an
	 * upgrade it has already done.
	 */
	public function test_plugin_version_is_preserved_when_the_form_omits_it(): void {
		$out = SettingsSanitizer::sanitize( [ 'schedule' => 'daily' ] );

		$this->assertSame( '0.9.0', $out['plugin_version'] );
	}

	public function test_plugin_version_cannot_be_set_from_the_input(): void {
		$out = SettingsSanitizer::sanitize( [ 'plugin_version' => '99.0.0' ] );

		$this->assertSame( '0.9.0', $out['plugin_version'] );
	}
}
