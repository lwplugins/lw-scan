<?php
/**
 * Verifies a file's content against wp.org checksum lists.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Index;

use LightweightPlugins\Scan\Remote\ChecksumProvider;
use LightweightPlugins\Scan\Vuln\InstalledSoftware;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a file's md5 matches one of the backend's known-good
 * checksums for its package (spec §6.4) — a file that shipped with more
 * than one valid content has several acceptable md5s, and any of them
 * counts as a match. `core`-origin files are looked up by their full
 * ABSPATH-relative path; `plugin:<slug>`/`theme:<slug>`-origin files are
 * looked up by the path relative to their package directory. A file
 * whose package has no checksum list, or that simply isn't in the list
 * (e.g. a build artefact), is `unknown` — never `mismatch`. A handful of
 * paths are never checksum-based regardless of origin, because they are
 * expected to differ per install (`wp-config.php`, `.htaccess`, files
 * directly under `wp-content/`). The plugin's own files are checked first,
 * against the manifest it ships (`Index\SelfManifest`) — the backend has
 * no checksum list for lw-scan, and its decoder sources would otherwise be
 * reported as infected on every install.
 */
final class KnownGood implements KnownGoodInterface {

	/**
	 * Exact relative paths that are never checksum-based.
	 *
	 * @var array<int,string>
	 */
	private const NEVER_CHECKSUM_FILES = [ 'wp-config.php', '.htaccess' ];

	/**
	 * @var ChecksumProvider
	 */
	private ChecksumProvider $checksums;

	/**
	 * @var array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>
	 */
	private array $software;

	/**
	 * @var SelfManifest
	 */
	private SelfManifest $manifest;

	/**
	 * @var array<string,array<int,string>>|null
	 */
	private ?array $core_paths = null;

	/**
	 * @var bool
	 */
	private bool $core_paths_loaded = false;

	/**
	 * Per-package checksum lists, memoized by "<kind>:<slug>:<version>".
	 *
	 * @var array<string, array<string,array<int,string>>|null>
	 */
	private array $package_lists = [];

	/**
	 * @param ChecksumProvider                                                                      $checksums Backend checksum client.
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $software  Result of InstalledSoftware::list().
	 * @param SelfManifest|null                                                                     $manifest  The plugin's own shipped checksums; defaults to the manifest at LW_SCAN_PATH.
	 */
	public function __construct( ChecksumProvider $checksums, array $software, ?SelfManifest $manifest = null ) {
		$this->checksums = $checksums;
		$this->software  = $software;
		$this->manifest  = $manifest ?? new SelfManifest();
	}

	/**
	 * @param string $rel    ABSPATH-relative path.
	 * @param string $origin Result of Origin::of().
	 * @param string $md5    The file's actual md5.
	 * @return array{status:string, expected:string, package:string, version:string}
	 */
	public function check( string $rel, string $origin, string $md5 ): array {
		$self = $this->check_self( $rel, $md5 );

		if ( null !== $self ) {
			return $self;
		}

		if ( self::is_never_checksummed( $rel ) ) {
			return self::unknown_result();
		}

		if ( 'core' === $origin ) {
			return $this->check_core( $rel, $md5 );
		}

		if ( 1 === preg_match( '/^(plugin|theme):(.+)$/', $origin, $matches ) ) {
			return $this->check_package( $matches[1], $matches[2], $rel, $md5 );
		}

		return self::unknown_result();
	}

	/**
	 * The plugin's own files, verified against the manifest it ships. Null
	 * means "not one of ours, or not the file we shipped" — the caller then
	 * continues with the normal lookup, so a tampered copy is still scanned.
	 *
	 * @param string $rel ABSPATH-relative path.
	 * @param string $md5 The file's actual md5.
	 * @return array{status:string, expected:string, package:string, version:string}|null
	 */
	private function check_self( string $rel, string $md5 ): ?array {
		$root = $this->manifest->root();

		if ( '' === $root || 0 !== strpos( $rel, $root ) ) {
			return null;
		}

		$expected = $this->manifest->md5_for( substr( $rel, strlen( $root ) ) );

		if ( null === $expected || $expected !== $md5 ) {
			return null;
		}

		return [
			'status'   => 'match',
			'expected' => $expected,
			'package'  => 'self',
			'version'  => $this->manifest->version(),
		];
	}

