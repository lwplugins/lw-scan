<?php
/**
 * Scan pipeline phase list and per-scope subsets.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * The scan pipeline (spec §10.1) is one fixed phase order; each scope runs
 * a subset of it. `full`/`changed` run every phase (the difference between
 * the two is handled elsewhere, by resetting `scanned_bundle` before the
 * files phase); `db` skips the file-scan phases; `path` skips the
 * database/vulnerability phases.
 */
final class Phases {

	/** Every phase, in pipeline order. */
	public const ALL = [ 'bundle', 'index', 'hash', 'files', 'db', 'vuln', 'finalize' ];

	/**
	 * Ordered phase list per scan scope.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const BY_SCOPE = [
		'full'    => self::ALL,
		'changed' => self::ALL,
		'db'      => [ 'bundle', 'db', 'vuln', 'finalize' ],
		'path'    => [ 'bundle', 'index', 'hash', 'files', 'finalize' ],
	];

	/**
	 * @param string $scope full|changed|db|path.
	 * @return array<int, string> Ordered phase list for the scope, or an empty array for an unknown scope.
	 */
	public static function for_scope( string $scope ): array {
		return self::BY_SCOPE[ $scope ] ?? [];
	}

	public static function valid_scope( string $scope ): bool {
		return array_key_exists( $scope, self::BY_SCOPE );
	}
}
