<?php
/**
 * JSON file cache for backend responses (checksums, vulnerability feeds).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Stores small JSON payloads as files in a given directory, keyed by an
 * arbitrary string that gets filesystem-sanitized. Deliberately built on raw
 * PHP filesystem calls rather than WP_Filesystem/wp_json_encode() so it can
 * run in unit tests without WordPress loaded, and stays a plain local cache
 * with no locking beyond the atomic tmp+rename write.
 */
final class FileCache {

	/**
	 * Absolute cache directory path, no trailing slash.
	 *
	 * @var string
	 */
	private string $dir;

	public function __construct( string $dir ) {
		$this->dir = rtrim( $dir, '/\\' );
	}

	/**
	 * @param string $key Cache key.
	 * @param int    $ttl Time-to-live in seconds.
	 * @return array<string,mixed>|null Null when missing, expired, or invalid JSON.
	 */
	public function get( string $key, int $ttl ): ?array {
		$file = $this->path( $key );

		if ( ! is_file( $file ) ) {
			return null;
		}

		$mtime = filemtime( $file );

		if ( false === $mtime || $mtime + $ttl < time() ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache file under the plugin's own storage dir, not a remote URL.
		$contents = file_get_contents( $file );

		if ( false === $contents ) {
			return null;
		}

		$data = json_decode( $contents, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Atomically writes a cache entry (write to a .tmp file, then rename).
	 *
	 * @param string               $key  Cache key.
	 * @param array<string, mixed> $data Data to store as JSON.
	 * @return bool
	 */
	public function put( string $key, array $data ): bool {
		if ( ! is_dir( $this->dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent writer creating the same dir first is not an error.
			if ( ! @mkdir( $this->dir, 0755, true ) && ! is_dir( $this->dir ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() is unavailable here; this class runs without WordPress loaded (unit tests).
		$json = json_encode( $data );

		if ( false === $json ) {
			return false;
		}

		$file = $this->path( $key );
		$tmp  = $file . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local cache file under the plugin's own storage dir; WP_Filesystem is unavailable here (unit tests, no WordPress loaded).
		if ( false === file_put_contents( $tmp, $json ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem is unavailable here; this class runs without WordPress loaded (unit tests), and the atomic tmp+rename write needs a real filesystem rename anyway.
		if ( ! rename( $tmp, $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of the temp file; failure here isn't actionable.
			@unlink( $tmp );

			return false;
		}

		return true;
	}

	public function delete( string $key ): void {
		$file = $this->path( $key );

		if ( is_file( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent delete racing us here is not an error.
			@unlink( $file );
		}
	}

	/**
	 * @param string $key Cache key.
	 * @return string Absolute path of the cache file for this key.
	 */
	public function path( string $key ): string {
		return $this->dir . '/' . self::sanitize( $key ) . '.json';
	}

	/**
	 * Maps an arbitrary cache key (or key prefix) onto the filename-safe
	 * alphabet the cache stores it under. Shared by `path()` and
	 * `purge_prefix()` so a prefix is matched exactly the way the keys it
	 * should match were written — and so a prefix can never smuggle a glob
	 * metacharacter (`*`, `?`, `[`) into the sweep below.
	 *
	 * @param string $key Cache key, or the leading part of one.
	 * @return string
	 */
	private static function sanitize( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9._-]/i', '_', $key );
	}

	public function size_bytes(): int {
		$total = 0;

		foreach ( $this->files() as $file ) {
			$size   = filesize( $file );
			$total += false !== $size ? $size : 0;
		}

		return $total;
	}

	public function clear(): int {
		return $this->purge_prefix( '' );
	}

	/**
	 * Deletes every entry whose key starts with `$prefix` — the one-shot
	 * sweep `Upgrader` uses to drop a whole family of cached responses
	 * (`ChecksumProvider::CACHE_PREFIX`) without disturbing the others.
	 *
	 * @param string $prefix Cache key prefix; an empty string matches every entry.
	 * @return int Number of files removed.
	 */
	public function purge_prefix( string $prefix ): int {
		$count = 0;

		foreach ( $this->files( $prefix ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort delete during a purge sweep.
			if ( @unlink( $file ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * The entries belonging to a key prefix — the single source of truth
	 * for "which cache files are this family's", shared by `purge_prefix()`,
	 * `size_bytes()` and `Health\Environment`'s tile counts.
	 *
	 * @param string $prefix Cache key prefix; an empty string matches every entry.
	 * @return string[] Absolute paths of the matching *.json files directly in the cache dir.
	 */
	public function files( string $prefix = '' ): array {
		if ( ! is_dir( $this->dir ) ) {
			return [];
		}

		$found = glob( $this->dir . '/' . self::sanitize( $prefix ) . '*.json' );

		return false !== $found ? $found : [];
	}
}
