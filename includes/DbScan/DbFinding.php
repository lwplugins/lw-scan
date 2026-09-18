<?php
/**
 * Builds a Finding from a RowMatcher hit on one db column.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\DbScan;

use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Scanner\MatchResult;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns `RowMatcher::match()`'s output into a stored
 * finding (spec §7/§9), shared by every DbScan\*Scanner instead of each
 * duplicating the tier/severity/reason bookkeeping. `$row_id` is typed
 * `int` because every caller with a real row (options/posts/postmeta) has
 * a numeric primary key; TriggersScanner, whose rows are keyed by trigger
 * name instead, builds with `$row_id = 0` and overwrites `locator`
 * afterwards.
 */
final class DbFinding {

	/**
	 * @param string                 $table   Table name without prefix, e.g. `options`.
	 * @param string                 $column  Column the matches were found in, e.g. `option_value`.
	 * @param int                    $row_id  Row's numeric primary key.
	 * @param array<int,MatchResult> $matches Non-empty result of RowMatcher::match().
	 * @param string                 $label   Human-readable row label, e.g. the option name.
	 */
	public static function build( string $table, string $column, int $row_id, array $matches, string $label ): Finding {
		$first = $matches[0];
		$tier  = $first->tier;

		$signature_ids = [];

		foreach ( $matches as $match ) {
			$signature_ids[] = $match->sig_id;

			if ( Severity::tier_rank( $match->tier ) > Severity::tier_rank( $tier ) ) {
				$tier = $match->tier;
			}
		}

		$locator = "{$table}:{$column}:{$row_id}";
		$finding = new Finding( 'db', $locator, $tier, Severity::of( $tier, 0, 'db' ) );

		$finding->signature_ids = array_values( array_unique( $signature_ids ) );
		$finding->category      = $first->category;
		$finding->excerpt       = $first->excerpt;
		$finding->reason        = "{$label}: {$first->name}";
		$finding->meta          = [
			'table'  => $table,
			'column' => $column,
			'row_id' => $row_id,
			'label'  => $label,
		];

		return $finding;
	}
}