	/**
	 * Path => acceptable md5s map for the installed core version, memoized.
	 * Null when the version is unknown or the backend has no list for it.
	 *
	 * @return array<string,array<int,string>>|null
	 */
	public function core_paths(): ?array {
		if ( ! $this->core_paths_loaded ) {
			$version          = InstalledSoftware::core_version( $this->software );
			$this->core_paths = '' !== $version ? $this->checksums->core( $version ) : null;

			$this->core_paths_loaded = true;
		}

		return $this->core_paths;
	}

	/**
	 * @param string $rel ABSPATH-relative path.
	 * @param string $md5 The file's actual md5.
	 * @return array{status:string, expected:string, package:string, version:string}
	 */
	private function check_core( string $rel, string $md5 ): array {
		$paths = $this->core_paths();

		if ( null === $paths || ! isset( $paths[ $rel ] ) ) {
			return self::unknown_result();
		}

		return [
			'status'   => in_array( $md5, $paths[ $rel ], true ) ? 'match' : 'mismatch',
			'expected' => $paths[ $rel ][0],
			'package'  => 'core',
			'version'  => InstalledSoftware::core_version( $this->software ),
		];
	}

	/**
	 * @param string $kind 'plugin' or 'theme'.
	 * @param string $slug Package slug.
	 * @param string $rel  ABSPATH-relative path.
	 * @param string $md5  The file's actual md5.
	 * @return array{status:string, expected:string, package:string, version:string}
	 */
	private function check_package( string $kind, string $slug, string $rel, string $md5 ): array {
		$item = InstalledSoftware::find( $this->software, $kind, $slug );

		if ( null === $item ) {
			return self::unknown_result();
		}

		$prefix = 'wp-content/' . ( 'plugin' === $kind ? 'plugins' : 'themes' ) . '/' . $slug . '/';

		if ( 0 !== strpos( $rel, $prefix ) ) {
			return self::unknown_result();
		}

		$package_rel = substr( $rel, strlen( $prefix ) );
		$paths       = $this->package_paths( $kind, $slug, $item['version'] );

		if ( null === $paths || ! isset( $paths[ $package_rel ] ) ) {
			return self::unknown_result();
		}

		return [
			'status'   => in_array( $md5, $paths[ $package_rel ], true ) ? 'match' : 'mismatch',
			// Only the first acceptable md5 is reported: `expected` is a
			// single-value display/storage field, and a mismatch is a
			// mismatch against all of them.
			'expected' => $paths[ $package_rel ][0],
			'package'  => $kind . ':' . $slug,
			'version'  => $item['version'],
		];
	}

	/**
	 * @param string $kind    'plugin' or 'theme'.
	 * @param string $slug    Package slug.
	 * @param string $version Installed version.
	 * @return array<string,array<int,string>>|null
	 */
	private function package_paths( string $kind, string $slug, string $version ): ?array {
		$key = $kind . ':' . $slug . ':' . $version;

		if ( ! array_key_exists( $key, $this->package_lists ) ) {
			$this->package_lists[ $key ] = 'plugin' === $kind
				? $this->checksums->plugin( $slug, $version )
				: $this->checksums->theme( $slug, $version );
		}

		return $this->package_lists[ $key ];
	}

	/**
	 * @param string $rel ABSPATH-relative path.
	 * @return bool
	 */
	private static function is_never_checksummed( string $rel ): bool {
		if ( in_array( $rel, self::NEVER_CHECKSUM_FILES, true ) ) {
			return true;
		}

		return 1 === preg_match( '~^wp-content/[^/]+$~', $rel );
	}

	/**
	 * @return array{status:string, expected:string, package:string, version:string}
	 */
	private static function unknown_result(): array {
		return [
			'status'   => 'unknown',
			'expected' => '',
			'package'  => '',
			'version'  => '',
		];
	}
}
