<?php
/**
 * Tests for Admin\Post\StatusEndpointHandler.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Admin\Post;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Admin\Post\StatusEndpointHandler;
use LightweightPlugins\Scan\Status\EndpointSettings;
use LightweightPlugins\Scan\Status\StatusReport;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FakeStore;
use RuntimeException;

/**
 * The real `check_admin_referer()` and `wp_die()` end the request; here
 * they throw, so a test can prove nothing was written after a refusal.
 */
final class StatusEndpointHandlerTest extends MonkeyTestCase {

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

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_key' )->alias( static fn ( $value ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( [ self::class, 'add_query_arg' ] );
	}

	protected function tearDown(): void {
		unset( $_POST['enabled'], $_POST['cache_ttl'] );
		parent::tearDown();
	}

	/**
	 * Just enough of core's add_query_arg() for the two call shapes the
	 * plugin uses.
	 *
	 * @param mixed ...$args Either ( array $params, string $url ) or ( string $key, string $value, string $url ).
	 */
	public static function add_query_arg( ...$args ): string {
		if ( is_array( $args[0] ) ) {
			return $args[1] . '?' . http_build_query( $args[0] );
		}

		return $args[2] . ( str_contains( (string) $args[2], '?' ) ? '&' : '?' ) . $args[0] . '=' . $args[1];
	}

	private function refuse_the_nonce_for( string $action, string $field ): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( $action, $field )
			->andReturnUsing(
				static function (): void {
					throw new RuntimeException( 'nonce' );
				}
			);
	}

	private function deny_the_capability(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new RuntimeException( 'wp_die' );
			}
		);
	}

	private function allow(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	public function test_both_writes_are_hooked_for_logged_in_users_only(): void {
		StatusEndpointHandler::register();

		$this->assertNotFalse( has_action( 'admin_post_lw_scan_status_save', [ StatusEndpointHandler::class, 'handle_save' ] ) );
		$this->assertNotFalse( has_action( 'admin_post_lw_scan_status_rotate', [ StatusEndpointHandler::class, 'handle_rotate' ] ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_lw_scan_status_save' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_lw_scan_status_rotate' ) );
	}

	public function test_save_refuses_a_bad_nonce_and_changes_nothing(): void {
		$_POST['cache_ttl'] = '900';
		$this->refuse_the_nonce_for( 'lw_scan_status_save', 'lw_scan_status_nonce' );

		try {
			StatusEndpointHandler::save( new EndpointSettings() );
			$this->fail( 'A bad nonce must end the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'nonce', $e->getMessage() );
		}

		$this->assertSame( [], $this->option_writes );
	}

	public function test_save_refuses_a_user_without_manage_options_and_changes_nothing(): void {
		$_POST['cache_ttl'] = '900';
		$this->deny_the_capability();

		try {
			StatusEndpointHandler::save( new EndpointSettings() );
			$this->fail( 'A user without manage_options must be refused.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertSame( [], $this->option_writes );
	}

	public function test_rotate_refuses_a_bad_nonce_and_keeps_the_key(): void {
		$this->refuse_the_nonce_for( 'lw_scan_status_rotate', 'lw_scan_rotate_nonce' );

		try {
			StatusEndpointHandler::rotate( new EndpointSettings() );
			$this->fail( 'A bad nonce must end the request.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'nonce', $e->getMessage() );
		}

		$this->assertSame( self::KEY, ( new EndpointSettings() )->key() );
	}

	public function test_rotate_refuses_a_user_without_manage_options_and_keeps_the_key(): void {
		$this->deny_the_capability();

		try {
			StatusEndpointHandler::rotate( new EndpointSettings() );
			$this->fail( 'A user without manage_options must be refused.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$this->assertSame( self::KEY, ( new EndpointSettings() )->key() );
	}

	public function test_save_without_the_checkbox_switches_the_endpoint_off(): void {
		$this->allow();
		$_POST['cache_ttl'] = '300';

		StatusEndpointHandler::save( new EndpointSettings() );

		$this->assertFalse( ( new EndpointSettings() )->is_enabled() );
	}

	public function test_save_stores_an_offered_ttl(): void {
		$this->allow();
		$_POST['enabled']   = '1';
		$_POST['cache_ttl'] = '900';

		StatusEndpointHandler::save( new EndpointSettings() );

		$settings = new EndpointSettings();
		$this->assertTrue( $settings->is_enabled() );
		$this->assertSame( 900, $settings->cache_ttl() );
	}

	public function test_save_ignores_a_ttl_the_form_does_not_offer(): void {
		$this->allow();
		$_POST['enabled']   = '1';
		$_POST['cache_ttl'] = '123';

		StatusEndpointHandler::save( new EndpointSettings() );

		$this->assertSame( 300, ( new EndpointSettings() )->cache_ttl() );
	}

	public function test_switching_on_provisions_a_missing_key(): void {
		$this->allow();
		$this->options[ EndpointSettings::OPTION ] = [ 'enabled' => false ];
		$_POST['enabled']                          = '1';

		StatusEndpointHandler::save( new EndpointSettings() );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', ( new EndpointSettings() )->key() );
	}

	public function test_switching_off_a_keyless_site_creates_no_key(): void {
		$this->allow();
		$this->options[ EndpointSettings::OPTION ] = [ 'enabled' => true ];
		$_POST['cache_ttl']                        = '300';

		StatusEndpointHandler::save( new EndpointSettings() );

		$settings = new EndpointSettings();
		$this->assertFalse( $settings->is_enabled() );
		$this->assertSame( '', $settings->key() );
	}

	public function test_save_returns_to_the_status_tab(): void {
		$this->allow();
		$_POST['enabled'] = '1';

		$url = StatusEndpointHandler::save( new EndpointSettings() );

		$this->assertStringContainsString( 'page=lw-scan', $url );
		$this->assertStringContainsString( 'tab=status', $url );
		$this->assertStringContainsString( 'lw-scan-status=saved', $url );
	}

	public function test_rotate_replaces_the_key_and_drops_the_cached_report(): void {
		$this->allow();

		$url = StatusEndpointHandler::rotate( new EndpointSettings() );

		$key = ( new EndpointSettings() )->key();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertNotSame( self::KEY, $key );
		$this->assertContains( StatusReport::TRANSIENT, $this->deleted_transients );
		$this->assertStringContainsString( 'lw-scan-status=rotated', $url );
	}
}
