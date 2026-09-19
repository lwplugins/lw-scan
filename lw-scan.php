<?php
/**
 * Plugin Name:       LW Scan
 * Plugin URI:        https://github.com/lwplugins/lw-scan
 * Description:       Lightweight malware scanner for WordPress — files, database and vulnerable software. Reports only, never modifies your site.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            LW Plugins
 * Author URI:        https://lwplugins.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lw-scan
 * Domain Path:       /languages
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LW_SCAN_VERSION', '1.1.0' );
define( 'LW_SCAN_FILE', __FILE__ );
define( 'LW_SCAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'LW_SCAN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( LW_SCAN_PATH . 'vendor/autoload.php' ) ) {
	require_once LW_SCAN_PATH . 'vendor/autoload.php';
} elseif ( ! class_exists( Plugin::class ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p><strong>LW Scan:</strong> %s</p></div>',
				esc_html__( 'Autoloader not found. Please run "composer install" in the plugin directory, or re-install the plugin from a release ZIP.', 'lw-scan' )
			);
		}
	);
	return;
}

register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Activator::class, 'deactivate' ] );

function lw_scan(): Plugin {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Plugin();
	}

	return $instance;
}

add_action(
	'plugins_loaded',
	static function (): void {
		lw_scan();
	}
);
