<?php
/**
 * Tests for Rest\StatusEndpointController.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Rest\StatusEndpointController;
use LightweightPlugins\Scan\Status\EndpointSettings;
use LightweightPlugins\Scan\Status\StatusReport;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FakeStore;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/RestStubs.php';

/**
 * The permission check is `Rest\Routes`' job; these cover what the three
 * endpoints do once a request is let through.
 */
final class StatusEndpointControllerTest extends MonkeyTestCase {

	use FakeStore;

	private const KEY = '0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		$this->stub_store();

		$this->options[ EndpointSettings::OPTION ] = [
			'enabled'    => true,
			'key'        => self::KEY,
			'key_set_at' => 1750000000,
			'cache_ttl'  => 300,
		];

		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'rest_sanitize_boolean' )->alias( static fn ( $value ) => in_array( strtolower( (string) $value ), [ '1', 'true', 'yes', 'on' ], true ) );
		Functions\when( 'rest_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-json/' . $path );
	}

	/**
	 * @param array<string, mixed> $params Request body.
	 * @return array<string, mixed>
	 */
	private function save( array $params ): array {
		return ( new StatusEndpointController() )->save( new WP_REST_Request( [], $params ) );
	}

	public function test_show_returns_the_contract_shape(): void {
		$this->assertSame(
			[
				'enabled'     => true,
				'url'         => 'https://example.com/wp-json/lw-scan/v1/status/' . self::KEY,
				'key_set_at'  => 1750000000,
				'cache_ttl'   => 300,
				'ttl_choices' => [ 60, 300, 900, 1800, 3600 ],
			],
			( new StatusEndpointController() )->show()
		);
	}

	public function test_show_provisions_a_missing_key(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'enabled' => false ];

		$view = ( new StatusEndpointController() )->show();

		$this->assertMatchesRegularExpression( '#/status/[a-f0-9]{32}$#', $view['url'] );
	}

	public function test_save_switches_the_endpoint_off(): void {
		$view = $this->save( [ 'enabled' => false ] );

		$this->assertFalse( $view['enabled'] );
		$this->assertFalse( ( new EndpointSettings() )->is_enabled() );
	}

	public function test_save_without_enabled_keeps_the_switch(): void {
		$this->assertTrue( $this->save( [ 'cache_ttl' => 900 ] )['enabled'] );
	}

	public function test_save_stores_an_offered_ttl(): void {
		$this->assertSame( 900, $this->save( [ 'cache_ttl' => 900 ] )['cache_ttl'] );
	}

	public function test_save_ignores_a_ttl_that_is_not_offered(): void {
		$this->assertSame( 300, $this->save( [ 'cache_ttl' => 123 ] )['cache_ttl'] );
	}

	public function test_switching_on_provisions_a_missing_key(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'enabled' => false ];

		$this->save( [ 'enabled' => true ] );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', ( new EndpointSettings() )->key() );
	}

	public function test_switching_off_a_keyless_site_creates_no_key(): void {
		$this->options[ EndpointSettings::OPTION ] = [ 'enabled' => true ];

		$this->save( [ 'enabled' => false ] );

		$this->assertSame( '', ( new EndpointSettings() )->key() );
	}

	public function test_rotate_replaces_the_key_and_drops_the_cached_report(): void {
		$view = ( new StatusEndpointController() )->rotate();

		$key = ( new EndpointSettings() )->key();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertNotSame( self::KEY, $key );
		$this->assertStringEndsWith( '/status/' . $key, $view['url'] );
		$this->assertContains( StatusReport::TRANSIENT, $this->deleted_transients );
	}
}
