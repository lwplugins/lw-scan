<?php
/**
 * One-shot cleanup after the plugin's version changes.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan;

use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Health\Environment;
use LightweightPlugins\Scan\Remote\ChecksumProvider;
use LightweightPlugins\Scan\Remote\FileCache;
use LightweightPlugins\Scan\Run\Cursor;

defined( 'ABSPATH' ) || exit;

/**
 * Two of the scanner's verdicts are sticky: a cached checksum list lives
 * for seven days, and a file's `known_good` flag is only recomputed when
 * the hash pass re-reads that file — which it does only when the file
 * changed on disk. So a release that fixes how checksums are parsed, or
 * which files count as the scanner's own, would change nothing at all on
 * an existing install: the site would keep the old build's false integrity
 * alerts and self-detections for up to a week.
 *
 * `maybe_upgrade()` closes that window. It runs on the first request after
 * the plugin's version changes (including the first request after a fresh
 * install, where the marker is still the empty default), drops the checksum
 * cache and the derived hash/known-good columns, and lets the next run
 * rebuild both against the new code. The index rows themselves survive, so
 * the walk stays incremental and nothing is rescanned that a fresh hash
 * pass would not have touched anyway.
 *
 * Every request pays for the version comparison, so the marker lives in the
 * autoloaded `Options` rather than in `State`, whose option is not
 * autoloaded: checking it must not cost a query. Everything past that
 * comparison — the schema probe, the cursor read, the claim — only ever
 * runs on the one request that actually upgrades.
 *
 * Three things make it skip rather than do half the work:
 *
 * - a run cursor exists: that run is mid-flight over exactly the columns
 *   this resets;
 * - a table is missing: `Schema::maybe_install()` is in its failure
 *   back-off, so there is nothing to reset and the marker must not be
 *   written as though there had been;
 * - another request already holds the claim.
 *
 * In all three cases the marker stays behind and the next request retries.
 */
final class Upgrader {

	/**
	 * Key in `Options` holding the version this site last upgraded to.
	 */
	public const VERSION_KEY = 'plugin_version';

	/**
	 * Option name held for the duration of an upgrade, so two requests
	 * arriving together do not both purge and reset.
	 */
	public const CLAIM_OPTION = 'lw_scan_upgrading';

	/**
	 * Seconds after which a claim counts as abandoned. An upgrade is three
	 * statements and a directory sweep; anything still holding the claim
	 * after ten minutes died holding it (a fatal, a killed worker) and must
	 * not block the upgrade forever.
	 */
	private const CLAIM_TTL = 600;

	/**
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$from = (string) Options::get( self::VERSION_KEY, '' );
		$to   = (string) LW_SCAN_VERSION;

		if ( $from === $to ) {
			return;
		}

		if ( [] !== Schema::missing_tables() ) {
			return;
		}

		if ( null !== Cursor::load() ) {
			return;
		}

		$claimed_at = self::claim();

		if ( 0 === $claimed_at ) {
			return;
		}

		try {
			self::purge_checksum_cache();
			( new FilesRepository() )->reset_hashes();
			Environment::invalidate();

			Options::update( [ self::VERSION_KEY => $to ] + self::keep_mail_on( $from ) );

			/**
			 * Fires once after the plugin has finished its post-upgrade cleanup.
			 *
			 * @param string $from Version the site was on, '' on a fresh install.
			 * @param string $to   Version now running.
			 */
			do_action( 'lw_scan_upgraded', $from, $to );
		} finally {
			self::release( $claimed_at );
		}
	}

	/**
	 * E-mail is off by default since 1.4.5, but an install that already
	 * existed and never stored `notify_enabled` was mailing under the old
	 * default. Pin that choice so the new default does not silence it.
	 * A fresh install (`$from` is '') gets the new default.
	 *
	 * @param string $from Version the site was on, '' on a fresh install.
	 * @return array<string, bool> The pin to store, empty when none is needed.
	 */
	private static function keep_mail_on( string $from ): array {
		$raw = get_option( Options::OPTION_NAME, [] );

		if ( '' === $from || ! is_array( $raw ) || array_key_exists( 'notify_enabled', $raw ) ) {
			return [];
		}

		return [ 'notify_enabled' => true ];
	}

	/**
	 * Takes the upgrade claim, or reports that someone else holds it.
	 *
	 * `add_option()` is the claim: it refuses an option name that already
	 * exists, so the first caller to reach it wins and every other caller
	 * is told to come back later. Non-autoloaded on purpose — it exists for
	 * seconds, and no ordinary request has any reason to read it.
	 *
	 * @return int The timestamp this request wrote, or 0 when someone else owns the upgrade.
	 */
	private static function claim(): int {
		$now = time();

		if ( add_option( self::CLAIM_OPTION, $now, '', false ) ) {
			return $now;
		}

		$claimed_at = (int) get_option( self::CLAIM_OPTION, 0 );

		if ( $claimed_at + self::CLAIM_TTL > $now ) {
			return 0;
		}

		// Abandoned (or unreadable, which amounts to the same): take it over
		// once. A second failure means another request got there first, and
		// this one has nothing left to do.
		delete_option( self::CLAIM_OPTION );

		$now = time();

		return add_option( self::CLAIM_OPTION, $now, '', false ) ? $now : 0;
	}

	/**
	 * Gives back the claim this request took — and only that one. An upgrade
	 * that outlives its own TTL is taken over by a later request, and the
	 * survivor then arrives at its `finally` holding nothing: deleting the
	 * claim there would hand the option to a third request while the second
	 * is still working. The stored timestamp is what tells the two apart.
	 *
	 * @param int $claimed_at The timestamp this request wrote when it claimed.
	 */
	private static function release( int $claimed_at ): void {
		// Read past the object cache: this request wrote the claim itself,
		// so a cached read hands back its own timestamp -- the one value
		// this comparison must not trust. Same move as StopFlag makes for
		// the same reason.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::CLAIM_OPTION, 'options' );
		}

		if ( (int) get_option( self::CLAIM_OPTION, 0 ) !== $claimed_at ) {
			return;
		}

		delete_option( self::CLAIM_OPTION );
	}

	/**
	 * Deletes every cached checksum list. Only that family: the
	 * vulnerability feed is keyed by software version rather than by how
	 * this plugin parses it, so it survives an upgrade unharmed.
	 *
	 * Returns early when the storage directory is not there yet — on a
	 * fresh install there is nothing cached to purge, and `cache_dir()`
	 * would create the tree as a side effect of looking.
	 *
	 * @return int Number of cache files removed.
	 */
	private static function purge_checksum_cache(): int {
		$store = new Store();

		if ( ! is_dir( $store->dir() ) ) {
			return 0;
		}

		return ( new FileCache( $store->cache_dir() ) )->purge_prefix( ChecksumProvider::CACHE_PREFIX );
	}
}
