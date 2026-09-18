<?php
/**
 * Pipeline phase: walk the filesystem into the file index.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Index\FileIndexer;
use LightweightPlugins\Scan\Index\Walker;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Context;

defined( 'ABSPATH' ) || exit;

/**
 * One uninterruptible pass (spec §6.5): a `Walker` can't have its position
 * saved mid-flight, so this phase deliberately ignores the tick deadline
 * and drains the whole walk in one go — a stat pass over ~46k files takes a
 * couple of seconds. `scope=full` resets `scanned_bundle` first so every
 * file is content-scanned again.
 */
final class IndexPhase implements PhaseInterface {

	public function run( Context $ctx, callable $deadline ): bool {
		unset( $deadline );

		$cursor = $ctx->cursor;

		if ( true === $cursor->get( 'index_done', false ) ) {
			return true;
		}

		$path = $cursor->path();

		if ( 'full' === $cursor->scope() ) {
			$ctx->files->reset_scanned( $path );
		}

		$root = rtrim( ABSPATH, '/\\' ) . ( '' === $path ? '' : '/' . $path );

		$indexer = new FileIndexer( $ctx->files, $ctx->known_good(), $ctx->findings, $ctx->software() );

		$result = $indexer->stat_pass(
			new Walker( $root, $path, Options::excluded_paths(), ! empty( $ctx->options['follow_symlinks'] ) ),
			$ctx->run_id
		);

		$cursor->set( 'index_done', true );

		$ctx->stats->inc( 'files.indexed', $result['indexed'] );
		$ctx->stats->inc( 'files.unreadable', $result['unreadable'] );
		$ctx->items += $result['indexed'];

		return true;
	}
}
