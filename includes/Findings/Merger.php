<?php
/**
 * Pure merge logic for combining a fresh Finding into an existing DB row.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * Extracted out of FindingsRepository so the merge rules (spec §9) can be
 * unit-tested without a database: no DB access, only arrays and a Finding.
 */
final class Merger {

	/**
	 * `$existing` is a raw findings-table row (as from `get_row(...,
	 * ARRAY_A)`); `$incoming` already carries an authoritative severity for
	 * its own tier (computed by the caller via Severity::of()). That
	 * severity is used whenever the incoming tier is at least as severe as
	 * the existing one (tier rose, or stayed the same tier with a fresh
	 * signal) — a rescan at the same tier can still escalate review to
	 * alert, and merging must not let a stale severity survive it. Only
	 * when the incoming tier is strictly lower is the existing severity
	 * kept, since the incoming Finding has nothing authoritative to say
	 * about the tier that's winning. `first_seen` is intentionally left
	 * out of the returned row: the caller's UPDATE never touches it.
	 *
	 * @param array<string, mixed> $existing Current DB row for this locator_hash.
	 * @param Finding              $incoming Freshly built finding to merge in.
	 * @param int                  $now      Current unix timestamp.
	 * @return array{row: array<string, mixed>, changed: bool, reopened: bool}
	 */
	public static function merge_rows( array $existing, Finding $incoming, int $now ): array {
		$existing_ids = Finding::decode_list( (string) ( $existing['signature_ids'] ?? '[]' ) );
		$union_ids    = array_values( array_unique( array_merge( $existing_ids, $incoming->signature_ids ) ) );
		$grown        = count( $union_ids ) > count( $existing_ids );

		$existing_tier = (string) ( $existing['tier'] ?? '' );
		$incoming_rank = Severity::tier_rank( $incoming->tier );
		$existing_rank = Severity::tier_rank( $existing_tier );
		$tier_rose     = $incoming_rank > $existing_rank;
		$tier          = $tier_rose ? $incoming->tier : $existing_tier;
		$severity      = $incoming_rank >= $existing_rank ? $incoming->severity : (string) ( $existing['severity'] ?? $incoming->severity );

		$state            = (string) ( $existing['state'] ?? 'new' );
		$state_changed_at = (int) ( $existing['state_changed_at'] ?? 0 );
		$reopened         = false;

		if ( 'ignored' === $state && $grown ) {
			$state            = 'new';
			$state_changed_at = $now;
			$reopened         = true;
		}

		$row = [
			'signature_ids'    => wp_json_encode( $union_ids ),
			'tier'             => $tier,
			'category'         => $incoming->category,
			'severity'         => $severity,
			'excerpt'          => $incoming->excerpt,
			'line'             => $incoming->line,
			'reason'           => $incoming->reason,
			'meta'             => wp_json_encode( $incoming->meta ),
			'last_seen'        => $now,
			'state'            => $state,
			'state_changed_at' => $state_changed_at,
		];

		return [
			'row'      => $row,
			'changed'  => $grown || $tier_rose,
			'reopened' => $reopened,
		];
	}
}
