<?php
/**
 * Plugin Activator.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan;

use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\PackFetcher;
use LightweightPlugins\Scan\Run\Scheduler;
use LightweightPlugins\Scan\Status\EndpointSettings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation and deactivation.
 *
 * Installs the schema and the options row, (de)schedules the recurring scan,
 * gives the status endpoint its key, and pulls the signature bundle down
 * while someone is watching — so the first scan already has signatures
 * instead of having to fetch them mid-run.
 */
final class Activator {

	public static function activate(): void {
		Schema::install();

		if ( false === get_option( Options::OPTION_NAME ) ) {
			add_option( Options::OPTION_NAME, Options::get_defaults(), '', true );
		}

		Scheduler::schedule();
		self::provision_status_key();
		self::seed_signatures();
	}

	public static function deactivate(): void {
		Scheduler::unschedule();
	}

	/**
	 * The status endpoint is on by default, so it gets its key here; a
	 * re-activation keeps the key it already has. Best effort like the
	 * signature download below: `random_bytes()` can throw where the
	 * system has no entropy source, and the Status tab provisions the key
	 * on its first visit anyway.
	 */
	private static function provision_status_key(): void {
		try {
			( new EndpointSettings() )->ensure_key();
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Downloads the signature pack, best effort.
	 *
	 * Belt and braces, not a requirement: the first scan's `bundle` phase
	 * downloads one anyway if this does not (spec §14), so nothing here may
	 * stop an activation. `PackFetcher` already answers a backend failure
	 * with a `failed` result rather than an exception; the catch is for
	 * everything underneath it that can still throw — a transport, a filter
	 * on one of WordPress' own HTTP hooks, a storage directory that turns
	 * out not to be writable. A plugin that refuses to activate because a
	 * server was down is worse than one that starts without signatures.
	 *
	 * Unlike the old compiled bundle, a pack is a plain JSON download and
	 * verify — no in-process compile step — so there is no memory floor to
	 * raise or check before attempting it here.
	 */
	private static function seed_signatures(): void {
		try {
			( new PackFetcher( new Client(), new Store() ) )->check( true );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
