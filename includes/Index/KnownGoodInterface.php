<?php
/**
 * Contract for KnownGood, extracted so callers can be unit-tested with a mock.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Index;

defined( 'ABSPATH' ) || exit;

/**
 * `KnownGood` is `final` (spec §6.4), so it can't be mocked directly with
 * Mockery; consumers (e.g. `FileIndexer`) type-hint this interface instead
 * so tests can substitute a mock.
 */
interface KnownGoodInterface {

	/**
	 * @param string $rel    ABSPATH-relative path.
	 * @param string $origin Result of Origin::of().
	 * @param string $md5    The file's actual md5.
	 * @return array{status:string, expected:string, package:string, version:string}
	 */
	public function check( string $rel, string $origin, string $md5 ): array;

	/**
	 * Path => acceptable md5s map for the installed core version, memoized.
	 * Null when the version is unknown or the backend has no list for it.
	 *
	 * @return array<string,array<int,string>>|null
	 */
	public function core_paths(): ?array;
}
