<?php
/**
 * One run, one signature bundle.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A run spans many ticks, and the bundle can be replaced between two of
 * them — an auto-update from another request, or an admin pressing "Update
 * signatures". The file index records the version each row was scanned at,
 * so carrying on against a different bundle would leave rows stamped with a
 * version they were never checked against, and the run's own
 * `bundle_version` would describe only part of its work.
 *
 * `record()` pins the version `BundlePhase` settled on into the cursor;
 * `verify()` refuses to continue a tick that loaded a different one — or no
 * bundle at all, which it reports as what it is rather than as a version
 * that changed to zero. The run is failed rather than re-queued: the next
 * scheduled scan starts over against the new bundle anyway, which is what
 * re-queuing would amount to.
 */
final class BundleGuard {

	/** Cursor field the run's bundle version is pinned in. */
	public const FIELD = 'bundle_version';

	/**
	 * @param Cursor $cursor  Run cursor.
	 * @param int    $version Bundle version this run scans against.
	 */
	public static function record( Cursor $cursor, int $version ): void {
		$cursor->set( self::FIELD, $version );
	}

	/**
	 * No pinned version means the bundle phase has not run yet (the first
	 * tick), so there is nothing to compare against — and nothing to load
	 * either, which keeps that tick from reading the pack twice.
	 *
	 * @param Context $ctx Run context.
	 * @throws RuntimeException When there is no bundle, or not the one the run started with.
	 */
	public static function verify( Context $ctx ): void {
		$pinned = (int) $ctx->cursor->get( self::FIELD, 0 );

		if ( 0 === $pinned ) {
			return;
		}

		// Literal, not a constant: WPCS treats any non-literal exception
		// message as unescaped output. Both are machine-readable codes,
		// stored verbatim as the run's `error`, never shown unescaped.
		if ( null === $ctx->signatures() ) {
			// A bundle that is gone rather than replaced: version 0 would
			// compare as "changed" below and name the wrong problem.
			// BundlePhase calls this same condition `bundle_missing`.
			throw new RuntimeException( 'bundle_missing' );
		}

		if ( $pinned === $ctx->bundle_version() ) {
			return;
		}

		throw new RuntimeException( 'bundle_changed_mid_run' );
	}
}
