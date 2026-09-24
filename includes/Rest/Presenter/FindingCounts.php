<?php
/**
 * Findings counts with every key present.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest\Presenter;

use LightweightPlugins\Scan\Db\FindingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * `FindingsRepository::counts()` only carries the groups that have rows,
 * so a site with no review findings has no `review` key at all. The React
 * filter chips read every combination, so this fills the gaps with 0 —
 * pure, no WP functions.
 */
final class FindingCounts {

	/** Severities every state group carries. */
	public const SEVERITIES = [ 'alert', 'review' ];

	/** Finding types the `type` group carries. */
	public const TYPES = [ 'file', 'integrity', 'db', 'vulnerability' ];

	/**
	 * @param array<string, mixed> $counts `FindingsRepository::counts()` output.
	 * @return array{state: array<string, array<string, int>>, type: array<string, int>, total: int}
	 */
	public static function complete( array $counts ): array {
		$raw_states = is_array( $counts['state'] ?? null ) ? $counts['state'] : [];
		$raw_types  = is_array( $counts['type'] ?? null ) ? $counts['type'] : [];
		$states     = [];
		$types      = [];

		foreach ( FindingsRepository::STATES as $state ) {
			$group = is_array( $raw_states[ $state ] ?? null ) ? $raw_states[ $state ] : [];

			foreach ( self::SEVERITIES as $severity ) {
				$states[ $state ][ $severity ] = (int) ( $group[ $severity ] ?? 0 );
			}
		}

		foreach ( self::TYPES as $type ) {
			$types[ $type ] = (int) ( $raw_types[ $type ] ?? 0 );
		}

		return [
			'state' => $states,
			'type'  => $types,
			'total' => (int) ( $counts['total'] ?? 0 ),
		];
	}
}
