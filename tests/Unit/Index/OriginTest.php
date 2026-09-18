<?php
/**
 * Tests for Index\Origin.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Index;

use LightweightPlugins\Scan\Index\Origin;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class OriginTest extends MonkeyTestCase {

	/**
	 * @var array<int,string>
	 */
	private array $plugin_slugs;

	/**
	 * @var array<int,string>
	 */
	private array $theme_slugs;

	protected function setUp(): void {
		parent::setUp();

		$this->plugin_slugs = [ 'akismet', 'lw-seo' ];
		$this->theme_slugs  = [ 'twentytwentyfour' ];
	}

	public function test_installed_plugin_file_is_plugin_origin(): void {
		$origin = Origin::of( 'wp-content/plugins/akismet/akismet.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'plugin:akismet', $origin );
	}

	public function test_plugin_folder_not_in_installed_list_is_other(): void {
		$origin = Origin::of( 'wp-content/plugins/rogue-slug/x.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'other', $origin );
	}

	public function test_installed_theme_file_is_theme_origin(): void {
		$origin = Origin::of( 'wp-content/themes/twentytwentyfour/style.css', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'theme:twentytwentyfour', $origin );
	}

	public function test_theme_folder_not_in_installed_list_is_other(): void {
		$origin = Origin::of( 'wp-content/themes/rogue-theme/style.css', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'other', $origin );
	}

	public function test_mu_plugins_file_is_mu_origin(): void {
		$origin = Origin::of( 'wp-content/mu-plugins/site-compat-layer.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'mu', $origin );
	}

	public function test_uploads_file_is_uploads_origin(): void {
		$origin = Origin::of( 'wp-content/uploads/2026/09/cafccefhgc.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'uploads', $origin );
	}

	public function test_wp_includes_file_is_core_origin(): void {
		$origin = Origin::of( 'wp-includes/blocks/DYO/block/debug-compat.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'core', $origin );
	}

	public function test_wp_admin_file_is_core_origin(): void {
		$origin = Origin::of( 'wp-admin/js/polym/AQ/wp-login.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'core', $origin );
	}

	public function test_root_wp_star_php_file_is_core_origin(): void {
		$origin = Origin::of( 'wp-login.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'core', $origin );
	}

	public function test_root_index_php_is_core_origin(): void {
		$origin = Origin::of( 'index.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'core', $origin );
	}

	public function test_root_xmlrpc_php_is_core_origin(): void {
		$origin = Origin::of( 'xmlrpc.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'core', $origin );
	}

	public function test_unrelated_root_directory_is_other(): void {
		$origin = Origin::of( 'TpAEMU/index.php', $this->plugin_slugs, $this->theme_slugs );

		$this->assertSame( 'other', $origin );
	}
}
