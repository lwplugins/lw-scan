<?php
/**
 * Main Plugin class.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan;

use LightweightPlugins\Scan\Admin\Ajax\Handlers;
use LightweightPlugins\Scan\Admin\SettingsPage;
use LightweightPlugins\Scan\CLI\Commands;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\HelloPack\StatusCheck;
use LightweightPlugins\Scan\Notify\AdminNotice;
use LightweightPlugins\Scan\Run\CatchUp;
use LightweightPlugins\Scan\Run\Scheduler;
use LightweightPlugins\Scan\SiteManager\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up plugin components and hooks.
 */
final class Plugin {

	public function __construct() {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		$this->init_components();
	}

	/**
	 * Registers plugin components in a fixed order:
	 *
	 * - Db\Schema::maybe_install()   one cached option read; installs or
	 *                                upgrades the three tables when behind
	 * - Upgrader::maybe_upgrade()    one autoloaded option read; drops the
	 *                                checksum cache and the derived
	 *                                hash/known-good columns on the first
	 *                                request after a version change, and
	 *                                returns at the comparison otherwise
	 * - Run\Scheduler                the two WP-Cron hooks and the
	 *                                settings-save reschedule
	 * - Run\CatchUp                  the missed-schedule recovery, on `init`
	 * - Admin\*                      is_admin() only
	 * - CLI\Commands                 WP_CLI only
	 * - SiteManager\Abilities        the four `lw-scan/*` abilities
	 * - HelloPack\StatusCheck        the `lw_scan` status check
	 *
	 * The scanning pipeline itself (Bundle\*, Index\*, Scanner\*, DbScan\*,
	 * Vuln\*) hooks nothing: `Run\Runner` drives it from whichever entry
	 * point opened the run, so none of it loads on an ordinary request.
	 *
	 * The last two register unconditionally: both only ever reach their
	 * optional host (the Abilities API, HelloPack Client) through a hook
	 * that does not fire — or a guard that does not pass — where it is
	 * absent.
	 */
	private function init_components(): void {
		Schema::maybe_install();
		Upgrader::maybe_upgrade();

		Scheduler::register();
		CatchUp::register();

		if ( is_admin() ) {
			AdminNotice::register();
			SettingsPage::register();
			Handlers::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}

		Abilities::register();
		StatusCheck::register();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'lw-scan',
			false,
			dirname( plugin_basename( LW_SCAN_FILE ) ) . '/languages'
		);
	}
}
