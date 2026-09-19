<?php
/**
 * Tests for CLI\EndpointCli.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\CLI;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\CLI\EndpointCli;
use LightweightPlugins\Scan\Status\EndpointSettings;
use LightweightPlugins\Scan\Status\StatusReport;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FakeStore;

/**
 * `EndpointCli` is the deciding logic behind `wp lw-scan endpoint …`, with
 * no WP_CLI in it (WP_CLI is not loadable in a unit test), so these tests
 * exercise it directly against the same `Status\EndpointSettings` the
 * admin screen uses, through the `FakeStore` option/transient double.
 */
final class EndpointCliTest extends MonkeyTestCase {

	use FakeStore;

	private const KEY = '0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		$this->stub_store();
		Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'https://example.com/wp-json/' . ltrim( (string) $path, '/' )
		);
	}

	public function test_url_reports_the_endpoint_off_and_creates_no_key(): void {
		( new EndpointSettings() )->set_enabled( false );

		$result = ( new EndpointCli() )->url();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( '', $result['url'] );
		$this->assertStringContainsString( 'off', $result['message'] );
		$this->assertFalse( ( new EndpointSettings() )->has_key() );
	}

	public function test_url_creates_a_key_when_enabled_and_missing_one(): void {
		$result = ( new EndpointCli() )->url();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Created a new key.', $result['message'] );
		$this->assertStringStartsWith( 'https://example.com/wp-json/lw-scan/v1/status/', $result['url'] );
		$this->assertTrue( ( new EndpointSettings() )->has_key() );
	}

	public function test_url_reuses_an_existing_key_without_creating_a_new_one(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'key' => self::KEY ];

		$result = ( new EndpointCli() )->url();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['message'] );
		$this->assertStringContainsString( self::KEY, $result['url'] );
		$this->assertSame( self::KEY, ( new EndpointSettings() )->key() );
	}

	public function test_enable_from_off_creates_a_key_and_reports_it_changed(): void {
		( new EndpointSettings() )->set_enabled( false );

		$result = ( new EndpointCli() )->enable();

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'Enabled. Created a new key.', $result['message'] );

		$settings = new EndpointSettings();
		$this->assertTrue( $settings->is_enabled() );
		$this->assertTrue( $settings->has_key() );
	}

	public function test_enable_is_idempotent_when_already_enabled_with_a_key(): void {
		$this->options[ EndpointSettings::OPTION ] = [
			'enabled' => true,
			'key'     => self::KEY,
		];

		$result = ( new EndpointCli() )->enable();

		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'Already enabled.', $result['message'] );
	}

	public function test_enable_reports_the_key_it_created_even_when_already_enabled(): void {
		// A fresh install: `enabled` reads true by default, but no key was ever provisioned.
		$result = ( new EndpointCli() )->enable();

		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'Already enabled. Created a new key.', $result['message'] );
	}

	public function test_disable_from_on_reports_it_changed(): void {
		$result = ( new EndpointCli() )->disable();

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'Disabled.', $result['message'] );
		$this->assertFalse( ( new EndpointSettings() )->is_enabled() );
	}

	public function test_disable_is_idempotent_when_already_off(): void {
		( new EndpointSettings() )->set_enabled( false );

		$result = ( new EndpointCli() )->disable();

		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'Already disabled.', $result['message'] );
	}

	public function test_rotate_replaces_the_key_and_invalidates_the_cached_report(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'key' => self::KEY ];

		$result = ( new EndpointCli() )->rotate();

		$this->assertStringNotContainsString( self::KEY, $result['url'] );
		$this->assertContains( StatusReport::TRANSIENT, $this->deleted_transients );

		$settings = new EndpointSettings();
		$this->assertNotSame( self::KEY, $settings->key() );
		$this->assertFalse( $settings->verify_key( self::KEY ) );
	}

	/**
	 * @dataProvider provide_disallowed_minutes
	 */
	public function test_ttl_rejects_values_outside_the_whitelist( int $minutes ): void {
		$result = ( new EndpointCli() )->ttl( $minutes );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '1, 5, 15, 30, 60', $result['message'] );
		$this->assertSame( 300, ( new EndpointSettings() )->cache_ttl() );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function provide_disallowed_minutes(): array {
		return [
			'zero'          => [ 0 ],
			'negative'      => [ -5 ],
			'two'           => [ 2 ],
			'between steps' => [ 45 ],
			'over an hour'  => [ 61 ],
		];
	}

	public function test_ttl_accepts_a_whitelisted_value_and_stores_it(): void {
		$result = ( new EndpointCli() )->ttl( 15 );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 15, $result['minutes'] );
		$this->assertSame( 900, ( new EndpointSettings() )->cache_ttl() );
	}

	public function test_ttl_with_no_argument_reports_the_current_value_and_changes_nothing(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'cache_ttl' => 1800 ];

		$result = ( new EndpointCli() )->ttl( null );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 30, $result['minutes'] );
		$this->assertSame( [], $this->option_writes );
	}

	public function test_status_reports_the_current_state(): void {
		$this->options[ EndpointSettings::OPTION ] = [
			'enabled'    => false,
			'key'        => self::KEY,
			'key_set_at' => 1700000000,
			'cache_ttl'  => 900,
		];

		$status = ( new EndpointCli() )->status();

		$this->assertFalse( $status['enabled'] );
		$this->assertSame( 15, $status['ttl_minutes'] );
		$this->assertTrue( $status['has_key'] );
		$this->assertSame( 1700000000, $status['key_set_at'] );
	}

	public function test_status_reports_no_key_on_a_fresh_site(): void {
		$status = ( new EndpointCli() )->status();

		$this->assertTrue( $status['enabled'] );
		$this->assertFalse( $status['has_key'] );
		$this->assertSame( 0, $status['key_set_at'] );
	}
}
