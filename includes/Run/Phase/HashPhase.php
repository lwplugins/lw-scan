<?php
/**
 * Pipeline phase: hash and classify newly indexed files.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Index\FileIndexer;
use LightweightPlugins\Scan\Run\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Drains the "not hashed yet" queue 100 rows at a time (spec §6.5) until
 * the deadline or the queue runs dry; the cursor keeps `hash_last_id`, so
 * the next tick picks up where this one stopped. Once the queue is empty,
 * the number of files still due a content scan is counted into the cursor
 * as the files phase's progress denominator.
 */
final class HashPhase implements PhaseInterface {

	private const BATCH = 100;

	public function run( Context $ctx, callable $deadline ): bool {
		$cursor  = $ctx->cursor;
		$indexer = new FileIndexer( $ctx->files, $ctx->known_good(), $ctx->findings, $ctx->software() );

		do {
			$result = $indexer->hash_pass( (int) $cursor->get( 'hash_last_id', 0 ), self::BATCH, $deadline );

			$cursor->set( 'hash_last_id', $result['last_id'] );

			$ctx->items += $result['hashed'];
			$ctx->stats->inc( 'files.hashed', $result['hashed'] );
			$ctx->stats->inc( 'files.known_good', $result['known_good'] );
			$ctx->stats->inc( 'files.integrity', $result['integrity'] );
			$ctx->stats->inc( 'files.unreadable', $result['unreadable'] );

			if ( $result['done'] ) {
				$cursor->set( 'files_total', $ctx->files->count_queue( $ctx->bundle_version(), $cursor->path() ) );

				return true;
			}
		} while ( ! $deadline() );

		return false;
	}
}
