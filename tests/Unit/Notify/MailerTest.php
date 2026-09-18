<?php
/**
 * Tests for Notify\Mailer.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Notify;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Notify\Mailer;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class MailerTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The failure-streak body runs a stored error code through
		// Run\RunError, whose sentences are translated.
		Functions\stubTranslationFunctions();
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

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function finding( array $overrides = [] ): array {
		return array_merge(
			[
				'type'          => 'file',
				'locator'       => 'wp-content/uploads/x.php',
				'tier'          => 'infected',
				'category'      => 'obfuscation',
				'severity'      => 'alert',
				'signature_ids' => [ 'ct:4711' ],
				'reason'        => 'matched signature',
				'excerpt'       => 'eval(base64_decode($_POST[1]));',
			],
			$overrides
		);
	}

	public function test_format_finding_line_shape(): void {
		$line = Mailer::format_finding_line( self::finding() );

		$this->assertSame(
			'[ALERT] file · wp-content/uploads/x.php · infected/obfuscation · ct:4711 · eval(base64_decode($_POST[1]));',
			$line
		);
	}

	public function test_format_finding_line_decodes_json_string_signature_ids(): void {
		$line = Mailer::format_finding_line( self::finding( [ 'signature_ids' => '["ct:4711","ct:9999"]' ] ) );

		$this->assertStringContainsString( 'ct:4711', $line );
		$this->assertStringNotContainsString( 'ct:9999', $line );
	}

	public function test_format_finding_line_uses_reason_for_vulnerability_findings(): void {
		$line = Mailer::format_finding_line(
			self::finding(
				[
					'type'          => 'vulnerability',
					'category'      => 'vulnerability',
					'locator'       => 'plugin:some-plugin',
					'signature_ids' => [ 'CVE-2024-1234' ],
					'reason'        => 'Some Plugin XSS — installed 1.0, patched in 1.1',
					'excerpt'       => '',
				]
			)
		);

		$this->assertStringContainsString( 'Some Plugin XSS — installed 1.0, patched in 1.1', $line );
		$this->assertStringNotContainsString( 'CVE-2024-1234', $line );
	}

	public function test_format_finding_line_caps_excerpt_and_collapses_newlines(): void {
		$excerpt = str_repeat( 'a', 130 ) . "\nnewline\ttab";

		$line = Mailer::format_finding_line( self::finding( [ 'excerpt' => $excerpt ] ) );

		$segments = explode( ' · ', $line );
		$excerpt_segment = end( $segments );

		$this->assertLessThanOrEqual( 120, strlen( $excerpt_segment ) );
		$this->assertStringNotContainsString( "\n", $excerpt_segment );
		$this->assertStringNotContainsString( "\t", $excerpt_segment );
	}

	public function test_send_new_findings_level_alert_filters_out_review_and_mails_once_with_both_recipients(): void {
		Functions\expect( 'wp_mail' )
			->once()
			->with(
				[ 'a@example.test', 'b@example.test' ],
				'[Example Site] LW Scan: 1 new alert',
				\Mockery::type( 'string' )
			)
			->andReturn( true );

		$findings = [
			self::finding( [ 'severity' => 'alert' ] ),
			self::finding( [ 'severity' => 'review', 'locator' => 'wp-content/uploads/y.php' ] ),
		];

		$options = [
			'notify_level'  => 'alert',
			'notify_emails' => [ 'a@example.test', 'b@example.test' ],
		];

		$result = Mailer::send_new_findings( [], $findings, $options );

		$this->assertTrue( $result );
	}

	public function test_send_new_findings_level_review_keeps_both_severities(): void {
		Functions\expect( 'wp_mail' )
			->once()
			->with(
				[ 'a@example.test' ],
				'[Example Site] LW Scan: 2 new findings',
				\Mockery::type( 'string' )
			)
			->andReturn( true );

		$findings = [
			self::finding( [ 'severity' => 'alert' ] ),
			self::finding( [ 'severity' => 'review', 'locator' => 'wp-content/uploads/y.php' ] ),
		];

		$options = [
			'notify_level'  => 'review',
			'notify_emails' => [ 'a@example.test' ],
		];

		Mailer::send_new_findings( [], $findings, $options );
	}

	public function test_send_new_findings_returns_false_and_sends_no_mail_when_filtered_list_is_empty(): void {
		Functions\expect( 'wp_mail' )->never();

		$findings = [ self::finding( [ 'severity' => 'review' ] ) ];
		$options  = [
			'notify_level'  => 'alert',
			'notify_emails' => [ 'a@example.test' ],
		];

		$this->assertFalse( Mailer::send_new_findings( [], $findings, $options ) );
	}

	public function test_send_new_findings_returns_false_when_no_valid_recipients(): void {
		Functions\expect( 'wp_mail' )->never();
		Functions\when( 'get_option' )->justReturn( false );

		$findings = [ self::finding( [ 'severity' => 'alert' ] ) ];
		$options  = [
			'notify_level'  => 'alert',
			'notify_emails' => [ 'not-an-email' ],
		];

		$this->assertFalse( Mailer::send_new_findings( [], $findings, $options ) );
	}

	public function test_send_new_findings_falls_back_to_admin_email_when_notify_emails_empty(): void {
		Functions\when( 'get_option' )->justReturn( 'admin@example.test' );

		Functions\expect( 'wp_mail' )
			->once()
			->with( [ 'admin@example.test' ], \Mockery::type( 'string' ), \Mockery::type( 'string' ) )
			->andReturn( true );

		$findings = [ self::finding( [ 'severity' => 'alert' ] ) ];
		$options  = [
			'notify_level'  => 'alert',
			'notify_emails' => [],
		];

		Mailer::send_new_findings( [], $findings, $options );
	}

	public function test_send_new_findings_body_ends_with_findings_link(): void {
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->assertStringContainsString( 'https://example.test/wp-admin/admin.php?page=lw-scan&tab=findings', $body );

				return true;
			}
		);

		$findings = [ self::finding( [ 'severity' => 'alert' ] ) ];
		$options  = [
			'notify_level'  => 'alert',
			'notify_emails' => [ 'a@example.test' ],
		];

		Mailer::send_new_findings( [], $findings, $options );
	}

	public function test_send_failure_streak_mails_recipients_with_the_error(): void {
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->assertSame( [ 'a@example.test' ], $to );
				$this->assertStringContainsString( 'scheduled scans are failing', $subject );
				$this->assertStringContainsString( 'disk full', $body );

				return true;
			}
		);

		$run     = [
			'error' => 'disk full',
			'id'    => 7,
		];
		$options = [ 'notify_emails' => [ 'a@example.test' ] ];

		$this->assertTrue( Mailer::send_failure_streak( $run, $options ) );
	}

	public function test_send_failure_streak_spells_out_a_machine_code(): void {
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				unset( $to, $subject );

				// "Last error: out_of_memory" told the recipient nothing.
				// Called without stats, so the label is the general
				// sentence rather than one naming a limit.
				$this->assertStringNotContainsString( 'out_of_memory', $body );
				$this->assertStringContainsString( 'ran out of memory', $body );

				return true;
			}
		);

		$run = [
			'error' => 'out_of_memory',
			'id'    => 9,
		];

		$this->assertTrue( Mailer::send_failure_streak( $run, [ 'notify_emails' => [ 'a@example.test' ] ] ) );
	}

	public function test_send_failure_streak_returns_false_when_no_valid_recipients(): void {
		Functions\expect( 'wp_mail' )->never();
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertFalse( Mailer::send_failure_streak( [ 'error' => 'x' ], [ 'notify_emails' => [] ] ) );
	}
}
