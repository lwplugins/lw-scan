<?php
/**
 * The Scan tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Run\Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.1: the tab has two faces, chosen by `Runner::progress()`. Idle
 * shows the verdict hero, the start bar and the run history; a live run
 * replaces the start bar with the phase tiles, the progress bar, Stop and
 * the findings feed, which `assets/js/admin.js` then keeps up to date.
 *
 * The heavy lifting lives in the three panel classes; this one only picks
 * which of them to draw and hands them their data.
 */
final class TabScan implements TabInterface {

	public function slug(): string {
		return 'scan';
	}

	public function label(): string {
		return __( 'Scan', 'lw-scan' );
	}

	public function icon(): string {
		return 'dashicons-search';
	}

	public function has_save(): bool {
		return false;
	}

	public function render(): void {
		if ( ! Schema::exists() ) {
			ScanHero::render_missing_tables();

			return;
		}

		$progress = ( new Runner() )->progress();
		$running  = 'running' === $progress['status'];

		echo '<div class="lw-scan-stack" data-lw-scan-tab="scan">';

		if ( $running ) {
			ScanProgress::render( $progress );
		} else {
			ScanHero::render( $progress );
			ScanStarter::render();
			RunsTable::render( RunsRepository::latest( 10 ) );
		}

		echo '</div>';
	}
}
