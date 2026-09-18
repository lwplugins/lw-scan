<?php
/**
 * The words behind a run's stored error code.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * A run that ends badly stores a short machine code in its `error` column —
 * `out_of_memory`, `bundle_missing` — rather than a sentence, so the reason
 * survives translation changes and can be matched on. This is where those
 * codes become something a person can act on, for the Scan tab's run history
 * and for `wp lw-scan run` alike.
 *
 * Anything else is passed through untouched: a PHP fatal's own message is
 * already the best description there is of what happened, and so is an old
 * `memory_too_low` — the pre-flight refusal code a run from before this
 * release may still carry. Task 12 dropped the pre-flight it came from
 * along with the memory floor it was refusing against, so there is nothing
 * left to spell it out with; the raw code is the whole of what a row that
 * old has to say.
 *
 * Numbers in the `out_of_memory` sentence come from the run's own stats,
 * never from the request doing the reading: the same site answers
 * `memory_limit` with 256M to an admin page and with `-1` to WP-CLI, so a
 * label measured at render time would narrate a machine that is not the one
 * that failed.
 */
final class RunError {

	/** Run `error` a tick that fatals on an OOM is closed with. */
	public const OUT_OF_MEMORY = 'out_of_memory';

	/**
	 * @param string               $error The run row's `error` column.
	 * @param array<string, mixed> $stats The run row's decoded `stats`, when the caller has them.
	 * @return string What to show for it; '' when the run has no error.
	 */
	public static function label( string $error, array $stats = [] ): string {
		if ( self::OUT_OF_MEMORY === $error ) {
			return self::memory_label( $stats );
		}

		// Literals: the two bundle codes are thrown as literal exception
		// messages (WPCS treats a non-literal one as unescaped output), so
		// there is no constant to point at. `Starter::restart()` writes
		// `bundle_changed`; `BundleGuard` and `BundlePhase` write the rest.
		switch ( $error ) {
			case 'bundle_changed':
			case 'bundle_changed_mid_run':
				return __( 'The signature set was updated while this scan was under way, so it was dropped — the next scan starts over against the new signatures.', 'lw-scan' );

			case 'bundle_missing':
				// Reached by a fresh install whose very first scan could not
				// download a bundle as much as by a site whose stored one
				// went missing, so it names both the place to retry from and
				// the thing that has to work for the retry to succeed.
				return __( 'Signatures could not be downloaded — check the Health tab and the backend connection.', 'lw-scan' );

			default:
				return $error;
		}
	}

	/**
	 * The `memory_limit` a tick was running at when it fatally ran out, if
	 * `Runner::work()` got far enough to record one. Without it the sentence
	 * stays general rather than borrowing a number that would be someone
	 * else's.
	 *
	 * @param array<string, mixed> $stats The run row's decoded `stats`.
	 */
	private static function memory_label( array $stats ): string {
		$limit = isset( $stats['memory_limit'] ) ? (int) $stats['memory_limit'] : 0;

		if ( $limit > 0 ) {
			return sprintf(
				/* translators: %s: memory_limit the tick was running at, e.g. "256 MB". */
				__( 'PHP ran out of memory during the scan (memory_limit %s). Raise memory_limit or run the scan with WP-CLI.', 'lw-scan' ),
				(string) size_format( $limit )
			);
		}

		return __( 'PHP ran out of memory during the scan. Raise memory_limit or run the scan with WP-CLI.', 'lw-scan' );
	}
}
