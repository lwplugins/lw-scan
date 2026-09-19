<?php
/**
 * Uninstall handler.
 *
 * Runs only when the plugin is deleted from the Plugins screen. LW Scan
 * keeps nothing back: the settings, the run state, the three scan tables and
 * the private storage directory under wp-content/lw-scan are all removed. A
 * findings list is a report about a site, not the site's own content — the
 * next scan after a re-install derives all of it again.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Everything that needs nothing but WordPress itself runs inline here, with
 * literal option/transient/hook names instead of the plugin's own class
 * constants: this file executes without the Composer autoloader, so it must
 * not depend on it for something this small. It must run whether or not
 * vendor/autoload.php exists.
 */
delete_option( 'lw_scan_options' );
delete_option( 'lw_scan_state' );
delete_option( 'lw_scan_db_version' );
delete_option( 'lw_scan_upgrading' );
delete_option( 'lw_scan_status_endpoint' );

delete_transient( 'lw_scan_health' );
delete_transient( 'lw_scan_lock' );
delete_transient( 'lw_scan_catchup' );
delete_transient( 'lw_scan_install_retry' );
delete_transient( 'lw_scan_status_report' );

wp_clear_scheduled_hook( 'lw_scan_scheduled' );
wp_clear_scheduled_hook( 'lw_scan_tick' );

// Belt-and-braces: clears any single events left on either hook regardless
// of the arguments they were scheduled with.
wp_unschedule_hook( 'lw_scan_scheduled' );
wp_unschedule_hook( 'lw_scan_tick' );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';

	LightweightPlugins\Scan\Uninstall\Uninstaller::run();
} else {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- no autoloader means no logger; this is the only way to surface why the tables and the storage directory were kept.
	error_log( 'lw-scan uninstall: vendor/autoload.php missing — the scan tables and wp-content/lw-scan were left in place (everything else was removed).' );
}
