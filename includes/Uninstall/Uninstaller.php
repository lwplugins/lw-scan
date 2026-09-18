<?php
/**
 * Removes everything the plugin created, when it is deleted.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Uninstall;

use FilesystemIterator;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Scheduler;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Upgrader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function LightweightPlugins\Scan\lw_scan_storage_dir;

defined( 'ABSPATH' ) || exit;

/**
 * Called from uninstall.php (WP_UNINSTALL_PLUGIN context only).
 *
 * The plugin keeps nothing back: its options, transients, cron hooks, three
 * tables and private storage directory all go. Findings are a report about
 * a site, not the site's own content — a re-install re-derives every one of
 * them from the next scan.
 *
 * The option/transient/hook cleanup below repeats what uninstall.php
 * already ran inline before the autoloader was available. That is a
 * harmless double-delete, and it keeps this class correct on its own.
 */
final class Uninstaller {

	/** The one directory name the uninstaller is ever allowed to delete. */
	private const STORAGE_DIR_NAME = 'lw-scan';

	/** @var array<int, string> Transient names; the constants holding them are private to their own classes. */
	private const TRANSIENTS = [
		'lw_scan_health',  // Health\Environment::TRANSIENT.
		'lw_scan_lock',    // Run\Lock::TRANSIENT.
		'lw_scan_catchup', // Run\CatchUp::FLAG.
		'lw_scan_install_retry', // Db\Schema::RETRY_TRANSIENT.
	];

	/**
	 * Removes every trace of the plugin.
	 *
	 * @return void
	 */
	public static function run(): void {
		delete_option( Options::OPTION_NAME );
		delete_option( State::OPTION_NAME );
		delete_option( Schema::VERSION_OPTION );
		delete_option( Upgrader::CLAIM_OPTION );

		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}

		wp_clear_scheduled_hook( Scheduler::HOOK_SCHEDULED );
		wp_clear_scheduled_hook( Scheduler::HOOK_TICK );

		// Belt-and-braces: clears any single events left on either hook
		// regardless of the arguments they were scheduled with.
		wp_unschedule_hook( Scheduler::HOOK_SCHEDULED );
		wp_unschedule_hook( Scheduler::HOOK_TICK );

		Schema::drop();

		// The plugin is already deactivated by the time an uninstall runs,
		// so a `lw_scan_storage_dir` filter only applies here when it lives
		// somewhere still loaded (an mu-plugin, the theme). Where it does
		// not, this deletes the default location — which is where the files
		// are on every site that never moved them.
		if ( ! self::delete_storage_dir( lw_scan_storage_dir(), WP_CONTENT_DIR ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- an uninstall has no admin notice left to raise and must not fail the request; the log line is the only way a leftover directory becomes discoverable.
			error_log( 'lw-scan uninstall: ' . lw_scan_storage_dir() . ' could not be removed and was left in place.' );
		}
	}

	/**
	 * Recursively deletes the plugin's storage directory — the signature
	 * pack and the checksum/vulnerability cache.
	 *
	 * Takes the content directory as an argument rather than reading
	 * `WP_CONTENT_DIR` itself, so the guard can be exercised against a real
	 * directory tree in a test.
	 *
	 * @param string $dir         Directory to delete, from `lw_scan_storage_dir()`.
	 * @param string $content_dir The site's content directory (`WP_CONTENT_DIR`).
	 * @return bool True when the directory is gone (deleted, or never there).
	 *              False on every path that leaves it standing: the guard
	 *              refused it, it is a symlink (unlinking what it points at
	 *              is not this function's call), or the walk could not
	 *              remove everything inside it.
	 */
	public static function delete_storage_dir( string $dir, string $content_dir ): bool {
		if ( ! self::safe_to_delete( $dir, $content_dir ) ) {
			return false;
		}

		if ( is_dir( $dir ) && ! is_link( $dir ) ) {
			self::delete_tree( $dir );
		}

		return ! is_dir( $dir );
	}

	/**
	 * Deletes a directory and everything below it, depth-first.
	 *
	 * Symlinks are unlinked, never followed:
	 * `RecursiveDirectoryIterator::hasChildren()` refuses to descend into a
	 * symlinked directory unless explicitly allowed, so the iterator hands
	 * one over as a leaf and it is removed with `unlink()` — whatever it
	 * pointed at is left alone.
	 *
	 * `CATCH_GET_CHILD` keeps an unreadable subdirectory from throwing out
	 * of the iterator: an uninstall that cannot delete everything must
	 * still finish, never fatal on the plugin-delete request.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	private static function delete_tree( string $dir ): void {
		if ( ! is_readable( $dir ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		/** @var SplFileInfo $item */
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- uninstall runs without an initialized WP_Filesystem (WP loads it for the plugin-delete request only); a directory that cannot be removed is not actionable here.
				@rmdir( $item->getPathname() );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- see above; wp_delete_file() would fire the `wp_delete_file` action for every cache entry during an uninstall.
			@unlink( $item->getPathname() );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- see above; the directory itself, now empty.
		@rmdir( $dir );
	}

	/**
	 * Whether `$dir` is a directory this plugin may recursively delete.
	 *
	 * Pure by design — the whole guard in front of a recursive delete, with
	 * no filesystem access, so every branch is testable. Two conditions,
	 * both required: the path sits inside the site's content directory, and
	 * its last segment is exactly `lw-scan`. A `..` segment fails it
	 * outright rather than being resolved, because resolving would mean
	 * `realpath()` — and a symlinked storage dir would then resolve to a
	 * target outside the content dir that this check just approved.
	 *
	 * @param string $dir         Directory to delete, from `lw_scan_storage_dir()`.
	 * @param string $content_dir The site's content directory (`WP_CONTENT_DIR`).
	 * @return bool True when deleting `$dir` is safe.
	 */
	public static function safe_to_delete( string $dir, string $content_dir ): bool {
		$dir     = self::normalize( $dir );
		$content = self::normalize( $content_dir );

		if ( '' === $dir || '' === $content || $dir === $content ) {
			return false;
		}

		if ( in_array( '..', explode( '/', $dir ), true ) ) {
			return false;
		}

		if ( 0 !== strpos( $dir . '/', $content . '/' ) ) {
			return false;
		}

		return self::STORAGE_DIR_NAME === basename( $dir );
	}

	/**
	 * Forward-slashed path with any trailing slash removed.
	 *
	 * @param string $path Path to normalise.
	 * @return string
	 */
	private static function normalize( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
