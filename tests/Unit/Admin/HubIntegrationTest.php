<?php
/**
 * Checks the synced LW Plugins hub copy (lwplugins/admin-hub).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Admin\Hub\Assets;
use LightweightPlugins\Scan\Admin\Hub\Hub;
use LightweightPlugins\Scan\Admin\Hub\RegistryFallback;
use LightweightPlugins\Scan\Admin\ParentPage;
use LightweightPlugins\Scan\Autoloader;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * The hub logic itself is tested in the admin-hub repo; these tests prove the
 * copy landed in this plugin's namespace, text domain and asset layout, and
 * that the release ZIP's own autoloader (no vendor/) finds it.
 */
final class HubIntegrationTest extends MonkeyTestCase {

	/**
	 * Plugin root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 3 ) . '/';
	}

	protected function tearDown(): void {
		Hub::reset();
		parent::tearDown();
	}

	public function test_candidate_is_this_plugins_copy(): void {
		Hub::init( $this->root() . 'lw-scan.php' );

		$candidate = Hub::add_candidate( [] )[0];

		$this->assertSame( 'LightweightPlugins\\Scan\\Admin\\Hub\\Hub', $candidate['class'] );
		$this->assertSame( Hub::VERSION, $candidate['version'] );
	}

	public function test_legacy_parent_page_api_is_kept(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( [ 'lw-scan' => [ 'name' => 'LW Scan' ] ] );
		Functions\stubTranslationFunctions();

		$this->assertSame( 'lw-plugins', ParentPage::SLUG );
		$this->assertSame( [ 'lw-scan' ], array_keys( ParentPage::get_plugins_registry() ) );
	}

	public function test_bundled_assets_use_this_text_domain(): void {
		$dir = $this->root() . Assets::DIR . '/';
		$js  = (string) file_get_contents( $dir . 'index.js' );

		$this->assertFileExists( $dir . 'index.css' );
		$this->assertFileExists( $dir . 'index.asset.json' );
		$this->assertStringContainsString( '"lw-scan"', $js );
		$this->assertStringNotContainsString( 'lw-admin-hub', $js );
		$this->assertFileExists( $this->root() . 'languages/lw-scan-hu_HU-' . md5( Assets::DIR . '/index.js' ) . '.json' );
	}

	public function test_every_bundled_registry_plugin_has_an_icon(): void {
		Functions\stubTranslationFunctions();

		foreach ( array_keys( RegistryFallback::get() ) as $slug ) {
			$this->assertFileExists( $this->root() . Assets::DIR . '/icons/' . $slug . '.svg' );
		}
	}

	public function test_own_autoloader_resolves_every_hub_class(): void {
		$files = glob( $this->root() . 'includes/Admin/Hub/*.php' );

		$this->assertNotEmpty( $files );

		foreach ( (array) $files as $file ) {
			$class = 'LightweightPlugins\\Scan\\Admin\\Hub\\' . basename( $file, '.php' );

			$this->assertSame( realpath( $file ), realpath( (string) Autoloader::path_for( $class ) ), $class );
		}
	}
}
