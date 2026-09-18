<?php
/**
 * Pipeline phase: make sure a signature pack is available.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Remote\PackFetcher;
use LightweightPlugins\Scan\Run\BundleGuard;
use LightweightPlugins\Scan\Run\Context;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the backend for a newer signature pack when auto-update is on (or
 * when there is no pack at all), then hands the loaded signature set to the
 * Context for every later phase. A backend that can't be reached is not
 * fatal as long as a stored pack exists (spec §14) — only a run with no
 * pack at all fails, which the Runner turns into a failed run. The version
 * it settles on is pinned into the cursor, so the rest of the run can check
 * it is still scanning against the same pack.
 */
final class BundlePhase implements PhaseInterface {

	public function run( Context $ctx, callable $deadline ): bool {
		unset( $deadline );

		$signatures = PackLoader::signatures();

		if ( null === $signatures || ! empty( $ctx->options['bundle_auto_update'] ) ) {
			$signatures = $this->check( $ctx, $signatures );
		}

		if ( null === $signatures ) {
			throw new RuntimeException( 'bundle_missing' );
		}

		$ctx->use_signatures( $signatures );
		$version = $signatures->version();

		// Pinned in the cursor, not just in the Context: every later tick is
		// a fresh process that loads the pack itself, and must be able to
		// tell that it loaded the same one (Run\BundleGuard).
		BundleGuard::record( $ctx->cursor, $version );

		$ctx->stats->set( 'bundle_version', $version );
		$ctx->runs->update( $ctx->run_id, [ 'bundle_version' => $version ] );

		return true;
	}

	/**
	 * Asks the backend for a newer pack and reloads the stored copy when one
	 * was installed.
	 *
	 * @param Context         $ctx     Run context.
	 * @param Signatures|null $current Signature set loaded before the check.
	 * @return Signatures|null The signature set to scan with.
	 */
	private function check( Context $ctx, ?Signatures $current ): ?Signatures {
		$result = ( new PackFetcher( $ctx->client(), $ctx->store() ) )->check();

		$ctx->stats->set( 'bundle', $result );

		if ( 'failed' === $result['status'] ) {
			// `remote.errors` is not touched here: Runner books the
			// Remote\Client counter delta for the whole tick, and a failed
			// check can also mean a local problem (storage, verification)
			// that never left the server.
			return $current;
		}

		if ( 'updated' !== $result['status'] ) {
			return $current;
		}

		PackLoader::reset();

		return PackLoader::signatures();
	}
}
