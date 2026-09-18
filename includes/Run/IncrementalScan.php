<?php
/**
 * Which queued files only need the signatures that are new in this pack.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * The incremental rule (spec §6.5), decided for one queue batch at a time.
 * A file may be checked against the new rules alone only when every other
 * rule has already run on it and nothing it found can be lost:
 *
 * - the scope is not `full`, and the pack carries a new-signature set;
 * - the file was last scanned at the version that set is relative to, or
 *   later — a file left behind at an older version never saw the rules
 *   that arrived in between;
 * - the file has no finding. A restricted scan cannot see what the older
 *   rules matched, and recording its outcome would drop that finding (no
 *   new match) or replace its matches with the new ones. Such a file is
 *   rescanned in full, which leaves the one-finding-per-file bookkeeping
 *   exactly as a normal scan does.
 *
 * The finding check is one repository query for the whole batch.
 */
final class IncrementalScan {

	/**
	 * @param Context                          $ctx  Run context.
	 * @param array<int, array<string, mixed>> $rows One batch of the content-scan queue.
	 * @return array<int, true> Ids of the rows to scan against the new signatures only.
	 */
	public static function row_ids( Context $ctx, array $rows ): array {
		$signatures = $ctx->signatures();

		if ( null === $signatures || $signatures->new_signatures()->is_empty() || 'full' === $ctx->cursor->scope() ) {
			return [];
		}

		$since      = $signatures->new_signatures()->since();
		$candidates = [];

		foreach ( $rows as $row ) {
			$scanned = (int) ( $row['scanned_bundle'] ?? 0 );

			if ( $scanned > 0 && $scanned >= $since ) {
				$candidates[ (int) $row['id'] ] = (string) $row['path'];
			}
		}

		if ( [] === $candidates ) {
			return [];
		}

		$with_finding = array_flip( $ctx->findings->existing_locators( 'file', array_values( $candidates ) ) );
		$ids          = [];

		foreach ( $candidates as $id => $path ) {
			if ( ! isset( $with_finding[ $path ] ) ) {
				$ids[ $id ] = true;
			}
		}

		return $ids;
	}
}
