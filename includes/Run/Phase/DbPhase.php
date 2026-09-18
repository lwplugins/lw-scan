<?php
/**
 * Pipeline phase: scan database content for injected payloads.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run\Phase;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\DbScan\MetaScanner;
use LightweightPlugins\Scan\DbScan\OptionsScanner;
use LightweightPlugins\Scan\DbScan\PostsScanner;
use LightweightPlugins\Scan\DbScan\TriggersScanner;
use LightweightPlugins\Scan\DbScan\UsersScanner;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the five sub-scanners in a fixed order (spec §7), keeping
 * `{scanner, last_id}` in the cursor so a tick that runs out of budget
 * resumes inside the same table. The paged scanners (options, posts, meta)
 * loop until they report `done`; users and triggers are single, cheap
 * calls.
 *
 * Every sub-scanner contributes the rows it examined to `$ctx->items`, so
 * `phases.db.items` answers "how much of the database did this run look
 * at?" -- and a zero there means nothing was examined, which is the shape a
 * run that quietly scanned nothing takes.
 */
final class DbPhase implements PhaseInterface {

	/** @var array<int, string> Sub-scanner order. */
	private const ORDER = [ 'options', 'posts', 'meta', 'users', 'triggers' ];

	private const LIMIT = 200;

	/**
	 * @param Context  $ctx      Run context.
	 * @param callable $deadline Returns true once the tick's budget is spent.
	 * @throws RuntimeException `bundle_missing` when there is no signature set to scan with.
	 */
	public function run( Context $ctx, callable $deadline ): bool {
		$signatures = $ctx->signatures();

		if ( null === $signatures ) {
			throw new RuntimeException( 'bundle_missing' );
		}

		$cursor  = $ctx->cursor;
		$db      = (array) $cursor->get( 'db', [] );
		$current = isset( $db['scanner'] ) && is_string( $db['scanner'] ) ? $db['scanner'] : self::ORDER[0];
		$last_id = (int) ( $db['last_id'] ?? 0 );

		$start = array_search( $current, self::ORDER, true );
		$start = false === $start ? 0 : (int) $start;
		$total = count( self::ORDER );

		for ( $i = $start; $i < $total; $i++ ) {
			$name = self::ORDER[ $i ];

			$this->store( $cursor, $name, $last_id );

			// Between sub-scanners is the cheapest place to hand the tick
			// back: the cursor already names the one that hasn't run.
			if ( $i > $start && $deadline() ) {
				return false;
			}

			if ( ! $this->drain( $ctx, $signatures, $name, $last_id, $deadline ) ) {
				return false;
			}

			$last_id = 0;
		}

		return true;
	}

	/**
	 * Runs one sub-scanner to completion (or to the deadline), advancing
	 * the cursor's `last_id` as it pages.
	 *
	 * @param Context    $ctx        Run context.
	 * @param Signatures $signatures Loaded signature set to scan with.
	 * @param string     $name       Sub-scanner name.
	 * @param int        $last_id    Cursor position to resume from.
	 * @param callable   $deadline   Returns true once the tick's budget is spent.
	 * @return bool True when the sub-scanner finished.
	 */
	private function drain( Context $ctx, Signatures $signatures, string $name, int $last_id, callable $deadline ): bool {
		if ( 'users' === $name ) {
			$result = ( new UsersScanner( $ctx->wpdb(), $ctx->findings ) )->scan();

			// `db.users` is a rows-examined counter like its four siblings --
			// Admin\Settings\RunStatsView sums all five as "DB rows" -- so
			// the new administrators it finds belong in `db.findings`, next
			// to what the other sub-scanners raise.
			$ctx->stats->inc( 'db.users', $result['users'] );
			$ctx->stats->inc( 'db.findings', $result['new_admins'] );
			$ctx->items += $result['users'];

			return true;
		}

		if ( 'triggers' === $name ) {
			$result = ( new TriggersScanner( $ctx->wpdb(), $signatures, $ctx->findings ) )->scan();
			$ctx->stats->inc( 'db.triggers', $result['triggers'] );
			$ctx->stats->inc( 'db.findings', $result['findings'] );
			$ctx->items += $result['triggers'];

			if ( $result['skipped'] ) {
				$this->mark_skipped( $ctx, 'triggers' );
			}

			return true;
		}

		$scanner = $this->paged_scanner( $ctx, $signatures, $name );

		do {
			$result  = $scanner->scan( $last_id, self::LIMIT );
			$last_id = (int) $result['last_id'];

			$this->store( $ctx->cursor, $name, $last_id );

			$ctx->stats->inc( 'db.' . $name, (int) $result['rows'] );
			$ctx->stats->inc( 'db.findings', (int) $result['findings'] );
			$ctx->items += (int) $result['rows'];

			if ( $result['done'] ) {
				return true;
			}
		} while ( ! $deadline() );

		return false;
	}

	/**
	 * @param Context    $ctx        Run context.
	 * @param Signatures $signatures Loaded signature set to scan with.
	 * @param string     $name       One of the paged sub-scanners.
	 * @return OptionsScanner|PostsScanner|MetaScanner
	 */
	private function paged_scanner( Context $ctx, Signatures $signatures, string $name ) {
		if ( 'posts' === $name ) {
			return new PostsScanner( $ctx->wpdb(), $signatures, $ctx->findings );
		}

		if ( 'meta' === $name ) {
			return new MetaScanner( $ctx->wpdb(), $signatures, $ctx->findings );
		}

		return new OptionsScanner( $ctx->wpdb(), $signatures, $ctx->findings );
	}

	/**
	 * @param Cursor $cursor  Run cursor.
	 * @param string $name    Sub-scanner name.
	 * @param int    $last_id Cursor position within that sub-scanner.
	 */
	private function store( Cursor $cursor, string $name, int $last_id ): void {
		$cursor->set(
			'db',
			[
				'scanner' => $name,
				'last_id' => $last_id,
			]
		);
	}

	/**
	 * @param Context $ctx  Run context.
	 * @param string  $name Sub-scanner that could not run.
	 */
	private function mark_skipped( Context $ctx, string $name ): void {
		$skipped = (array) ( $ctx->stats->to_array()['db']['skipped'] ?? [] );

		if ( ! in_array( $name, $skipped, true ) ) {
			$skipped[] = $name;
		}

		$ctx->stats->set( 'db.skipped', array_values( $skipped ) );
	}
}
