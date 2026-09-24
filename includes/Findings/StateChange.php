<?php
/**
 * Input normalization for finding state changes.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * Every entry point that restates findings — the Findings tab's row and
 * bulk actions (`Rest\FindingsController::set_state()`) and the
 * `lw-scan/acknowledge` ability (`SiteManager\AbilityCallbacks`) — takes a
 * list of ids from an untrusted caller and hands it to
 * `Db\FindingsRepository::set_state()`. They agree on what a usable list
 * is, so they share it here instead of each writing the same
 * absint/filter chain. The states themselves are `FindingsRepository::STATES`.
 */
final class StateChange {

	/**
	 * Turns raw caller input into finding ids fit for a `WHERE id IN (...)`:
	 * every value absint'd, a stray `0` (or anything unusable) dropped
	 * rather than treated as a row id, duplicates removed and the keys
	 * reindexed so the list is a clean 0..n array.
	 *
	 * @param array<int|string, mixed> $ids Raw ids from a request or an ability input.
	 * @return int[] Usable finding ids, possibly empty.
	 */
	public static function normalize_ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}
}
