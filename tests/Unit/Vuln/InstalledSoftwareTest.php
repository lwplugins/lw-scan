<?php
/**
 * Tests for Vuln\InstalledSoftware.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Vuln;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Vuln\InstalledSoftware;

final class InstalledSoftwareTest extends MonkeyTestCase {

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function stub_plugins(): array {
		return [
			'woocommerce/woocommerce.php' => [
				'Version' => '11.0.0',
				'Name'    => 'WooCommerce',
			],
			'hello.php'                   => [
				'Version' => '1.7',
				'Name'    => 'Hello',
			],
		];
	}

	private function stub_theme( string $stylesheet, string $version, string $name ): object {
		return new class( $stylesheet, $version, $name ) {
			private string $stylesheet;
			private string $version;
			private string $name;

			public function __construct( string $stylesheet, string $version, string $name ) {
				$this->stylesheet = $stylesheet;
				$this->version    = $version;
				$this->name       = $name;
			}

			public function get( string $field ): string {
				if ( 'Version' === $field ) {
					return $this->version;
				}

				if ( 'Name' === $field ) {
					return $this->name;
				}

				return '';
			}

			public function get_stylesheet(): string {
				return $this->stylesheet;
			}
		};
	}

	private function stub_common(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.6.2' );
		Functions\when( 'get_plugins' )->justReturn( $this->stub_plugins() );
		Functions\when( 'is_plugin_active' )->alias(
			static function ( string $file ): bool {
				return 'woocommerce/woocommerce.php' === $file;
			}
		);
		Functions\when( 'wp_get_themes' )->justReturn(
			[
				'twentytwentyfive' => $this->stub_theme( 'twentytwentyfive', '1.2', 'Twenty Twenty-Five' ),
			]
		);
		Functions\when( 'wp_get_theme' )->justReturn( $this->stub_theme( 'twentytwentyfive', '1.2', 'Twenty Twenty-Five' ) );
	}

	public function test_list_includes_core_with_installed_version(): void {
		$this->stub_common();

		$core = InstalledSoftware::find( InstalledSoftware::list(), 'core', 'wordpress' );

		$this->assertNotNull( $core );
		$this->assertSame( '6.6.2', $core['version'] );
		$this->assertTrue( $core['active'] );
	}

	public function test_list_derives_plugin_slug_from_directory(): void {
		$this->stub_common();

		$plugin = InstalledSoftware::find( InstalledSoftware::list(), 'plugin', 'woocommerce' );

		$this->assertNotNull( $plugin );
		$this->assertSame( '11.0.0', $plugin['version'] );
		$this->assertSame( 'WooCommerce', $plugin['name'] );
		$this->assertTrue( $plugin['active'] );
	}

	public function test_list_derives_plugin_slug_from_single_file_plugin(): void {
		$this->stub_common();

		$plugin = InstalledSoftware::find( InstalledSoftware::list(), 'plugin', 'hello' );

		$this->assertNotNull( $plugin );
		$this->assertSame( '1.7', $plugin['version'] );
		$this->assertFalse( $plugin['active'] );
	}

	public function test_list_includes_theme_and_marks_active_theme(): void {
		$this->stub_common();

		$theme = InstalledSoftware::find( InstalledSoftware::list(), 'theme', 'twentytwentyfive' );

		$this->assertNotNull( $theme );
		$this->assertSame( '1.2', $theme['version'] );
		$this->assertTrue( $theme['active'] );
	}

	public function test_plugin_slugs_returns_only_plugin_kind_slugs(): void {
		$this->stub_common();

		$slugs = InstalledSoftware::plugin_slugs( InstalledSoftware::list() );

		sort( $slugs );
		$this->assertSame( [ 'hello', 'woocommerce' ], $slugs );
	}

	public function test_theme_slugs_returns_only_theme_kind_slugs(): void {
		$this->stub_common();

		$this->assertSame( [ 'twentytwentyfive' ], InstalledSoftware::theme_slugs( InstalledSoftware::list() ) );
	}

	public function test_core_version_returns_the_core_entry_version(): void {
		$this->stub_common();

		$this->assertSame( '6.6.2', InstalledSoftware::core_version( InstalledSoftware::list() ) );
	}

	public function test_core_version_returns_empty_string_when_list_has_no_core_entry(): void {
		$this->assertSame( '', InstalledSoftware::core_version( [] ) );
	}

	public function test_find_returns_null_when_not_present(): void {
		$this->stub_common();

		$this->assertNull( InstalledSoftware::find( InstalledSoftware::list(), 'plugin', 'ghost' ) );
	}
}
