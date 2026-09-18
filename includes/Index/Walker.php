<?php
/**
 * Recursively walks a directory, yielding scannable files.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Index;

use FilesystemIterator;
use Generator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function LightweightPlugins\Scan\lw_scan_storage_exclusion;

defined( 'ABSPATH' ) || exit;

/**
 * Pure filesystem walker (spec §6.1). Never stores the file list in memory;
 * `walk()` is a generator so a caller can process (or time-budget) one file
 * at a time. `.git`, `node_modules` (matched as a path segment, any depth)
 * and the plugin's own storage directory are excluded regardless of
 * `$excluded_patterns`, since the plugin must never scan the signature bundle
 * it just downloaded, or VCS/tooling cruft. The storage directory is asked
 * for (`lw_scan_storage_exclusion()`) rather than written out, so a site that
 * moves it with the `lw_scan_storage_dir` filter is still covered.
 * Symlinked directories are not entered unless `$follow_symlinks`.
 */
final class Walker {

	/**
	 * Path segments that are excluded at any depth.
	 *
	 * @var array<int,string>
	 */
	private const ALWAYS_EXCLUDED_SEGMENTS = [ '.git', 'node_modules' ];

	/**
	 * @var string
	 */
	private string $root;

	/**
	 * @var string
	 */
	private string $rel_base;

	/**
	 * @var array<int,string>
	 */
	private array $excluded_patterns;

	/**
	 * @var bool
	 */
	private bool $follow_symlinks;

	/**
	 * Full relative paths (and everything under them) that are excluded
	 * whatever the configured patterns say. Resolved once, in the
	 * constructor: `accept()` asks for them for every entry the walk sees —
	 * tens of thousands on an ordinary site — and `lw_scan_storage_exclusion()`
	 * runs a filter, which is not something to do per file.
	 *
	 * @var array<int,string>
	 */
	private array $always_excluded_paths;

	/**
	 * @param string            $root              Absolute directory to iterate.
	 * @param string            $rel_base          ABSPATH-relative prefix of $root; '' for ABSPATH itself.
	 * @param array<int,string> $excluded_patterns Normalized glob patterns, ABSPATH-relative.
	 * @param bool              $follow_symlinks   Whether to descend into symlinked directories.
	 */
	public function __construct( string $root, string $rel_base, array $excluded_patterns, bool $follow_symlinks = false ) {
		$this->root              = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->rel_base          = trim( str_replace( '\\', '/', $rel_base ), '/' );
		$this->excluded_patterns = $excluded_patterns;
		$this->follow_symlinks   = $follow_symlinks;

		$storage = lw_scan_storage_exclusion();

		$this->always_excluded_paths = '' === $storage ? [] : [ $storage ];
	}

	/**
	 * @return Generator<int, array{path:string, size:int, mtime:int, unreadable:bool}>
	 */
	public function walk(): Generator {
		if ( '' === $this->root || ! is_dir( $this->root ) ) {
			return;
		}

		$flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS;

		if ( $this->follow_symlinks ) {
			// Without this flag RecursiveDirectoryIterator reports symlinked
			// directories as childless leaves, so recursion never enters them.
			$flags |= FilesystemIterator::FOLLOW_SYMLINKS;
		}

		$directory = new RecursiveDirectoryIterator( $this->root, $flags );

		$filter = new RecursiveCallbackFilterIterator(
			$directory,
			function ( SplFileInfo $current ): bool {
				return $this->accept( $current );
			}
		);

		$iterator = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( $file->isDir() ) {
				continue;
			}

			yield $this->stat( $file );
		}
	}

	/**
	 * Decides whether an entry (file or directory) is kept, and — for a
	 * directory — whether the walker descends into it.
	 *
	 * @param SplFileInfo $current Current entry.
	 * @return bool
	 */
	private function accept( SplFileInfo $current ): bool {
		$rel = $this->relative_path( $current->getPathname() );

		if ( $this->is_always_excluded( $rel ) ) {
			return false;
		}

		if ( self::is_excluded( $rel, $this->excluded_patterns ) ) {
			return false;
		}

		if ( ! $this->follow_symlinks && $current->isDir() && $current->isLink() ) {
			return false;
		}

		return true;
	}

	/**
	 * A file's size and mtime, or zeros and an `unreadable` flag when `stat`
	 * fails — a broken symlink, a file deleted between the directory read and
	 * this call, a directory the walker may list but not stat.
	 *
	 * The size is 0 rather than a negative sentinel because it is written
	 * straight into the file index's `size bigint unsigned` column: MySQL in
	 * strict mode rejects an out-of-range value, and `Db\FilesRepository`
	 * inserts 200 rows per statement, so one broken symlink took 199 good
	 * rows down with it. The flag carries the fact instead, for
	 * `Index\FileIndexer`'s `unreadable` counter; the hash pass finds out for
	 * itself when its `fopen()` fails.
	 *
	 * @param SplFileInfo $file File entry.
	 * @return array{path:string, size:int, mtime:int, unreadable:bool}
	 */
	private function stat( SplFileInfo $file ): array {
		$rel = $this->relative_path( $file->getPathname() );

		try {
			$size  = $file->getSize();
			$mtime = $file->getMTime();
		} catch ( Throwable $e ) {
			unset( $e );

			return [
				'path'       => $rel,
				'size'       => 0,
				'mtime'      => 0,
				'unreadable' => true,
			];
		}

		return [
			'path'       => $rel,
			'size'       => max( 0, (int) $size ),
			'mtime'      => (int) $mtime,
			'unreadable' => false,
		];
	}

	/**
	 * @param string $absolute Absolute path under $this->root.
	 * @return string ABSPATH-relative path, '/'-separated.
	 */
	private function relative_path( string $absolute ): string {
		$normalized  = str_replace( '\\', '/', $absolute );
		$rel_to_root = ltrim( substr( $normalized, strlen( $this->root ) ), '/' );

		return '' === $this->rel_base ? $rel_to_root : $this->rel_base . '/' . $rel_to_root;
	}

	/**
	 * A pattern matches the path itself and everything under it.
	 *
	 * @param string            $rel      ABSPATH-relative path.
	 * @param array<int,string> $patterns Normalized glob patterns.
	 * @return bool
	 */
	public static function is_excluded( string $rel, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( fnmatch( $pattern, $rel, FNM_CASEFOLD ) || fnmatch( $pattern . '/*', $rel, FNM_CASEFOLD ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $rel ABSPATH-relative path.
	 * @return bool
	 */
	private function is_always_excluded( string $rel ): bool {
		foreach ( $this->always_excluded_paths as $path ) {
			if ( $rel === $path || 0 === strpos( $rel, $path . '/' ) ) {
				return true;
			}
		}

		foreach ( explode( '/', $rel ) as $segment ) {
			if ( in_array( $segment, self::ALWAYS_EXCLUDED_SEGMENTS, true ) ) {
				return true;
			}
		}

		return false;
	}
}
