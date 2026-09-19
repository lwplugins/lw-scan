<?php
/**
 * Tests for Status\EndpointSettings.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Status;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Status\EndpointSettings;
use LightweightPlugins\Scan\Status\StatusReport;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FakeStore;

final class EndpointSettingsTest extends MonkeyTestCase {

	use FakeStore;

	private const KEY = '0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		$this->stub_store();
		Functions\when( 'rest_url' )->alias(
			static fn ( $path = '' ) => 'https://example.com/wp-json/' . ltrim( (string) $path, '/' )
		);
	}

	public function test_a_fresh_site_has_the_endpoint_enabled(): void {
		$this->assertTrue( ( new EndpointSettings() )->is_enabled() );
	}

	public function test_a_fresh_site_has_no_key_and_no_url(): void {
		$settings = new EndpointSettings();

		$this->assertSame( '', $settings->key() );
		$this->assertFalse( $settings->has_key() );
		$this->assertSame( 0, $settings->key_set_at() );
		$this->assertSame( '', $settings->status_url() );
	}

	public function test_the_default_cache_ttl_is_five_minutes(): void {
		$this->assertSame( 300, ( new EndpointSettings() )->cache_ttl() );
	}

	public function test_the_endpoint_can_be_switched_off(): void {
		$settings = new EndpointSettings();
		$settings->set_enabled( false );

		$this->assertFalse( ( new EndpointSettings() )->is_enabled() );
	}

	public function test_set_cache_ttl_clamps_to_one_minute_and_one_hour(): void {
		$settings = new EndpointSettings();

		$settings->set_cache_ttl( 10 );
		$this->assertSame( 60, $settings->cache_ttl() );

		$settings->set_cache_ttl( 99999 );
		$this->assertSame( 3600, $settings->cache_ttl() );

		$settings->set_cache_ttl( 900 );
		$this->assertSame( 900, $settings->cache_ttl() );
	}

	public function test_a_stored_ttl_out_of_range_is_clamped_on_read(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'cache_ttl' => 5 ];

		$this->assertSame( 60, ( new EndpointSettings() )->cache_ttl() );
	}

	public function test_generate_key_stores_32_lowercase_hex_characters_and_the_time(): void {
		$settings = new EndpointSettings();
		$before   = time();
		$key      = $settings->generate_key();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( $key, $settings->key() );
		$this->assertTrue( $settings->has_key() );
		$this->assertGreaterThanOrEqual( $before, $settings->key_set_at() );
		$this->assertLessThanOrEqual( time(), $settings->key_set_at() );
	}

	public function test_generate_key_replaces_the_previous_key(): void {
		$settings = new EndpointSettings();
		$first    = $settings->generate_key();
		$second   = $settings->generate_key();

		$this->assertNotSame( $first, $second );
		$this->assertFalse( $settings->verify_key( $first ) );
		$this->assertTrue( $settings->verify_key( $second ) );
	}

	public function test_generate_key_drops_the_cached_report(): void {
		( new EndpointSettings() )->generate_key();

		$this->assertContains( StatusReport::TRANSIENT, $this->deleted_transients );
	}

	/**
	 * @dataProvider provide_malformed_keys
	 *
	 * @param mixed $bad Stored key value.
	 */
	public function test_a_malformed_stored_key_counts_as_no_key( $bad ): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'key' => $bad ];

		$settings = new EndpointSettings();

		$this->assertSame( '', $settings->key() );
		$this->assertFalse( $settings->verify_key( (string) $bad ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_malformed_keys(): array {
		return [
			'uppercase hex' => [ 'ABCDEF0123456789ABCDEF0123456789' ],
			'too short'     => [ 'abc' ],
			'too long'      => [ self::KEY . '0' ],
			'non-hex'       => [ 'g123456789abcdef0123456789abcdef' ],
			'not a string'  => [ 123 ],
		];
	}

	public function test_verify_key_accepts_only_the_stored_key(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'key' => self::KEY ];

		$settings = new EndpointSettings();

		$this->assertTrue( $settings->verify_key( self::KEY ) );
		$this->assertFalse( $settings->verify_key( 'ffffffffffffffffffffffffffffffff' ) );
		$this->assertFalse( $settings->verify_key( strtoupper( self::KEY ) ) );
		$this->assertFalse( $settings->verify_key( '' ) );
	}

	public function test_verify_key_rejects_an_empty_key_when_none_is_stored(): void {
		$this->assertFalse( ( new EndpointSettings() )->verify_key( '' ) );
	}

	public function test_ensure_key_generates_one_only_when_missing(): void {
		$settings = new EndpointSettings();
		$first    = $settings->ensure_key();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $first );
		$this->assertSame( $first, $settings->ensure_key() );
		$this->assertSame( $first, ( new EndpointSettings() )->key() );
	}

	public function test_ensure_key_keeps_an_existing_key(): void {
		$this->options[ EndpointSettings::OPTION ] = [
			'key'        => self::KEY,
			'key_set_at' => 1700000000,
		];

		$settings = new EndpointSettings();

		$this->assertSame( self::KEY, $settings->ensure_key() );
		$this->assertSame( 1700000000, $settings->key_set_at() );
		$this->assertSame( [], $this->option_writes );
	}

	public function test_status_url_points_at_the_keyed_route(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'key' => self::KEY ];

		$this->assertSame(
			'https://example.com/wp-json/lw-scan/v1/status/' . self::KEY,
			( new EndpointSettings() )->status_url()
		);
	}

	public function test_switching_the_endpoint_drops_the_cached_report(): void {
		$settings = new EndpointSettings();
		$settings->set_enabled( false );

		$this->assertContains( StatusReport::TRANSIENT, $this->deleted_transients );

		$this->deleted_transients = [];
		$settings->set_enabled( true );

		$this->assertContains( StatusReport::TRANSIENT, $this->deleted_transients );
	}

	public function test_saving_the_same_switch_state_keeps_the_cached_report(): void {
		( new EndpointSettings() )->set_enabled( true );

		$this->assertSame( [], $this->deleted_transients );
	}

	public function test_the_option_is_never_autoloaded(): void {
		$settings = new EndpointSettings();
		$settings->generate_key();
		$settings->set_cache_ttl( 900 );

		$this->assertSame( [ 'add_option', EndpointSettings::OPTION, false ], $this->option_writes[0] );
		$this->assertSame( [ 'update_option', EndpointSettings::OPTION, false ], $this->option_writes[1] );
	}

	public function test_the_stored_option_carries_the_documented_shape(): void {
		( new EndpointSettings() )->generate_key();

		$this->assertSame(
			[ 'enabled', 'key', 'key_set_at', 'cache_ttl' ],
			array_keys( $this->options[ EndpointSettings::OPTION ] )
		);
		$this->assertTrue( $this->options[ EndpointSettings::OPTION ]['enabled'] );
		$this->assertSame( 300, $this->options[ EndpointSettings::OPTION ]['cache_ttl'] );
	}
}
