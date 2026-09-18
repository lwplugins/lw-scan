<?php
/**
 * Filesystem access to the plugin's private storage directory: the
 * downloaded signature pack files and the remote-response cache.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Bundle;

use function LightweightPlugins\Scan\lw_scan_storage_dir;

defined( 'ABSPATH' ) || exit;

/**
 * Owns `wp-content/lw-scan/` (see design spec §4.5): the deny-all guard
 * files, the versioned `pack-<v>.json` / `meta-<v>.json` / `new-<v>.json`
 * trio, and the `cache/` subdirectory used by Remote\FileCache. Deliberately
 * built on raw PHP filesystem calls (no WP_Filesystem) so it can run from
 * WP-Cron/CLI without an initialized filesystem abstraction, matching
 * Remote\PackFetcher and Remote\FileCache.
 */
final class Store {

	/**
	 * Absolute storage directory path, no trailing slash.
	 *
	 * @var string
	 */
	private string $dir;

	public function __construct( ?string $dir = null ) {
		$this->dir = rtrim( $dir ?? lw_scan_storage_dir(), '/\\' );
	}

	public function dir(): string {
		return $this->dir;
	}

	/**
	 * Creates the storage directory and its deny-all guard files if missing.
	 *
	 * @return bool Whether the directory exists and is writable afterwards.
	 */
	public function ensure_dir(): bool {
		if ( ! self::ensure_directory_exists( $this->dir ) ) {
			return false;
		}

		$index = $this->dir . '/index.php';

		if ( ! is_file( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- guard file under the plugin's own storage dir; a concurrent writer racing us here is not an error.
			@file_put_contents( $index, "<?php // Silence is golden.\n" );
		}

		$htaccess = $this->dir . '/.htaccess';

		if ( ! is_file( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- guard file under the plugin's own storage dir; a concurrent writer racing us here is not an error.
			@file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		return $this->is_writable();
	}

	public function is_writable(): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is unavailable here; this class runs without WordPress loaded (unit tests) and from WP-Cron/CLI without an initialized filesystem abstraction.
		return is_dir( $this->dir ) && is_writable( $this->dir );
	}

	public function pack_path( int $v ): string {
		return $this->dir . '/pack-' . $v . '.json';
	}

	public function meta_path( int $v ): string {
		return $this->dir . '/meta-' . $v . '.json';
	}

	public function new_path( int $v ): string {
		return $this->dir . '/new-' . $v . '.json';
	}

	/**
	 * Atomically writes `$data` to `$path` (tmp file + rename). Only creates
	 * `$path`'s immediate parent directory when missing — matching how the
	 * pack/meta/new files always sit directly in the storage dir — so a
	 * deeper missing hierarchy fails cleanly instead of being built out.
	 *
	 * The tmp file's name carries the pid and a unique id, not just `.tmp`:
	 * two writers racing on the same `$path` (two overlapping pack fetches)
	 * would otherwise share one tmp file and could each read back the
	 * other's partial write.
	 *
	 * @param string $path Absolute destination path.
	 * @param string $data Payload to write.
	 * @return bool Whether the file exists at `$path` afterwards; never leaves the tmp file behind.
	 */
	public function write_atomic( string $path, string $data ): bool {
		$dir = dirname( $path );

		// Only ever attempt to create $dir itself, and only when its own
		// parent already exists: this is a single mkdir(), never a
		// recursive one, so a multi-level missing hierarchy fails here
		// instead of being silently built out.
		if ( ! is_dir( $dir ) ) {
			if ( ! is_dir( dirname( $dir ) ) ) {
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent writer creating the same dir first is not an error.
			if ( ! @mkdir( $dir, 0755 ) && ! is_dir( $dir ) ) {
				return false;
			}
		}

		$tmp = $path . '.' . getmypid() . '.' . uniqid( '', true ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- pack/meta/new file under the plugin's own storage dir; atomic tmp+rename write needs a real filesystem rename anyway.
		if ( false === @file_put_contents( $tmp, $data ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of a partial temp file.
			@unlink( $tmp );

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- see file_put_contents note above; atomic tmp+rename write.
		if ( ! @rename( $tmp, $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of the temp file after a failed rename.
			@unlink( $tmp );

			return false;
		}

		return true;
	}

	/**
	 * @param int $v Pack version.
	 * @return bool Whether both `pack-<v>.json` and `meta-<v>.json` exist and are non-empty.
	 */
	public function has_pack( int $v ): bool {
		return $v > 0 && self::non_empty( $this->pack_path( $v ) ) && self::non_empty( $this->meta_path( $v ) );
	}

	/**
	 * Removes a version's pack, meta and new-signatures files.
	 *
	 * @param int $v Pack version to delete.
	 */
	public function delete_version( int $v ): void {
		foreach ( [ $this->pack_path( $v ), $this->meta_path( $v ), $this->new_path( $v ) ] as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of a stale pack file under the plugin's own storage dir.
				@unlink( $file );
			}
		}
	}

	private static function non_empty( string $path ): bool {
		clearstatcache( true, $path );

		return is_file( $path ) && filesize( $path ) > 0;
	}

	/**
	 * Deletes every legacy `bundle-*` file unconditionally (nothing writes
	 * or reads the pre-pack-format compiled bundle any more, so its version
	 * number is meaningless), every `pack-*`, `meta-*` and `new-*` file
	 * whose version is not `$v`, plus any stray `*.tmp` file left behind by
	 * a write_atomic() that never reached its rename (a crashed or killed
	 * request).
	 *
	 * @param int $v Version to keep.
	 * @return int Number of files removed.
	 */
	public function prune_except( int $v ): int {
		$removed = 0;

		foreach ( $this->legacy_bundle_files() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort prune of a dead legacy bundle file.
			if ( @unlink( $file ) ) {
				++$removed;
			}
		}

		foreach ( $this->pack_meta_new_files() as $file => $version ) {
			if ( $version === $v ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort prune of a stale pack/meta/new file.
			if ( @unlink( $file ) ) {
				++$removed;
			}
		}

		foreach ( (array) glob( $this->dir . '/*.tmp' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort prune of a stray temp file from an interrupted write.
			if ( @unlink( (string) $file ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Total size, in bytes, of every regular file under the storage
	 * directory (bundle files, guard files, and the cache/ subtree).
	 */
	public function size_bytes(): int {
		return $this->dir_size( $this->dir );
	}

	private function dir_size( string $dir ): int {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$total = 0;

		foreach ( (array) glob( $dir . '/*' ) as $entry ) {
			if ( is_dir( $entry ) ) {
				$total += $this->dir_size( $entry );
				continue;
			}

			$size   = filesize( $entry );
			$total += false !== $size ? $size : 0;
		}

		// glob() without GLOB_BRACE skips dotfiles such as .htaccess.
		$htaccess = $dir . '/.htaccess';

		if ( is_file( $htaccess ) ) {
			$size   = filesize( $htaccess );
			$total += false !== $size ? $size : 0;
		}

		return $total;
	}

	/**
	 * Creates (if missing) and returns the response-cache subdirectory used
	 * by Remote\FileCache.
	 */
	public function cache_dir(): string {
		$dir = $this->dir . '/cache';

		self::ensure_directory_exists( $dir );

		return $dir;
	}

	/**
	 * Creates `$dir` (recursively) if it doesn't already exist.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool Whether the directory exists (already did, or was just created) afterwards.
	 */
	private static function ensure_directory_exists( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent writer creating the same dir first is not an error.
		return @mkdir( $dir, 0755, true ) || is_dir( $dir );
	}

	/**
	 * @return array<string,int> Absolute pack/meta/new file path => its version.
	 */
	private function pack_meta_new_files(): array {
		$files = [];

		// GLOB_BRACE is unavailable on some systems (Alpine/musl).
		$paths = defined( 'GLOB_BRACE' )
			? (array) glob( $this->dir . '/{pack,meta,new}-*', GLOB_BRACE )
			: array_merge( (array) glob( $this->dir . '/pack-*' ), (array) glob( $this->dir . '/meta-*' ), (array) glob( $this->dir . '/new-*' ) );

		foreach ( $paths as $file ) {
			if ( 1 === preg_match( '/(?:pack|meta|new)-(\d+)\.json$/', (string) $file, $m ) ) {
				$files[ (string) $file ] = (int) $m[1];
			}
		}

		return $files;
	}

	/**
	 * Legacy compiled-bundle files from the pre-pack-format storage layout
	 * (`bundle-<v>.php` and `bundle-<v>.json.gz`). Nothing writes or reads
	 * these any more, so every one of them is dead regardless of its
	 * version number.
	 *
	 * @return string[] Absolute paths of legacy bundle files.
	 */
	private function legacy_bundle_files(): array {
		$files = [];

		foreach ( (array) glob( $this->dir . '/bundle-*' ) as $file ) {
			if ( 1 === preg_match( '/bundle-\d+\.(?:json\.gz|php)$/', (string) $file ) ) {
				$files[] = (string) $file;
			}
		}

		return $files;
	}
}
