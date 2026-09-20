<?php
/**
 * Tests for CLI\NotifyCli.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\CLI\NotifyCli;
use LightweightPlugins\Scan\Notify\Baseline;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FakeStore;

/**
 * `NotifyCli` is the deciding logic behind `wp lw-scan notify …`, with no
 * WP_CLI in it (WP_CLI is not loadable in a unit test), so these tests
 * exercise it directly against the same options the Notifications tab
 * writes, through the `FakeStore` option double.
 */
final class NotifyCliTest extends MonkeyTestCase {

	use FakeStore;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_store();

		$this->options['admin_email'] = 'admin@example.test';

		Functions\when( 'is_email' )->alias(
			static fn ( $email ) => (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL )
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ): string => trim( (string) $value ) );
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $value ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);
		Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Site' );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\stubTranslationFunctions();
	}

	/**
	 * @param bool $taken What the injected baseline reports.
	 */
	private static function cli( bool $taken = true ): NotifyCli {
		return new NotifyCli(
			new Baseline(
				static function () use ( $taken ): bool {
					return $taken;
				}
			)
		);
	}

	/**
	 * @param string $key Option key.
	 * @return mixed
	 */
	private function stored( string $key ) {
		return $this->options[ Options::OPTION_NAME ][ $key ] ?? null;
	}

	public function test_status_on_a_fresh_site(): void {
		$status = self::cli( false )->status();

		$this->assertTrue( $status['enabled'] );
		$this->assertSame( 'alerts', $status['level'] );
		$this->assertSame( [ 'admin@example.test' ], $status['recipients'] );
		$this->assertTrue( $status['uses_admin_email'] );
		$this->assertSame( 20, $status['limit'] );
		$this->assertFalse( $status['baseline'] );
	}

	public function test_status_reflects_what_was_configured(): void {
		$this->options[ Options::OPTION_NAME ] = [
			'notify_enabled' => false,
			'notify_level'   => 'review',
			'notify_emails'  => [ 'ops@example.test' ],
			'notify_limit'   => 50,
		];

		$status = self::cli()->status();

		$this->assertFalse( $status['enabled'] );
		$this->assertSame( 'review', $status['level'] );
		$this->assertSame( [ 'ops@example.test' ], $status['recipients'] );
		$this->assertFalse( $status['uses_admin_email'] );
		$this->assertSame( 50, $status['limit'] );
		$this->assertTrue( $status['baseline'] );
	}

	public function test_disable_switches_e_mail_off(): void {
		$result = self::cli()->disable();

		$this->assertTrue( $result['changed'] );
		$this->assertStringContainsString( 'failure', $result['message'], 'The failure-streak warning stops too; the message has to say so.' );
		$this->assertFalse( $this->stored( 'notify_enabled' ) );
	}

	public function test_disable_is_idempotent(): void {
		self::cli()->disable();

		$result = self::cli()->disable();

		$this->assertFalse( $result['changed'] );
		$this->assertStringContainsString( 'Already', $result['message'] );
	}

	public function test_enable_switches_e_mail_back_on_at_the_remembered_level(): void {
		$this->options[ Options::OPTION_NAME ] = [
			'notify_enabled' => false,
			'notify_level'   => 'review',
		];

		$result = self::cli()->enable();

		$this->assertTrue( $result['changed'] );
		$this->assertTrue( $this->stored( 'notify_enabled' ) );
		$this->assertSame( 'review', $this->stored( 'notify_level' ), 'Switching off must not lose the level.' );
		$this->assertStringContainsString( 'review', $result['message'] );
	}

	public function test_enable_is_idempotent(): void {
		$result = self::cli()->enable();

		$this->assertFalse( $result['changed'] );
		$this->assertStringContainsString( 'Already', $result['message'] );
	}

	public function test_level_with_no_argument_reports_the_current_one_and_writes_nothing(): void {
		$result = self::cli()->level( null );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'alerts', $result['level'] );
		$this->assertSame( [], $this->option_writes );
	}

	public function test_level_review_is_stored(): void {
		$result = self::cli()->level( 'review' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'review', $this->stored( 'notify_level' ) );
	}

	public function test_level_alerts_is_stored_as_the_singular_option_value(): void {
		self::cli()->level( 'review' );

		$result = self::cli()->level( 'alerts' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'alert', $this->stored( 'notify_level' ) );
	}

	/**
	 * @dataProvider provide_bad_levels
	 */
	public function test_an_unknown_level_is_rejected_and_stores_nothing( string $level ): void {
		$result = self::cli()->level( $level );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'alerts, review', $result['message'] );
		$this->assertSame( [], $this->option_writes );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_bad_levels(): array {
		return [
			'unknown word' => [ 'everything' ],
			'the switch'   => [ 'off' ],
			'empty'        => [ '' ],
		];
	}

	public function test_setting_a_level_while_e_mail_is_off_says_so(): void {
		self::cli()->disable();

		$result = self::cli()->level( 'review' );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'off', $result['message'] );
	}

	public function test_recipients_with_no_argument_reports_the_admin_fallback(): void {
		$result = self::cli()->recipients( null, false );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( [], $result['recipients'] );
		$this->assertTrue( $result['uses_admin_email'] );
		$this->assertStringContainsString( 'admin@example.test', $result['message'] );
		$this->assertSame( [], $this->option_writes );
	}

	public function test_recipients_stores_a_comma_separated_list(): void {
		$result = self::cli()->recipients( 'ops@example.test, dev@example.test', false );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 'ops@example.test', 'dev@example.test' ], $this->stored( 'notify_emails' ) );
	}

	public function test_recipients_rejects_the_whole_list_when_one_address_is_bad(): void {
		$result = self::cli()->recipients( 'ops@example.test, nonsense', false );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'nonsense', $result['message'] );
		$this->assertSame( [], $this->option_writes, 'A typo must not half-write the list.' );
	}

	public function test_recipients_clear_falls_back_to_the_site_admin(): void {
		$this->options[ Options::OPTION_NAME ] = [ 'notify_emails' => [ 'ops@example.test' ] ];

		$result = self::cli()->recipients( null, true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( [], $this->stored( 'notify_emails' ) );
		$this->assertStringContainsString( 'admin@example.test', $result['message'] );
	}

	public function test_recipients_refuses_a_list_and_clear_together(): void {
		$result = self::cli()->recipients( 'ops@example.test', true );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( [], $this->option_writes );
	}

	public function test_limit_with_no_argument_reports_the_current_cap(): void {
		$result = self::cli()->limit( null );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 20, $result['limit'] );
		$this->assertSame( [], $this->option_writes );
	}

	public function test_limit_all_stores_no_cap(): void {
		$result = self::cli()->limit( 'all' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['limit'] );
		$this->assertSame( 0, $this->stored( 'notify_limit' ) );
	}

	public function test_limit_accepts_an_offered_number(): void {
		$result = self::cli()->limit( '10' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 10, $this->stored( 'notify_limit' ) );
	}

	/**
	 * @dataProvider provide_bad_limits
	 */
	public function test_an_unoffered_cap_is_rejected_and_stores_nothing( string $limit ): void {
		$result = self::cli()->limit( $limit );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '10, 20, 50 or all', $result['message'] );
		$this->assertSame( [], $this->option_writes );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_bad_limits(): array {
		return [
			'between steps' => [ '33' ],
			'negative'      => [ '-10' ],
			'a word'        => [ 'every' ],
			'zero'          => [ '0' ],
		];
	}

	public function test_test_reports_that_wp_mail_accepted_the_message(): void {
		Functions\when( 'wp_mail' )->justReturn( true );

		$result = self::cli()->test();

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'admin@example.test', $result['message'] );
	}

	public function test_test_reports_a_refused_message(): void {
		Functions\when( 'wp_mail' )->justReturn( false );

		$result = self::cli()->test();

		$this->assertFalse( $result['ok'] );
	}

	public function test_test_says_when_there_is_nobody_to_mail(): void {
		unset( $this->options['admin_email'] );
		Functions\expect( 'wp_mail' )->never();

		$result = self::cli()->test();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'nobody', $result['message'] );
	}
}
